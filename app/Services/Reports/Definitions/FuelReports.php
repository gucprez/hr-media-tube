<?php

namespace App\Services\Reports\Definitions;

use App\Models\FuelDispatch;
use App\Models\FuelLedger;
use App\Models\FuelReceipt;
use App\Models\Vehicle;
use App\Services\Reports\Period;
use App\Services\Reports\ReportResult;
use App\Services\Reports\ReportSet;

class FuelReports extends ReportSet
{
    public function section(): string
    {
        return 'fuel';
    }

    public function definitions(): array
    {
        return [
            $this->def('fuel-receipts', 'Recepciones de gasoil', 'Camiones de gasoil recibidos en el tanque, con lectura del medidor y reserva.', fn (Period $p, array $f) => $this->receipts($p)),
            $this->def('fuel-dispatches', 'Despachos de gasoil', 'Despachos a vehículos y furgones con lecturas, kilometraje y reserva.', fn (Period $p, array $f) => $this->dispatches($p, $f), ['vehicle']),
            $this->def('fuel-consumption', 'Consumo de gasoil por vehículo', 'Galones por vehículo en el período, kilometraje y rendimiento.', fn (Period $p, array $f) => static::consumptionByVehicle($p, $f['vehicle'] ?? null), ['vehicle']),
            $this->def('fuel-reserve', 'Reserva histórica de gasoil', 'Cada movimiento de la reserva del tanque con saldo antes y después (libro de gasoil).', fn (Period $p, array $f) => $this->reserve($p)),
            $this->def('fuel-efficiency', 'Rendimiento km / galón', 'Kilómetros recorridos entre cargas consecutivas dividido por los galones de la carga.', fn (Period $p, array $f) => $this->efficiency($p, $f['vehicle'] ?? null), ['vehicle']),
        ];
    }

    protected function receipts(Period $p): ReportResult
    {
        $rows = FuelReceipt::with(['tank', 'user'])->whereBetween('received_at', [$p->from->toDateString(), $p->to->toDateString()])->orderBy('received_at')->orderBy('id')->get()->map(fn ($r) => [
            'date' => $r->received_at, 'number' => $r->receipt_number, 'supplier' => $r->supplier, 'plate' => $r->truck_plate,
            'start' => $r->meter_start, 'end' => $r->meter_end, 'gallons' => $r->voided_at ? 0 : $r->gallons_received, 'before' => $r->reserve_before, 'after' => $r->reserve_after,
            'user' => $r->user?->name, 'state' => $r->voided_at ? 'Anulada' : null, '_tone' => $r->voided_at ? 'red' : null,
        ]);

        return ReportResult::make([
            ['date', 'Fecha', 'date'], ['number', 'Recibo'], ['supplier', 'Suplidor'], ['plate', 'Placa del camión'], ['start', 'Medidor inicial', 'dec1'], ['end', 'Medidor final', 'dec1'],
            ['gallons', 'Galones', 'dec1'], ['before', 'Reserva antes', 'dec1'], ['after', 'Reserva después', 'dec1'], ['user', 'Registró'], ['state', 'Estado'],
        ], $rows, ['gallons'])->note('Los galones recibidos = medidor final − medidor inicial. Las recepciones anuladas no suman.');
    }

    protected function dispatches(Period $p, array $f): ReportResult
    {
        $rows = FuelDispatch::with(['vehicle', 'container', 'user'])->whereBetween('dispatched_at', [$p->from->toDateString(), $p->to->toDateString()])
            ->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle']))
            ->orderBy('dispatched_at')->orderBy('id')->get()->map(fn ($d) => [
                'date' => $d->dispatched_at, 'vehicle' => $d->vehicle?->ficha ?? ($d->container ? 'Furgón #'.$d->container->number : '—'), 'plate' => $d->vehicle?->plate,
                'start' => $d->reading_start, 'end' => $d->reading_end, 'gallons' => $d->voided_at ? 0 : $d->gallons, 'odometer' => $d->odometer, 'after' => $d->reserve_after,
                'user' => $d->user?->name, 'state' => $d->voided_at ? 'Anulado' : null, '_tone' => $d->voided_at ? 'red' : null,
            ]);

        return ReportResult::make([
            ['date', 'Fecha', 'date'], ['vehicle', 'Vehículo / furgón'], ['plate', 'Placa'], ['start', 'Lectura inicial', 'dec1'], ['end', 'Lectura final', 'dec1'],
            ['gallons', 'Galones', 'dec1'], ['odometer', 'Kilometraje', 'dec1'], ['after', 'Reserva después', 'dec1'], ['user', 'Despachó'], ['state', 'Estado'],
        ], $rows, ['gallons']);
    }

    /** Reutilizado por «Gasoil por vehículo» de la sección de vehículos. */
    public static function consumptionByVehicle(Period $p, mixed $vehicleId): ReportResult
    {
        $rows = Vehicle::query()->when($vehicleId, fn ($q) => $q->where('id', (int) $vehicleId))->orderBy('ficha')->get()->map(function (Vehicle $v) use ($p) {
            $d = FuelDispatch::where('vehicle_id', $v->id)->whereNull('voided_at')->whereBetween('dispatched_at', [$p->from->toDateString(), $p->to->toDateString()])->orderBy('dispatched_at')->orderBy('id')->get();
            if ($d->isEmpty()) {
                return null;
            }
            $odo = $d->pluck('odometer')->filter()->values();

            return [
                'ficha' => $v->ficha, 'plate' => $v->plate, 'dispatches' => $d->count(), 'gallons' => $d->sum('gallons'),
                'avg' => round($d->sum('gallons') / $d->count(), 2), 'km_first' => $odo->first(), 'km_last' => $odo->last(),
                'km_gal' => static::kmPerGallon($d),
            ];
        })->filter()->values();

        return ReportResult::make([
            ['ficha', 'Ficha'], ['plate', 'Placa'], ['dispatches', 'Despachos', 'int'], ['gallons', 'Galones', 'dec1'], ['avg', 'Promedio por carga', 'dec1'],
            ['km_first', 'Km primera carga', 'dec1'], ['km_last', 'Km última carga', 'dec1'], ['km_gal', 'Km / galón', 'dec2'],
        ], $rows, ['dispatches', 'gallons']);
    }

    protected function efficiency(Period $p, mixed $vehicleId): ReportResult
    {
        $rows = Vehicle::query()->when($vehicleId, fn ($q) => $q->where('id', (int) $vehicleId))->orderBy('ficha')->get()->map(function (Vehicle $v) use ($p) {
            $d = FuelDispatch::where('vehicle_id', $v->id)->whereNull('voided_at')->whereNotNull('odometer')->where('dispatched_at', '<=', $p->to->toDateString())->orderBy('dispatched_at')->orderBy('id')->get();
            $km = 0.0;
            $gal = 0.0;
            for ($i = 1; $i < $d->count(); $i++) {
                if ($d[$i]->dispatched_at->lt($p->from)) {
                    continue;
                }
                $delta = $d[$i]->odometer - $d[$i - 1]->odometer;
                if ($delta > 0 && $d[$i]->gallons > 0) {
                    $km += $delta;
                    $gal += $d[$i]->gallons;
                }
            }

            return $gal > 0 ? ['ficha' => $v->ficha, 'plate' => $v->plate, 'km' => $km, 'gallons' => $gal, 'km_gal' => round($km / $gal, 2)] : null;
        })->filter()->values();

        return ReportResult::make([['ficha', 'Ficha'], ['plate', 'Placa'], ['km', 'Km recorridos', 'dec1'], ['gallons', 'Galones', 'dec1'], ['km_gal', 'Km / galón', 'dec2']], $rows, ['km', 'gallons'])
            ->note('Solo cuenta cargas consecutivas con kilometraje registrado; la carga de referencia puede ser anterior al período.');
    }

    /** @param  \Illuminate\Support\Collection<int,FuelDispatch>  $d */
    protected static function kmPerGallon($d): ?float
    {
        $km = 0.0;
        $gal = 0.0;
        $with = $d->filter(fn ($x) => $x->odometer)->values();
        for ($i = 1; $i < $with->count(); $i++) {
            $delta = $with[$i]->odometer - $with[$i - 1]->odometer;
            if ($delta > 0 && $with[$i]->gallons > 0) {
                $km += $delta;
                $gal += $with[$i]->gallons;
            }
        }

        return $gal > 0 ? round($km / $gal, 2) : null;
    }

    protected function reserve(Period $p): ReportResult
    {
        $rows = FuelLedger::with(['user', 'vehicle'])->whereBetween('occurred_at', [$p->from, $p->to])->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($l) => [
            'at' => $l->occurred_at, 'type' => ['receipt' => 'Recepción', 'dispatch' => 'Despacho', 'adjustment' => 'Ajuste', 'void' => 'Anulación'][$l->type] ?? $l->type,
            'delta' => $l->delta_gallons, 'before' => $l->reserve_before, 'after' => $l->reserve_after, 'vehicle' => $l->vehicle?->ficha, 'reason' => $l->reason, 'user' => $l->user?->name,
        ]);
        $r = ReportResult::make([
            ['at', 'Fecha', 'datetime'], ['type', 'Tipo'], ['delta', 'Galones (+/−)', 'dec1'], ['before', 'Reserva antes', 'dec1'], ['after', 'Reserva después', 'dec1'],
            ['vehicle', 'Vehículo'], ['reason', 'Motivo'], ['user', 'Usuario'],
        ], $rows, ['delta']);
        if ($rows->isNotEmpty()) {
            $r->summary(['Reserva inicial' => number_format($rows->first()['before'], 1).' gal', 'Reserva final' => number_format($rows->last()['after'], 1).' gal']);
        }

        return $r;
    }
}
