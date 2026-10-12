<?php

namespace App\Services\Fleet;

use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use App\Services\Audit;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;

/**
 * Mantenimiento / reparaciones. Las piezas y materiales usados SALEN del inventario con un movimiento de tipo
 * «mantenimiento» asociado al vehículo y a la reparación (nada de editar números).
 */
class MaintenanceService
{
    public function __construct(protected StockService $stock, protected IncidentService $incidents, protected QuantityConverter $converter) {}

    /**
     * @param  array{performed_at:mixed,type?:string,description:string,responsible?:?string,odometer?:mixed,notes?:?string,incident_id?:?int,warehouse_id?:?int,allow_negative?:bool}  $d
     * @param  array<int,array{product_id:int,boxes?:mixed,units?:mixed}>  $parts
     */
    public function record(Vehicle $vehicle, array $d, array $parts, ?User $user = null): MaintenanceRecord
    {
        $incident = null;
        if (! empty($d['incident_id'])) {
            $incident = VehicleIncident::where('vehicle_id', $vehicle->id)->find($d['incident_id']);
            if (! $incident || ! $incident->isOpen()) {
                throw new InventoryException('La avería seleccionada no existe para este vehículo o ya fue reparada.');
            }
        }
        if ($parts && empty($d['warehouse_id'])) {
            throw new InventoryException('Seleccione el almacén de donde salen las piezas.');
        }

        return DB::transaction(function () use ($vehicle, $d, $parts, $user, $incident) {
            $record = MaintenanceRecord::create([
                'vehicle_id' => $vehicle->id, 'incident_id' => $incident?->id, 'type' => $d['type'] ?? ($incident ? 'corrective' : 'preventive'), 'user_id' => $user?->id,
                'performed_at' => $d['performed_at'], 'odometer' => $d['odometer'] ?? null, 'description' => $d['description'],
                'responsible' => $d['responsible'] ?? null, 'notes' => $d['notes'] ?? null,
            ]);

            if ($parts) {
                $movement = $this->stock->maintenance([
                    'warehouse_id' => $d['warehouse_id'], 'vehicle_id' => $vehicle->id, 'destination' => 'Mantenimiento '.$vehicle->ficha, 'destination_type' => 'vehicle',
                    'reason' => $incident ? 'Reparación: '.$incident->title : 'Mantenimiento: '.mb_substr($d['description'], 0, 80), 'reference' => 'Mantenimiento #'.$record->id,
                    'source_type' => $record->getMorphClass(), 'source_id' => $record->id, 'allow_negative' => ! empty($d['allow_negative']),
                    'occurred_at' => $this->when($d['performed_at']),
                ], $parts, $user);
                $record->forceFill(['movement_id' => $movement->id])->save();
                foreach ($movement->items as $line) {
                    MaintenancePart::create([
                        'maintenance_record_id' => $record->id, 'product_id' => $line->product_id, 'product_sku' => $line->product_sku, 'product_name' => $line->product_name,
                        'movement_id' => $movement->id, 'quantity_base' => abs($line->delta_base),
                    ]);
                }
            }

            if (! empty($d['odometer']) && $d['odometer'] > (float) $vehicle->odometer) {
                $vehicle->forceFill(['odometer' => $d['odometer']])->save();
            }
            if ($incident) {
                $this->incidents->changeStatus($incident, 'repaired', 'Reparación registrada: '.mb_substr($d['description'], 0, 120), $user);
            }
            $this->incidents->syncVehicleStatus($vehicle);

            Audit::log('maintenance.created', sprintf('Mantenimiento en %s: %s%s', $vehicle->ficha, mb_substr($d['description'], 0, 100),
                $record->parts()->count() ? ' (piezas: '.$record->parts()->get()->map(fn ($p) => $p->product_name.' '.rtrim(rtrim(number_format($p->quantity_base, 3), '0'), '.'))->implode(', ').')' : ''),
                $record, null, ['vehiculo' => $vehicle->ficha, 'avería' => $incident?->title, 'movimiento' => $record->movement_id]);

            return $record;
        }, 3);
    }

    /** Anula un mantenimiento: devuelve las piezas al inventario (movimiento inverso) y reabre la avería si correspondía. */
    public function void(MaintenanceRecord $record, string $reason, ?User $user = null, bool $allowNegative = false): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new InventoryException('Indique el motivo de la anulación (mín. 5 caracteres).');
        }
        DB::transaction(function () use ($record, $reason, $user, $allowNegative) {
            $record = MaintenanceRecord::lockForUpdate()->findOrFail($record->id);
            if ($record->voided_at) {
                throw new InventoryException('El mantenimiento ya está anulado.');
            }
            if ($record->movement_id) {
                $this->stock->reverse($record->movement, 'Anulación de mantenimiento #'.$record->id.': '.$reason, $user, $allowNegative);
            }
            $record->forceFill(['voided_at' => now(), 'voided_by' => $user?->id, 'void_reason' => $reason])->save();

            $incident = $record->incident;
            if ($incident && $incident->status === 'repaired' && ! $incident->maintenance()->whereNull('voided_at')->exists()) {
                $this->incidents->changeStatus($incident, 'in_repair', 'Se anuló el mantenimiento #'.$record->id.': '.$reason, $user);
            }
            $this->incidents->syncVehicleStatus($record->vehicle);
            Audit::log('maintenance.voided', "Mantenimiento #{$record->id} anulado: {$reason}", $record, ['anulado' => false], ['anulado' => true, 'motivo' => $reason]);
        }, 3);
    }

    protected function when($date)
    {
        $d = $date instanceof \Carbon\CarbonInterface ? $date->copy() : now()->parse($date);

        return $d->isToday() ? now() : $d->setTime(12, 0);
    }
}
