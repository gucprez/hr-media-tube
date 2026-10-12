<?php

namespace App\Services\Reports\Definitions;

use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\VehicleIncident;
use App\Models\VehicleInspection;
use App\Services\Reports\Period;
use App\Services\Reports\ReportResult;
use App\Services\Reports\ReportSet;

class MaintenanceReports extends ReportSet
{
    public function section(): string
    {
        return 'maintenance';
    }

    public function definitions(): array
    {
        return [
            $this->def('inspections', 'Inspecciones mensuales', 'Inspecciones realizadas, resultado y puntos con novedad.', fn (Period $p, array $f) => $this->inspections($p, $f), ['vehicle', 'status' => ['label' => 'Resultado', 'options' => VehicleInspection::OVERALL]]),
            $this->def('incidents', 'Averías', 'Averías reportadas en el período, abiertas y cerradas, con tiempo de resolución.', fn (Period $p, array $f) => $this->incidents($p, $f),
                ['vehicle', 'status' => ['label' => 'Estado', 'options' => VehicleIncident::STATUSES + ['open' => 'Todas las abiertas']], 'priority' => ['label' => 'Prioridad', 'options' => VehicleIncident::PRIORITIES]]),
            $this->def('maintenance-records', 'Mantenimientos realizados', 'Reparaciones y mantenimientos preventivos con piezas usadas.', fn (Period $p, array $f) => $this->records($p, $f), ['vehicle', 'type' => ['label' => 'Tipo', 'options' => MaintenanceRecord::TYPES]]),
            $this->def('parts-used', 'Piezas utilizadas', 'Piezas y materiales consumidos en mantenimientos (sin anulados).', fn (Period $p, array $f) => $this->parts($p, $f), ['vehicle', 'product']),
        ];
    }

    protected function inspections(Period $p, array $f): ReportResult
    {
        $rows = VehicleInspection::with(['vehicle', 'inspector', 'items'])->whereBetween('inspected_at', [$p->from->toDateString(), $p->to->toDateString()])
            ->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle']))
            ->when(! empty($f['status']), fn ($q) => $q->where('overall_status', $f['status']))
            ->orderBy('inspected_at')->orderBy('id')->get()->map(fn ($i) => [
                'date' => $i->inspected_at, 'vehicle' => $i->vehicle?->ficha, 'inspector' => $i->inspector?->name, 'odometer' => $i->odometer,
                'result' => VehicleInspection::OVERALL[$i->overall_status] ?? $i->overall_status,
                'attention' => $i->items->where('status', 'attention')->count(), 'damaged' => $i->items->where('status', 'damaged')->count(), 'next' => $i->next_due_at,
                '_tone' => $i->overall_status === 'damaged' ? 'red' : ($i->overall_status === 'attention' ? 'yellow' : null),
            ]);

        return ReportResult::make([
            ['date', 'Fecha', 'date'], ['vehicle', 'Vehículo'], ['inspector', 'Inspector'], ['odometer', 'Kilometraje', 'dec1'], ['result', 'Resultado'],
            ['attention', 'Puntos con atención', 'int'], ['damaged', 'Puntos dañados', 'int'], ['next', 'Próxima', 'date'],
        ], $rows, ['attention', 'damaged'])->summary(['Inspecciones' => (string) $rows->count()]);
    }

    protected function incidents(Period $p, array $f): ReportResult
    {
        $rows = VehicleIncident::with(['vehicle', 'reporter'])->whereBetween('reported_at', [$p->from, $p->to])
            ->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle']))
            ->when(($f['status'] ?? '') === 'open', fn ($q) => $q->whereIn('status', VehicleIncident::OPEN))
            ->when(! empty($f['status']) && $f['status'] !== 'open', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['priority']), fn ($q) => $q->where('priority', $f['priority']))
            ->orderBy('reported_at')->get()->map(fn ($i) => [
                'at' => $i->reported_at, 'vehicle' => $i->vehicle?->ficha, 'title' => $i->title, 'priority' => $i->priority_label, 'status' => $i->status_label,
                'reporter' => $i->reporter?->name, 'closed' => $i->closed_at, 'days' => round(($i->closed_at ?? now())->diffInHours($i->reported_at, true) / 24, 1),
                '_tone' => in_array($i->priority, ['high', 'critical'], true) && in_array($i->status, VehicleIncident::OPEN, true) ? 'red' : null,
            ]);

        return ReportResult::make([
            ['at', 'Reportada', 'datetime'], ['vehicle', 'Vehículo'], ['title', 'Avería'], ['priority', 'Prioridad'], ['status', 'Estado'], ['reporter', 'Reportó'],
            ['closed', 'Cerrada', 'datetime'], ['days', 'Días abierta', 'dec1'],
        ], $rows)->summary(['Averías' => (string) $rows->count(), 'Abiertas' => (string) $rows->filter(fn ($r) => ! $r['closed'])->count()]);
    }

    protected function records(Period $p, array $f): ReportResult
    {
        $rows = MaintenanceRecord::with(['vehicle', 'parts', 'incident'])->whereBetween('performed_at', [$p->from->toDateString(), $p->to->toDateString()])
            ->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle']))
            ->when(! empty($f['type']), fn ($q) => $q->where('type', $f['type']))
            ->orderBy('performed_at')->orderBy('id')->get()->map(fn ($m) => [
                'date' => $m->performed_at, 'vehicle' => $m->vehicle?->ficha, 'type' => MaintenanceRecord::TYPES[$m->type] ?? $m->type, 'description' => $m->description,
                'incident' => $m->incident?->title, 'responsible' => $m->responsible, 'odometer' => $m->odometer, 'parts' => $m->parts->count(),
                'state' => $m->voided_at ? 'Anulado' : null, '_tone' => $m->voided_at ? 'red' : null,
            ]);

        return ReportResult::make([
            ['date', 'Fecha', 'date'], ['vehicle', 'Vehículo'], ['type', 'Tipo'], ['description', 'Trabajo realizado'], ['incident', 'Avería atendida'], ['responsible', 'Responsable'],
            ['odometer', 'Kilometraje', 'dec1'], ['parts', 'Piezas', 'int'], ['state', 'Estado'],
        ], $rows->all(), [])->summary(['Mantenimientos' => (string) $rows->filter(fn ($r) => ! $r['state'])->count()]);
    }

    protected function parts(Period $p, array $f): ReportResult
    {
        $rows = MaintenancePart::with(['record.vehicle'])->whereHas('record', fn ($q) => $q->whereNull('voided_at')->whereBetween('performed_at', [$p->from->toDateString(), $p->to->toDateString()])
            ->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle'])))
            ->when(! empty($f['product']), fn ($q) => $q->where('product_id', (int) $f['product']))
            ->get()->sortBy(fn ($m) => $m->record->performed_at)->map(fn ($m) => [
                'date' => $m->record->performed_at, 'vehicle' => $m->record->vehicle?->ficha, 'sku' => $m->product_sku, 'name' => $m->product_name,
                'quantity' => $m->quantity_base, 'work' => $m->record->description,
            ])->values();

        return ReportResult::make([['date', 'Fecha', 'date'], ['vehicle', 'Vehículo'], ['sku', 'Código'], ['name', 'Pieza'], ['quantity', 'Cantidad', 'num'], ['work', 'Trabajo']], $rows, ['quantity']);
    }
}
