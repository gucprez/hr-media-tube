@extends('layouts.app')
@section('title', 'Mantenimiento')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Mantenimiento / Mecánica</h1><p class="text-sm text-slate-500">Reparaciones y mantenimientos preventivos. Las piezas usadas se descuentan del inventario.</p></div>
    @can('maintenance.manage')<a href="{{ route('maintenance.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Registrar mantenimiento</a>@endcan
</div>
@if($openIncidents->isNotEmpty())
<div class="card mb-4"><h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">Averías pendientes de reparar ({{ $openIncidents->count() }})</h2>
    @foreach($openIncidents as $i)<div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3 last:border-0"><a href="{{ route('incidents.show', $i) }}" class="min-w-0 text-sm"><b class="text-slate-900">{{ $i->vehicle?->ficha }}</b> — {{ $i->title }} <span class="{{ \App\Models\VehicleIncident::PRIORITY_BADGES[$i->priority] }}">{{ $i->priority_label }}</span> <span class="{{ \App\Models\VehicleIncident::STATUS_BADGES[$i->status] }}">{{ $i->status_label }}</span></a>
        @can('maintenance.manage')<a href="{{ route('maintenance.create', ['incident' => $i->id]) }}" class="btn-secondary btn-sm">Registrar reparación</a>@endcan</div>@endforeach</div>
@endif
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-[1fr_160px_160px_auto]"><select name="vehicle" class="input" aria-label="Vehículo"><option value="">Todos los vehículos</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') == $v->id)>{{ $v->ficha }}</option>@endforeach</select><input type="date" name="from" value="{{ request('from') }}" class="input" aria-label="Desde"><input type="date" name="to" value="{{ request('to') }}" class="input" aria-label="Hasta"><button class="btn-primary">Filtrar</button></form>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Fecha</th><th>Vehículo</th><th>Tipo</th><th>Descripción</th><th>Responsable</th><th class="text-right">Piezas</th><th></th></tr></thead>
    <tbody>
    @forelse($records as $r)
        <tr class="{{ $r->isVoided() ? 'opacity-60' : '' }}"><td>{{ $r->performed_at->format('d/m/Y') }}</td><td class="font-semibold">{{ $r->vehicle?->ficha }}</td><td class="text-xs">{{ \App\Models\MaintenanceRecord::TYPES[$r->type] ?? $r->type }}</td>
            <td class="max-w-sm"><span class="line-clamp-2">{{ $r->description }}</span>@if($r->isVoided())<span class="badge-red">Anulado</span>@endif</td><td>{{ $r->responsible }}</td><td class="text-right">{{ $r->parts_count }}</td><td class="text-right"><a href="{{ route('maintenance.show', $r) }}" class="btn-secondary btn-sm">Ver</a></td></tr>
    @empty
        <tr><td colspan="7" class="py-10 text-center text-slate-500">No hay mantenimientos registrados.</td></tr>
    @endforelse
    </tbody></table></div></div>
<div class="mt-2">{{ $records->links() }}</div>
@endsection
