<?php

namespace App\Services\Reports\Definitions;

use App\Models\Container;
use App\Models\InventoryMovementItem as Line;
use App\Models\Ticket;
use App\Services\Operations\ContainerConsumption;
use App\Services\Reports\Period;
use App\Services\Reports\ReportResult;
use App\Services\Reports\ReportSet;

class ContainerReports extends ReportSet
{
    /** Un furgón se considera con exceso si consume más de este múltiplo del promedio del producto. */
    public const EXCESS_FACTOR = 1.25;

    public function __construct(protected ContainerConsumption $consumption) {}

    public function section(): string
    {
        return 'containers';
    }

    public function definitions(): array
    {
        return [
            $this->def('containers-period', 'Furgones por período', 'Furgones del período con chofer, vehículo, estado, materiales y gasoil.', fn (Period $p, array $f) => $this->byPeriod($p, $f), ['vehicle', 'status' => ['label' => 'Estado', 'options' => Container::STATUSES]]),
            $this->def('container-consumption', 'Consumo de materiales por furgón', 'Materiales consumidos por cada furgón, calculados desde el libro de movimientos.', fn (Period $p, array $f) => $this->consumptionReport($p, $f), ['product', 'container']),
            $this->def('container-comparison', 'Comparación entre furgones', 'Compara el consumo de cada producto entre furgones y marca los que superan el promedio.', fn (Period $p, array $f) => $this->comparison($p, $f), ['product']),
            $this->def('container-history', 'Historial por furgón', 'Todo lo ocurrido en un furgón: materiales, tickets y gasoil.', fn (Period $p, array $f) => $this->history($f), ['container' => ['label' => 'Furgón (obligatorio)', 'required' => true]], false),
        ];
    }

    protected function containers(Period $p, array $f)
    {
        return Container::with(['driver', 'vehicle'])->whereBetween('date', [$p->from->toDateString(), $p->to->toDateString()])
            ->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle']))
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['container']), fn ($q) => $q->where('id', (int) $f['container']))
            ->orderBy('date')->orderBy('id');
    }

    protected function byPeriod(Period $p, array $f): ReportResult
    {
        $containers = $this->containers($p, $f)->get();
        $units = $this->consumption->byProduct($containers->pluck('id')->all())->groupBy('container_id')->map(fn ($g) => round($g->sum('quantity_base'), 3));
        $rows = $containers->map(fn (Container $c) => [
            'date' => $c->date, 'number' => $c->number, 'driver' => $c->driver_display, 'vehicle' => $c->vehicle?->ficha, 'conduce' => $c->conduce, 'status' => $c->status_label,
            'units' => $units[$c->id] ?? 0, 'fuel' => $c->fuel_gallons, '_tone' => $c->status === Container::VOIDED ? 'red' : null,
        ]);

        return ReportResult::make([
            ['date', 'Fecha', 'date'], ['number', 'Furgón'], ['driver', 'Chofer'], ['vehicle', 'Vehículo'], ['conduce', 'Conduce'], ['status', 'Estado'],
            ['units', 'Materiales (unid.)', 'num'], ['fuel', 'Gasoil (gal)', 'dec1'],
        ], $rows, ['units', 'fuel'])->summary(['Furgones' => (string) $rows->count()]);
    }

    protected function consumptionReport(Period $p, array $f): ReportResult
    {
        $containers = $this->containers($p, $f)->where('status', '!=', Container::VOIDED)->get()->keyBy('id');
        $rows = $this->consumption->byProduct($containers->keys()->all())
            ->when(! empty($f['product']), fn ($c) => $c->where('product_id', (int) $f['product']))
            ->map(fn ($r) => [
                'date' => $containers[$r->container_id]->date, 'number' => $containers[$r->container_id]->number, 'vehicle' => $containers[$r->container_id]->vehicle?->ficha,
                'sku' => $r->product_sku, 'name' => $r->product_name, 'quantity' => $r->quantity_base,
            ])->sortBy([['date', 'asc'], ['number', 'asc'], ['name', 'asc']])->values();

        return ReportResult::make([
            ['date', 'Fecha', 'date'], ['number', 'Furgón'], ['vehicle', 'Vehículo'], ['sku', 'Código'], ['name', 'Producto'], ['quantity', 'Cantidad (unid.)', 'num'],
        ], $rows, ['quantity'])->note('Calculado desde el libro de movimientos (consumos y salidas hacia el furgón, netos de anulaciones).');
    }

    protected function comparison(Period $p, array $f): ReportResult
    {
        $containers = $this->containers($p, $f)->where('status', Container::CLOSED)->get()->keyBy('id');
        $data = $this->consumption->byProduct($containers->keys()->all())->when(! empty($f['product']), fn ($c) => $c->where('product_id', (int) $f['product']));
        $rows = [];
        foreach ($data->groupBy('product_id') as $group) {
            $avg = $group->avg('quantity_base');
            foreach ($group as $r) {
                $excess = $group->count() > 1 && $avg > 0 && $r->quantity_base > $avg * self::EXCESS_FACTOR;
                $rows[] = [
                    'name' => $r->product_name, 'number' => $containers[$r->container_id]->number, 'date' => $containers[$r->container_id]->date,
                    'quantity' => $r->quantity_base, 'average' => round($avg, 3), 'diff' => $avg > 0 ? round(($r->quantity_base - $avg) / $avg * 100, 1) : null,
                    'flag' => $excess ? 'Exceso' : null, '_tone' => $excess ? 'red' : null,
                ];
            }
        }
        usort($rows, fn ($a, $b) => [$a['name'], $a['number']] <=> [$b['name'], $b['number']]);

        return ReportResult::make([
            ['name', 'Producto'], ['number', 'Furgón'], ['date', 'Fecha', 'date'], ['quantity', 'Consumo (unid.)', 'num'], ['average', 'Promedio de furgones', 'num'],
            ['diff', 'Diferencia %', 'dec1'], ['flag', 'Alerta'],
        ], $rows)->note('Solo furgones cerrados. «Exceso»: consumo superior al '.round((self::EXCESS_FACTOR - 1) * 100).'% sobre el promedio del producto (se requieren al menos 2 furgones).');
    }

    protected function history(array $f): ReportResult
    {
        $c = ! empty($f['container']) ? Container::with(['vehicle', 'driver'])->find((int) $f['container']) : null;
        if (! $c) {
            return ReportResult::make([['what', 'Evento']], [])->note('Seleccione un furgón para ver su historial.');
        }
        $rows = [];
        foreach (Line::with('movement')->whereHas('movement', fn ($q) => $q->where('container_id', $c->id))->orderBy('occurred_at')->get() as $l) {
            $rows[] = ['at' => $l->occurred_at, 'what' => 'Material', 'detail' => ($l->movement?->number ?? '').' · '.$l->product_name.' ('.Line::LABELS[$l->type].')', 'qty' => $l->delta_base];
        }
        foreach (Ticket::where('container_id', $c->id)->orderBy('created_at')->get() as $t) {
            $rows[] = ['at' => $t->created_at, 'what' => 'Ticket', 'detail' => $t->number.($t->voided_at ? ' (anulado)' : ''), 'qty' => null];
        }
        foreach (\App\Models\FuelDispatch::where('container_id', $c->id)->whereNull('voided_at')->get() as $d) {
            $rows[] = ['at' => $d->dispatched_at, 'what' => 'Gasoil', 'detail' => 'Despacho de gasoil', 'qty' => $d->gallons];
        }
        usort($rows, fn ($a, $b) => $a['at'] <=> $b['at']);

        return ReportResult::make([['at', 'Fecha', 'datetime'], ['what', 'Evento'], ['detail', 'Detalle'], ['qty', 'Cantidad', 'num']], $rows)
            ->summary(['Furgón' => '#'.$c->number, 'Estado' => $c->status_label, 'Chofer' => (string) $c->driver_display, 'Vehículo' => (string) ($c->vehicle?->ficha ?? '—')]);
    }
}
