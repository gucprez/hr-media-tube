@extends('layouts.app')
@section('title', 'Recepciones de gasoil')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3"><div><a href="{{ route('fuel.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Combustible</a><h1 class="page-title">Recepciones de gasoil</h1></div>
    @can('fuel.receive')<a href="{{ route('fuel.receipts.create') }}" class="btn-success"><x-icon name="plus" class="h-4 w-4" /> Nueva recepción</a>@endcan</div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-[1fr_160px_160px_auto]"><input type="search" name="q" value="{{ request('q') }}" placeholder="N° recepción, suplidor o placa…" class="input" aria-label="Buscar"><input type="date" name="from" value="{{ request('from') }}" class="input" aria-label="Desde"><input type="date" name="to" value="{{ request('to') }}" class="input" aria-label="Hasta"><button class="btn-primary">Filtrar</button></form>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>N°</th><th>Fecha</th><th class="text-right">Metro inicial</th><th class="text-right">Metro final</th><th class="text-right">Galones</th><th class="text-right">Reserva antes</th><th class="text-right">Reserva después</th><th>Suplidor</th><th>Camión</th><th>Usuario</th></tr></thead>
    <tbody>
    @forelse($receipts as $r)
        <tr class="{{ $r->isVoided() ? 'opacity-60' : '' }}">
            <td><a href="{{ route('fuel.receipts.show', $r) }}" class="font-semibold text-brand hover:underline">{{ $r->receipt_number }}</a> @if($r->isVoided())<span class="badge-red">Anulada</span>@endif</td>
            <td>{{ $r->received_at->format('d/m/Y') }}</td><td class="text-right">{{ number_format($r->meter_start, 0) }}</td><td class="text-right">{{ number_format($r->meter_end, 0) }}</td>
            <td class="text-right font-semibold">{{ number_format($r->gallons_received, 1) }}</td><td class="text-right">{{ number_format($r->reserve_before, 1) }}</td><td class="text-right">{{ number_format($r->reserve_after, 1) }}</td>
            <td>{{ $r->supplier }}</td><td>{{ $r->truck_plate }}</td><td class="text-xs">{{ $r->user?->name }}</td>
        </tr>
    @empty
        <tr><td colspan="10" class="py-10 text-center text-slate-500">No hay recepciones.</td></tr>
    @endforelse
    </tbody></table></div></div>
<div class="mt-2">{{ $receipts->links() }}</div>
@endsection
