@extends('layouts.app')
@section('title', 'Reportar avería')
@section('content')
<div class="mb-4"><a href="{{ route('incidents.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Averías</a><h1 class="page-title">Reportar avería</h1></div>
<form method="POST" action="{{ route('incidents.store') }}" enctype="multipart/form-data" class="space-y-6">
    @csrf
    <div class="card card-body space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div><label for="vehicle_id" class="label">Vehículo (ficha) <span class="text-red-500">*</span></label>
                <select id="vehicle_id" name="vehicle_id" class="input" required><option value="">Seleccione…</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(old('vehicle_id', $selected) == $v->id)>{{ $v->ficha }}{{ $v->plate ? ' · '.$v->plate : '' }}</option>@endforeach</select>@error('vehicle_id')<p class="field-error">{{ $message }}</p>@enderror</div>
            <x-field name="odometer" label="Kilometraje" type="number" step="0.1" min="0" inputmode="decimal" />
            <div><label class="label">Prioridad <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-4 gap-1.5">
                    @foreach(['low' => ['Baja', 'has-[:checked]:border-slate-500 has-[:checked]:bg-slate-100'], 'medium' => ['Media', 'has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50'], 'high' => ['Alta', 'has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50'], 'critical' => ['Crítica', 'has-[:checked]:border-red-500 has-[:checked]:bg-red-50']] as $v => [$l, $c])
                        <label class="flex min-h-10 cursor-pointer items-center justify-center rounded-lg border border-slate-300 text-sm font-medium {{ $c }}"><input type="radio" name="priority" value="{{ $v }}" class="sr-only" @checked(old('priority', 'medium') === $v)> {{ $l }}</label>
                    @endforeach
                </div>@error('priority')<p class="field-error">{{ $message }}</p>@enderror</div>
        </div>
        <x-field name="title" label="Problema" required hint="Ej.: Luz trasera derecha dañada" />
        <x-field name="description" label="Observaciones" type="textarea" />
    </div>
    <div class="card card-body"><x-photo-picker name="photos" label="Fotografías" :max="5" /></div>
    <div class="flex gap-2"><button class="btn-primary">Reportar avería</button><a href="{{ route('incidents.index') }}" class="btn-secondary">Cancelar</a></div>
</form>
@endsection
