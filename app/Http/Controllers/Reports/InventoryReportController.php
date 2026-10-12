<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InventoryReportController extends Controller
{
    public function __construct(protected QuantityConverter $converter) {}

    /** Inventario actual (imprimible): código, foto, producto, categoría, cajas, unidades, total, mínimo, estado, ubicación. */
    public function current(Request $request, StockQuery $stock)
    {
        $filters = $request->only(['q', 'category', 'status', 'warehouse', 'product']) + ['active' => $request->input('active', 'active')];
        $products = $stock->products($filters)->orderBy('name')->get();

        return view('reports.inventory-current', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'allProducts' => Product::orderBy('name')->get(['id', 'name', 'sku']),
            'warehouse' => ! empty($filters['warehouse']) ? Warehouse::find($filters['warehouse']) : null,
            'converter' => $this->converter,
        ]);
    }

    /** Inventario inicial + entradas − salidas ± ajustes = final, por rango de fechas. */
    public function movement(Request $request, InventoryLedger $ledger)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'warehouse' => ['nullable', 'integer'], 'category' => ['nullable', 'integer'], 'product' => ['nullable', 'integer'],
        ]);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($data['to'] ?? now());

        $rows = $ledger->periodSummary($from, $to, $data['warehouse'] ?? null, $data['category'] ?? null, $data['product'] ?? null);
        $products = Product::withTrashed()->with(['unit', 'category'])->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');

        return view('reports.inventory-movement', [
            'rows' => $rows->filter(fn ($r) => $r->initial != 0 || $r->entries != 0 || $r->exits != 0 || $r->adjustments != 0 || $r->others != 0 || $r->final != 0)->values(),
            'products' => $products,
            'from' => $from, 'to' => $to,
            'filters' => $data,
            'categories' => Category::orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'allProducts' => Product::orderBy('name')->get(['id', 'name', 'sku']),
            'warehouse' => ! empty($data['warehouse']) ? Warehouse::find($data['warehouse']) : null,
            'converter' => $this->converter,
        ]);
    }
}
