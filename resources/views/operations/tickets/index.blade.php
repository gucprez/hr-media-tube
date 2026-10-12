@extends('layouts.app')
@section('title', 'Tickets')
@section('content')
<div class="mb-4"><h1 class="page-title">Tickets</h1><p class="text-sm text-slate-500">Numeración correlativa. Los tickets anulados se conservan con usuario, fecha y motivo. Se emiten desde la ficha de cada furgón.</p></div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_1fr_auto]">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="N° ticket, furgón, chofer, conduce, ficha…" class="input" aria-label="Buscar">
    <select name="type" class="input" aria-label="Tipo"><option value="">Todos los tipos</option>@foreach(\App\Models\Ticket::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="status" class="input" aria-label="Estado"><option value="">Todos</option><option value="issued" @selected(request('status') === 'issued')>Emitidos</option><option value="voided" @selected(request('status') === 'voided')>Anulados</option></select>
    <input type="date" name="from" value="{{ request('from') }}" class="input" aria-label="Desde">
    <input type="date" name="to" value="{{ request('to') }}" class="input" aria-label="Hasta">
    <div class="flex gap-2"><button class="btn-primary flex-1">Filtrar</button><a href="{{ route('tickets.index') }}" class="btn-secondary" title="Limpiar">✕</a></div>
</form>
<div class="space-y-2 md:hidden">
    @forelse($tickets as $t)
        <a href="{{ route('tickets.show', $t) }}" class="card block p-3 {{ $t->isVoided() ? 'opacity-60' : '' }}">
            <div class="flex items-center justify-between gap-2"><p class="font-bold text-slate-900">{{ $t->type === 'fuel' ? 'Gasoil' : 'Materiales' }} N° {{ $t->number }}</p>@if($t->isVoided())<span class="badge-red">Anulado</span>@else<span class="badge-green">Emitido</span>@endif</div>
            <p class="text-sm text-slate-600">Furgón {{ $t->container_number }} · {{ $t->issued_at->format('d/m/Y') }} · {{ $t->driver_name }}</p>
        </a>
    @empty
        <div class="card card-body text-center text-slate-500">No hay tickets con esos filtros.</div>
    @endforelse
</div>
<div class="card hidden md:block"><div class="table-wrap"><table class="table">
    <thead><tr><th>N°</th><th>Tipo</th><th>Furgón</th><th>Fecha</th><th>Chofer</th><th>Ficha</th><th>Impresiones</th><th>Estado</th></tr></thead>
    <tbody>
    @forelse($tickets as $t)
        <tr class="{{ $t->isVoided() ? 'opacity-60' : '' }}">
            <td><a href="{{ route('tickets.show', $t) }}" class="font-semibold text-brand hover:underline">{{ $t->number }}</a></td>
            <td>{{ $t->type === 'fuel' ? 'Gasoil' : 'Materiales' }}</td><td>#{{ $t->container_number }}</td><td>{{ $t->issued_at->format('d/m/Y') }}</td>
            <td>{{ $t->driver_name }}</td><td>{{ $t->ficha }}</td><td>{{ $t->print_count }}</td>
            <td>@if($t->isVoided())<span class="badge-red">Anulado</span>@else<span class="badge-green">Emitido</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="8" class="py-10 text-center text-slate-500">No hay tickets con esos filtros.</td></tr>
    @endforelse
    </tbody></table></div></div>
<div class="mt-2">{{ $tickets->links() }}</div>
@endsection
