<?php

namespace App\Services\Reports\Definitions;

use App\Models\ReturnableMovement;
use App\Services\Fleet\ReturnableService;
use App\Services\Reports\Period;
use App\Services\Reports\ReportResult;
use App\Services\Reports\ReportSet;

class ReturnableReports extends ReportSet
{
    public function __construct(protected ReturnableService $service) {}

    public function section(): string
    {
        return 'returnables';
    }

    public function definitions(): array
    {
        return [
            $this->def('returnables-delivered', 'Retornables entregados', 'Tóner y otros retornables entregados a personas, áreas o vehículos.', fn (Period $p, array $f) => $this->movements($p, $f, ['delivered']), ['product', 'vehicle']),
            $this->def('returnables-returned', 'Retornables retornados', 'Retornados, recargables y desechados en el período.', fn (Period $p, array $f) => $this->movements($p, $f, ReturnableMovement::CLOSING), ['product', 'vehicle']),
            $this->def('returnables-pending', 'Retornables pendientes', 'Lo entregado que todavía no ha sido devuelto, recargado o desechado.', fn (Period $p, array $f) => $this->pending(), [], false),
        ];
    }

    protected function movements(Period $p, array $f, array $states): ReportResult
    {
        $rows = ReturnableMovement::with(['vehicle', 'user'])->whereIn('state', $states)->whereBetween('occurred_at', [$p->from, $p->to])
            ->when(! empty($f['product']), fn ($q) => $q->where('product_id', (int) $f['product']))
            ->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle']))
            ->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($m) => [
                'at' => $m->occurred_at, 'product' => $m->product_name, 'state' => ReturnableMovement::STATES[$m->state] ?? $m->state, 'quantity' => $m->quantity,
                'holder' => $m->vehicle?->ficha ?? $m->holder, 'user' => $m->user?->name, 'notes' => $m->notes,
            ]);

        return ReportResult::make([
            ['at', 'Fecha', 'datetime'], ['product', 'Producto'], ['state', 'Estado'], ['quantity', 'Cant.', 'num'], ['holder', 'Persona / vehículo'], ['user', 'Registró'], ['notes', 'Notas'],
        ], $rows, ['quantity']);
    }

    protected function pending(): ReportResult
    {
        $rows = $this->service->pending()->map(fn ($r) => [
            'product' => $r->product_name, 'holder' => $r->vehicle_id ? \App\Models\Vehicle::find($r->vehicle_id)?->ficha : $r->holder,
            'delivered' => $r->delivered, 'used' => $r->used, 'closed' => $r->closed, 'outstanding' => $r->outstanding,
        ]);

        return ReportResult::make([
            ['product', 'Producto'], ['holder', 'Persona / vehículo'], ['delivered', 'Entregado', 'num'], ['used', 'Usado', 'num'], ['closed', 'Devuelto / desechado', 'num'], ['outstanding', 'Pendiente', 'num'],
        ], $rows, ['delivered', 'used', 'closed', 'outstanding']);
    }
}
