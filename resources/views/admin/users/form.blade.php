@extends('layouts.app')
@section('title', $user->exists ? 'Editar usuario' : 'Nuevo usuario')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <h1 class="page-title">{{ $user->exists ? 'Editar usuario' : 'Nuevo usuario' }}</h1>
    <a href="{{ route('admin.users.index') }}" class="btn-secondary">Volver</a>
</div>
<form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}"
      x-data="{ role: '{{ old('role_id', $user->role_id) }}', roles: @js($rolePermissions) }" class="space-y-6">
    @csrf @if($user->exists) @method('PUT') @endif
    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Datos de la cuenta</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="first_name" label="Nombre" :value="$user->first_name" required />
            <x-field name="last_name" label="Apellido" :value="$user->last_name" required />
            <x-field name="username" label="Usuario" :value="$user->username" required autocomplete="off" />
            <x-field name="email" label="Correo" type="email" :value="$user->email" required />
            <x-field name="password" label="Contraseña" type="password" :required="! $user->exists" autocomplete="new-password"
                     :hint="$user->exists ? 'Deje en blanco para no cambiarla.' : 'Mínimo 8 caracteres, con letras y números.'" />
            <div>
                <label for="role_id" class="label">Rol <span class="text-red-500">*</span></label>
                <select id="role_id" name="role_id" x-model="role" class="input" required>
                    <option value="">Seleccione…</option>
                    @foreach($roles as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
                </select>
                @error('role_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="branch_id" class="label">Sucursal</label>
                <select id="branch_id" name="branch_id" class="input">
                    <option value="">Sin sucursal</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $user->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $user->is_active))> Cuenta activa</label>
        </div>
    </div>

    <div class="card card-body">
        <h2 class="font-semibold text-slate-900">Permisos individuales</h2>
        <p class="mb-4 text-sm text-slate-500">El usuario hereda los permisos de su rol. Aquí puede <b class="text-emerald-700">otorgar</b> o <b class="text-red-700">denegar</b> permisos específicos solo para esta persona.</p>
        <div class="space-y-5">
        @foreach($groups as $group => $perms)
            <div>
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $group }}</p>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($perms as $key => $label)
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2">
                        <div class="min-w-0"><p class="truncate text-sm text-slate-800">{{ $label }}</p><p class="truncate text-[11px] text-slate-400">{{ $key }}
                            <span x-show="roles[role] && roles[role].includes('{{ $key }}')" class="text-emerald-600">· por rol</span></p></div>
                        <select name="permissions[{{ $key }}]" class="rounded-md border-slate-300 py-1 text-xs">
                            @php $cur = old('permissions.'.$key, $overrides[$key] ?? 'inherit'); @endphp
                            <option value="inherit" @selected($cur === 'inherit')>Según rol</option>
                            <option value="grant" @selected($cur === 'grant')>Otorgar</option>
                            <option value="deny" @selected($cur === 'deny')>Denegar</option>
                        </select>
                    </div>
                @endforeach
                </div>
            </div>
        @endforeach
        </div>
    </div>
    <div class="flex gap-2"><button class="btn-primary">Guardar usuario</button><a href="{{ route('admin.users.index') }}" class="btn-secondary">Cancelar</a></div>
</form>
@endsection
