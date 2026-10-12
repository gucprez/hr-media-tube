<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovementItem as Line;
use App\Models\InventoryStock;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas históricas sobre el libro de movimientos. NUNCA usan el stock actual:
 *   Inventario inicial + Entradas − Salidas ± Ajustes ± Otros = Inventario final
 * para cualquier rango de fechas, reconstruido solo a partir de las líneas del libro.
 */
class InventoryLedger
{
    /**
     * @return Collection<int, object{product_id:int,product_sku:?string,product_name:?string,initial:float,entries:float,exits:float,adjustments:float,others:float,final:float}>
     */
    public function periodSummary(
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $warehouseId = null,
        ?int $categoryId = null,
        ?int $productId = null,
    ): Collection {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $adj = [Line::ADJUSTMENT_IN, Line::ADJUSTMENT_OUT];
        $adjIn = "'".implode("','", $adj)."'";

        $rows = Line::query()
            ->join('products', 'products.id', '=', 'inventory_movement_items.product_id')
            ->when($warehouseId, fn ($q) => $q->where('inventory_movement_items.warehouse_id', $warehouseId))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->when($productId, fn ($q) => $q->where('inventory_movement_items.product_id', $productId))
            ->where('inventory_movement_items.occurred_at', '<=', $to)
            ->groupBy('inventory_movement_items.product_id')
            ->select('inventory_movement_items.product_id')
            ->selectRaw('MAX(products.sku) as product_sku, MAX(products.name) as product_name')
            ->selectRaw('COALESCE(SUM(CASE WHEN inventory_movement_items.occurred_at < ? THEN delta_base ELSE 0 END),0) as initial', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN inventory_movement_items.occurred_at >= ? AND inventory_movement_items.type = ? THEN delta_base ELSE 0 END),0) as entries', [$from, Line::ENTRY])
            ->selectRaw('COALESCE(SUM(CASE WHEN inventory_movement_items.occurred_at >= ? AND inventory_movement_items.type = ? THEN -delta_base ELSE 0 END),0) as exits', [$from, Line::EXIT])
            ->selectRaw("COALESCE(SUM(CASE WHEN inventory_movement_items.occurred_at >= ? AND inventory_movement_items.type IN ({$adjIn}) THEN delta_base ELSE 0 END),0) as adjustments", [$from])
            ->selectRaw("COALESCE(SUM(CASE WHEN inventory_movement_items.occurred_at >= ? AND inventory_movement_items.type NOT IN ({$adjIn}, ?, ?) THEN delta_base ELSE 0 END),0) as others", [$from, Line::ENTRY, Line::EXIT])
            ->selectRaw('COALESCE(SUM(delta_base),0) as final')
            ->orderBy('product_name')
            ->get();

        return $rows->map(function ($r) {
            foreach (['initial', 'entries', 'exits', 'adjustments', 'others', 'final'] as $k) {
                $r->{$k} = round((float) $r->{$k}, 3);
            }

            return $r;
        });
    }

    /** Existencia de un producto en una fecha/hora, reconstruida desde el libro. */
    public function stockAt(int $productId, CarbonInterface $at, ?int $warehouseId = null): float
    {
        return round((float) Line::where('product_id', $productId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('occurred_at', '<=', $at)->sum('delta_base'), 3);
    }

    /**
     * Auditoría de integridad: el stock de cada (almacén, producto) debe ser igual a la suma de sus movimientos.
     *
     * @return Collection<int, array{warehouse_id:int,product_id:int,stock:float,ledger:float}>
     */
    public function discrepancies(): Collection
    {
        $ledger = Line::query()->select('warehouse_id', 'product_id', DB::raw('SUM(delta_base) as total'))
            ->groupBy('warehouse_id', 'product_id')->get()
            ->mapWithKeys(fn ($r) => [$r->warehouse_id.'-'.$r->product_id => round((float) $r->total, 3)]);

        $stocks = InventoryStock::query()->get()->mapWithKeys(fn ($s) => [$s->warehouse_id.'-'.$s->product_id => round((float) $s->quantity_base, 3)]);

        return $ledger->keys()->merge($stocks->keys())->unique()->map(function ($key) use ($ledger, $stocks) {
            [$w, $p] = explode('-', $key);

            return ['warehouse_id' => (int) $w, 'product_id' => (int) $p, 'stock' => $stocks[$key] ?? 0.0, 'ledger' => $ledger[$key] ?? 0.0];
        })->filter(fn ($r) => abs($r['stock'] - $r['ledger']) > 0.0005)->values();
    }
}
