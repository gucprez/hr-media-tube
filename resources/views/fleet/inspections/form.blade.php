@extends('layouts.app')
@section('title', 'Nueva inspección')
@section('content')
<div class="mb-4"><a href="{{ route('inspections.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Inspecciones</a><h1 class="page-title">Inspección mensual de camión</h1></div>
<form method="POST" action="{{ route('inspections.store') }}" enctype="multipart/form-data" class="space-y-6">
    @csrf
    <div class="card card-body grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div><label for="vehicle_id" class="label">Vehículo (ficha) <span class="text-red-500">*</span></label>
            <select id="vehicle_id" name="vehicle_id" class="input" required><option value="">Seleccione…</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(old('vehicle_id', $selected) == $v->id)>{{ $v->ficha }}{{ $v->plate ? ' · '.$v->plate : '' }}</option>@endforeach</select>
            @error('vehicle_id')<p class="field-error">{{ $message }}</p>@enderror</div>
        <x-field name="inspected_at" label="Fecha" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
        <x-field name="odometer" label="Kilometraje" type="number" step="0.1" min="0" inputmode="decimal" />
        <p class="text-xs text-slate-500 sm:col-span-2 lg:col-span-3">Inspector: <b>{{ auth()->user()->name }}</b> (se registra automáticamente).</p>
    </div>

    <div class="space-y-3">
        @foreach($items as $it)
        <div class="card card-body !py-3" x-data="{ s: '{{ old('items.'.$it->id.'.status', 'good') }}', open: false }" x-init="open = s !== 'good'">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-semibold text-slate-900">{{ $it->name }}</p>
                <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Estado de {{ $it->name }}">
                    @foreach(['good' => ['Bien', 'has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 has-[:checked]:text-emerald-800'], 'attention' => ['Atención', 'has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50 has-[:checked]:text-amber-800'], 'damaged' => ['Dañado', 'has-[:checked]:border-red-500 has-[:checked]:bg-red-50 has-[:checked]:text-red-800'], 'na' => ['N/A', 'has-[:checked]:border-slate-500 has-[:checked]:bg-slate-100 has-[:checked]:text-slate-800']] as $val => [$label, $cls])
                        <label class="flex min-h-10 cursor-pointer items-center rounded-lg border border-slate-300 px-3 text-sm font-medium text-slate-600 {{ $cls }}">
                            <input type="radio" name="items[{{ $it->id }}][status]" value="{{ $val }}" x-model="s" @change="open = s !== 'good'" class="sr-only" required> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
            <button type="button" @click="open = !open" class="mt-1 self-start text-xs font-medium text-brand" x-text="open ? 'Ocultar observación y foto' : 'Agregar observación o foto'"></button>
            <div x-show="open" x-cloak class="mt-3 space-y-3 border-t border-slate-100 pt-3">
                <x-field :name="'items['.$it->id.'][notes]'" label="Observación" />
                <x-photo-picker :name="'photo_'.$it->id" label="Fotografía" :max="3" />
            </div>
        </div>
        @endforeach
        @error('items')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="card card-body space-y-3">
        <x-field name="notes" label="Observaciones generales" type="textarea" />
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="create_incidents" value="1" class="mt-0.5 rounded border-slate-300" @checked(old('create_incidents', true))> Reportar automáticamente una avería por cada punto marcado como <b>Dañado</b>.</label>
    </div>
    <div class="sticky bottom-0 -mx-4 flex gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0"><button class="btn-primary flex-1 sm:flex-none">Guardar inspección</button><a href="{{ route('inspections.index') }}" class="btn-secondary">Cancelar</a></div>
</form>
@endsection
