@extends('layouts.app')
@section('title', 'Averías')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Averías de vehículos</h1><p class="text-sm text-slate-500">Desde PC o celular. Cada cambio de estado queda en el historial.</p></div>
    @can('incidents.report')<a href="{{ route('incidents.create') }}" class="btn-primary"><x-icon name="alert" class="h-4 w-4" /> Reportar avería</a>@endcan
</div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.2fr_1fr_1fr_1fr_auto]">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar en el problema…" class="input" aria-label="Buscar">
    <select name="vehicle" class="input" aria-label="Vehículo"><option value="">Todos los vehículos</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') == $v->id)>{{ $v->ficha }}</option>@endforeach</select>
    <select name="status" class="input" aria-label="Estado"><option value="">Todos los estados</option><option value="open" @selected(request('status') === 'open')>Abiertas</option>@foreach(\App\Models\VehicleIncident::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="priority" class="input" aria-label="Prioridad"><option value="">Todas las prioridades</option>@foreach(\App\Models\VehicleIncident::PRIORITIES as $k => $l)<option value="{{ $k }}" @selected(request('priority') === $k)>{{ $l }}</option>@endforeach</select>
    <button class="btn-primary">Filtrar</button></form>
<div class="space-y-2">
    @forelse($incidents as $i)
        <a href="{{ route('incidents.show', $i) }}" class="card flex flex-wrap items-center gap-3 p-4 transition hover:shadow-md">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-lg font-bold text-slate-700">{{ $i->vehicle?->ficha }}</span>
            <div class="min-w-0 flex-1"><p class="truncate font-semibold text-slate-900">{{ $i->title }}</p><p class="text-xs text-slate-500">{{ $i->reported_at->format('d/m/Y H:i') }} · {{ $i->reporter?->name }}{{ $i->odometer ? ' · '.number_format($i->odometer, 1).' km' : '' }}</p></div>
            <div class="flex gap-1.5"><span class="{{ \App\Models\VehicleIncident::PRIORITY_BADGES[$i->priority] }}">{{ $i->priority_label }}</span><span class="{{ \App\Models\VehicleIncident::STATUS_BADGES[$i->status] }}">{{ $i->status_label }}</span></div>
        </a>
    @empty
        <div class="card card-body text-center text-slate-500">No hay averías con esos filtros.</div>
    @endforelse
</div>
<div class="mt-3">{{ $incidents->links() }}</div>
@endsection
