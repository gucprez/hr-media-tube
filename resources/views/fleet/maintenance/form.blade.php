@extends('layouts.app')
@section('title', 'Registrar mantenimiento')
@section('content')
<div class="mb-4"><a href="{{ route('maintenance.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Mantenimiento</a><h1 class="page-title">Registrar mantenimiento / reparación</h1><p class="text-sm text-slate-500">Las piezas que agregue saldrán del inventario y quedarán asociadas al vehículo y a la reparación.</p></div>
<form method="POST" action="{{ route('maintenance.store') }}" enctype="multipart/form-data" class="space-y-6"
      x-data="docLines({ mode: 'maintenance', warehouse: {{ (int) old('warehouse_id', $defaultWarehouse?->id) }}, urls: { lookup: '{{ route('inventory.lookup') }}', preview: '{{ route('inventory.preview') }}' }, initial: @js($oldLines) })">
    @csrf
    <div class="card card-body space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div><label for="vehicle_id" class="label">Vehículo (ficha) <span class="text-red-500">*</span></label>
                <select id="vehicle_id" name="vehicle_id" class="input" required><option value="">Seleccione…</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(old('vehicle_id', $selectedVehicle) == $v->id)>{{ $v->ficha }}{{ $v->plate ? ' · '.$v->plate : '' }}</option>@endforeach</select>@error('vehicle_id')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label for="incident_id" class="label">Avería que se repara</label>
                <select id="incident_id" name="incident_id" class="input"><option value="">Mantenimiento sin avería (preventivo)</option>@foreach($incidents as $i)<option value="{{ $i->id }}" @selected(old('incident_id', $incident?->id) == $i->id)>{{ $i->vehicle?->ficha }} — {{ $i->title }}</option>@endforeach</select>@error('incident_id')<p class="field-error">{{ $message }}</p>@enderror</div>
            <x-field name="performed_at" label="Fecha de reparación" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
            <x-field name="responsible" label="Responsable" :value="auth()->user()->name" />
            <x-field name="odometer" label="Kilometraje" type="number" step="0.1" min="0" inputmode="decimal" />
            <div><label for="warehouse_id" class="label">Almacén de las piezas</label>
                <select id="warehouse_id" name="warehouse_id" x-model.number="warehouse" @change="refreshAll()" class="input">@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
        </div>
        <x-field name="description" label="Descripción de la reparación" type="textarea" required />
        <x-field name="notes" label="Observaciones" type="textarea" />
    </div>
    @include('inventory._lines-editor', ['field' => 'parts', 'title' => 'Piezas y materiales utilizados (opcional)'])
    @if($canNegative)<label class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><input type="checkbox" name="allow_negative" value="1" class="mt-0.5 rounded border-red-300"> Autorización especial: permitir existencia negativa.</label>@endif
    <div class="card card-body"><x-photo-picker name="photos" label="Fotografías de la reparación" :max="5" /></div>
    <div class="sticky bottom-0 -mx-4 flex gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0"><button class="btn-success flex-1 sm:flex-none">Guardar mantenimiento</button><a href="{{ route('maintenance.index') }}" class="btn-secondary">Cancelar</a></div>
</form>
@endsection
