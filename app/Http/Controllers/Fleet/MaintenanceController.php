<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Concerns\HandlesAttachments;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\InventoryController;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use App\Models\Warehouse;
use App\Services\Fleet\MaintenanceService;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\QuantityConverter;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    use HandlesAttachments;

    public function __construct(protected QuantityConverter $converter) {}

    public function index(Request $request)
    {
        $records = MaintenanceRecord::with(['vehicle', 'incident', 'user'])->withCount('parts')
            ->when($request->filled('vehicle'), fn ($q) => $q->where('vehicle_id', (int) $request->vehicle))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('performed_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('performed_at', '<=', $request->date('to')))
            ->orderByDesc('performed_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('fleet.maintenance.index', [
            'records' => $records, 'vehicles' => Vehicle::orderBy('ficha')->get(['id', 'ficha']),
            'openIncidents' => VehicleIncident::with('vehicle')->whereIn('status', VehicleIncident::OPEN)->orderByDesc('reported_at')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $incident = $request->filled('incident') ? VehicleIncident::with('vehicle')->find($request->integer('incident')) : null;
        $warehouses = Warehouse::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
        $oldLines = collect(old('parts', []))->map(function ($l) {
            $p = Product::with(['unit', 'primaryImage'])->withSum('stocks as total_base', 'quantity_base')->find($l['product_id'] ?? 0);

            return $p ? ['product' => app(InventoryController::class)->payload($p), 'boxes' => $l['boxes'] ?? '', 'units' => $l['units'] ?? ''] : null;
        })->filter()->values();

        return view('fleet.maintenance.form', [
            'vehicles' => Vehicle::orderBy('ficha')->get(), 'warehouses' => $warehouses, 'defaultWarehouse' => $warehouses->firstWhere('is_default', true) ?? $warehouses->first(),
            'incident' => $incident, 'selectedVehicle' => $incident?->vehicle_id ?: $request->integer('vehicle'),
            'incidents' => VehicleIncident::with('vehicle')->whereIn('status', VehicleIncident::OPEN)->get(), 'oldLines' => $oldLines,
            'canNegative' => auth()->user()->hasPermission('inventory.negative') && app(CurrentCompany::class)->get()?->setting('allow_negative_stock'),
        ]);
    }

    public function store(Request $request, MaintenanceService $service)
    {
        $cid = app(CurrentCompany::class)->id();
        \App\Support\LineInput::dropEmpty($request, 'parts');
        $data = $request->validate([
            'vehicle_id' => ['required', Rule::exists('vehicles', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'incident_id' => ['nullable', Rule::exists('vehicle_incidents', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'performed_at' => ['required', 'date', 'before_or_equal:today'],
            'type' => ['nullable', Rule::in(array_keys(MaintenanceRecord::TYPES))],
            'description' => ['required', 'string', 'max:3000'],
            'responsible' => ['nullable', 'string', 'max:150'],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $cid)->where('is_active', true)],
            'allow_negative' => ['boolean'],
            'parts' => ['nullable', 'array', 'max:50'],
            'parts.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'parts.*.boxes' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'parts.*.units' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ] + $this->photoRule('photos', 5), ['description.required' => 'Describa la reparación o el mantenimiento realizado.']);

        $vehicle = Vehicle::findOrFail($data['vehicle_id']);
        $data['allow_negative'] = $request->boolean('allow_negative');
        $stored = [];
        try {
            $record = DB::transaction(function () use ($service, $vehicle, $data, $request, &$stored) {
                $record = $service->record($vehicle, $data, $data['parts'] ?? [], $request->user());
                $this->attachFiles($request, $record, 'photos', 'maintenance', $stored, 'maintenance');

                return $record;
            });
        } catch (InventoryException $e) {
            $this->cleanupStored($stored);

            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->cleanupStored($stored);
            throw $e;
        }

        return redirect()->route('maintenance.show', $record)->with('success', 'Mantenimiento registrado'.($record->movement_id ? ' y piezas descontadas del inventario.' : '.'));
    }

    public function show(MaintenanceRecord $maintenance)
    {
        $maintenance->load(['vehicle', 'incident', 'parts.product.unit', 'user', 'voider', 'attachments', 'movement']);

        return view('fleet.maintenance.show', ['record' => $maintenance, 'converter' => $this->converter]);
    }

    public function void(Request $request, MaintenanceRecord $maintenance, MaintenanceService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], ['reason.required' => 'Indique el motivo de la anulación.', 'reason.min' => 'El motivo debe tener al menos 5 caracteres.']);
        try {
            $service->void($maintenance, $data['reason'], $request->user(), $request->boolean('allow_negative'));
        } catch (InventoryException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Mantenimiento anulado; las piezas volvieron al inventario.');
    }
}
