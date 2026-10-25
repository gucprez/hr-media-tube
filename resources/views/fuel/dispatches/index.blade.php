@extends('layouts.app')
@section('title', 'Despachos de gasoil')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3"><div><a href="{{ route('fuel.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Combustible</a><h1 class="page-title">Control de gasoil (despachos)</h1></div>
    @can('fuel.dispatch')<a href="{{ route('fuel.dispatches.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Nuevo despacho</a>@endcan</div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-[1fr_160px_160px_auto]">
    <select name="vehicle" class="input" aria-label="Vehículo"><option value="">Todos los vehículos</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') == $v->id)>{{ $v->ficha }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ request('from') }}" class="input" aria-label="Desde"><input type="date" name="to" value="{{ request('to') }}" class="input" aria-label="Hasta"><button class="btn-primary">Filtrar</button></form>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Fecha</th><th>Ficha</th><th class="text-right">Inicio</th><th class="text-right">Final</th><th class="text-right">Glns</th><th class="text-right">Kilometraje</th><th class="text-right">Reserva</th><th>Origen</th><th>Usuario</th><th></th></tr></thead>
    <tbody>
    @forelse($dispatches as $d)
        <tr class="{{ $d->isVoided() ? 'opacity-60' : '' }}">
            <td>{{ $d->dispatched_at->format('d/m/Y') }}</td>
            <td class="font-semibold">{{ $d->vehicle?->ficha ?? '—' }} @if($d->isVoided())<span class="badge-red">Anulado</span>@endif</td>
            <td class="text-right">{{ $d->reading_start !== null ? number_format($d->reading_start, 1) : '—' }}</td><td class="text-right">{{ $d->reading_end !== null ? number_format($d->reading_end, 1) : '—' }}</td>
            <td class="text-right font-semibold">{{ number_format($d->gallons, 1) }}</td><td class="text-right">{{ $d->odometer !== null ? number_format($d->odometer, 1) : '—' }}</td>
            <td class="text-right text-xs text-slate-500">{{ number_format($d->reserve_before, 1) }} → {{ number_format($d->reserve_after, 1) }}</td>
            <td class="text-xs">@if($d->container)<a href="{{ route('containers.show', $d->container) }}" class="text-brand hover:underline">Furgón #{{ $d->container->number }}</a>@else Control manual @endif</td>
            <td class="text-xs">{{ $d->user?->name }}</td>
            <td>@can('fuel.edit')@unless($d->isVoided())@if($d->origin !== 'container')<a href="{{ route('fuel.dispatches.edit', $d) }}" class="btn-secondary btn-sm mb-1">Editar</a>@endif @endunless @endcan
            @can('fuel.void')@unless($d->isVoided())@if($d->origin !== 'container')
                <form method="POST" action="{{ route('fuel.dispatches.void', $d) }}" class="flex gap-1" @submit="if (!confirm('¿Anular el despacho? La reserva se restituye.')) $event.preventDefault()">@csrf<input name="reason" placeholder="Motivo" class="input !min-h-8 !w-32 !py-1 text-xs" required minlength="5" aria-label="Motivo"><button class="btn-danger btn-sm">Anular</button></form>
            @endif @endunless @endcan</td>
        </tr>
    @empty
        <tr><td colspan="10" class="py-10 text-center text-slate-500">No hay despachos.</td></tr>
    @endforelse
    </tbody></table></div></div>
<div class="mt-2">{{ $dispatches->links() }}</div>
@endsection
