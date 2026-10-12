<?php

namespace App\Services\Fleet;

use App\Models\Container;
use App\Models\FuelDispatch;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/** Expediente del vehículo: indicadores y línea de tiempo armados desde los registros históricos (no desde campos sueltos). */
class VehicleFile
{
    /** @var array<int,callable> fuentes de eventos adicionales (las registran los módulos: mantenimiento, inspecciones, gomas…) */
    protected static array $sources = [];

    public static function addSource(callable $source): void
    {
        self::$sources[] = $source;
    }

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
        foreach (Container::where('vehicle_id', $v->id)->where('status', '!=', 'voided')->orderByDesc('date')->limit($limit)->get() as $c) {
            $events->push([
                'at' => $c->date->copy()->setTime(9, 0), 'icon' => 'truck', 'tone' => 'slate', 'title' => 'Furgón #'.$c->number.' ('.$c->status_label.')',
                'detail' => $c->driver_display, 'url' => route('containers.show', $c),
            ]);
        }
        foreach (self::$sources as $source) {
            foreach ($source($v, $limit) as $event) {
                $events->push($event);
            }
        }

        return $events->sortByDesc(fn ($e) => $e['at']->timestamp)->values()->take($limit);
    }
}
