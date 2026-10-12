<?php

namespace App\Services\Reports\Definitions;

use App\Models\TireReturn;
use App\Services\Reports\Period;
use App\Services\Reports\ReportResult;
use App\Services\Reports\ReportSet;

class TireReports extends ReportSet
{
    public function section(): string
    {
        return 'tires';
    }

    public function definitions(): array
    {
        return [
            $this->def('tires-delivered', 'Gomas entregadas', 'Gomas nuevas entregadas a camiones y estado de su devolución.', fn (Period $p, array $f) => $this->delivered($p, $f), ['vehicle', 'status' => ['label' => 'Devolución', 'options' => TireReturn::STATUSES]]),
            $this->def('tires-received', 'Gomas usadas recibidas', 'Gomas usadas recibidas en el período con su condición.', fn (Period $p, array $f) => $this->received($p, $f), ['vehicle', 'condition' => ['label' => 'Condición', 'options' => TireReturn::CONDITIONS]]),
            $this->def('tires-pending', 'Devoluciones pendientes', 'Gomas nuevas entregadas cuya goma usada aún no ha regresado.', fn (Period $p, array $f) => $this->pending($f), ['vehicle'], false),
            $this->def('used-tires-inventory', 'Inventario de gomas usadas', 'Gomas usadas recibidas agrupadas por condición.', fn (Period $p, array $f) => $this->usedInventory($f), [], false),
        ];
    }

    protected function delivered(Period $p, array $f): ReportResult
    {
        $rows = TireReturn::with(['vehicle', 'deliverer'])->whereBetween('delivered_at', [$p->from->toDateString(), $p->to->toDateString()])
            ->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle']))
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderBy('delivered_at')->orderBy('id')->get()->map(fn ($t) => [
                'date' => $t->delivered_at, 'vehicle' => $t->vehicle?->ficha, 'tire' => $t->product_name, 'quantity' => $t->quantity, 'odometer' => $t->odometer, 'reason' => $t->reason,
                'status' => TireReturn::STATUSES[$t->status] ?? $t->status, 'user' => $t->deliverer?->name, '_tone' => $t->status === 'pending' ? 'yellow' : null,
            ]);

        return ReportResult::make([
            ['date', 'Fecha', 'date'], ['vehicle', 'Vehículo'], ['tire', 'Goma'], ['quantity', 'Cant.', 'int'], ['odometer', 'Kilometraje', 'dec1'], ['reason', 'Motivo'], ['status', 'Devolución'], ['user', 'Entregó'],
        ], $rows, ['quantity']);
    }

    protected function received(Period $p, array $f): ReportResult
    {
        $rows = TireReturn::with(['vehicle', 'receiver'])->where('status', 'received')->whereBetween('received_at', [$p->from, $p->to])
            ->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle']))
            ->when(! empty($f['condition']), fn ($q) => $q->where('condition', $f['condition']))
            ->orderBy('received_at')->get()->map(fn ($t) => [
                'at' => $t->received_at, 'vehicle' => $t->vehicle?->ficha, 'tire' => $t->product_name, 'quantity' => $t->quantity,
                'condition' => TireReturn::CONDITIONS[$t->condition] ?? $t->condition, 'user' => $t->receiver?->name, 'notes' => $t->notes,
            ]);

        return ReportResult::make([
            ['at', 'Recibida', 'datetime'], ['vehicle', 'Vehículo'], ['tire', 'Goma'], ['quantity', 'Cant.', 'int'], ['condition', 'Condición'], ['user', 'Recibió'], ['notes', 'Notas'],
        ], $rows, ['quantity']);
    }

    protected function pending(array $f): ReportResult
    {
        $rows = TireReturn::with('vehicle')->where('status', 'pending')->when(! empty($f['vehicle']), fn ($q) => $q->where('vehicle_id', (int) $f['vehicle']))
            ->orderBy('delivered_at')->get()->map(fn ($t) => [
                'date' => $t->delivered_at, 'vehicle' => $t->vehicle?->ficha, 'tire' => $t->product_name, 'quantity' => $t->quantity,
                'days' => (int) $t->delivered_at->diffInDays(now()), '_tone' => $t->delivered_at->diffInDays(now()) > 15 ? 'red' : null,
            ]);

        return ReportResult::make([['date', 'Entregada', 'date'], ['vehicle', 'Vehículo'], ['tire', 'Goma'], ['quantity', 'Cant.', 'int'], ['days', 'Días pendiente', 'int']], $rows, ['quantity'])
            ->note('Pendientes a la fecha de generación del reporte.');
    }

    protected function usedInventory(array $f): ReportResult
    {
        $rows = TireReturn::where('status', 'received')->selectRaw('`condition` as cond, SUM(quantity) as qty')->groupBy('condition')->get()
            ->map(fn ($r) => ['condition' => TireReturn::CONDITIONS[$r->cond] ?? $r->cond, 'quantity' => (int) $r->qty]);

        return ReportResult::make([['condition', 'Condición'], ['quantity', 'Gomas usadas', 'int']], $rows, ['quantity']);
    }
}
