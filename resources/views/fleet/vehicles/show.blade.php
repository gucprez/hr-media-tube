@extends('layouts.app')
@section('title', 'Vehículo '.$vehicle->ficha)
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('vehicles.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Vehículos</a>
    <div class="flex gap-2">
        @can('incidents.report')<a href="{{ route('incidents.create', ['vehicle' => $vehicle->id]) }}" class="btn-secondary btn-sm"><x-icon name="alert" class="h-4 w-4" /> Reportar avería</a>@endcan
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
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Inspección más reciente</p>
                @if($inspection['last'])<p class="text-sm font-bold text-slate-900">{{ $inspection['last']->inspected_at->format('d/m/Y') }} <span class="{{ \App\Models\VehicleInspection::OVERALL_BADGES[$inspection['last']->overall_status] }}">{{ \App\Models\VehicleInspection::OVERALL[$inspection['last']->overall_status] }}</span></p>@else<p class="text-sm text-slate-400">Nunca inspeccionado</p>@endif</div>
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Próxima inspección</p>
                <p class="text-sm font-bold {{ $inspection['overdue'] ? 'text-red-600' : 'text-slate-900' }}">{{ $inspection['due'] ? $inspection['due']->format('d/m/Y') : 'Pendiente' }}{{ $inspection['overdue'] ? ' · vencida' : '' }}</p>
                @can('vehicles.inspect')<a href="{{ route('inspections.create', ['vehicle' => $vehicle->id]) }}" class="text-xs text-brand hover:underline">Inspeccionar ahora</a>@endcan</div>
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Averías</p><p class="text-xl font-bold {{ $incidents['open'] ? 'text-red-600' : 'text-slate-900' }}">{{ $incidents['open'] }} abierta(s)</p><p class="text-xs text-slate-500">{{ $incidents['total'] }} en total · <a href="{{ route('incidents.index', ['vehicle' => $vehicle->id]) }}" class="text-brand hover:underline">ver</a></p></div>
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Último mantenimiento</p>
                @if($lastMaintenance)<p class="text-sm font-bold text-slate-900">{{ $lastMaintenance->performed_at->format('d/m/Y') }}</p><a href="{{ route('maintenance.show', $lastMaintenance) }}" class="text-xs text-brand hover:underline">{{ \Illuminate\Support\Str::limit($lastMaintenance->description, 40) }}</a>@else<p class="text-sm text-slate-400">Sin mantenimientos</p>@endif</div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Materiales / piezas recibidas</p>
                @forelse($parts as $p)<div class="flex justify-between border-b border-slate-100 py-1 text-sm last:border-0"><span>{{ $p->product_name }}</span><b>{{ rtrim(rtrim(number_format($p->total, 3), '0'), '.') }}</b></div>@empty<p class="text-sm text-slate-400">Ninguna.</p>@endforelse</div>
            <div class="card p-4"><p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Gomas</p><p class="text-sm">Cambiadas: <b>{{ $tires['delivered'] }}</b> · Usadas pendientes de devolver: <b class="{{ $tires['pending'] ? 'text-amber-600' : '' }}">{{ $tires['pending'] }}</b></p><a href="{{ route('tires.index', ['vehicle' => $vehicle->id, 'tab' => 'history']) }}" class="text-xs text-brand hover:underline">Ver historial de gomas</a></div>
        </div>
        @if($openIncidents->isNotEmpty())
        <div class="card"><h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">Averías abiertas</h2>
            @foreach($openIncidents as $inc)<a href="{{ route('incidents.show', $inc) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50"><span class="text-sm font-medium text-slate-800">{{ $inc->title }}</span><span class="shrink-0"><span class="{{ \App\Models\VehicleIncident::PRIORITY_BADGES[$inc->priority] }}">{{ $inc->priority_label }}</span> <span class="{{ \App\Models\VehicleIncident::STATUS_BADGES[$inc->status] }}">{{ $inc->status_label }}</span></span></a>@endforeach</div>
        @endif
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
