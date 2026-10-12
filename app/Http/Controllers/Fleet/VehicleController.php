<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Audit;
use App\Services\ImageStore;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $vehicles = Vehicle::with(['type', 'usualDriver'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->q).'%';
                $q->where(fn ($q) => $q->where('ficha', 'like', $like)->orWhere('plate', 'like', $like)->orWhere('brand', 'like', $like)->orWhere('model', 'like', $like));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderBy('ficha')->paginate(24)->withQueryString();

        return view('fleet.vehicles.index', compact('vehicles'));
    }

    public function create()
    {
        return view('fleet.vehicles.form', $this->formData(new Vehicle(['status' => 'operational', 'odometer' => 0])));
    }

    public function store(Request $request)
    {
        $vehicle = new Vehicle($this->validated($request));
        $vehicle->save();
        $this->storePhoto($request, $vehicle);
        Audit::log('vehicle.created', "Vehículo creado: {$vehicle->ficha}", $vehicle, null, $vehicle->only(['ficha', 'plate', 'brand', 'model', 'year']));

        return redirect()->route('vehicles.show', $vehicle)->with('success', 'Vehículo creado.');
    }

    public function show(Vehicle $vehicle)
    {
        $vehicle->load(['type', 'usualDriver']);

        $file = app(\App\Services\Fleet\VehicleFile::class);

        return view('fleet.vehicles.show', [
            'vehicle' => $vehicle,
            'containers' => $vehicle->containers()->orderByDesc('date')->orderByDesc('id')->limit(10)->get(),
            'lastDispatch' => $file->lastDispatch($vehicle),
            'fuel' => $file->fuelStats($vehicle),
            'timeline' => $file->timeline($vehicle),
        ]);
    }

    public function edit(Vehicle $vehicle)
    {
        return view('fleet.vehicles.form', $this->formData($vehicle));
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $original = $vehicle->getAttributes();
        $vehicle->update($this->validated($request, $vehicle));
        $this->storePhoto($request, $vehicle);
        Audit::changes('updated', $vehicle, $original, "Vehículo actualizado: {$vehicle->ficha}");

        return redirect()->route('vehicles.show', $vehicle)->with('success', 'Vehículo actualizado.');
    }

    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();
        Audit::log('vehicle.deleted', "Vehículo eliminado: {$vehicle->ficha}", $vehicle);

        return redirect()->route('vehicles.index')->with('success', 'Vehículo eliminado (su historial se conserva).');
    }

    public function storeType(Request $request)
    {
        $cid = app(CurrentCompany::class)->id();
        $data = $request->validate(['name' => ['required', 'string', 'max:60', Rule::unique('vehicle_types', 'name')->where('company_id', $cid)]]);
        VehicleType::create($data);

        return back()->with('success', 'Tipo de vehículo creado.');
    }

    protected function formData(Vehicle $vehicle): array
    {
        return [
            'vehicle' => $vehicle,
            'types' => VehicleType::orderBy('name')->get(),
            'drivers' => Driver::where('is_active', true)->orderBy('name')->get(),
        ];
    }

    protected function validated(Request $request, ?Vehicle $vehicle = null): array
    {
        $cid = app(CurrentCompany::class)->id();

        $data = $request->validate([
            'ficha' => ['required', 'string', 'max:30', Rule::unique('vehicles', 'ficha')->where('company_id', $cid)->ignore($vehicle?->id)],
            'plate' => ['nullable', 'string', 'max:20'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'vehicle_type_id' => ['nullable', Rule::exists('vehicle_types', 'id')->where('company_id', $cid)],
            'usual_driver_id' => ['nullable', Rule::exists('drivers', 'id')->where('company_id', $cid)],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(array_keys(Vehicle::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        unset($data['photo']);
        $data['odometer'] = $data['odometer'] ?? 0;

        return $data;
    }

    protected function storePhoto(Request $request, Vehicle $vehicle): void
    {
        if (! $request->hasFile('photo')) {
            return;
        }
        $store = app(ImageStore::class);
        $info = $store->store($request->file('photo'), 'companies/'.$vehicle->company_id.'/vehicles');
        $store->delete($vehicle->photo_path, $vehicle->photo_path ? preg_replace('/\.jpg$/', '_t.jpg', $vehicle->photo_path) : null);
        $vehicle->update(['photo_path' => $info['path']]);
    }
}
