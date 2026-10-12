<?php

namespace App\Services\Reports\Definitions;

use App\Models\InventoryMovementItem as Line;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use App\Services\Fleet\VehicleFile;
use App\Services\Reports\Period;
use App\Services\Reports\ReportResult;
use App\Services\Reports\ReportSet;

class VehicleReports extends ReportSet
{
    public function __construct(protected VehicleFile $file) {}

    public function section(): string
    {
        return 'vehicles';
    }

    public function definitions(): array
    {
        return [
            $this->def('fleet-status', 'Estado de la flotilla', 'Cada vehículo con estado, kilometraje, averías abiertas, última inspección y último mantenimiento.', fn (Period $p, array $f) => $this->fleet($f), ['status' => ['label' => 'Estado', 'options' => Vehicle::STATUSES]], false),
            $this->def('vehicle-history', 'Historial por vehículo', 'Línea de tiempo completa de un vehículo: gasoil, furgones, inspecciones, averías, piezas y gomas.', fn (Period $p, array $f) => $this->history($p, $f), ['vehicle' => ['label' => 'Vehículo (obligatorio)', 'required' => true]]),
            $this->def('vehicle-materials', 'Materiales y piezas por vehículo', 'Materiales entregados a cada vehículo (mantenimiento y retornables), desde el libro de movimientos.', fn (Period $p, array $f) => $this->materials($p, $f), ['vehicle', 'product']),
            $this->def('vehicle-fuel', 'Gasoil por vehículo', 'Galones despachados por vehículo y rendimiento en el período.', fn (Period $p, array $f) => FuelReports::consumptionByVehicle($p, $f['vehicle'] ?? null), ['vehicle']),
        ];
    }

    protected function fleet(array $f): ReportResult
    {
        $rows = Vehicle::with('type')->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))->orderBy('ficha')->get()->map(function (Vehicle $v) {
            $insp = $this->file->lastInspection($v);
            $next = $this->file->nextInspection($v);
            $maint = $this->file->lastMaintenance($v);

            return [
                'ficha' => $v->ficha, 'plate' => $v->plate, 'type' => $v->type?->name, 'status' => $v->status_label, 'odometer' => $v->odometer,
                'open' => VehicleIncident::where('vehicle_id', $v->id)->whereIn('status', VehicleIncident::OPEN)->count(),
                'inspection' => $insp?->inspected_at, 'next' => $next['due'], 'maintenance' => $maint?->performed_at,
                '_tone' => in_array($v->status, ['breakdown'], true) ? 'red' : (in_array($v->status, ['maintenance'], true) ? 'yellow' : null),
            ];
        });

        return ReportResult::make([
            ['ficha', 'Ficha'], ['plate', 'Placa'], ['type', 'Tipo'], ['status', 'Estado'], ['odometer', 'Kilometraje', 'dec1'], ['open', 'Averías abiertas', 'int'],
            ['inspection', 'Última inspección', 'date'], ['next', 'Próxima inspección', 'date'], ['maintenance', 'Último mantenimiento', 'date'],
        ], $rows, ['open'])->summary(['Vehículos' => (string) $rows->count()]);
    }

    protected function history(Period $p, array $f): ReportResult
    {
        $v = ! empty($f['vehicle']) ? Vehicle::find((int) $f['vehicle']) : null;
        if (! $v) {
            return ReportResult::make([['what', 'Evento']], [])->note('Seleccione un vehículo.');
        }
        $rows = $this->file->timeline($v, 1000)->filter(fn ($e) => $e['at']->between($p->from, $p->to))->map(fn ($e) => ['at' => $e['at'], 'title' => $e['title'], 'detail' => $e['detail']])->sortBy('at')->values();

        return ReportResult::make([['at', 'Fecha', 'datetime'], ['title', 'Evento'], ['detail', 'Detalle']], $rows)->summary(['Vehículo' => $v->ficha.($v->plate ? ' · '.$v->plate : ''), 'Estado' => $v->status_label]);
    }

    protected function materials(Period $p, array $f): ReportResult
    {
        $rows = Line::with(['movement.vehicle'])->whereBetween('occurred_at', [$p->from, $p->to])->whereIn('type', [Line::MAINTENANCE, Line::VOID, Line::RETURN])
            ->whereHas('movement', fn ($q) => $q->whereNotNull('vehicle_id')->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle'])))
            ->when(! empty($f['product']), fn ($q) => $q->where('product_id', (int) $f['product']))
            ->orderBy('occurred_at')->get()->map(fn (Line $l) => [
                'at' => $l->occurred_at, 'vehicle' => $l->movement?->vehicle?->ficha, 'number' => $l->movement?->number, 'sku' => $l->product_sku, 'name' => $l->product_name,
                'quantity' => -$l->delta_base, 'reason' => $l->movement?->reason,
            ])->filter(fn ($r) => $r['quantity'] != 0.0);

        return ReportResult::make([
            ['at', 'Fecha', 'datetime'], ['vehicle', 'Vehículo'], ['number', 'Documento'], ['sku', 'Código'], ['name', 'Producto'], ['quantity', 'Cantidad (unid.)', 'num'], ['reason', 'Motivo'],
        ], $rows, ['quantity'])->note('Las anulaciones y devoluciones restan.');
    }
}
