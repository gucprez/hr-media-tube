<?php

namespace App\Services\Fleet;

use App\Models\Container;
use App\Models\FuelDispatch;
use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\TireReturn;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use App\Models\VehicleInspection;
use Illuminate\Support\Collection;

/** Expediente del vehículo: indicadores y línea de tiempo armados desde los registros históricos (no desde campos sueltos). */
class VehicleFile
{
    public function lastDispatch(Vehicle $v): ?FuelDispatch
    {
        return FuelDispatch::where('vehicle_id', $v->id)->whereNull('voided_at')->orderByDesc('dispatched_at')->orderByDesc('id')->first();
    }

    /** @return array{gallons_30:float,gallons_total:float,km_per_gallon:?float,dispatches:int} */
    public function fuelStats(Vehicle $v): array
    {
        $base = FuelDispatch::where('vehicle_id', $v->id)->whereNull('voided_at');

        return [
            'gallons_30' => round((float) (clone $base)->whereDate('dispatched_at', '>=', now()->subDays(30))->sum('gallons'), 1),
            'gallons_total' => round((float) (clone $base)->sum('gallons'), 1),
            'km_per_gallon' => $this->efficiency($v),
            'dispatches' => (clone $base)->count(),
        ];
    }

    /** Rendimiento km/galón entre cargas consecutivas con kilometraje: (km recorridos entre cargas) / (galones de la carga posterior). */
    public function efficiency(Vehicle $v): ?float
    {
        $rows = FuelDispatch::where('vehicle_id', $v->id)->whereNull('voided_at')->whereNotNull('odometer')
            ->orderBy('dispatched_at')->orderBy('id')->get(['odometer', 'gallons']);
        $km = 0.0;
        $gal = 0.0;
        for ($i = 1; $i < $rows->count(); $i++) {
            $d = $rows[$i]->odometer - $rows[$i - 1]->odometer;
            if ($d > 0 && $rows[$i]->gallons > 0) {
                $km += $d;
                $gal += $rows[$i]->gallons;
            }
        }

        return $gal > 0 ? round($km / $gal, 2) : null;
    }

    /**
     * Línea de tiempo (más reciente primero).
     *
     * @return Collection<int, array{at:\Carbon\CarbonInterface,icon:string,title:string,detail:?string,url:?string,tone:string}>
     */
    public function timeline(Vehicle $v, int $limit = 60): Collection
    {
        $events = collect();

        foreach (FuelDispatch::with('container')->where('vehicle_id', $v->id)->orderByDesc('dispatched_at')->limit($limit)->get() as $d) {
            $events->push([
                'at' => $d->dispatched_at->copy()->setTime(12, 0), 'icon' => 'fuel', 'tone' => $d->isVoided() ? 'red' : 'blue',
                'title' => number_format($d->gallons, 1).' galones de gasoil'.($d->isVoided() ? ' (anulado)' : ''),
                'detail' => $d->odometer ? 'Kilometraje '.number_format($d->odometer, 1) : null, 'url' => null,
            ]);
        }
        foreach (Container::with('driver')->where('vehicle_id', $v->id)->where('status', '!=', 'voided')->orderByDesc('date')->limit($limit)->get() as $c) {
            $events->push([
                'at' => $c->date->copy()->setTime(9, 0), 'icon' => 'truck', 'tone' => 'slate', 'title' => 'Furgón #'.$c->number.' ('.$c->status_label.')',
                'detail' => $c->driver_display, 'url' => route('containers.show', $c),
            ]);
        }
        foreach (VehicleInspection::where('vehicle_id', $v->id)->orderByDesc('inspected_at')->limit($limit)->get() as $i) {
            $events->push([
                'at' => $i->inspected_at->copy()->setTime(10, 0), 'icon' => 'clipboard', 'tone' => $i->overall_status === 'good' ? 'green' : ($i->overall_status === 'attention' ? 'yellow' : 'red'),
                'title' => 'Inspección mensual — '.VehicleInspection::OVERALL[$i->overall_status], 'detail' => $i->odometer ? 'Kilometraje '.number_format($i->odometer, 1) : null, 'url' => route('inspections.show', $i),
            ]);
        }
        foreach (VehicleIncident::where('vehicle_id', $v->id)->orderByDesc('reported_at')->limit($limit)->get() as $inc) {
            $events->push([
                'at' => $inc->reported_at, 'icon' => 'alert', 'tone' => in_array($inc->priority, ['high', 'critical'], true) ? 'red' : 'yellow',
                'title' => $inc->title.' reportada', 'detail' => 'Prioridad '.$inc->priority_label.' · '.$inc->status_label, 'url' => route('incidents.show', $inc),
            ]);
        }
        foreach (MaintenanceRecord::with('parts')->where('vehicle_id', $v->id)->whereNull('voided_at')->orderByDesc('performed_at')->limit($limit)->get() as $m) {
            foreach ($m->parts as $part) {
                $events->push([
                    'at' => $m->performed_at->copy()->setTime(13, 0), 'icon' => 'box', 'tone' => 'blue',
                    'title' => $part->product_name.' entregado ('.rtrim(rtrim(number_format($part->quantity_base, 3), '0'), '.').')', 'detail' => null, 'url' => route('maintenance.show', $m),
                ]);
            }
            $events->push([
                'at' => $m->performed_at->copy()->setTime(14, 0), 'icon' => 'wrench', 'tone' => 'green',
                'title' => ($m->type === 'preventive' ? 'Mantenimiento preventivo' : 'Reparación completada'), 'detail' => \Illuminate\Support\Str::limit($m->description, 90), 'url' => route('maintenance.show', $m),
            ]);
        }
        foreach (TireReturn::where('vehicle_id', $v->id)->orderByDesc('delivered_at')->limit($limit)->get() as $t) {
            $events->push([
                'at' => $t->delivered_at->copy()->setTime(11, 0), 'icon' => 'circle', 'tone' => $t->status === 'pending' ? 'yellow' : 'slate',
                'title' => 'Goma nueva entregada'.($t->status === 'pending' ? ' — usada pendiente' : ''), 'detail' => $t->odometer ? 'Kilometraje '.number_format($t->odometer, 1) : null, 'url' => route('tires.index', ['tab' => 'history']),
            ]);
        }

        return $events->sortByDesc(fn ($e) => $e['at']->timestamp)->values()->take($limit);
    }

    public function lastInspection(Vehicle $v): ?VehicleInspection
    {
        return VehicleInspection::where('vehicle_id', $v->id)->orderByDesc('inspected_at')->orderByDesc('id')->first();
    }

    /** Próxima inspección: un mes después de la última; si nunca se inspeccionó, «pendiente». */
    public function nextInspection(Vehicle $v): array
    {
        $last = $this->lastInspection($v);
        $due = $last?->next_due_at ?? $last?->inspected_at?->copy()->addMonth();

        return ['last' => $last, 'due' => $due, 'overdue' => $due ? $due->isPast() : true];
    }

    /** @return array{open:int,total:int,critical_open:int} */
    public function incidentCounts(Vehicle $v): array
    {
        $q = VehicleIncident::where('vehicle_id', $v->id);

        return [
            'open' => (clone $q)->whereIn('status', VehicleIncident::OPEN)->count(), 'total' => (clone $q)->count(),
            'critical_open' => (clone $q)->whereIn('status', VehicleIncident::OPEN)->whereIn('priority', ['high', 'critical'])->count(),
        ];
    }

    public function lastMaintenance(Vehicle $v): ?MaintenanceRecord
    {
        return MaintenanceRecord::where('vehicle_id', $v->id)->whereNull('voided_at')->orderByDesc('performed_at')->orderByDesc('id')->first();
    }

    /** Piezas/materiales entregados al vehículo (mantenimiento), agrupados por producto. */
    public function partsReceived(Vehicle $v): Collection
    {
        return MaintenancePart::query()->join('maintenance_records', 'maintenance_records.id', '=', 'maintenance_parts.maintenance_record_id')
            ->where('maintenance_records.vehicle_id', $v->id)->whereNull('maintenance_records.voided_at')
            ->groupBy('maintenance_parts.product_id')->selectRaw('maintenance_parts.product_id, MAX(maintenance_parts.product_name) as product_name, SUM(maintenance_parts.quantity_base) as total')
            ->orderBy('product_name')->get();
    }

    /** @return array{delivered:int,pending:int} */
    public function tireStats(Vehicle $v): array
    {
        $q = TireReturn::where('vehicle_id', $v->id);

        return ['delivered' => (clone $q)->count(), 'pending' => (clone $q)->where('status', 'pending')->count()];
    }
}
