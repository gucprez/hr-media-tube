<?php

namespace App\Services\Operations;

use App\Models\Container;
use App\Models\InventoryMovement as Movement;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit;
use App\Services\Fleet\ContainerFuel;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;

/**
 * Anular un furgón = devolver al inventario lo que consumió y BORRAR el furgón con sus tickets y materiales.
 * Lo que no se borra: las salidas de almacén dirigidas a ese furgón (el material sí salió; solo pierden el enlace, el texto
 * del destino se conserva) y el registro de gasoil, que sigue en el módulo de combustible como despacho anulado.
 * Solo queda constancia en la auditoría (quién, cuándo, motivo y el contenido).
 */
class ContainerRemoval
{
    public function __construct(protected StockService $stock, protected ContainerFuel $fuel) {}

    /** @throws \App\Services\Inventory\InventoryException|\App\Services\Fuel\FuelException */
    public function remove(Container $container, string $reason, ?User $user, bool $allowNegative = false): void
    {
        DB::transaction(function () use ($container, $reason, $user, $allowNegative) {
            $c = Container::lockForUpdate()->findOrFail($container->id);

            if ($c->movement_id && ($movement = Movement::find($c->movement_id)) && ! $movement->voided_at) {
                DB::table('containers')->where('id', $c->id)->update(['movement_id' => null, 'void_movement_id' => null]);   // el documento va a desaparecer
                $this->stock->remove($movement, 'Anulación del furgón '.$c->number.': '.$reason, $user, $allowNegative, fromSource: true);
            }
            $this->fuel->onVoid($c, $reason, $user);

            $this->delete($c, $reason);
        }, 3);
    }

    /**
     * Limpieza ÚNICA de lo que ya estaba anulado con el sistema anterior: furgones anulados (con su consumo y reverso) y tickets
     * anulados sueltos. Devuelve un resumen; con $dryRun no borra nada.
     *
     * @return array{furgones:int,tickets:int,omitidos:int,detalle:array<int,string>}
     */
    public function purgeVoided(bool $dryRun = false): array
    {
        $out = ['furgones' => 0, 'tickets' => 0, 'omitidos' => 0, 'detalle' => []];

        foreach (Container::where('status', Container::VOIDED)->orderBy('id')->get() as $c) {
            $orig = $c->movement_id ? Movement::find($c->movement_id) : null;
            $voids = $orig ? Movement::where('reversal_of_id', $orig->id)->get() : collect();
            if ($orig && $orig->voided_at && ! $this->stock->pairIsNeutral($orig, $voids)) {
                $out['omitidos']++;
                $out['detalle'][] = 'Furgón '.$c->number.': omitido (el efecto de su anulación en el inventario no es cero)';
                continue;
            }
            if ($orig && ! $orig->voided_at) {
                $out['omitidos']++;
                $out['detalle'][] = 'Furgón '.$c->number.': omitido (su consumo no figura anulado)';
                continue;
            }
            $out['furgones']++;
            $out['detalle'][] = 'Furgón '.$c->number.': '.($dryRun ? 'se borraría' : 'borrado').' con '.Ticket::where('container_id', $c->id)->count().' ticket(s)'.($orig ? ', su consumo '.$orig->number : '');
            if ($dryRun) {
                continue;
            }
            DB::transaction(function () use ($c, $orig, $voids) {
                if ($orig) {
                    $this->stock->purgePair($orig, $voids);
                }
                $this->delete($c->fresh(), $c->void_reason ?: 'Limpieza de furgón ya anulado');
            });
        }

        foreach (Ticket::where('status', 'voided')->orderBy('id')->get() as $t) {
            $out['tickets']++;
            $out['detalle'][] = 'Ticket '.$t->number.' ('.$t->type_label.'): '.($dryRun ? 'se borraría' : 'borrado');
            if (! $dryRun) {
                $this->deleteTicket($t, $t->void_reason ?: 'Limpieza de ticket ya anulado');
            }
        }

        return $out;
    }

    /** Borra un ticket y sus renglones; queda constancia en la auditoría. Los números no se reutilizan. */
    public function deleteTicket(Ticket $ticket, string $reason, bool $syncContainer = true): void
    {
        DB::transaction(function () use ($ticket, $reason, $syncContainer) {
            $snapshot = $this->ticketSnapshot($ticket);
            $container = $syncContainer && $ticket->type === Ticket::FUEL && $ticket->container_id ? Container::find($ticket->container_id) : null;
            $fuelBefore = $container ? $this->fuel->ticketGallons($container) : 0.0;
            DB::table('ticket_items')->where('ticket_id', $ticket->id)->delete();
            DB::table('tickets')->where('id', $ticket->id)->delete();
            if ($container) {
                $this->fuel->onTicketRemoved($container, $fuelBefore);
            }
            Audit::log('ticket.deleted', "{$ticket->type_label} {$ticket->number} anulado y borrado: {$reason}", $ticket, $snapshot, ['motivo' => $reason, 'borrado' => true]);
        });
    }

    protected function delete(Container $c, string $reason): void
    {
        $snapshot = [
            'numero' => $c->number, 'fecha' => optional($c->date)->toDateString(), 'estado' => $c->status, 'chofer' => $c->driver_display, 'conduce' => $c->conduce,
            'materiales' => $c->materials()->with('product')->get()->map(fn ($m) => ['producto' => $m->product?->name, 'cantidad_base' => (float) $m->quantity_base])->all(),
            'tickets' => Ticket::where('container_id', $c->id)->get()->map(fn ($t) => $t->type_label.' '.$t->number)->all(),
        ];

        foreach (Ticket::where('container_id', $c->id)->get() as $t) {
            $this->deleteTicket($t, 'Furgón '.$c->number.' anulado: '.$reason, false);
        }
        $this->stock->purgeAttachments($c);
        DB::table('inventory_movements')->where('container_id', $c->id)->update(['container_id' => null]);   // las salidas se quedan; conservan el texto del destino
        DB::table('fuel_dispatches')->where('container_id', $c->id)->update(['container_id' => null]);
        DB::table('container_materials')->where('container_id', $c->id)->delete();
        DB::table('containers')->where('id', $c->id)->delete();

        Audit::log('container.deleted', "Furgón {$c->number} anulado y borrado: {$reason}", $c, $snapshot, ['motivo' => $reason, 'borrado' => true]);
    }

    protected function ticketSnapshot(Ticket $t): array
    {
        return [
            'numero' => $t->number, 'tipo' => $t->type_label, 'furgon' => $t->container_number, 'chofer' => $t->driver_name, 'emitido' => optional($t->issued_at)->toDateString(),
            'impreso_veces' => $t->print_count, 'renglones' => $t->items->map(fn ($i) => ['descripcion' => $i->description, 'cantidad' => $i->quantity_label, 'galones' => $i->gallons])->all(),
        ];
    }
}
