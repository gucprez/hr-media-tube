<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ReturnableMovement;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Fleet\ReturnableService;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\QuantityConverter;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReturnableController extends Controller
{
    public function __construct(protected QuantityConverter $converter) {}

    public function index(Request $request, ReturnableService $service)
    {
        $products = Product::with('unit')->withSum('stocks as total_base', 'quantity_base')->where('is_returnable', true)->where(fn ($q) => $q->whereNull('returnable_kind')->orWhere('returnable_kind', '!=', 'tire'))
            ->where('is_active', true)->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();

        return view('fleet.returnables.index', [
            'pending' => $service->pending(), 'products' => $products, 'warehouses' => $warehouses,
            'vehicles' => Vehicle::orderBy('ficha')->get(['id', 'ficha']), 'converter' => $this->converter,
            'history' => ReturnableMovement::with(['vehicle', 'user'])->when($request->filled('product'), fn ($q) => $q->where('product_id', (int) $request->product))
                ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'states' => ReturnableMovement::STATES, 'canNegative' => auth()->user()->hasPermission('inventory.negative') && app(CurrentCompany::class)->get()?->setting('allow_negative_stock'),
        ]);
    }

    public function deliver(Request $request, ReturnableService $service)
    {
        $cid = app(CurrentCompany::class)->id();
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $cid)->where('is_active', true)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'vehicle_id' => ['nullable', Rule::exists('vehicles', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'holder' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'allow_negative' => ['boolean'],
        ]);
        $data['allow_negative'] = $request->boolean('allow_negative');
        try {
            $service->deliver($data, $request->user());
        } catch (InventoryException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Entrega registrada y descontada del inventario.');
    }

    public function transition(Request $request, ReturnableService $service)
    {
        $cid = app(CurrentCompany::class)->id();
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'state' => ['required', Rule::in(['used', 'returned', 'refillable', 'discarded'])],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'vehicle_id' => ['nullable', Rule::exists('vehicles', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'holder' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $service->transition($data, $request->user());
        } catch (InventoryException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Estado registrado: '.ReturnableMovement::STATES[$data['state']].'.');
    }
}
