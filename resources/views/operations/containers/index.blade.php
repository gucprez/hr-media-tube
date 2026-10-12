@extends('layouts.app')
@section('title', 'Furgones')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Furgones</h1><p class="text-sm text-slate-500">Cada furgón es una operación: sus materiales se descuentan del inventario al cerrarlo.</p></div>
    @can('containers.create')<a href="{{ route('containers.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Nuevo furgón</a>@endcan
</div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_1fr_auto]">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Furgón, chofer, conduce o ficha…" class="input" aria-label="Buscar">
    <select name="status" class="input" aria-label="Estado"><option value="">Todos los estados</option>@foreach(\App\Models\Container::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="vehicle" class="input" aria-label="Vehículo"><option value="">Todos los vehículos</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') == $v->id)>{{ $v->ficha }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ request('from') }}" class="input" aria-label="Desde">
    <input type="date" name="to" value="{{ request('to') }}" class="input" aria-label="Hasta">
    <div class="flex gap-2"><button class="btn-primary flex-1">Filtrar</button><a href="{{ route('containers.index') }}" class="btn-secondary" title="Limpiar">✕</a></div>
</form>

<div class="space-y-2 md:hidden">
    @forelse($containers as $c)
        <a href="{{ route('containers.show', $c) }}" class="card block p-3">
            <div class="flex items-center justify-between gap-2"><p class="text-lg font-bold text-slate-900">Furgón {{ $c->number }}</p><span class="{{ $c->status_badge }}">{{ $c->status_label }}</span></div>
            <p class="text-sm text-slate-600">{{ $c->date->format('d/m/Y') }} · {{ $c->driver_display ?? 'Sin chofer' }} @if($c->vehicle)· {{ $c->vehicle->ficha }}@endif</p>
            <p class="text-xs text-slate-500">{{ $c->materials_count }} material(es) @if($c->conduce)· Conduce {{ $c->conduce }}@endif</p>
        </a>
    @empty
        <div class="card card-body text-center text-slate-500">No hay furgones con esos filtros.</div>
    @endforelse
</div>
<div class="card hidden md:block">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Furgón</th><th>Fecha</th><th>Chofer</th><th>Vehículo</th><th>Conduce</th><th class="text-right">Materiales</th><th class="text-right">Gasoil</th><th>Estado</th></tr></thead>
            <tbody>
            @forelse($containers as $c)
                <tr>
                    <td><a href="{{ route('containers.show', $c) }}" class="font-semibold text-brand hover:underline">#{{ $c->number }}</a></td>
                    <td>{{ $c->date->format('d/m/Y') }}</td>
                    <td>{{ $c->driver_display ?? '—' }}</td>
                    <td>{{ $c->vehicle?->ficha ?? '—' }}</td>
                    <td>{{ $c->conduce ?? '—' }}</td>
                    <td class="text-right">{{ $c->materials_count }}</td>
                    <td class="text-right">{{ $c->fuel_gallons > 0 ? number_format($c->fuel_gallons, 1).' gal' : '—' }}</td>
                    <td><span class="{{ $c->status_badge }}">{{ $c->status_label }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-10 text-center text-slate-500">No hay furgones con esos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-2">{{ $containers->links() }}</div>
@endsection
