<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::where('company_id', app(CurrentCompany::class)->id())
            ->withCount(['users', 'permissions'])->orderBy('name')->get();

        return view('admin.roles.index', compact('roles'));
    }

    public function create()
    {
        return view('admin.roles.form', $this->formData(new Role));
    }

    public function store(Request $request)
    {
        $companyId = app(CurrentCompany::class)->id();
        $data = $this->validated($request, $companyId);
        $role = Role::create(['company_id' => $companyId, 'name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'] ?? null]);
        $this->syncPermissions($role, $data['permissions'] ?? []);
        Audit::log('role.created', "Rol creado: {$role->name}", null, null, ['permissions' => $data['permissions'] ?? []]);

        return redirect()->route('admin.roles.index')->with('success', 'Rol creado.');
    }

    public function edit(Role $role)
    {
        $this->own($role);

        return view('admin.roles.form', $this->formData($role));
    }

    public function update(Request $request, Role $role)
    {
        $this->own($role);
        $data = $this->validated($request, $role->company_id, $role);
        $old = $role->permissions()->pluck('key')->all();
        $role->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $this->syncPermissions($role, $data['permissions'] ?? []);
        $new = $role->permissions()->pluck('key')->all();
        Audit::log('role.updated', "Rol actualizado: {$role->name}", null, ['permissions' => $old], ['permissions' => $new]);

        return redirect()->route('admin.roles.index')->with('success', 'Rol actualizado.');
    }

    public function destroy(Role $role)
    {
        $this->own($role);
        if ($role->users()->exists()) {
            return back()->with('error', 'No se puede eliminar un rol con usuarios asignados.');
        }
        $role->permissions()->detach();
        $role->delete();
        Audit::log('role.deleted', "Rol eliminado: {$role->name}");

        return redirect()->route('admin.roles.index')->with('success', 'Rol eliminado.');
    }

    protected function own(Role $role): void
    {
        abort_unless($role->company_id === app(CurrentCompany::class)->id(), 404);
    }

    protected function formData(Role $role): array
    {
        $mine = auth()->user()->effectivePermissions();

        return [
            'role' => $role,
            'groups' => collect(config('permissions.groups'))->map(fn ($perms) => array_intersect_key($perms, array_flip($mine)))->filter(),
            'selected' => $role->exists ? $role->permissions()->pluck('key')->all() : [],
        ];
    }

    protected function validated(Request $request, int $companyId, ?Role $role = null): array
    {
        $request->merge(['slug' => $role?->slug ?? \Illuminate\Support\Str::slug($request->input('name', ''))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:60', Rule::unique('roles', 'slug')->where('company_id', $companyId)->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);
    }

    /** Un administrador solo puede asignar permisos que él mismo posee. */
    protected function syncPermissions(Role $role, array $keys): void
    {
        $allowed = auth()->user()->effectivePermissions();
        $ids = Permission::pluck('id', 'key');
        $final = collect($keys)->filter(fn ($k) => isset($ids[$k]) && in_array($k, $allowed, true))->map(fn ($k) => $ids[$k]);

        // Mantener los permisos actuales del rol que el actor no puede ver.
        $hidden = $role->permissions()->whereNotIn('key', $allowed)->pluck('permissions.id');
        $role->permissions()->sync($final->merge($hidden)->unique()->values()->all());
    }
}
