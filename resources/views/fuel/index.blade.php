@extends('layouts.app')
@section('title', 'Combustible')
@section('content')
@php $g = fn ($n, $d = 1) => number_format($n, $d, '.', ','); @endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Combustible / Gasoil</h1><p class="text-sm text-slate-500">Reserva, recepciones y despachos. Cada cambio queda en el libro de la reserva.</p></div>
    <div class="flex flex-wrap gap-2">
        @can('fuel.receive')<a href="{{ route('fuel.receipts.create') }}" class="btn-success"><x-icon name="arrow-down" class="h-4 w-4" /> Recepción</a>@endcan
        @can('fuel.dispatch')<a href="{{ route('fuel.dispatches.create') }}" class="btn-primary"><x-icon name="arrow-up" class="h-4 w-4" /> Despacho</a>@endcan
        @can('fuel.adjust')<a href="{{ route('fuel.adjust') }}" class="btn-secondary"><x-icon name="scale" class="h-4 w-4" /> Ajustar reserva</a>@endcan
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-[1.4fr_1fr_1fr]">
    <div class="card card-body">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Reserva de gasoil · {{ $tank->name }}</p>
        <p class="mt-1 text-4xl font-bold text-slate-900">{{ $g($tank->current_gallons) }} <span class="text-lg font-semibold text-slate-500">galones</span></p>
        @if($tank->capacity_gallons)
            <div class="mt-3 h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $tank->percent < 15 ? 'bg-red-500' : ($tank->percent < 30 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $tank->percent }}%"></div></div>
            <p class="mt-1 text-xs text-slate-500">{{ $tank->percent }}% de {{ $g($tank->capacity_gallons, 0) }} gal de capacidad</p>
        @else
            <p class="mt-2 text-xs text-slate-500">Sin capacidad registrada.</p>
        @endif
        @can('fuel.adjust')
        <form method="POST" action="{{ route('fuel.tank.update') }}" class="mt-4 grid gap-2 border-t border-slate-100 pt-3 sm:grid-cols-[1fr_140px_auto] sm:items-end">@csrf @method('PUT')
            <x-field name="name" label="Tanque" :value="$tank->name" /><x-field name="capacity_gallons" label="Capacidad (gal)" type="number" step="1" min="0" :value="$tank->capacity_gallons" /><button class="btn-secondary btn-sm">Guardar</button>
        </form>
        @endcan
    </div>
    <x-stat label="Recibido este mes" :value="$g($month['received']).' gal'" icon="arrow-down" tone="green" :href="route('fuel.receipts.index', ['from' => now()->startOfMonth()->toDateString()])" />
    <x-stat label="Despachado este mes" :value="$g($month['dispatched']).' gal'" icon="arrow-up" tone="blue" :href="route('fuel.dispatches.index', ['from' => now()->startOfMonth()->toDateString()])" />
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-2">
    <div class="card">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3"><h2 class="font-semibold text-slate-900">Últimas recepciones</h2><a href="{{ route('fuel.receipts.index') }}" class="text-sm font-medium text-brand hover:underline">Ver todas</a></div>
        @forelse($receipts as $r)
            <a href="{{ route('fuel.receipts.show', $r) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50 {{ $r->isVoided() ? 'opacity-60' : '' }}">
                <div class="min-w-0"><p class="text-sm font-semibold text-slate-800">{{ $r->receipt_number }} @if($r->isVoided())<span class="badge-red">Anulada</span>@endif</p><p class="truncate text-xs text-slate-500">{{ $r->received_at->format('d/m/Y') }} · {{ $r->supplier ?? 'Sin suplidor' }}</p></div>
                <div class="text-right"><p class="text-sm font-bold text-emerald-700">+{{ $g($r->gallons_received) }} gal</p><p class="text-xs text-slate-500">Reserva {{ $g($r->reserve_after) }}</p></div>
            </a>
        @empty<p class="px-5 py-8 text-center text-sm text-slate-500">Aún no hay recepciones.</p>@endforelse
    </div>
    <div class="card">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3"><h2 class="font-semibold text-slate-900">Últimos despachos</h2><a href="{{ route('fuel.dispatches.index') }}" class="text-sm font-medium text-brand hover:underline">Ver todos</a></div>
        @forelse($dispatches as $d)
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 last:border-0 {{ $d->isVoided() ? 'opacity-60' : '' }}">
                <div class="min-w-0"><p class="text-sm font-semibold text-slate-800">{{ $d->vehicle?->ficha ?? 'Sin vehículo' }} @if($d->isVoided())<span class="badge-red">Anulado</span>@endif</p><p class="truncate text-xs text-slate-500">{{ $d->dispatched_at->format('d/m/Y') }} · {{ $d->odometer ? number_format($d->odometer, 1).' km' : 'sin km' }}</p></div>
                <div class="text-right"><p class="text-sm font-bold text-blue-700">−{{ $g($d->gallons) }} gal</p><p class="text-xs text-slate-500">Reserva {{ $g($d->reserve_after) }}</p></div>
            </div>
        @empty<p class="px-5 py-8 text-center text-sm text-slate-500">Aún no hay despachos.</p>@endforelse
    </div>
</div>
<p class="mt-4 text-sm"><a href="{{ route('fuel.ledger') }}" class="font-medium text-brand hover:underline">Ver libro completo de la reserva →</a></p>
@endsection
