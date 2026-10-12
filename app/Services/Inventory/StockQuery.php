<?php

namespace App\Services\Inventory;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/** Consulta única de productos con su existencia (todas las bodegas o una sola) y filtros. */
class StockQuery
{
    public function __construct(protected StockStatus $status) {}

    /**
     * @param  array{q?:?string,category?:?int,status?:?string,warehouse?:?int,active?:?string,product?:?int}  $f
     */
    public function products(array $f = []): Builder
    {
        $warehouse = ! empty($f['warehouse']) ? (int) $f['warehouse'] : null;

        $q = Product::query()
            ->with(['unit', 'category', 'primaryImage', 'defaultWarehouse'])
            ->withSum(['stocks as total_base' => fn ($s) => $warehouse ? $s->where('warehouse_id', $warehouse) : $s], 'quantity_base')
            ->search($f['q'] ?? null)
            ->when(! empty($f['category']), fn ($q) => $q->where('category_id', (int) $f['category']))
            ->when(! empty($f['product']), fn ($q) => $q->where('id', (int) $f['product']))
            ->when(($f['active'] ?? '') === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($f['active'] ?? '') === 'inactive', fn ($q) => $q->where('is_active', false));

        if (! empty($f['status']) && array_key_exists($f['status'], StockStatus::LABELS)) {
            $this->status->scopeStatus($q, $f['status'], $warehouse);
        }

        return $q;
    }
}
