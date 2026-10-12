@extends('layouts.app')
@section('title', $role->exists ? 'Editar rol' : 'Nuevo rol')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <h1 class="page-title">{{ $role->exists ? 'Editar rol: '.$role->name : 'Nuevo rol' }}</h1>
    <a href="{{ route('admin.roles.index') }}" class="btn-secondary">Volver</a>
</div>
<form method="POST" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="space-y-6"
      x-data="{ toggle(group, on) { $root.querySelectorAll('[data-group=\''+group+'\']').forEach(c => c.checked = on) } }">
    @csrf @if($role->exists) @method('PUT') @endif
    <div class="card card-body grid gap-4 sm:grid-cols-2">
        <x-field name="name" label="Nombre del rol" :value="$role->name" required />
        <x-field name="description" label="Descripción" :value="$role->description" />
    </div>
    <div class="card card-body space-y-5">
        <h2 class="font-semibold text-slate-900">Permisos</h2>
        @foreach($groups as $group => $perms)
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $group }}</p>
                    <span class="space-x-2 text-xs"><button type="button" @click="toggle('{{ $group }}', true)" class="text-brand hover:underline">Todos</button><button type="button" @click="toggle('{{ $group }}', false)" class="text-slate-500 hover:underline">Ninguno</button></span>
                </div>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($perms as $key => $label)
                    <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-200 px-3 py-2 hover:bg-slate-50">
                        <input type="checkbox" name="permissions[]" value="{{ $key }}" data-group="{{ $group }}" class="mt-0.5 rounded border-slate-300"
                               @checked(in_array($key, old('permissions', $selected)))>
                        <span><span class="block text-sm text-slate-800">{{ $label }}</span><span class="block text-[11px] text-slate-400">{{ $key }}</span></span>
                    </label>
                @endforeach
                </div>
            </div>
        @endforeach
    </div>
    <div class="flex gap-2"><button class="btn-primary">Guardar rol</button><a href="{{ route('admin.roles.index') }}" class="btn-secondary">Cancelar</a></div>
</form>
@endsection
