@extends('layouts.app')
@section('title', 'Inspecciones')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Inspecciones mensuales</h1><p class="text-sm text-slate-500">Checklist por vehículo con observaciones y fotografías.</p></div>
    @can('vehicles.inspect')<a href="{{ route('inspections.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Nueva inspección</a>@endcan
</div>
@if($pending->isNotEmpty())
<div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
    <p class="text-sm font-semibold text-amber-900">Sin inspección este mes ({{ $pending->count() }})</p>
    <div class="mt-2 flex flex-wrap gap-2">@foreach($pending as $v)
        @can('vehicles.inspect')<a href="{{ route('inspections.create', ['vehicle' => $v->id]) }}" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-amber-900 shadow-sm ring-1 ring-amber-200 hover:bg-amber-100">{{ $v->ficha }}</a>@else<span class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-amber-900 ring-1 ring-amber-200">{{ $v->ficha }}</span>@endcan
    @endforeach</div>
</div>
@endif
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]">
    <select name="vehicle" class="input" aria-label="Vehículo"><option value="">Todos los vehículos</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') == $v->id)>{{ $v->ficha }}</option>@endforeach</select>
    <select name="status" class="input" aria-label="Resultado"><option value="">Todos los resultados</option>@foreach(\App\Models\VehicleInspection::OVERALL as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ request('from') }}" class="input" aria-label="Desde"><input type="date" name="to" value="{{ request('to') }}" class="input" aria-label="Hasta"><button class="btn-primary">Filtrar</button></form>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Fecha</th><th>Vehículo</th><th>Resultado</th><th class="text-right">Kilometraje</th><th>Inspector</th><th>Próxima</th><th></th></tr></thead>
    <tbody>
    @forelse($inspections as $i)
        <tr><td>{{ $i->inspected_at->format('d/m/Y') }}</td><td class="font-semibold">{{ $i->vehicle?->ficha }}</td><td><span class="{{ \App\Models\VehicleInspection::OVERALL_BADGES[$i->overall_status] }}">{{ \App\Models\VehicleInspection::OVERALL[$i->overall_status] }}</span></td>
            <td class="text-right">{{ $i->odometer ? number_format($i->odometer, 1) : '—' }}</td><td>{{ $i->inspector?->name }}</td><td>{{ $i->next_due_at?->format('d/m/Y') }}</td><td class="text-right"><a href="{{ route('inspections.show', $i) }}" class="btn-secondary btn-sm">Ver</a></td></tr>
    @empty
        <tr><td colspan="7" class="py-10 text-center text-slate-500">No hay inspecciones.</td></tr>
    @endforelse
    </tbody></table></div></div>
<div class="mt-2">{{ $inspections->links() }}</div>
@endsection
