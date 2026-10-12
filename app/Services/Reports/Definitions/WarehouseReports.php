<?php

namespace App\Services\Reports\Definitions;

use App\Models\InventoryMovementItem as Line;
use App\Models\Product;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockQuery;
use App\Services\Inventory\StockStatus;
use App\Services\Reports\Period;
use App\Services\Reports\ReportResult;
use App\Services\Reports\ReportSet;

class WarehouseReports extends ReportSet
{
    public function __construct(protected StockQuery $stock, protected QuantityConverter $converter, protected InventoryLedger $ledger) {}

    public function section(): string
    {
        return 'warehouse';
    }

    public function definitions(): array
    {
        $statusFilter = ['status' => ['label' => 'Estado', 'options' => StockStatus::LABELS]];

        return [
            $this->def('inventory-current', 'Inventario general', 'Existencia actual por producto en cajas y unidades, mínimo, estado y ubicación.',
                fn (Period $p, array $f) => $this->current($f), ['product', 'category', 'warehouse'] + $statusFilter, false),
            $this->def('inventory-by-category', 'Inventario por categoría', 'Resumen del inventario agrupado por categoría, con el estado de sus productos.',
                fn (Period $p, array $f) => $this->byCategory($f), ['warehouse'], false),
            $this->def('low-stock', 'Stock bajo', 'Productos por debajo de su mínimo.', fn (Period $p, array $f) => $this->current($f + ['status' => 'low']), ['category', 'warehouse'], false),
            $this->def('critical-stock', 'Productos críticos', 'Productos en nivel crítico.', fn (Period $p, array $f) => $this->current($f + ['status' => 'critical']), ['category', 'warehouse'], false),
            $this->def('out-of-stock', 'Productos agotados', 'Productos sin existencia.', fn (Period $p, array $f) => $this->current($f + ['status' => 'out']), ['category', 'warehouse'], false),
            $this->def('inventory-movement', 'Movimiento de inventario', 'Inicial + entradas − salidas ± ajustes = final, por cualquier rango de fechas (desde el libro de movimientos).',
                fn (Period $p, array $f) => $this->movementSummary($p, $f), ['product', 'category', 'warehouse']),
            $this->def('entries', 'Entradas de almacén', 'Detalle de entradas y devoluciones al almacén.', fn (Period $p, array $f) => $this->lines($p, $f, [Line::ENTRY, Line::RETURN]), ['product', 'category', 'warehouse']),
            $this->def('exits', 'Salidas de almacén', 'Salidas, consumos de furgón y piezas de mantenimiento.', fn (Period $p, array $f) => $this->lines($p, $f, [Line::EXIT, Line::CONTAINER, Line::MAINTENANCE]), ['product', 'category', 'warehouse']),
            $this->def('movements', 'Historial de movimientos', 'Libro completo de movimientos con saldo antes y después.', fn (Period $p, array $f) => $this->lines($p, $f, null),
                ['product', 'category', 'warehouse', 'type' => ['label' => 'Tipo', 'options' => Line::LABELS]]),
            $this->def('product-consumption', 'Consumo por producto', 'Cantidad consumida por producto y mes (salidas, furgones y mantenimiento, netas de anulaciones).',
                fn (Period $p, array $f) => $this->consumption($p, $f), ['product', 'category', 'warehouse']),
        ];
    }

    protected function current(array $f): ReportResult
    {
        $products = $this->stock->products(['category' => $f['category'] ?? null, 'status' => $f['status'] ?? null, 'warehouse' => $f['warehouse'] ?? null, 'product' => $f['product'] ?? null, 'active' => 'active'])
            ->orderBy('name')->get();
        $rows = $products->map(function (Product $p) {
            $base = $p->totalBase();
            [$boxes, $units] = $this->converter->split($p, $base);

            return [
                'sku' => $p->sku, 'name' => $p->name, 'category' => $p->category?->name,
                'boxes' => $p->handling_mode === 'units' ? null : $boxes, 'units' => $p->handling_mode === 'boxes' ? null : $units,
                'total' => $base, 'display' => $this->converter->formatTotal($p, $base), 'minimum' => $this->converter->formatMinimum($p),
                'status' => StockStatus::LABELS[$p->status($base)] ?? '', 'location' => $p->location_label,
                '_tone' => in_array($p->status($base), ['critical', 'out'], true) ? 'red' : ($p->status($base) === 'low' ? 'yellow' : null),
            ];
        });

        return ReportResult::make([
            ['sku', 'Código'], ['name', 'Producto'], ['category', 'Categoría'], ['boxes', 'Cajas', 'num'], ['units', 'Unidades sueltas', 'num'],
            ['total', 'Total (unid.)', 'num'], ['display', 'Existencia'], ['minimum', 'Mínimo'], ['status', 'Estado'], ['location', 'Ubicación'],
        ], $rows)->summary(['Productos' => (string) $rows->count()])->note('Existencia actual calculada desde el libro de movimientos / saldos; el estado compara contra el mínimo de cada producto.');
    }

    protected function byCategory(array $f): ReportResult
    {
        $products = $this->stock->products(['warehouse' => $f['warehouse'] ?? null, 'active' => 'active'])->get();
        $rows = $products->groupBy(fn (Product $p) => $p->category?->name ?? 'Sin categoría')->map(function ($group, $name) {
            $count = fn (string $s) => $group->filter(fn (Product $p) => $p->status($p->totalBase()) === $s)->count();

            return [
                'category' => $name, 'products' => $group->count(), 'total' => round($group->sum(fn (Product $p) => $p->totalBase()), 3),
                'available' => $count('available'), 'low' => $count('low'), 'critical' => $count('critical'), 'out' => $count('out'),
            ];
        })->sortKeys()->values();

        return ReportResult::make([
            ['category', 'Categoría'], ['products', 'Productos', 'int'], ['total', 'Total (unid.)', 'num'], ['available', 'Disponibles', 'int'],
            ['low', 'Stock bajo', 'int'], ['critical', 'Críticos', 'int'], ['out', 'Agotados', 'int'],
        ], $rows, ['products', 'total', 'available', 'low', 'critical', 'out']);
    }

    protected function movementSummary(Period $p, array $f): ReportResult
    {
        $rows = $this->ledger->periodSummary($p->from, $p->to, $f['warehouse'] ?? null, $f['category'] ?? null, $f['product'] ?? null)
            ->filter(fn ($r) => $r->initial != 0 || $r->entries != 0 || $r->exits != 0 || $r->adjustments != 0 || $r->others != 0 || $r->final != 0)
            ->map(fn ($r) => [
                'sku' => $r->product_sku, 'name' => $r->product_name, 'initial' => $r->initial, 'entries' => $r->entries, 'exits' => $r->exits,
                'adjustments' => $r->adjustments, 'others' => $r->others, 'final' => $r->final,
            ]);

        return ReportResult::make([
            ['sku', 'Código'], ['name', 'Producto'], ['initial', 'Inicial', 'num'], ['entries', 'Entradas', 'num'], ['exits', 'Salidas', 'num'],
            ['adjustments', 'Ajustes', 'num'], ['others', 'Otros (furgón/mant./dev./anul.)', 'num'], ['final', 'Final', 'num'],
        ], $rows, ['initial', 'entries', 'exits', 'adjustments', 'others', 'final'])->note('Cantidades en unidades base. Inicial + entradas − salidas ± ajustes ± otros = final.');
    }

    /** @param  string[]|null  $types */
    protected function lines(Period $p, array $f, ?array $types): ReportResult
    {
        $q = Line::query()->with(['movement.user', 'movement.container', 'movement.vehicle', 'warehouse'])
            ->whereBetween('occurred_at', [$p->from, $p->to])
            ->when($types, fn ($q) => $q->whereIn('type', $types))
            ->when(! empty($f['type']), fn ($q) => $q->where('type', $f['type']))
            ->when(! empty($f['warehouse']), fn ($q) => $q->where('warehouse_id', (int) $f['warehouse']))
            ->when(! empty($f['product']), fn ($q) => $q->where('product_id', (int) $f['product']))
            ->when(! empty($f['category']), fn ($q) => $q->whereIn('product_id', Product::where('category_id', (int) $f['category'])->pluck('id')))
            ->orderBy('occurred_at')->orderBy('id');

        $rows = $q->get()->map(function (Line $l) {
            $m = $l->movement;

            return [
                'at' => $l->occurred_at, 'number' => $m?->number, 'type' => Line::LABELS[$l->type] ?? $l->type, 'warehouse' => $l->warehouse?->name,
                'sku' => $l->product_sku, 'name' => $l->product_name, 'delta' => $l->delta_base, 'before' => $l->stock_before_base, 'after' => $l->balance_after_base,
                'party' => $m?->supplier ?: ($m?->container ? 'Furgón #'.$m->container->number : ($m?->vehicle ? $m->vehicle->ficha : ($m?->destination ?: $m?->employee_name))),
                'reason' => $m?->reason, 'user' => $m?->user?->name, 'state' => $m?->voided_at ? 'Anulado' : null,
            ];
        });

        return ReportResult::make([
            ['at', 'Fecha', 'datetime'], ['number', 'Documento'], ['type', 'Tipo'], ['warehouse', 'Almacén'], ['sku', 'Código'], ['name', 'Producto'],
            ['delta', 'Cantidad (unid.)', 'num'], ['before', 'Antes', 'num'], ['after', 'Después', 'num'], ['party', 'Proveedor / destino'], ['reason', 'Motivo'], ['user', 'Usuario'], ['state', 'Estado'],
        ], $rows)->summary(['Líneas' => (string) $rows->count()])->note('Cantidades con signo: positivo entra al almacén, negativo sale.');
    }

    protected function consumption(Period $p, array $f): ReportResult
    {
        $driver = \DB::connection()->getDriverName();
        $month = $driver === 'sqlite' ? "strftime('%Y-%m', inventory_movement_items.occurred_at)" : "DATE_FORMAT(inventory_movement_items.occurred_at, '%Y-%m')";
        $rows = Line::query()
            ->whereBetween('inventory_movement_items.occurred_at', [$p->from, $p->to])
            ->whereIn('inventory_movement_items.type', [Line::EXIT, Line::CONTAINER, Line::MAINTENANCE, Line::VOID, Line::RETURN])
            ->when(! empty($f['warehouse']), fn ($q) => $q->where('inventory_movement_items.warehouse_id', (int) $f['warehouse']))
            ->when(! empty($f['product']), fn ($q) => $q->where('inventory_movement_items.product_id', (int) $f['product']))
            ->when(! empty($f['category']), fn ($q) => $q->whereIn('inventory_movement_items.product_id', Product::where('category_id', (int) $f['category'])->pluck('id')))
            ->groupBy('inventory_movement_items.product_id')->groupByRaw($month)
            ->selectRaw("inventory_movement_items.product_id as pid, MAX(inventory_movement_items.product_sku) as sku, MAX(inventory_movement_items.product_name) as name, {$month} as ym, -SUM(inventory_movement_items.delta_base) as consumed")
            ->orderByRaw('ym, name')->get()
            ->map(fn ($r) => (object) ['sku' => $r->sku, 'name' => $r->name, 'month' => $r->ym, 'consumed' => round((float) $r->consumed, 3)])
            ->filter(fn ($r) => $r->consumed != 0.0)->values();

        return ReportResult::make([['month', 'Mes'], ['sku', 'Código'], ['name', 'Producto'], ['consumed', 'Consumo (unid.)', 'num']], $rows, ['consumed'])
            ->note('Consumo neto: las anulaciones y devoluciones restan.');
    }
}
