<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InspectionChecklistItem;
use App\Models\VehicleType;
use App\Services\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Configuración: checklist de inspección y tipos de vehículo. */
class ChecklistController extends Controller
{
    public function index()
    {
        if (! InspectionChecklistItem::exists()) {
            foreach (InspectionChecklistItem::DEFAULTS as $i => $name) {
                InspectionChecklistItem::create(['name' => $name, 'sort_order' => $i + 1, 'is_active' => true]);
            }
        }

        return view('admin.settings.checklist', [
            'items' => InspectionChecklistItem::orderBy('sort_order')->orderBy('id')->get(),
            'types' => VehicleType::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $item = InspectionChecklistItem::create($data + ['sort_order' => (int) InspectionChecklistItem::max('sort_order') + 1, 'is_active' => true]);
        Audit::log('checklist.created', "Punto de inspección agregado: {$item->name}", $item);

        return back()->with('success', 'Punto agregado al checklist.');
    }

    public function update(Request $request, InspectionChecklistItem $item)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'sort_order' => ['required', 'integer', 'between:0,999']]);
        $original = $item->getAttributes();
        $item->update($data + ['is_active' => $request->boolean('is_active')]);
        Audit::changes('updated', $item, $original, "Punto de inspección actualizado: {$item->name}");

        return back()->with('success', 'Punto actualizado (las inspecciones anteriores conservan su texto).');
    }

    public function destroy(InspectionChecklistItem $item)
    {
        $item->delete();
        Audit::log('checklist.deleted', "Punto de inspección eliminado: {$item->name}", $item);

        return back()->with('success', 'Punto eliminado (las inspecciones anteriores no cambian).');
    }

    public function storeType(Request $request)
    {
        $cid = app(CurrentCompany::class)->id();
        $data = $request->validate(['name' => ['required', 'string', 'max:60', Rule::unique('vehicle_types', 'name')->where('company_id', $cid)]]);
        VehicleType::create($data);

        return back()->with('success', 'Tipo de vehículo creado.');
    }

    public function destroyType(VehicleType $type)
    {
        if (\App\Models\Vehicle::withTrashed()->where('vehicle_type_id', $type->id)->exists()) {
            return back()->with('error', 'Hay vehículos de este tipo.');
        }
        $type->delete();

        return back()->with('success', 'Tipo eliminado.');
    }
}
