<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\InventoryMovementItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\ImageStore;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockQuery;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public const MAX_IMAGES = 8;

    public function __construct(protected QuantityConverter $converter) {}

    public function index(Request $request, StockQuery $stock)
    {
        $filters = $request->only(['q', 'category', 'status', 'active', 'warehouse']);
        $products = $stock->products($filters)->orderBy('name')->paginate(20)->withQueryString();

        return view('inventory.products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'converter' => $this->converter,
        ]);
    }

    public function create()
    {
        return view('inventory.products.form', $this->formData(new Product([
            'handling_mode' => Product::MODE_UNITS, 'box_label' => 'caja', 'units_per_box' => 1, 'alert_unit' => 'units',
            'min_quantity' => 0, 'is_active' => true,
        ])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $product = new Product($data);
        $product->created_by = $request->user()->id;
        $this->applyDerived($product);
        $product->save();

        $stored = $this->storePhotos($request, $product);
        Audit::log('product.created', "Producto creado: {$product->sku} · {$product->name}", $product, null,
            $product->only(['sku', 'name', 'category_id', 'handling_mode', 'units_per_box', 'min_quantity', 'alert_unit']) + ['fotos' => $stored]);

        return redirect()->route('products.show', $product)->with('success', 'Producto creado. Registre una entrada para cargar su existencia.');
    }

    public function show(Product $product)
    {
        $product->load(['unit', 'category', 'images', 'defaultWarehouse']);
        $total = $product->totalBase();
        $monthStart = now()->startOfMonth();

        $sum = fn (string $type, $since) => (float) InventoryMovementItem::where('product_id', $product->id)->where('type', $type)->where('occurred_at', '>=', $since)->sum('delta_base');

        return view('inventory.products.show', [
            'product' => $product,
            'total' => $total,
            'status' => $product->status($total),
            'stocks' => $product->stocks()->with('warehouse')->get(),
            'movements' => InventoryMovementItem::with(['movement.user', 'warehouse'])->where('product_id', $product->id)
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(15)->get(),
            'stats' => [
                'entries_month' => $sum(InventoryMovementItem::ENTRY, $monthStart),
                'exits_month' => abs($sum(InventoryMovementItem::EXIT, $monthStart)),
                'consumption_30' => abs((float) InventoryMovementItem::where('product_id', $product->id)
                    ->whereIn('type', [InventoryMovementItem::EXIT, InventoryMovementItem::CONTAINER, InventoryMovementItem::MAINTENANCE])
                    ->where('occurred_at', '>=', now()->subDays(30))->sum('delta_base')),
            ],
            'converter' => $this->converter,
        ]);
    }

    public function edit(Product $product)
    {
        return view('inventory.products.form', $this->formData($product->load('images')));
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request, $product);
        $original = $product->getAttributes();
        $wasActive = $product->is_active;

        $product->fill($data);
        $this->applyDerived($product);
        $product->save();
        $this->storePhotos($request, $product);

        $action = $wasActive && ! $product->is_active ? 'product.deactivated' : 'updated';
        Audit::changes($action, $product, $original, ($action === 'updated' ? 'Producto modificado' : 'Producto desactivado').": {$product->sku} · {$product->name}");

        return redirect()->route('products.show', $product)->with('success', 'Producto actualizado.');
    }

    public function destroy(Product $product)
    {
        if ($product->totalBase() != 0.0) {
            return back()->with('error', 'No puede eliminar un producto con existencia. Ajuste o despache su inventario primero (o desactívelo).');
        }
        $product->delete(); // soft delete: el historial de movimientos se conserva
        Audit::log('product.deleted', "Producto eliminado: {$product->sku} · {$product->name}", $product, ['sku' => $product->sku, 'name' => $product->name]);

        return redirect()->route('products.index')->with('success', 'Producto eliminado (su historial se conserva).');
    }

    // ---- fotografías -----------------------------------------------------------

    public function storeImages(Request $request, Product $product)
    {
        $request->validate($this->photoRules());
        $count = $this->storePhotos($request, $product);
        Audit::log('product.photos_added', "Fotos agregadas al producto {$product->sku}", $product, null, ['cantidad' => $count]);

        return back()->with($count ? 'success' : 'error', $count ? "{$count} foto(s) agregada(s)." : 'No se recibió ninguna foto.');
    }

    public function destroyImage(Product $product, ProductImage $image, ImageStore $store)
    {
        abort_unless($image->product_id === $product->id, 404);
        $wasPrimary = $image->is_primary;
        $store->delete($image->path, $image->thumb_path);
        $image->delete();
        if ($wasPrimary && ($next = $product->images()->first())) {
            $next->update(['is_primary' => true]);
        }
        Audit::log('product.photo_deleted', "Foto eliminada del producto {$product->sku}", $product);

        return back()->with('success', 'Foto eliminada.');
    }

    public function primaryImage(Product $product, ProductImage $image)
    {
        abort_unless($image->product_id === $product->id, 404);
        $product->images()->update(['is_primary' => false]);
        $image->update(['is_primary' => true]);

        return back()->with('success', 'Foto principal actualizada.');
    }

    // ---- helpers ---------------------------------------------------------------

    protected function formData(Product $product): array
    {
        return [
            'product' => $product,
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'units' => Unit::where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'hasMovements' => $product->exists && InventoryMovementItem::where('product_id', $product->id)->exists(),
            'maxImages' => self::MAX_IMAGES,
        ];
    }

    protected function photoRules(): array
    {
        return [
            'photos' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    protected function validated(Request $request, ?Product $product = null): array
    {
        $cid = app(CurrentCompany::class)->id();
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $cid);

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:60', Rule::unique('products', 'sku')->where('company_id', $cid)->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('products', 'barcode')->where('company_id', $cid)->ignore($product?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', $scoped('categories')],
            'unit_id' => ['required', $scoped('units')],
            'handling_mode' => ['required', Rule::in(array_keys(Product::MODES))],
            'box_label' => ['nullable', 'string', 'max:40'],
            'units_per_box' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'alert_unit' => ['required', Rule::in(['boxes', 'units'])],
            'min_quantity' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'default_warehouse_id' => ['nullable', $scoped('warehouses')],
            'aisle' => ['nullable', 'string', 'max:40'],
            'shelf' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_returnable' => ['boolean'],
            'returnable_kind' => ['nullable', Rule::in(['tire', 'toner', 'other'])],
            'is_active' => ['boolean'],
        ] + $this->photoRules());

        unset($data['photos']);
        $data['is_returnable'] = $request->boolean('is_returnable');
        $data['is_active'] = $request->boolean('is_active');
        if (! $data['is_returnable']) {
            $data['returnable_kind'] = null;
        }

        return $data;
    }

    /** Coherencia entre modo de manejo, unidades por caja y mínimo (calculado en servidor, nunca confiado al cliente). */
    protected function applyDerived(Product $product): void
    {
        if ($product->handling_mode === Product::MODE_UNITS) {
            $product->units_per_box = 1;
            $product->alert_unit = 'units';
        } elseif ($product->handling_mode === Product::MODE_BOXES) {
            $product->alert_unit = 'boxes';
        }
        $product->units_per_box = max(1, (int) $product->units_per_box);
        $product->box_label = $product->box_label ?: 'caja';
        $product->min_stock_base = $this->converter->minimumBase($product);
    }

    protected function storePhotos(Request $request, Product $product): int
    {
        $files = array_filter((array) $request->file('photos'));
        if (! $files) {
            return 0;
        }
        $room = max(0, self::MAX_IMAGES - $product->images()->count());
        $store = app(ImageStore::class);
        $dir = 'companies/'.$product->company_id.'/products';
        $saved = 0;

        foreach (array_slice($files, 0, $room) as $file) {
            $info = $store->store($file, $dir);
            $hasPrimary = $product->images()->where('is_primary', true)->exists();
            $product->images()->create([
                'path' => $info['path'], 'thumb_path' => $info['thumb_path'], 'original_name' => $info['original_name'],
                'size' => $info['size'], 'is_primary' => ! $hasPrimary,
            ]);
            $saved++;
        }

        return $saved;
    }
}
