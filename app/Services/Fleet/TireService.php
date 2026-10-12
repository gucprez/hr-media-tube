<?php

namespace App\Services\Fleet;

use App\Models\Product;
use App\Models\TireReturn;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Audit;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gomas nuevas y usadas. Entregar una goma nueva descuenta el inventario (movimiento de mantenimiento ligado al vehículo) y crea,
 * por cada goma, un registro de seguimiento: si la usada no se recibió queda «DEVOLUCIÓN PENDIENTE».
 */
class TireService
{
    public function __construct(protected StockService $stock) {}

    /**
     * @param  array{vehicle_id:int,product_id:int,warehouse_id:int,quantity:int,delivered_at:mixed,odometer?:mixed,reason?:?string,used_received:bool,allow_negative?:bool}  $d
     * @param  array<int,array{condition?:?string,notes?:?string}>  $used  una fila por goma cuando se recibió la usada
     * @return Collection<int,TireReturn>
     */
    public function deliver(array $d, array $used, ?User $user = null): Collection
    {
        $qty = (int) $d['quantity'];
        if ($qty < 1 || $qty > 20) {
            throw new InventoryException('La cantidad de gomas debe estar entre 1 y 20.');
        }
        $vehicle = Vehicle::find($d['vehicle_id']) ?? throw new InventoryException('El vehículo no existe o no pertenece a su empresa.');
        $product = Product::with('unit')->find($d['product_id']) ?? throw new InventoryException('El producto no existe.');
        if ($product->returnable_kind !== 'tire') {
            throw new InventoryException("«{$product->name}» no está configurado como goma (retornable de tipo Goma).");
        }
        if (! empty($d['used_received'])) {
            for ($i = 0; $i < $qty; $i++) {
                if (! isset(TireReturn::CONDITIONS[$used[$i]['condition'] ?? ''])) {
                    throw new InventoryException('Indique el estado de cada goma usada recibida.');
                }
            }
        }

        return DB::transaction(function () use ($d, $used, $user, $qty, $vehicle, $product) {
            $movement = $this->stock->maintenance([
                'warehouse_id' => $d['warehouse_id'], 'vehicle_id' => $vehicle->id, 'destination' => 'Goma nueva — '.$vehicle->ficha, 'destination_type' => 'vehicle',
                'reason' => $d['reason'] ?? 'Entrega de goma', 'reference' => 'Goma '.$vehicle->ficha, 'allow_negative' => ! empty($d['allow_negative']),
                'occurred_at' => ($date = now()->parse($d['delivered_at']))->isToday() ? now() : $date->setTime(12, 0),
            ], [['product_id' => $product->id, 'units' => $qty]], $user);

            $rows = collect();
            for ($i = 0; $i < $qty; $i++) {
                $received = ! empty($d['used_received']);
                $rows->push(TireReturn::create([
                    'vehicle_id' => $vehicle->id, 'product_id' => $product->id, 'product_name' => $product->name, 'movement_id' => $movement->id, 'quantity' => 1,
                    'odometer' => $d['odometer'] ?? null, 'delivered_at' => $d['delivered_at'], 'delivered_by' => $user?->id, 'reason' => $d['reason'] ?? null,
                    'used_tire_received' => $received, 'status' => $received ? 'received' : 'pending',
                    'condition' => $received ? $used[$i]['condition'] : null, 'received_at' => $received ? now() : null, 'received_by' => $received ? $user?->id : null,
                    'notes' => $received ? ($used[$i]['notes'] ?? null) : null,
                ]));
            }
            if (! empty($d['odometer']) && $d['odometer'] > (float) $vehicle->odometer) {
                $vehicle->forceFill(['odometer' => $d['odometer']])->save();
            }

            Audit::log('tire.delivered', sprintf('Entregó %d %s a %s%s', $qty, $qty === 1 ? 'goma' : 'gomas', $vehicle->ficha,
                ! empty($d['used_received']) ? ' (recibió usada)' : ' (devolución pendiente)'), $movement, null,
                ['vehiculo' => $vehicle->ficha, 'cantidad' => $qty, 'kilometraje' => $d['odometer'] ?? null, 'usada_recibida' => ! empty($d['used_received'])]);

            return $rows;
        }, 3);
    }

    /** Registra la recepción de una goma usada que estaba pendiente. */
    public function receive(TireReturn $tire, string $condition, ?string $notes, ?User $user = null): TireReturn
    {
        if ($tire->status !== 'pending') {
            throw new InventoryException('Esta devolución ya fue registrada.');
        }
        if (! isset(TireReturn::CONDITIONS[$condition])) {
            throw new InventoryException('Indique el estado de la goma usada.');
        }
        $tire->forceFill(['status' => 'received', 'used_tire_received' => true, 'condition' => $condition, 'received_at' => now(), 'received_by' => $user?->id, 'notes' => $notes])->save();
        Audit::log('tire.received', 'Recibió 1 goma usada — vehículo '.$tire->vehicle->ficha.' ('.TireReturn::CONDITIONS[$condition].')', $tire, ['status' => 'pending'], ['status' => 'received', 'estado' => $condition]);

        return $tire;
    }

    /** Cancela una devolución pendiente (por ejemplo, la goma se perdió o se quedó en el camino) dejando constancia del motivo. */
    public function waive(TireReturn $tire, string $reason, ?User $user = null): TireReturn
    {
        if ($tire->status !== 'pending') {
            throw new InventoryException('Esta devolución ya no está pendiente.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new InventoryException('Indique el motivo (mín. 5 caracteres).');
        }
        $tire->forceFill(['status' => 'waived', 'waived_reason' => trim($reason), 'received_by' => $user?->id, 'received_at' => now()])->save();
        Audit::log('tire.waived', 'Devolución de goma cancelada — vehículo '.$tire->vehicle->ficha.': '.trim($reason), $tire, ['status' => 'pending'], ['status' => 'waived', 'motivo' => trim($reason)]);

        return $tire;
    }

    /** Inventario de gomas usadas recibidas, por estado. @return array<string,int> */
    public function usedInventory(): array
    {
        $counts = TireReturn::where('status', 'received')->select('condition', DB::raw('COUNT(*) as n'))->groupBy('condition')->pluck('n', 'condition')->all();

        return collect(TireReturn::CONDITIONS)->mapWithKeys(fn ($l, $k) => [$k => (int) ($counts[$k] ?? 0)])->all();
    }
}
