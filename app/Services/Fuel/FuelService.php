<?php

namespace App\Services\Fuel;

use App\Models\FuelDispatch;
use App\Models\FuelLedger;
use App\Models\FuelReceipt;
use App\Models\FuelTank;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Audit;
use App\Services\DocumentNumbering;
use App\Support\CurrentCompany;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Motor de combustible. Única vía para cambiar la reserva de gasoil:
 *   recepción  : reserva = antes + (metro final − metro inicial)
 *   despacho   : reserva = antes − (lectura final − lectura inicial)
 *   anulación  : reverso con motivo
 *   ajuste     : medición física con motivo
 * Todo en transacción con el tanque bloqueado (FOR UPDATE); cada cambio deja una línea en `fuel_ledger` (antes/después).
 */
class FuelService
{
    public function __construct(protected DocumentNumbering $numbering) {}

    /** Tanque principal de la empresa (se crea la primera vez). */
    public function tank(): FuelTank
    {
        $tank = FuelTank::where('is_active', true)->orderBy('id')->first();

        return $tank ?? FuelTank::create(['name' => 'Tanque principal', 'current_gallons' => 0, 'is_active' => true]);
    }

    /**
     * @param  array{received_at:mixed,meter_start:float|int|string,meter_end:float|int|string,supplier?:?string,truck_plate?:?string,notes?:?string,receipt_number?:?string}  $d
     */
    public function receive(array $d, ?User $user = null): FuelReceipt
    {
        $start = $this->num($d['meter_start'] ?? null);
        $end = $this->num($d['meter_end'] ?? null);
        if ($end <= $start) {
            throw new FuelException('El metro final debe ser mayor que el metro inicial.');
        }
        $gallons = round($end - $start, 2);

        return DB::transaction(function () use ($d, $user, $start, $end, $gallons) {
            $tank = $this->lockedTank();
            $before = (float) $tank->current_gallons;
            $after = round($before + $gallons, 2);
            if ($tank->capacity_gallons && $after > (float) $tank->capacity_gallons + 0.005) {
                throw new FuelException(sprintf(
                    'La recepción (%s gal) excede la capacidad del tanque (%s gal) con la reserva actual de %s gal.',
                    $this->g($gallons), $this->g($tank->capacity_gallons), $this->g($before),
                ));
            }

            $companyId = app(CurrentCompany::class)->id();
            $number = trim((string) ($d['receipt_number'] ?? '')) ?: $this->numbering->next('fuel_receipt', $companyId);
            if (FuelReceipt::where('receipt_number', $number)->exists()) {
                throw new FuelException("El número de recepción {$number} ya existe.");
            }

            $receipt = FuelReceipt::create([
                'fuel_tank_id' => $tank->id, 'receipt_number' => $number, 'received_at' => $d['received_at'], 'meter_start' => $start, 'meter_end' => $end,
                'gallons_received' => $gallons, 'reserve_before' => $before, 'reserve_after' => $after, 'supplier' => $d['supplier'] ?? null,
                'truck_plate' => $d['truck_plate'] ?? null, 'user_id' => $user?->id, 'notes' => $d['notes'] ?? null,
            ]);
            $this->write($tank, $gallons, 'receipt', $receipt, $user, null, null, $d['received_at']);

            Audit::log('fuel.receipt', sprintf('Recepción %s: +%s gal (reserva %s → %s)', $number, $this->g($gallons), $this->g($before), $this->g($after)), $receipt, null, [
                'metro_inicial' => $start, 'metro_final' => $end, 'galones' => $gallons, 'reserva_antes' => $before, 'reserva_despues' => $after, 'suplidor' => $d['supplier'] ?? null,
            ]);

            return $receipt;
        }, 3);
    }

    /**
     * Despacho a un vehículo. Con lecturas: galones = final − inicial. Sin lecturas (origen furgón): se indican los galones.
     *
     * @param  array{dispatched_at:mixed,vehicle_id?:?int,container_id?:?int,reading_start?:mixed,reading_end?:mixed,gallons?:mixed,odometer?:mixed,notes?:?string,origin?:string}  $d
     */
    public function dispatch(array $d, ?User $user = null): FuelDispatch
    {
        $hasReadings = isset($d['reading_start'], $d['reading_end']) && $d['reading_start'] !== '' && $d['reading_end'] !== '';
        if ($hasReadings) {
            $start = $this->num($d['reading_start']);
            $end = $this->num($d['reading_end']);
            if ($end <= $start) {
                throw new FuelException('La lectura final debe ser mayor que la lectura inicial.');
            }
            $gallons = round($end - $start, 2);
        } else {
            $start = $end = null;
            $gallons = round($this->num($d['gallons'] ?? null), 2);
            if ($gallons <= 0) {
                throw new FuelException('Indique los galones a despachar.');
            }
        }

        return DB::transaction(function () use ($d, $user, $start, $end, $gallons) {
            $tank = $this->lockedTank();
            $before = (float) $tank->current_gallons;
            $after = round($before - $gallons, 2);
            if ($after < 0) {
                throw new FuelException(sprintf('Reserva de gasoil insuficiente: hay %s gal y se solicitan %s gal.', $this->g($before), $this->g($gallons)));
            }

            $vehicle = null;
            $odometer = isset($d['odometer']) && $d['odometer'] !== '' ? $this->num($d['odometer']) : null;
            if (! empty($d['vehicle_id'])) {
                $vehicle = Vehicle::lockForUpdate()->find($d['vehicle_id']);
                if (! $vehicle) {
                    throw new FuelException('El vehículo no existe o no pertenece a su empresa.');
                }
                if ($odometer !== null && $odometer < (float) $vehicle->odometer) {
                    throw new FuelException(sprintf('El kilometraje (%s) no puede ser menor al último registrado de %s (%s).', $this->g($odometer, 1), $vehicle->ficha, $this->g($vehicle->odometer, 1)));
                }
            }

            $dispatch = FuelDispatch::create([
                'fuel_tank_id' => $tank->id, 'vehicle_id' => $vehicle?->id, 'container_id' => $d['container_id'] ?? null, 'dispatched_at' => $d['dispatched_at'],
                'reading_start' => $start, 'reading_end' => $end, 'gallons' => $gallons, 'odometer' => $odometer, 'reserve_before' => $before, 'reserve_after' => $after,
                'user_id' => $user?->id, 'notes' => $d['notes'] ?? null, 'origin' => $d['origin'] ?? 'manual',
            ]);
            $this->write($tank, -$gallons, 'dispatch', $dispatch, $user, $vehicle?->id, null, $d['dispatched_at']);

            if ($vehicle && $odometer !== null && $odometer > (float) $vehicle->odometer) {
                $vehicle->forceFill(['odometer' => $odometer])->save();
            }

            Audit::log('fuel.dispatch', sprintf('Despacho a %s: −%s gal (reserva %s → %s)', $vehicle?->ficha ?? 'sin vehículo', $this->g($gallons), $this->g($before), $this->g($after)), $dispatch, null, [
                'vehiculo' => $vehicle?->ficha, 'lectura_inicial' => $start, 'lectura_final' => $end, 'galones' => $gallons, 'kilometraje' => $odometer, 'reserva_antes' => $before, 'reserva_despues' => $after,
            ]);

            return $dispatch;
        }, 3);
    }

    /** Anula una recepción o un despacho (reverso con motivo; nada se borra). */
    public function void(FuelReceipt|FuelDispatch $record, string $reason, ?User $user = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new FuelException('Indique el motivo de la anulación (mín. 5 caracteres).');
        }

        DB::transaction(function () use ($record, $reason, $user) {
            $record = $record::lockForUpdate()->findOrFail($record->id);
            if ($record->voided_at) {
                throw new FuelException('El registro ya está anulado.');
            }
            $tank = $this->lockedTank($record->fuel_tank_id);
            $isReceipt = $record instanceof FuelReceipt;
            $gallons = (float) ($isReceipt ? $record->gallons_received : $record->gallons);
            $delta = $isReceipt ? -$gallons : $gallons;
            $before = (float) $tank->current_gallons;
            $after = round($before + $delta, 2);

            if ($after < -0.0001) {
                throw new FuelException(sprintf('No se puede anular: la reserva quedaría en negativo (hay %s gal y se retirarían %s gal; el gasoil ya fue despachado).', $this->g($before), $this->g($gallons)));
            }
            if ($tank->capacity_gallons && $after > (float) $tank->capacity_gallons + 0.005) {
                throw new FuelException('No se puede anular: la reserva superaría la capacidad del tanque.');
            }

            $record->forceFill(['voided_at' => now(), 'voided_by' => $user?->id, 'void_reason' => $reason])->save();
            $this->write($tank, $delta, $isReceipt ? 'void_receipt' : 'void_dispatch', $record, $user, $isReceipt ? null : $record->vehicle_id, $reason);

            Audit::log($isReceipt ? 'fuel.receipt_void' : 'fuel.dispatch_void', sprintf('Anulación de %s: %s gal (reserva %s → %s). Motivo: %s',
                $isReceipt ? 'recepción '.$record->receipt_number : 'despacho', $this->g($gallons), $this->g($before), $this->g($after), $reason), $record,
                ['anulado' => false], ['anulado' => true, 'motivo' => $reason, 'reserva_despues' => $after]);
        }, 3);
    }

    // ---- editar en el mismo registro ------------------------------------------

    /**
     * EDITAR una recepción en su MISMO registro (conserva su número): corrige metros, fecha, suplidor, camión y notas. Los galones se
     * recalculan, la reserva de TODO el historial se vuelve a calcular y se rechaza si en algún punto quedaría negativa o por encima
     * de la capacidad del tanque. La auditoría guarda el antes y el después con el motivo.
     *
     * @param  array{received_at:mixed,meter_start:mixed,meter_end:mixed,supplier?:?string,truck_plate?:?string,notes?:?string}  $d
     */
    public function amendReceipt(FuelReceipt $receipt, array $d, string $why, ?User $user = null): FuelReceipt
    {
        $why = $this->editReason($why);
        $start = $this->num($d['meter_start'] ?? null);
        $end = $this->num($d['meter_end'] ?? null);
        if ($end <= $start) {
            throw new FuelException('El metro final debe ser mayor que el metro inicial.');
        }
        $gallons = round($end - $start, 2);

        return DB::transaction(function () use ($receipt, $d, $why, $start, $end, $gallons) {
            $record = FuelReceipt::lockForUpdate()->findOrFail($receipt->id);
            if ($record->voided_at) {
                throw new FuelException('La recepción está anulada y no se puede editar.');
            }
            $tank = $this->lockedTank($record->fuel_tank_id);
            $before = ['fecha' => $record->received_at->toDateString(), 'metro_inicial' => (float) $record->meter_start, 'metro_final' => (float) $record->meter_end, 'galones' => (float) $record->gallons_received,
                'suplidor' => $record->supplier, 'camion' => $record->truck_plate, 'notas' => $record->notes];

            $this->reflow($tank, $record, $gallons, $d['received_at']);
            $record->forceFill([
                'received_at' => $d['received_at'], 'meter_start' => $start, 'meter_end' => $end, 'gallons_received' => $gallons,
                'supplier' => $d['supplier'] ?? null, 'truck_plate' => $d['truck_plate'] ?? null, 'notes' => $d['notes'] ?? null,
            ])->save();

            Audit::log('fuel.receipt_edit', sprintf('Edición de recepción %s (conserva su número): %s gal → %s gal. Motivo: %s', $record->receipt_number, $this->g($before['galones']), $this->g($gallons), $why), $record,
                $before, ['fecha' => $record->received_at->toDateString(), 'metro_inicial' => $start, 'metro_final' => $end, 'galones' => $gallons, 'suplidor' => $record->supplier, 'camion' => $record->truck_plate, 'notas' => $record->notes, 'motivo' => $why]);

            return $record->refresh();
        }, 3);
    }

    /**
     * EDITAR un despacho hecho a mano en su MISMO registro: ficha, furgón, lecturas (galones = final − inicial), kilometraje, fecha y notas.
     * El kilometraje del vehículo solo sube, nunca baja. Los despachos que nacen del cierre de un furgón se corrigen desde el furgón.
     *
     * @param  array{dispatched_at:mixed,vehicle_id:mixed,container_id?:mixed,reading_start:mixed,reading_end:mixed,odometer?:mixed,notes?:?string}  $d
     */
    public function amendDispatch(FuelDispatch $dispatch, array $d, string $why, ?User $user = null): FuelDispatch
    {
        $why = $this->editReason($why);
        $start = $this->num($d['reading_start'] ?? null);
        $end = $this->num($d['reading_end'] ?? null);
        if ($end <= $start) {
            throw new FuelException('La lectura final debe ser mayor que la lectura inicial.');
        }
        $gallons = round($end - $start, 2);
        $odometer = isset($d['odometer']) && $d['odometer'] !== '' ? $this->num($d['odometer']) : null;

        return DB::transaction(function () use ($dispatch, $d, $why, $start, $end, $gallons, $odometer) {
            $record = FuelDispatch::lockForUpdate()->findOrFail($dispatch->id);
            if ($record->voided_at) {
                throw new FuelException('El despacho está anulado y no se puede editar.');
            }
            if ($record->origin === 'container') {
                throw new FuelException('Este despacho nació del cierre de un furgón: se corrige desde el furgón.');
            }
            $vehicle = Vehicle::lockForUpdate()->find($d['vehicle_id'] ?? 0);
            if (! $vehicle) {
                throw new FuelException('El vehículo no existe o no pertenece a su empresa.');
            }
            $tank = $this->lockedTank($record->fuel_tank_id);
            $before = ['fecha' => $record->dispatched_at->toDateString(), 'ficha' => $record->vehicle?->ficha, 'furgon_id' => $record->container_id, 'lectura_inicial' => $record->reading_start,
                'lectura_final' => $record->reading_end, 'galones' => (float) $record->gallons, 'kilometraje' => $record->odometer, 'notas' => $record->notes];

            $this->reflow($tank, $record, -$gallons, $d['dispatched_at'], $vehicle->id);
            $record->forceFill([
                'dispatched_at' => $d['dispatched_at'], 'vehicle_id' => $vehicle->id, 'container_id' => ! empty($d['container_id']) ? $d['container_id'] : null,
                'reading_start' => $start, 'reading_end' => $end, 'gallons' => $gallons, 'odometer' => $odometer, 'notes' => $d['notes'] ?? null,
            ])->save();
            if ($odometer !== null && $odometer > (float) $vehicle->odometer) {
                $vehicle->forceFill(['odometer' => $odometer])->save();
            }

            Audit::log('fuel.dispatch_edit', sprintf('Edición de despacho a %s: %s gal → %s gal. Motivo: %s', $vehicle->ficha, $this->g($before['galones']), $this->g($gallons), $why), $record,
                $before, ['fecha' => $record->dispatched_at->toDateString(), 'ficha' => $vehicle->ficha, 'furgon_id' => $record->container_id, 'lectura_inicial' => $start, 'lectura_final' => $end, 'galones' => $gallons, 'kilometraje' => $odometer, 'notas' => $record->notes, 'motivo' => $why]);

            return $record->refresh();
        }, 3);
    }

    /**
     * Cambia el efecto de UN registro (recepción/despacho) sobre la reserva y recalcula «antes/después» de todo lo que vino después.
     * Rechaza si en algún punto desde ese registro la reserva quedaría negativa o superaría la capacidad. Escribe con UPDATE directo.
     */
    protected function reflow(FuelTank $tank, FuelReceipt|FuelDispatch $record, float $newDelta, $occurredAt, ?int $vehicleId = null): void
    {
        $type = $record instanceof FuelReceipt ? 'receipt' : 'dispatch';
        $line = FuelLedger::where('fuel_tank_id', $tank->id)->where('type', $type)->where('source_type', $record->getMorphClass())->where('source_id', $record->id)->first();
        if (! $line) {
            throw new FuelException('No se encontró el renglón del libro de este registro.');
        }

        $capacity = $tank->capacity_gallons ? (float) $tank->capacity_gallons : null;
        $run = 0.0;
        $plan = [];
        $reached = false;
        foreach (DB::table('fuel_ledger')->where('fuel_tank_id', $tank->id)->orderBy('id')->get(['id', 'type', 'delta_gallons', 'source_id', 'reserve_before', 'reserve_after']) as $r) {
            $reached = $reached || (int) $r->id === (int) $line->id;
            $delta = (int) $r->id === (int) $line->id ? round($newDelta, 2) : (float) $r->delta_gallons;
            $b = round($run, 2);
            $run = round($run + $delta, 2);
            if ($reached && $run < -0.0001) {
                throw new FuelException(sprintf('No se puede editar: la reserva quedaría en negativo en el historial (%s gal; el gasoil ya fue despachado).', $this->g($run)));
            }
            if ($reached && $capacity !== null && $run > $capacity + 0.005) {
                throw new FuelException(sprintf('No se puede editar: la reserva superaría la capacidad del tanque (%s gal) en el historial.', $this->g($capacity)));
            }
            $plan[] = [$r, $delta, $b, $run];
        }

        foreach ($plan as [$r, $delta, $b, $a]) {
            $isLine = (int) $r->id === (int) $line->id;
            if (! $isLine && abs($b - (float) $r->reserve_before) < 0.005 && abs($a - (float) $r->reserve_after) < 0.005) {
                continue;
            }
            $set = ['delta_gallons' => $delta, 'reserve_before' => $b, 'reserve_after' => $a];
            if ($isLine) {
                $set['occurred_at'] = $this->when($occurredAt);
                if ($vehicleId !== null) {
                    $set['vehicle_id'] = $vehicleId;
                }
            }
            DB::table('fuel_ledger')->where('id', $r->id)->update($set);
            $table = $r->type === 'receipt' ? 'fuel_receipts' : ($r->type === 'dispatch' ? 'fuel_dispatches' : null);
            if ($table && $r->source_id) {
                DB::table($table)->where('id', $r->source_id)->update(['reserve_before' => $b, 'reserve_after' => $a]);
            }
        }
        DB::table('fuel_tanks')->where('id', $tank->id)->update(['current_gallons' => $run, 'updated_at' => now()]);
        $tank->setRawAttributes(array_merge($tank->getAttributes(), ['current_gallons' => $run]), true);
    }

    protected function editReason(string $why): string
    {
        $why = trim($why);
        if (mb_strlen($why) < 5) {
            throw new FuelException('Indique el motivo de la edición (mín. 5 caracteres).');
        }

        return $why;
    }

    /** Ajuste de reserva por medición física (también sirve para fijar la reserva inicial). */
    public function adjust(float $physicalGallons, string $reason, ?User $user = null, $occurredAt = null): FuelLedger
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new FuelException('El ajuste exige un motivo (mín. 5 caracteres).');
        }
        if ($physicalGallons < 0) {
            throw new FuelException('La medición física no puede ser negativa.');
        }

        return DB::transaction(function () use ($physicalGallons, $reason, $user, $occurredAt) {
            $tank = $this->lockedTank();
            $before = (float) $tank->current_gallons;
            $physical = round($physicalGallons, 2);
            if ($tank->capacity_gallons && $physical > (float) $tank->capacity_gallons + 0.005) {
                throw new FuelException('La medición supera la capacidad registrada del tanque. Corrija primero la capacidad.');
            }
            $delta = round($physical - $before, 2);
            if ($delta == 0.0) {
                throw new FuelException('La medición coincide con la reserva del sistema: no hay nada que ajustar.');
            }
            $line = $this->write($tank, $delta, 'adjustment', $tank, $user, null, $reason, $occurredAt);
            Audit::log('fuel.adjustment', sprintf('Ajuste de reserva: %s → %s gal (%s%s). Motivo: %s', $this->g($before), $this->g($physical), $delta > 0 ? '+' : '', $this->g($delta), $reason), $tank,
                ['reserva' => $before], ['reserva' => $physical, 'motivo' => $reason]);

            return $line;
        }, 3);
    }

    /** Reserva en cualquier fecha, reconstruida desde el libro. */
    public function reserveAt(CarbonInterface $at, ?int $tankId = null): float
    {
        return round((float) FuelLedger::when($tankId, fn ($q) => $q->where('fuel_tank_id', $tankId))->where('occurred_at', '<=', $at)->sum('delta_gallons'), 2);
    }

    /** Verifica que la reserva de cada tanque coincida con su libro. @return array<int,array{tank:string,reserve:float,ledger:float}> */
    public function discrepancies(): array
    {
        $out = [];
        foreach (FuelTank::withTrashed()->get() as $tank) {
            $ledger = round((float) FuelLedger::where('fuel_tank_id', $tank->id)->sum('delta_gallons'), 2);
            if (abs($ledger - (float) $tank->current_gallons) > 0.005) {
                $out[] = ['tank' => $tank->name, 'reserve' => (float) $tank->current_gallons, 'ledger' => $ledger];
            }
        }

        return $out;
    }

    // ---- internos --------------------------------------------------------------

    protected function lockedTank(?int $id = null): FuelTank
    {
        $this->tank(); // garantiza que exista
        $tank = FuelTank::withTrashed()->when($id, fn ($q) => $q->where('id', $id), fn ($q) => $q->where('is_active', true)->orderBy('id'))->lockForUpdate()->first();
        if (! $tank) {
            throw new FuelException('No hay un tanque de combustible configurado.');
        }

        return $tank;
    }

    /** Escribe la línea del libro y actualiza la reserva con un UPDATE directo (sin eventos de Eloquent). */
    protected function write(FuelTank $tank, float $delta, string $type, $source, ?User $user, ?int $vehicleId = null, ?string $reason = null, $occurredAt = null): FuelLedger
    {
        $before = (float) $tank->current_gallons;
        $after = round($before + $delta, 2);

        $updated = DB::table('fuel_tanks')->where('id', $tank->id)->update(['current_gallons' => $after, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new FuelException('No se pudo actualizar la reserva. Intente de nuevo.');
        }
        $tank->setRawAttributes(array_merge($tank->getAttributes(), ['current_gallons' => $after]), true);

        return FuelLedger::create([
            'fuel_tank_id' => $tank->id, 'type' => $type, 'occurred_at' => $this->when($occurredAt), 'delta_gallons' => $delta, 'reserve_before' => $before, 'reserve_after' => $after,
            'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'vehicle_id' => $vehicleId, 'user_id' => $user?->id, 'reason' => $reason,
        ]);
    }

    /** Fecha del evento: el día indicado con la hora actual (o ahora). */
    protected function when($date): CarbonInterface
    {
        if (! $date) {
            return now();
        }
        $d = $date instanceof CarbonInterface ? $date->copy() : now()->parse($date);

        return $d->isToday() ? now() : $d->setTime(12, 0);
    }

    protected function num(mixed $v): float
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            throw new FuelException('Valor numérico no válido.');
        }

        return (float) $v;
    }

    protected function g(float|int|string $v, int $d = 1): string
    {
        return number_format((float) $v, $d, '.', ',');
    }
}
