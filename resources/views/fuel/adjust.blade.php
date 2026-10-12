@extends('layouts.app')
@section('title', 'Ajustar reserva de gasoil')
@section('content')
<div class="mb-4"><a href="{{ route('fuel.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Combustible</a><h1 class="page-title">Ajuste de la reserva de gasoil</h1><p class="text-sm text-slate-500">Registre la medición física del tanque. La diferencia queda como movimiento con motivo y usuario (también se usa para fijar la reserva inicial).</p></div>
<form method="POST" action="{{ route('fuel.adjust.store') }}" class="card card-body max-w-xl space-y-4" x-data="{ physical: '{{ old('physical') }}', system: {{ $tank->current_gallons }}, get diff() { const d = parseFloat(this.physical) - this.system; return isFinite(d) ? Math.round(d * 100) / 100 : 0 } }">
    @csrf
    <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-semibold uppercase text-slate-500">Reserva según el sistema</p><p class="text-2xl font-bold text-slate-900">{{ number_format($tank->current_gallons, 1) }} gal</p></div>
    <div><label for="physical" class="label">Medición física (galones) <span class="text-red-500">*</span></label><input id="physical" name="physical" x-model="physical" type="number" step="0.01" min="0" inputmode="decimal" class="input" required>@error('physical')<p class="field-error">{{ $message }}</p>@enderror
        <p class="mt-1 text-sm">Diferencia: <b :class="diff < 0 ? 'text-red-600' : (diff > 0 ? 'text-emerald-700' : '')" x-text="(diff > 0 ? '+' : '') + diff.toFixed(2) + ' gal'"></b></p></div>
    <x-field name="reason" label="Motivo" required hint="Ej.: medición con varilla, reserva inicial, evaporación" />
    <button class="btn-primary">Registrar ajuste</button>
</form>
@endsection
