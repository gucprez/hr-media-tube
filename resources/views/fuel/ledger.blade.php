@extends('layouts.app')
@section('title', 'Libro de la reserva')
@section('content')
<div class="mb-4"><a href="{{ route('fuel.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Combustible</a><h1 class="page-title">Libro de la reserva de gasoil</h1><p class="text-sm text-slate-500">Cada cambio de reserva con su valor anterior y resultante. Reserva actual: <b>{{ number_format($tank->current_gallons, 1) }} gal</b>.</p></div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-[1fr_160px_160px_auto]">
    <select name="type" class="input" aria-label="Tipo"><option value="">Todos los tipos</option>@foreach(\App\Models\FuelLedger::LABELS as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ request('from') }}" class="input" aria-label="Desde"><input type="date" name="to" value="{{ request('to') }}" class="input" aria-label="Hasta"><button class="btn-primary">Filtrar</button></form>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Fecha</th><th>Tipo</th><th>Vehículo</th><th class="text-right">Cambio</th><th class="text-right">Antes</th><th class="text-right">Después</th><th>Usuario</th><th>Motivo</th></tr></thead>
    <tbody>
    @forelse($lines as $l)
        <tr><td class="whitespace-nowrap text-xs">{{ $l->occurred_at->format('d/m/Y H:i') }}</td><td><span class="{{ $l->delta_gallons > 0 ? 'badge-green' : 'badge-blue' }}">{{ $l->type_label }}</span></td><td>{{ $l->vehicle?->ficha }}</td>
            <td class="text-right font-semibold {{ $l->delta_gallons > 0 ? 'text-emerald-700' : 'text-blue-700' }}">{{ $l->delta_gallons > 0 ? '+' : '' }}{{ number_format($l->delta_gallons, 1) }}</td>
            <td class="text-right">{{ number_format($l->reserve_before, 1) }}</td><td class="text-right font-semibold">{{ number_format($l->reserve_after, 1) }}</td><td class="text-xs">{{ $l->user?->name }}</td><td class="max-w-[220px] truncate text-xs text-slate-500">{{ $l->reason }}</td></tr>
    @empty
        <tr><td colspan="8" class="py-10 text-center text-slate-500">Sin movimientos de reserva.</td></tr>
    @endforelse
    </tbody></table></div></div>
<div class="mt-2">{{ $lines->links() }}</div>
@endsection
