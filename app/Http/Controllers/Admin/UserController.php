<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with(['role', 'branch'])
            ->where('is_super_admin', false)
            ->when($request->filled('q'), fn ($q) => $q->where(function ($q) use ($request) {
                $term = '%'.$request->q.'%';
                $q->where('name', 'like', $term)->orWhere('username', 'like', $term)->orWhere('email', 'like', $term);
            }))
            ->when($request->filled('role'), fn ($q) => $q->where('role_id', $request->role))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'roles' => $this->roles()]);
    }

    public function create()
    {
        return view('admin.users.form', $this->formData(new User(['is_active' => true])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = new User(collect($data)->except(['permissions'])->all());
        $user->company_id = app(CurrentCompany::class)->id();
        $user->save();
        $this->syncOverrides($user, $request->input('permissions', []));
        Audit::log('user.created', "Usuario creado: {$user->username}", $user, null, ['role' => $user->role?->name, 'email' => $user->email]);

        return redirect()->route('admin.users.index')->with('success', 'Usuario creado.');
    }

    public function edit(User $user)
    {
        $this->guard($user);

        return view('admin.users.form', $this->formData($user));
    }

    public function update(Request $request, User $user)
    {
        $this->guard($user);
        $data = $this->validated($request, $user);

        if ($user->id === $request->user()->id && ! ($data['is_active'] ?? true)) {
            return back()->withErrors(['is_active' => 'No puede desactivar su propia cuenta.'])->withInput();
        }

        $original = $user->getAttributes();
        $fill = collect($data)->except(['permissions', 'password'])->all();
        if (! empty($data['password'])) {
            $fill['password'] = $data['password'];
        }
        $user->update($fill);
        $this->syncOverrides($user, $request->input('permissions', []));
        Audit::changes('updated', $user, $original, "Usuario actualizado: {$user->username}");

        return redirect()->route('admin.users.index')->with('success', 'Usuario actualizado.');
    }

    public function destroy(Request $request, User $user)
    {
        $this->guard($user);
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'No puede eliminar su propia cuenta.');
        }
        $user->delete();
        Audit::log('user.deleted', "Usuario eliminado: {$user->username}", $user);

        return redirect()->route('admin.users.index')->with('success', 'Usuario eliminado.');
    }

    protected function guard(User $user): void
    {
        abort_if($user->is_super_admin, 404);
    }

    protected function roles()
    {
        return Role::where('company_id', app(CurrentCompany::class)->id())->orderBy('name')->get();
    }

    protected function formData(User $user): array
    {
        $mine = $this->assignable(auth()->user());

        return [
            'user' => $user,
            'roles' => $this->roles(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'groups' => collect(config('permissions.groups'))->map(fn ($perms) => array_intersect_key($perms, array_flip($mine)))->filter(),
            'overrides' => $user->exists ? $user->permissionOverrides()->get()->mapWithKeys(fn ($p) => [$p->key => $p->pivot->granted ? 'grant' : 'deny'])->all() : [],
            'rolePermissions' => $this->roles()->mapWithKeys(fn ($r) => [$r->id => $r->permissions()->pluck('key')->all()])->all(),
        ];
    }

    /** Permisos que quien edita puede delegar (nunca más de los que posee). */
    protected function assignable(User $actor): array
    {
        return $actor->effectivePermissions();
    }

    protected function validated(Request $request, ?User $user = null): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'username' => ['required', 'alpha_dash', 'max:50', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', Password::min(8)->letters()->numbers()],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('company_id', $companyId)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'is_active' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['in:inherit,grant,deny'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }

    protected function syncOverrides(User $user, array $submitted): void
    {
        $allowed = $this->assignable(auth()->user());
        $ids = Permission::pluck('id', 'key');
        $sync = [];
        foreach ($submitted as $key => $mode) {
            if (! isset($ids[$key]) || ! in_array($key, $allowed, true) || $mode === 'inherit') {
                continue;
            }
            $sync[$ids[$key]] = ['granted' => $mode === 'grant'];
        }
        // Conservar overrides que el actor no puede ver/delegar.
        foreach ($user->permissionOverrides()->get() as $existing) {
            if (! in_array($existing->key, $allowed, true)) {
                $sync[$existing->id] = ['granted' => (bool) $existing->pivot->granted];
            }
        }
        $user->permissionOverrides()->sync($sync);
        $user->flushPermissionCache();
    }
}
