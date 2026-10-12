<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Concerns\HandlesAttachments;
use App\Http\Controllers\Controller;
use App\Models\InspectionChecklistItem;
use App\Models\InspectionItem;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use App\Services\Audit;
use App\Services\Fleet\IncidentService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InspectionController extends Controller
{
    use HandlesAttachments;

    public function index(Request $request)
    {
        $this->ensureChecklist();
        $inspections = VehicleInspection::with(['vehicle', 'inspector'])->withCount('items')
            ->when($request->filled('vehicle'), fn ($q) => $q->where('vehicle_id', (int) $request->vehicle))
            ->when($request->filled('status'), fn ($q) => $q->where('overall_status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('inspected_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('inspected_at', '<=', $request->date('to')))
            ->orderByDesc('inspected_at')->orderByDesc('id')->paginate(20)->withQueryString();

        $monthStart = now()->startOfMonth()->toDateString();
        $done = VehicleInspection::whereDate('inspected_at', '>=', $monthStart)->pluck('vehicle_id');

        return view('fleet.inspections.index', [
            'inspections' => $inspections,
            'vehicles' => Vehicle::orderBy('ficha')->get(['id', 'ficha']),
            'pending' => Vehicle::where('status', '!=', 'inactive')->whereNotIn('id', $done)->orderBy('ficha')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $this->ensureChecklist();

        return view('fleet.inspections.form', [
            'vehicles' => Vehicle::where('status', '!=', 'inactive')->orderBy('ficha')->get(),
            'items' => InspectionChecklistItem::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(),
            'selected' => $request->integer('vehicle'),
        ]);
    }

    public function store(Request $request, IncidentService $incidents)
    {
        $cid = app(CurrentCompany::class)->id();
        $items = InspectionChecklistItem::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $rules = [
            'vehicle_id' => ['required', Rule::exists('vehicles', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'inspected_at' => ['required', 'date', 'before_or_equal:today'],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'create_incidents' => ['boolean'],
            'items' => ['required', 'array'],
        ];
        foreach ($items as $it) {
            $rules["items.{$it->id}.status"] = ['required', Rule::in(array_keys(VehicleInspection::STATUSES))];
            $rules["items.{$it->id}.notes"] = ['nullable', 'string', 'max:500'];
            $rules += $this->photoRule("photo_{$it->id}", 3);
        }
        $data = $request->validate($rules, ['items.*.status.required' => 'Indique el estado de cada punto de la inspección.']);

        $vehicle = Vehicle::findOrFail($data['vehicle_id']);
        $stored = [];
        try {
            $inspection = DB::transaction(function () use ($data, $items, $vehicle, $request, $incidents, &$stored) {
                $statuses = collect($items)->map(fn ($it) => $data['items'][$it->id]['status']);
                $overall = $statuses->contains('damaged') ? 'damaged' : ($statuses->contains('attention') ? 'attention' : 'good');

                $inspection = VehicleInspection::create([
                    'vehicle_id' => $vehicle->id, 'inspector_id' => $request->user()->id, 'inspected_at' => $data['inspected_at'],
                    'next_due_at' => now()->parse($data['inspected_at'])->addMonth()->toDateString(), 'odometer' => $data['odometer'] ?? null,
                    'overall_status' => $overall, 'notes' => $data['notes'] ?? null,
                ]);
                foreach ($items as $it) {
                    $row = InspectionItem::create([
                        'vehicle_inspection_id' => $inspection->id, 'checklist_item_id' => $it->id, 'label' => $it->name,
                        'status' => $data['items'][$it->id]['status'], 'notes' => $data['items'][$it->id]['notes'] ?? null,
                    ]);
                    $this->attachFiles($request, $row, "photo_{$it->id}", 'inspection', $stored, 'inspections');
                    if ($request->boolean('create_incidents') && $row->status === 'damaged') {
                        $incidents->report($vehicle, [
                            'title' => $it->name.' dañado(a)', 'description' => trim('Detectado en la inspección del '.now()->parse($data['inspected_at'])->format('d/m/Y').'. '.($row->notes ?? '')),
                            'odometer' => $data['odometer'] ?? null, 'priority' => 'medium',
                        ], $request->user());
                    }
                }
                if (! empty($data['odometer']) && $data['odometer'] > (float) $vehicle->odometer) {
                    $vehicle->forceFill(['odometer' => $data['odometer']])->save();
                }
                Audit::log('inspection.created', "Inspección de {$vehicle->ficha}: ".VehicleInspection::OVERALL[$overall], $inspection, null, [
                    'vehiculo' => $vehicle->ficha, 'resultado' => $overall, 'danados' => $statuses->filter(fn ($s) => $s === 'damaged')->count(), 'atencion' => $statuses->filter(fn ($s) => $s === 'attention')->count(),
                ]);

                return $inspection;
            });
        } catch (\Throwable $e) {
            $this->cleanupStored($stored);
            throw $e;
        }

        return redirect()->route('inspections.show', $inspection)->with('success', 'Inspección registrada.');
    }

    public function show(VehicleInspection $inspection)
    {
        $inspection->load(['vehicle', 'inspector', 'items.attachments']);

        return view('fleet.inspections.show', ['inspection' => $inspection]);
    }

    /** Crea el checklist inicial (los 18 puntos de la especificación) si la empresa aún no tiene. */
    protected function ensureChecklist(): void
    {
        if (! InspectionChecklistItem::exists()) {
            foreach (InspectionChecklistItem::DEFAULTS as $i => $name) {
                InspectionChecklistItem::create(['name' => $name, 'sort_order' => $i + 1, 'is_active' => true]);
            }
        }
    }
}
