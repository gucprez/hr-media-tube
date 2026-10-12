@extends('layouts.app')
@section('title', 'Sucursales y almacenes')
@section('content')
@include('admin.settings._tabs')
<div class="grid gap-6 xl:grid-cols-2">
    <section class="space-y-4">
        <h2 class="font-semibold text-slate-900">Sucursales</h2>
        @foreach($branches as $b)
        <form method="POST" action="{{ route('admin.settings.branches.update', $b) }}" class="card card-body grid gap-3 sm:grid-cols-2">
            @csrf @method('PUT')
            <x-field name="name" label="Nombre" :value="$b->name" required />
            <x-field name="code" label="Código" :value="$b->code" />
            <x-field name="address" label="Dirección" :value="$b->address" />
            <x-field name="phone" label="Teléfono" :value="$b->phone" />
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked($b->is_active)> Activa</label>
            <div class="flex justify-end gap-2">
                <button class="btn-primary btn-sm">Guardar</button>
                <button type="submit" form="del-branch-{{ $b->id }}" class="btn-danger btn-sm" onclick="return confirm('¿Eliminar la sucursal?')" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button>
            </div>
        </form>
        <form id="del-branch-{{ $b->id }}" method="POST" action="{{ route('admin.settings.branches.destroy', $b) }}">@csrf @method('DELETE')</form>
        @endforeach
        <form method="POST" action="{{ route('admin.settings.branches.store') }}" class="card card-body grid gap-3 border-dashed sm:grid-cols-2">
            @csrf
            <p class="font-medium text-slate-800 sm:col-span-2">Nueva sucursal</p>
            <x-field name="name" label="Nombre" required />
            <x-field name="code" label="Código" />
            <x-field name="address" label="Dirección" />
            <x-field name="phone" label="Teléfono" />
            <div class="sm:col-span-2"><button class="btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4" /> Agregar sucursal</button></div>
        </form>
    </section>

    <section class="space-y-4">
        <h2 class="font-semibold text-slate-900">Almacenes</h2>
        @foreach($warehouses as $w)
        <form method="POST" action="{{ route('admin.settings.warehouses.update', $w) }}" class="card card-body grid gap-3 sm:grid-cols-2">
            @csrf @method('PUT')
            <x-field name="name" label="Nombre" :value="$w->name" required />
            <x-field name="code" label="Código" :value="$w->code" />
            <div><label class="label">Sucursal</label><select name="branch_id" class="input"><option value="">—</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected($w->branch_id == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
            <div class="flex items-end gap-4 pb-2 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="is_default" value="1" class="rounded border-slate-300" @checked($w->is_default)> Predeterminado</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked($w->is_active)> Activo</label>
            </div>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button class="btn-primary btn-sm">Guardar</button>
                @unless($w->is_default)<button type="submit" form="del-wh-{{ $w->id }}" class="btn-danger btn-sm" onclick="return confirm('¿Eliminar el almacén?')" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button>@endunless
            </div>
        </form>
        @unless($w->is_default)<form id="del-wh-{{ $w->id }}" method="POST" action="{{ route('admin.settings.warehouses.destroy', $w) }}">@csrf @method('DELETE')</form>@endunless
        @endforeach
        <form method="POST" action="{{ route('admin.settings.warehouses.store') }}" class="card card-body grid gap-3 border-dashed sm:grid-cols-2">
            @csrf
            <p class="font-medium text-slate-800 sm:col-span-2">Nuevo almacén</p>
            <x-field name="name" label="Nombre" required />
            <x-field name="code" label="Código" />
            <div><label class="label">Sucursal</label><select name="branch_id" class="input"><option value="">—</option>@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
            <div class="flex items-end pb-2"><button class="btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4" /> Agregar almacén</button></div>
        </form>
    </section>
</div>
@endsection
