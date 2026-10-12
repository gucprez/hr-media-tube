<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Concerns\HandlesAttachments;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use App\Services\Fleet\IncidentService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    use HandlesAttachments;

    public function index(Request $request)
    {
        $incidents = VehicleIncident::with(['vehicle', 'reporter'])
            ->when($request->filled('vehicle'), fn ($q) => $q->where('vehicle_id', (int) $request->vehicle))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->priority))
            ->when($request->input('status') === 'open', fn ($q) => $q->whereIn('status', VehicleIncident::OPEN))
            ->when($request->filled('status') && $request->status !== 'open', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $request->q).'%'))
            ->orderByRaw("CASE status WHEN 'reported' THEN 1 WHEN 'pending' THEN 2 WHEN 'in_repair' THEN 3 WHEN 'repaired' THEN 4 ELSE 5 END")
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderByDesc('reported_at')->paginate(20)->withQueryString();

        return view('fleet.incidents.index', ['incidents' => $incidents, 'vehicles' => Vehicle::orderBy('ficha')->get(['id', 'ficha'])]);
    }

    public function create(Request $request)
    {
        return view('fleet.incidents.form', ['vehicles' => Vehicle::where('status', '!=', 'inactive')->orderBy('ficha')->get(), 'selected' => $request->integer('vehicle')]);
    }

    public function store(Request $request, IncidentService $service)
    {
        $cid = app(CurrentCompany::class)->id();
        $data = $request->validate([
            'vehicle_id' => ['required', Rule::exists('vehicles', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'priority' => ['required', Rule::in(array_keys(VehicleIncident::PRIORITIES))],
        ] + $this->photoRule('photos', 5), ['title.required' => 'Describa el problema.']);

        $vehicle = Vehicle::findOrFail($data['vehicle_id']);
        $stored = [];
        try {
            $incident = DB::transaction(function () use ($service, $vehicle, $data, $request, &$stored) {
                $incident = $service->report($vehicle, $data, $request->user());
                $this->attachFiles($request, $incident, 'photos', 'incident', $stored, 'incidents');

                return $incident;
            });
        } catch (\Throwable $e) {
            $this->cleanupStored($stored);
            throw $e;
        }

        return redirect()->route('incidents.show', $incident)->with('success', 'Avería reportada.');
    }

    public function show(VehicleIncident $incident)
    {
        $incident->load(['vehicle', 'reporter', 'events.user', 'attachments', 'maintenance.user']);

        return view('fleet.incidents.show', ['incident' => $incident]);
    }

    public function status(Request $request, VehicleIncident $incident, IncidentService $service)
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(VehicleIncident::STATUSES))], 'note' => ['nullable', 'string', 'max:500']]);
        try {
            $service->changeStatus($incident, $data['status'], $data['note'] ?? null, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', 'Estado actualizado: '.VehicleIncident::STATUSES[$data['status']].'.');
    }
}
