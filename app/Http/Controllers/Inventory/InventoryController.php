<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockQuery;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(protected QuantityConverter $converter) {}

    /** Existencias actuales (vista operativa). */
    public function index(Request $request, StockQuery $stock)
    {
        $filters = $request->only(['q', 'category', 'status', 'warehouse']) + ['active' => 'active'];
        $products = $stock->products($filters)->orderBy('name')->paginate(25)->withQueryString();

        return view('inventory.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'converter' => $this->converter,
        ]);
    }

    /** Autocompletado de productos para los formularios de entrada/salida/ajuste. */
    public function lookup(Request $request, StockQuery $stock)
    {
        $warehouseId = (int) $request->integer('warehouse_id') ?: null;
        $type = $request->string('type')->toString();

        $items = $stock->products(['q' => $request->string('q')->toString(), 'warehouse' => $warehouseId, 'active' => $type === 'entry' ? 'active' : null])
            ->orderBy('name')->limit(15)->get();

        return response()->json($items->map(fn (Product $p) => $this->payload($p)));
    }

    /** Vista previa de líneas (sin escribir): cantidades convertidas, existencia resultante y errores. */
    public function preview(Request $request, StockService $service)
    {
        $data = $request->validate([
            'type' => ['required', 'in:entry,exit,adjustment'],
            'warehouse_id' => ['required', 'integer'],
            'lines' => ['array', 'max:100'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.boxes' => ['nullable'],
            'lines.*.units' => ['nullable'],
        ]);

        return response()->json($service->preview($data['type'], (int) $data['warehouse_id'], $data['lines'] ?? []));
    }

    public function payload(Product $p): array
    {
        $base = $p->totalBase();

        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'thumb' => $p->primaryImage?->url(true),
            'mode' => $p->handling_mode,
            'units_per_box' => $p->units_per_box,
            'box_label' => $this->converter->boxLabel($p, 2),
            'unit_label' => $this->converter->unitLabel($p, 2),
            'stock_label' => $this->converter->format($p, $base),
            'status' => $p->status($base),
            'status_label' => \App\Services\Inventory\StockStatus::LABELS[$p->status($base)],
            'active' => $p->is_active,
        ];
    }
}
