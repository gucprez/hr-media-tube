<?php

namespace App\Services\Fleet;

use App\Models\Container;
use App\Models\FuelDispatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Audit;
use App\Services\Fuel\FuelService;
use Illuminate\Support\Facades\DB;

/**
 * Integración furgón ↔ combustible. El furgón guarda en «Gasoil (galones)» lo que le corresponde. Ese dato se llena solo cuando se
 * emite un ticket de gasoil del furgón o se registra un despacho de Combustible con ese furgón; al CERRAR se descuenta de la reserva
 * únicamente lo que FALTA (galones del furgón menos lo ya despachado a él), así nunca se cuenta dos veces. Corre dentro de la misma
 * transacción del cierre: si no hay reserva suficiente, el furgón NO se cierra. Al anularlo, el despacho del cierre se anula y la
 * reserva se restituye.
 */
class ContainerFuel
{
    public function __construct(protected FuelService $fuel) {}

    /** Galones ya despachados (y no anulados) con destino este furgón, venga el despacho de donde venga. */
    public function dispatched(Container $container): float
    {
        return round((float) FuelDispatch::where('container_id', $container->id)->whereNull('voided_at')->sum('gallons'), 2);
    }

    /** Galones del furgón que todavía NO se han descontado de la reserva. */
    public function pending(Container $container): float
    {
        return max(0.0, round((float) $container->fuel_gallons - $this->dispatched($container), 2));
    }

    public function onClose(Container $container, User $user): void
    {
        $pending = $this->pending($container);
        if ($pending <= 0) {
            return;
        }
        $already = $this->dispatched($container);
        $this->fuel->dispatch([
            'dispatched_at' => now()->toDateString(), 'vehicle_id' => $container->vehicle_id, 'container_id' => $container->id,
            'gallons' => $pending, 'notes' => 'Gasoil del furgón '.$container->number.($already > 0 ? ' (lo que faltaba; ya se habían despachado '.rtrim(rtrim(number_format($already, 2, '.', ''), '0'), '.').' gal)' : ''), 'origin' => 'container',
        ], $user);
    }

    public function onVoid(Container $container, string $reason, User $user): void
    {
        $dispatch = FuelDispatch::where('container_id', $container->id)->where('origin', 'container')->whereNull('voided_at')->first();
        if ($dispatch) {
            $this->fuel->void($dispatch, 'Anulación del furgón '.$container->number.': '.$reason, $user);
        }
    }

    /**
     * Pone en los datos del furgón el gasoil de sus tickets de gasoil (suma de los galones de los tickets vigentes) y, si el furgón
     * no tiene vehículo, el de la ficha del ticket. Solo mientras el furgón sigue abierto o en proceso.
     *
     * @return array{old:float,new:float}|null  null si no cambió nada
     */
    public function syncFromTickets(Container $container, ?User $user = null): ?array
    {
        if (! $container->isEditable()) {
            return null;
        }
        $total = $this->ticketGallons($container);
        if ($total <= 0) {
            return null;
        }
        $old = (float) $container->fuel_gallons;
        $changes = [];
        if (abs($old - $total) > 0.004) {
            $changes['fuel_gallons'] = $total;
        }
        if (! $container->vehicle_id) {
            $ficha = DB::table('ticket_items')->join('tickets', 'tickets.id', '=', 'ticket_items.ticket_id')
                ->where('tickets.container_id', $container->id)->where('tickets.type', 'fuel')->where('tickets.status', '!=', 'voided')
                ->orderBy('ticket_items.id')->value('ticket_items.ficha');
            if ($ficha && ($vehicle = Vehicle::where('ficha', $ficha)->first())) {
                $changes['vehicle_id'] = $vehicle->id;
            }
        }
        if (! $changes) {
            return null;
        }
        $container->forceFill($changes)->save();
        Audit::log('container.fuel_from_ticket', "Furgón {$container->number}: gasoil {$old} → {$total} gal (según sus tickets de gasoil)", $container,
            ['gasoil' => $old], ['gasoil' => $total] + (isset($changes['vehicle_id']) ? ['vehiculo_id' => $changes['vehicle_id']] : []));

        return ['old' => $old, 'new' => $total];
    }

    /** Al borrar un ticket de gasoil: si el gasoil del furgón venía de sus tickets, se recalcula con los que quedan. */
    public function onTicketRemoved(Container $container, float $totalBefore): void
    {
        if (! $container->isEditable() || abs((float) $container->fuel_gallons - $totalBefore) > 0.004) {
            return;
        }
        $after = $this->ticketGallons($container);
        $container->forceFill(['fuel_gallons' => $after])->save();
        Audit::log('container.fuel_from_ticket', "Furgón {$container->number}: gasoil {$totalBefore} → {$after} gal (se anuló un ticket de gasoil)", $container, ['gasoil' => $totalBefore], ['gasoil' => $after]);
    }

    /** Suma de los galones de los tickets de gasoil vigentes del furgón. */
    public function ticketGallons(Container $container): float
    {
        return round((float) DB::table('ticket_items')->join('tickets', 'tickets.id', '=', 'ticket_items.ticket_id')
            ->where('tickets.container_id', $container->id)->where('tickets.type', 'fuel')->where('tickets.status', '!=', 'voided')->sum('ticket_items.gallons'), 2);
    }

    /**
     * Despacho de Combustible hecho CON este furgón: sus galones pasan a los datos del furgón (si lo despachado supera lo que tenía) y,
     * si el furgón no tenía vehículo, se le pone el del despacho. Así el cierre solo descuenta lo que falte.
     */
    public function afterDispatch(Container $container, FuelDispatch $dispatch, ?User $user = null): void
    {
        $total = $this->dispatched($container);
        $changes = [];
        if ($total > (float) $container->fuel_gallons + 0.004) {
            $changes['fuel_gallons'] = $total;
        }
        if (! $container->vehicle_id && $dispatch->vehicle_id) {
            $changes['vehicle_id'] = $dispatch->vehicle_id;
        }
        if ($changes) {
            $old = (float) $container->fuel_gallons;
            $container->forceFill($changes)->save();
            Audit::log('container.fuel_from_dispatch', "Furgón {$container->number}: gasoil {$old} → ".($changes['fuel_gallons'] ?? $old).' gal (despacho de combustible)', $container,
                ['gasoil' => $old], ['gasoil' => $changes['fuel_gallons'] ?? $old] + (isset($changes['vehicle_id']) ? ['vehiculo_id' => $changes['vehicle_id']] : []));
        }
    }
}
