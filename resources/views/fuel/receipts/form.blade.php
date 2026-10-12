@extends('layouts.app')
@section('title', 'Nueva recepción de gasoil')
@section('content')
<div class="mb-4"><a href="{{ route('fuel.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Combustible</a><h1 class="page-title">Nueva recepción de gasoil</h1><p class="text-sm text-slate-500">Los galones y la reserva resultante se calculan automáticamente.</p></div>
<form method="POST" action="{{ route('fuel.receipts.store') }}" enctype="multipart/form-data" class="space-y-6"
      x-data="{ s: '{{ old('meter_start') }}', e: '{{ old('meter_end') }}', reserve: {{ $tank->current_gallons }}, cap: {{ $tank->capacity_gallons ?: 'null' }},
        get gal() { const g = parseFloat(this.e) - parseFloat(this.s); return isFinite(g) && g > 0 ? Math.round(g * 100) / 100 : 0 },
        get after() { return Math.round((this.reserve + this.gal) * 100) / 100 },
        f(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 2 }) } }">
    @csrf
    <div class="card card-body space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="received_at" label="Fecha" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
            <x-field name="receipt_number" label="No. recepción" :placeholder="'Automático: '.$nextNumber" hint="Déjelo vacío para usar el correlativo." />
            <x-field name="supplier" label="Suplidor" />
            <x-field name="truck_plate" label="Camión / placa" />
            <div><label for="meter_start" class="label">Metro del camión — inicial <span class="text-red-500">*</span></label><input id="meter_start" name="meter_start" x-model="s" type="number" step="0.01" min="0" inputmode="decimal" class="input" required>@error('meter_start')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label for="meter_end" class="label">Metro del camión — final <span class="text-red-500">*</span></label><input id="meter_end" name="meter_end" x-model="e" type="number" step="0.01" min="0" inputmode="decimal" class="input" required>@error('meter_end')<p class="field-error">{{ $message }}</p>@enderror</div>
        </div>
        <div class="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-3">
            <div><p class="text-xs font-semibold uppercase text-slate-500">Reserva antes</p><p class="text-xl font-bold text-slate-900"><span x-text="f(reserve)"></span> gal</p></div>
            <div><p class="text-xs font-semibold uppercase text-slate-500">Gasoil recibido</p><p class="text-xl font-bold text-emerald-700">+<span x-text="f(gal)"></span> gal</p></div>
            <div><p class="text-xs font-semibold uppercase text-slate-500">Reserva resultante</p><p class="text-xl font-bold" :class="cap && after > cap ? 'text-red-600' : 'text-slate-900'"><span x-text="f(after)"></span> gal</p><p x-show="cap && after > cap" x-cloak class="text-xs text-red-600">Excede la capacidad del tanque</p></div>
        </div>
        <x-field name="notes" label="Observaciones" type="textarea" />
    </div>
    <div class="card card-body space-y-5">
        <h2 class="font-semibold text-slate-900">Fotografías</h2>
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach($photoTypes as $key => $label)<x-photo-picker :name="$key" :label="$label" :max="3" />@endforeach
        </div>
    </div>
    <div class="flex gap-2"><button class="btn-success">Registrar recepción</button><a href="{{ route('fuel.index') }}" class="btn-secondary">Cancelar</a></div>
</form>
@endsection
