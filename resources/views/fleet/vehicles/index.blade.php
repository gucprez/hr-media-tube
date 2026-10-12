@extends('layouts.app')
@section('title', 'Vehículos')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Vehículos</h1><p class="text-sm text-slate-500">Flotilla: un expediente por camión.</p></div>
    <div class="flex gap-2">
        <a href="{{ route('drivers.index') }}" class="btn-secondary"><x-icon name="users" class="h-4 w-4" /> Choferes</a>
        @can('vehicles.edit')<a href="{{ route('vehicles.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Nuevo vehículo</a>@endcan
    </div>
</div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-[1fr_220px_auto]">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Ficha, placa, marca o modelo…" class="input" aria-label="Buscar">
    <select name="status" class="input" aria-label="Estado"><option value="">Todos los estados</option>@foreach(\App\Models\Vehicle::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
    <div class="flex gap-2"><button class="btn-primary flex-1">Filtrar</button><a href="{{ route('vehicles.index') }}" class="btn-secondary" title="Limpiar">✕</a></div>
</form>
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    @forelse($vehicles as $v)
        <a href="{{ route('vehicles.show', $v) }}" class="card flex items-center gap-3 p-3 transition hover:shadow-md">
            @if($v->photo_path)<img src="{{ route('files.vehicle-photo', $v) }}" alt="" class="h-16 w-16 rounded-lg border border-slate-200 object-cover" loading="lazy">
            @else<div class="flex h-16 w-16 items-center justify-center rounded-lg bg-slate-100 text-slate-400"><x-icon name="truck" class="h-8 w-8" /></div>@endif
            <div class="min-w-0 flex-1">
                <p class="text-lg font-bold text-slate-900">{{ $v->ficha }} <span class="text-sm font-medium text-slate-500">{{ $v->plate }}</span></p>
                <p class="truncate text-sm text-slate-600">{{ trim(($v->brand ?? '').' '.($v->model ?? '').' '.($v->year ?? '')) ?: 'Sin datos' }}</p>
                <p class="truncate text-xs text-slate-500">{{ $v->type?->name }} {{ $v->usualDriver ? '· '.$v->usualDriver->name : '' }}</p>
            </div>
            <span class="{{ $v->status_badge }}">{{ $v->status_label }}</span>
        </a>
    @empty
        <div class="card card-body text-center text-slate-500 sm:col-span-2 xl:col-span-3">No hay vehículos registrados.</div>
    @endforelse
</div>
<div class="mt-3">{{ $vehicles->links() }}</div>
@endsection
