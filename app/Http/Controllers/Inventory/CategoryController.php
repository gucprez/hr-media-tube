<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Unit;
use App\Services\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Categorías y unidades de medida (catálogos del almacén). */
class CategoryController extends Controller
{
    public function index()
    {
        return view('inventory.categories', [
            'categories' => Category::withCount('products')->orderBy('name')->get(),
            'units' => Unit::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $category = Category::create($this->data($request));
        Audit::log('category.created', "Categoría creada: {$category->name}", $category);

        return back()->with('success', 'Categoría creada.');
    }

    public function update(Request $request, Category $category)
    {
        $original = $category->getAttributes();
        $category->update($this->data($request, $category) + ['is_active' => $request->boolean('is_active')]);
        Audit::changes('updated', $category, $original, "Categoría actualizada: {$category->name}");

        return back()->with('success', 'Categoría actualizada.');
    }

    public function destroy(Category $category)
    {
        if ($category->products()->exists()) {
            return back()->with('error', 'La categoría tiene productos asignados. Reasígnelos antes de eliminarla.');
        }
        $category->delete();
        Audit::log('category.deleted', "Categoría eliminada: {$category->name}", $category);

        return back()->with('success', 'Categoría eliminada.');
    }

    public function storeUnit(Request $request)
    {
        $unit = Unit::create($this->unitData($request));
        Audit::log('unit.created', "Unidad creada: {$unit->name}", $unit);

        return back()->with('success', 'Unidad creada.');
    }

    public function updateUnit(Request $request, Unit $unit)
    {
        $original = $unit->getAttributes();
        $unit->update($this->unitData($request, $unit) + ['is_active' => $request->boolean('is_active')]);
        Audit::changes('updated', $unit, $original, "Unidad actualizada: {$unit->name}");

        return back()->with('success', 'Unidad actualizada.');
    }

    public function destroyUnit(Unit $unit)
    {
        if (\App\Models\Product::where('unit_id', $unit->id)->withTrashed()->exists()) {
            return back()->with('error', 'La unidad está en uso por productos.');
        }
        $unit->delete();
        Audit::log('unit.deleted', "Unidad eliminada: {$unit->name}", $unit);

        return back()->with('success', 'Unidad eliminada.');
    }

    protected function data(Request $request, ?Category $category = null): array
    {
        $cid = app(CurrentCompany::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('categories', 'name')->where('company_id', $cid)->ignore($category?->id)],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
    }

    protected function unitData(Request $request, ?Unit $unit = null): array
    {
        $cid = app(CurrentCompany::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('units', 'name')->where('company_id', $cid)->ignore($unit?->id)],
            'plural' => ['nullable', 'string', 'max:40'],
            'abbreviation' => ['nullable', 'string', 'max:10'],
        ]);
    }
}
