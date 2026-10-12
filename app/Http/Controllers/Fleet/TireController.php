<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Concerns\HandlesAttachments;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\TireReturn;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Fleet\TireService;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\QuantityConverter;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TireController extends Controller
{
    use HandlesAttachments;

    public function __construct(protected QuantityConverter $converter) {}

    public function index(Request $request, TireService $service)
    {
        $tab = in_array($request->query('tab'), ['pending', 'used', 'history', 'new'], true) ? $request->query('tab') : 'pending';
        $base = TireReturn::with(['vehicle', 'deliverer', 'receiver', 'product', 'attachments'])
            ->when($request->filled('vehicle'), fn ($q) => $q->where('vehicle_id', (int) $request->vehicle));

        $newTires = Product::with('unit')->withSum('stocks as total_base', 'quantity_base')->where('returnable_kind', 'tire')->where('is_active', true)->orderBy('name')->get();

        return view('fleet.tires.index', [
            'tab' => $tab,
            'pending' => (clone $base)->where('status', 'pending')->orderBy('delivered_at')->get(),
            'received' => (clone $base)->where('status', 'received')->orderByDesc('received_at')->limit(100)->get(),
            'history' => (clone $base)->orderByDesc('delivered_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'usedInventory' => $service->usedInventory(),
            'newTires' => $newTires,
            'vehicles' => Vehicle::orderBy('ficha')->get(['id', 'ficha']),
            'converter' => $this->converter,
            'pendingCount' => TireReturn::where('status', 'pending')->count(),
        ]);
    }

    public function create(Request $request)
    {
        $warehouses = Warehouse::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();

        return view('fleet.tires.form', [
            'vehicles' => Vehicle::where('status', '!=', 'inactive')->orderBy('ficha')->get(),
            'tires' => Product::with('unit')->withSum('stocks as total_base', 'quantity_base')->where('returnable_kind', 'tire')->where('is_active', true)->orderBy('name')->get(),
            'warehouses' => $warehouses, 'defaultWarehouse' => $warehouses->firstWhere('is_default', true) ?? $warehouses->first(),
            'selected' => $request->integer('vehicle'), 'conditions' => TireReturn::CONDITIONS, 'converter' => $this->converter,
            'canNegative' => auth()->user()->hasPermission('inventory.negative') && app(CurrentCompany::class)->get()?->setting('allow_negative_stock'),
        ]);
    }

    public function store(Request $request, TireService $service)
    {
        $cid = app(CurrentCompany::class)->id();
        $data = $request->validate([
            'vehicle_id' => ['required', Rule::exists('vehicles', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $cid)->where('is_active', true)],
            'quantity' => ['required', 'integer', 'between:1,20'],
            'delivered_at' => ['required', 'date', 'before_or_equal:today'],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'reason' => ['nullable', 'string', 'max:200'],
            'used_received' => ['required', 'in:0,1'],
            'allow_negative' => ['boolean'],
            'used' => ['nullable', 'array', 'max:20'],
            'used.*.condition' => ['nullable', Rule::in(array_keys(TireReturn::CONDITIONS))],
            'used.*.notes' => ['nullable', 'string', 'max:500'],
        ] + $this->photoRule('used_photos', 20), ['used_received.required' => 'Indique si se recibió la goma usada (Sí / No).']);
        $data['used_received'] = $data['used_received'] === '1';
        $data['allow_negative'] = $request->boolean('allow_negative');

        $stored = [];
        try {
            $rows = DB::transaction(function () use ($service, $data, $request, &$stored) {
                $rows = $service->deliver($data, array_values($data['used'] ?? []), $request->user());
                // foto de cada goma usada (una por fila, en el mismo orden)
                foreach (array_values(array_filter((array) $request->file('used_photos'))) as $i => $file) {
                    if (isset($rows[$i]) && $data['used_received']) {
                        $this->attachFile($request, $rows[$i], $file, 'used_tire', $stored, 'tires');
                    }
                }

                return $rows;
            });
        } catch (InventoryException $e) {
            $this->cleanupStored($stored);

            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->cleanupStored($stored);
            throw $e;
        }

        $msg = $data['used_received'] ? 'Entrega registrada y goma(s) usada(s) recibida(s).' : 'Entrega registrada. Quedó DEVOLUCIÓN PENDIENTE de '.$rows->count().' goma(s) usada(s).';

        return redirect()->route('tires.index', ['tab' => $data['used_received'] ? 'history' : 'pending'])->with('success', $msg);
    }

    public function receive(Request $request, TireReturn $tire, TireService $service)
    {
        $data = $request->validate(['condition' => ['required', Rule::in(array_keys(TireReturn::CONDITIONS))], 'notes' => ['nullable', 'string', 'max:500']]
            + $this->photoRule('photo', 3), ['condition.required' => 'Indique el estado de la goma usada.']);
        $stored = [];
        try {
            DB::transaction(function () use ($service, $tire, $data, $request, &$stored) {
                $service->receive($tire, $data['condition'], $data['notes'] ?? null, $request->user());
                $this->attachFiles($request, $tire, 'photo', 'used_tire', $stored, 'tires');
            });
        } catch (InventoryException $e) {
            $this->cleanupStored($stored);

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Goma usada recibida y registrada.');
    }

    public function waive(Request $request, TireReturn $tire, TireService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], ['reason.required' => 'Indique el motivo.', 'reason.min' => 'El motivo debe tener al menos 5 caracteres.']);
        try {
            $service->waive($tire, $data['reason'], $request->user());
        } catch (InventoryException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Devolución cancelada con motivo registrado.');
    }
}
