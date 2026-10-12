@extends('layouts.app')
@section('title', 'Choferes')
@section('content')
<h1 class="page-title mb-4">Choferes</h1>
@can('vehicles.edit')
<form method="POST" action="{{ route('drivers.store') }}" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.5fr_1fr_1fr_auto] lg:items-end">
    @csrf
    <x-field name="name" label="Nuevo chofer" required />
    <x-field name="phone" label="Teléfono" inputmode="tel" />
    <x-field name="license_no" label="Licencia" />
    <button class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Agregar</button>
</form>
@endcan
<div class="card divide-y divide-slate-100">
    @forelse($drivers as $d)
        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
            @can('vehicles.edit')
            <form method="POST" action="{{ route('drivers.update', $d) }}" class="grid min-w-0 flex-1 gap-2 sm:grid-cols-[1.5fr_1fr_1fr_auto_auto] sm:items-center">
                @csrf @method('PUT')
                <input name="name" value="{{ $d->name }}" class="input !min-h-9" required aria-label="Nombre">
                <input name="phone" value="{{ $d->phone }}" class="input !min-h-9" placeholder="Teléfono" aria-label="Teléfono">
                <input name="license_no" value="{{ $d->license_no }}" class="input !min-h-9" placeholder="Licencia" aria-label="Licencia">
                <label class="flex items-center gap-1 text-xs text-slate-500"><input type="checkbox" name="is_active" value="1" @checked($d->is_active) class="rounded border-slate-300"> Activo</label>
                <button class="btn-secondary btn-sm">Guardar</button>
            </form>
            <form method="POST" action="{{ route('drivers.destroy', $d) }}" @submit="if (!confirm('¿Eliminar al chofer?')) $event.preventDefault()">@csrf @method('DELETE')<button class="btn-danger btn-sm" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button></form>
            @else
            <div class="flex-1"><p class="font-medium text-slate-900">{{ $d->name }}</p><p class="text-xs text-slate-500">{{ $d->phone }} {{ $d->license_no ? '· Lic. '.$d->license_no : '' }}</p></div>
            @endcan
        </div>
    @empty
        <p class="px-4 py-8 text-center text-sm text-slate-500">Aún no hay choferes.</p>
    @endforelse
</div>
@endsection
