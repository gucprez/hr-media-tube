@extends('layouts.app')
@section('title', 'Vehículo '.$vehicle->ficha)
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('vehicles.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Vehículos</a>
    <div class="flex gap-2">
        @can('vehicles.edit')<a href="{{ route('vehicles.edit', $vehicle) }}" class="btn-secondary btn-sm"><x-icon name="pencil" class="h-4 w-4" /> Editar</a>
        <form method="POST" action="{{ route('vehicles.destroy', $vehicle) }}" @submit="if (!confirm('¿Eliminar el vehículo? Su historial se conserva.')) $event.preventDefault()">@csrf @method('DELETE')<button class="btn-danger btn-sm"><x-icon name="trash" class="h-4 w-4" /> Eliminar</button></form>@endcan
    </div>
</div>
<div class="grid gap-6 lg:grid-cols-[320px_1fr]">
    <div class="card card-body">
        @if($vehicle->photo_path)<img src="{{ route('files.vehicle-photo', $vehicle) }}" alt="Foto {{ $vehicle->ficha }}" class="aspect-video w-full rounded-xl border border-slate-200 object-cover">
        @else<div class="flex aspect-video w-full items-center justify-center rounded-xl bg-slate-100 text-slate-400"><x-icon name="truck" class="h-16 w-16" /></div>@endif
        <h1 class="page-title mt-4">{{ $vehicle->ficha }} <span class="{{ $vehicle->status_badge }} align-middle">{{ $vehicle->status_label }}</span></h1>
        <dl class="mt-3 space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Placa</dt><dd>{{ $vehicle->plate ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Marca / modelo</dt><dd>{{ trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')) ?: '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Año</dt><dd>{{ $vehicle->year ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Tipo</dt><dd>{{ $vehicle->type?->name ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Chofer habitual</dt><dd>{{ $vehicle->usualDriver?->name ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Kilometraje</dt><dd class="font-semibold">{{ number_format($vehicle->odometer, 1) }}</dd></div>
        </dl>
        @if($vehicle->notes)<p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">{{ $vehicle->notes }}</p>@endif
    </div>
    <div class="space-y-6">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Último despacho de gasoil</p>
                @if($lastDispatch)<p class="text-xl font-bold text-slate-900">{{ number_format($lastDispatch->gallons, 1) }} gal</p><p class="text-xs text-slate-500">{{ $lastDispatch->dispatched_at->format('d/m/Y') }}{{ $lastDispatch->odometer ? ' · '.number_format($lastDispatch->odometer, 1).' km' : '' }}</p>@else<p class="text-sm text-slate-400">Sin despachos</p>@endif</div>
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gasoil últimos 30 días</p><p class="text-xl font-bold text-slate-900">{{ number_format($fuel['gallons_30'], 1) }} gal</p><p class="text-xs text-slate-500">Total histórico {{ number_format($fuel['gallons_total'], 1) }} gal</p></div>
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rendimiento</p><p class="text-xl font-bold text-slate-900">{{ $fuel['km_per_gallon'] ? number_format($fuel['km_per_gallon'], 2).' km/gal' : '—' }}</p><p class="text-xs text-slate-500">Entre cargas con kilometraje</p></div>
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Despachos registrados</p><p class="text-xl font-bold text-slate-900">{{ $fuel['dispatches'] }}</p><a href="{{ route('fuel.dispatches.index', ['vehicle' => $vehicle->id]) }}" class="text-xs text-brand hover:underline">Ver control de gasoil</a></div>
        </div>
        @include('fleet.vehicles._timeline')
        <div class="card">
            <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">Últimos furgones</h2>
            @forelse($containers as $c)
                <a href="{{ route('containers.show', $c) }}" class="flex items-center justify-between border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50"><span class="font-medium text-slate-800">Furgón #{{ $c->number }}</span><span class="text-xs text-slate-500">{{ $c->date->format('d/m/Y') }} · <span class="{{ $c->status_badge }}">{{ $c->status_label }}</span></span></a>
            @empty
                <p class="px-5 py-8 text-center text-sm text-slate-500">Sin furgones.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
