<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\DocumentNumbering;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    protected function current()
    {
        return app(CurrentCompany::class)->get();
    }

    // ---- Datos de la empresa -------------------------------------------------

    public function company()
    {
        return view('admin.settings.company', ['company' => $this->current()]);
    }

    public function updateCompany(Request $request)
    {
        $company = $this->current();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'rnc' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['boolean'],
            'settings.ticket_footer' => ['nullable', 'string', 'max:200'],
            'settings.allow_negative_stock' => ['boolean'],
            'settings.critical_ratio' => ['nullable', 'numeric', 'between:0.05,1'],
        ]);

        $original = $company->getAttributes();

        if ($request->hasFile('logo')) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store("companies/{$company->id}", 'public');
        } elseif ($request->boolean('remove_logo') && $company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $data['logo_path'] = null;
        }

        $settings = $company->settings ?? [];
        $settings['ticket_footer'] = $data['settings']['ticket_footer'] ?? null;
        $settings['allow_negative_stock'] = $request->boolean('settings.allow_negative_stock');
        $settings['critical_ratio'] = (float) ($data['settings']['critical_ratio'] ?? 0.5);

        unset($data['logo'], $data['remove_logo'], $data['settings']);
        $company->update($data + ['settings' => $settings]);
        Audit::changes('updated', $company, $original, 'Datos de la empresa actualizados');

        return back()->with('success', 'Datos de la empresa guardados.');
    }

    // ---- Sucursales y almacenes ---------------------------------------------

    public function branches()
    {
        return view('admin.settings.branches', [
            'branches' => Branch::with('warehouses')->orderBy('name')->get(),
            'warehouses' => Warehouse::with('branch')->orderBy('name')->get(),
        ]);
    }

    public function storeBranch(Request $request)
    {
        $branch = Branch::create($this->branchData($request));
        Audit::log('branch.created', "Sucursal creada: {$branch->name}", $branch);

        return back()->with('success', 'Sucursal creada.');
    }

    public function updateBranch(Request $request, Branch $branch)
    {
        $original = $branch->getAttributes();
        $branch->update($this->branchData($request, $branch) + ['is_active' => $request->boolean('is_active')]);
        Audit::changes('updated', $branch, $original, "Sucursal actualizada: {$branch->name}");

        return back()->with('success', 'Sucursal actualizada.');
    }

    public function destroyBranch(Branch $branch)
    {
        if ($branch->warehouses()->exists() || \App\Models\User::where('branch_id', $branch->id)->exists()) {
            return back()->with('error', 'La sucursal tiene almacenes o usuarios asignados.');
        }
        $branch->delete();
        Audit::log('branch.deleted', "Sucursal eliminada: {$branch->name}", $branch);

        return back()->with('success', 'Sucursal eliminada.');
    }

    public function storeWarehouse(Request $request)
    {
        $warehouse = Warehouse::create($this->warehouseData($request));
        $this->ensureSingleDefault($warehouse);
        Audit::log('warehouse.created', "Almacén creado: {$warehouse->name}", $warehouse);

        return back()->with('success', 'Almacén creado.');
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse)
    {
        $original = $warehouse->getAttributes();
        $warehouse->update($this->warehouseData($request, $warehouse));
        $this->ensureSingleDefault($warehouse);
        Audit::changes('updated', $warehouse, $original, "Almacén actualizado: {$warehouse->name}");

        return back()->with('success', 'Almacén actualizado.');
    }

    public function destroyWarehouse(Warehouse $warehouse)
    {
        if ($warehouse->is_default) {
            return back()->with('error', 'No puede eliminar el almacén predeterminado.');
        }
        $warehouse->delete();
        Audit::log('warehouse.deleted', "Almacén eliminado: {$warehouse->name}", $warehouse);

        return back()->with('success', 'Almacén eliminado.');
    }

    protected function branchData(Request $request, ?Branch $branch = null): array
    {
        $cid = app(CurrentCompany::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('branches', 'code')->where('company_id', $cid)->ignore($branch?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
    }

    protected function warehouseData(Request $request, ?Warehouse $warehouse = null): array
    {
        $cid = app(CurrentCompany::class)->id();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('warehouses', 'code')->where('company_id', $cid)->ignore($warehouse?->id)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $cid)],
        ]);
        $data['is_default'] = $request->boolean('is_default');
        $data['is_active'] = $warehouse ? $request->boolean('is_active') : true;

        return $data;
    }

    protected function ensureSingleDefault(Warehouse $warehouse): void
    {
        if ($warehouse->is_default) {
            Warehouse::where('id', '!=', $warehouse->id)->update(['is_default' => false]);
        } elseif (! Warehouse::where('is_default', true)->exists()) {
            $warehouse->update(['is_default' => true]);
        }
    }

    // ---- Numeración ----------------------------------------------------------

    public function numbering()
    {
        foreach (DocumentNumbering::KEYS as $key => $label) {
            DocumentSequence::firstOrCreate(['key' => $key], ['next_number' => 1] + DocumentNumbering::defaults($key));
        }

        return view('admin.settings.numbering', [
            'sequences' => DocumentSequence::orderBy('key')->get(),
            'labels' => DocumentNumbering::KEYS,
        ]);
    }

    public function updateNumbering(Request $request)
    {
        $request->validate([
            'seq' => ['required', 'array'],
            'seq.*.prefix' => ['nullable', 'string', 'max:20'],
            'seq.*.padding' => ['required', 'integer', 'between:1,10'],
            'seq.*.next_number' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($request->input('seq') as $id => $row) {
            $sequence = DocumentSequence::find($id);
            if (! $sequence) {
                continue;
            }
            $old = $sequence->only(['prefix', 'padding', 'next_number']);
            if ((int) $row['next_number'] < $sequence->next_number) {
                return back()->with('error', "El siguiente número de «{$sequence->key}» no puede ser menor al actual ({$sequence->next_number}).");
            }
            $sequence->update(['prefix' => $row['prefix'] ?? '', 'padding' => $row['padding'], 'next_number' => $row['next_number']]);
            if ($old != $sequence->only(['prefix', 'padding', 'next_number'])) {
                Audit::log('numbering.updated', "Numeración «{$sequence->key}» actualizada", $sequence, $old, $sequence->only(['prefix', 'padding', 'next_number']));
            }
        }

        return back()->with('success', 'Numeración guardada.');
    }
}
