@extends('layouts.app')
@section('title', 'Nuevo despacho de gasoil')
@section('content')
<div class="mb-4"><a href="{{ route('fuel.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Combustible</a><h1 class="page-title">Nuevo despacho de gasoil</h1><p class="text-sm text-slate-500">Digitalización de la hoja «Control gasoil»: ficha, inicio, final, galones y kilometraje.</p></div>
<form method="POST" action="{{ route('fuel.dispatches.store') }}" enctype="multipart/form-data" class="space-y-6"
      x-data="{ s: '{{ old('reading_start', $lastReading) }}', e: '{{ old('reading_end') }}', reserve: {{ $tank->current_gallons }}, vehicles: @js($vehicles->mapWithKeys(fn ($v) => [$v->id => $v->odometer])), vehicle: '{{ old('vehicle_id', $selected ?: '') }}', km: '{{ old('odometer') }}',
        get gal() { const g = parseFloat(this.e) - parseFloat(this.s); return isFinite(g) && g > 0 ? Math.round(g * 100) / 100 : 0 },
        get after() { return Math.round((this.reserve - this.gal) * 100) / 100 },
        pick() { if (this.vehicle && !this.km) this.km = this.vehicles[this.vehicle] || '' },
        f(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 2 }) } }" x-init="pick()">
    @csrf
    <div class="card card-body space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="dispatched_at" label="Fecha" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
            <div>
                <label for="vehicle_id" class="label">Ficha (vehículo) <span class="text-red-500">*</span></label>
                <select id="vehicle_id" name="vehicle_id" x-model="vehicle" @change="km = ''; pick()" class="input" required><option value="">Seleccione…</option>@foreach($vehicles as $v)<option value="{{ $v->id }}">{{ $v->ficha }}{{ $v->plate ? ' · '.$v->plate : '' }}</option>@endforeach</select>
                @error('vehicle_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div><label for="odometer" class="label">Kilometraje</label><input id="odometer" name="odometer" x-model="km" type="number" step="0.1" min="0" inputmode="decimal" class="input">@error('odometer')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label for="reading_start" class="label">Lectura inicial (inicio) <span class="text-red-500">*</span></label><input id="reading_start" name="reading_start" x-model="s" type="number" step="0.1" min="0" inputmode="decimal" class="input" required>@error('reading_start')<p class="field-error">{{ $message }}</p>@enderror @if($lastReading !== null)<p class="mt-1 text-xs text-slate-500">Última lectura final registrada: {{ number_format($lastReading, 1) }}</p>@endif</div>
            <div><label for="reading_end" class="label">Lectura final <span class="text-red-500">*</span></label><input id="reading_end" name="reading_end" x-model="e" type="number" step="0.1" min="0" inputmode="decimal" class="input" required>@error('reading_end')<p class="field-error">{{ $message }}</p>@enderror</div>
        </div>
        <div class="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-3">
            <div><p class="text-xs font-semibold uppercase text-slate-500">Reserva antes</p><p class="text-xl font-bold text-slate-900"><span x-text="f(reserve)"></span> gal</p></div>
            <div><p class="text-xs font-semibold uppercase text-slate-500">Galones despachados</p><p class="text-xl font-bold text-blue-700">−<span x-text="f(gal)"></span> gal</p></div>
            <div><p class="text-xs font-semibold uppercase text-slate-500">Nueva reserva</p><p class="text-xl font-bold" :class="after < 0 ? 'text-red-600' : 'text-slate-900'"><span x-text="f(after)"></span> gal</p><p x-show="after < 0" x-cloak class="text-xs text-red-600">Reserva insuficiente</p></div>
        </div>
        <x-field name="notes" label="Observaciones" type="textarea" />
    </div>
    <div class="card card-body"><x-photo-picker name="photos" label="Foto del contador (opcional)" :max="3" /></div>
    <div class="flex gap-2"><button class="btn-primary">Registrar despacho</button><a href="{{ route('fuel.index') }}" class="btn-secondary">Cancelar</a></div>
</form>
@endsection
