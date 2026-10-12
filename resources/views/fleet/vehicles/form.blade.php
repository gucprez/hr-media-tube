@extends('layouts.app')
@section('title', $vehicle->exists ? 'Editar vehículo' : 'Nuevo vehículo')
@section('content')
<div class="mb-4 flex items-center justify-between"><h1 class="page-title">{{ $vehicle->exists ? 'Editar vehículo '.$vehicle->ficha : 'Nuevo vehículo' }}</h1><a href="{{ $vehicle->exists ? route('vehicles.show', $vehicle) : route('vehicles.index') }}" class="btn-secondary">Volver</a></div>
<form method="POST" enctype="multipart/form-data" action="{{ $vehicle->exists ? route('vehicles.update', $vehicle) : route('vehicles.store') }}" class="space-y-6">
    @csrf @if($vehicle->exists) @method('PUT') @endif
    <div class="card card-body grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-field name="ficha" label="Ficha" :value="$vehicle->ficha" required hint="Ej.: F1, F2, F3" />
        <x-field name="plate" label="Placa" :value="$vehicle->plate" />
        <div>
            <label for="vehicle_type_id" class="label">Tipo</label>
            <select id="vehicle_type_id" name="vehicle_type_id" class="input"><option value="">—</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected(old('vehicle_type_id', $vehicle->vehicle_type_id) == $t->id)>{{ $t->name }}</option>@endforeach</select>
        </div>
        <x-field name="brand" label="Marca" :value="$vehicle->brand" />
        <x-field name="model" label="Modelo" :value="$vehicle->model" />
        <x-field name="year" label="Año" type="number" min="1950" :value="$vehicle->year" inputmode="numeric" />
        <div>
            <label for="usual_driver_id" class="label">Chofer habitual</label>
            <select id="usual_driver_id" name="usual_driver_id" class="input"><option value="">—</option>@foreach($drivers as $d)<option value="{{ $d->id }}" @selected(old('usual_driver_id', $vehicle->usual_driver_id) == $d->id)>{{ $d->name }}</option>@endforeach</select>
        </div>
        <x-field name="odometer" label="Kilometraje actual" type="number" step="0.1" min="0" inputmode="decimal" :value="$vehicle->odometer" />
        <div>
            <label for="status" class="label">Estado</label>
            <select id="status" name="status" class="input">@foreach(\App\Models\Vehicle::STATUSES as $k => $l)<option value="{{ $k }}" @selected(old('status', $vehicle->status) === $k)>{{ $l }}</option>@endforeach</select>
        </div>
        <div class="sm:col-span-2 lg:col-span-3"><x-field name="notes" label="Observaciones" type="textarea" :value="$vehicle->notes" /></div>
    </div>
    <div class="card card-body"><x-photo-picker name="photo" label="Fotografía del vehículo" :multiple="false" :max="1" /></div>
    <div class="flex gap-2"><button class="btn-primary">Guardar vehículo</button></div>
</form>
@can('vehicles.edit')
<form method="POST" action="{{ route('vehicle-types.store') }}" class="card card-body mt-6 flex flex-wrap items-end gap-3">@csrf<x-field name="name" label="Crear nuevo tipo de vehículo" class="min-w-[220px] flex-1" /><button class="btn-secondary">Agregar tipo</button></form>
@endcan
@endsection
