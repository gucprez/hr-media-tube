@extends('layouts.app')
@section('title', $container->exists ? 'Editar furgón '.$container->number : 'Nuevo furgón')
@section('content')
<div class="mb-4"><h1 class="page-title">{{ $container->exists ? 'Editar furgón #'.$container->number : 'Nuevo furgón' }}</h1><p class="text-sm text-slate-500">Registre el furgón y los materiales que utilizará. Al cerrarlo se descontarán del inventario.</p></div>

<form method="POST" action="{{ $container->exists ? route('containers.update', $container) : route('containers.store') }}" class="space-y-6"
      x-data="docLines({ mode: 'container', warehouse: {{ (int) old('warehouse_id', $container->warehouse_id ?? $defaultWarehouse?->id) }}, urls: { lookup: '{{ route('inventory.lookup') }}', preview: '{{ route('inventory.preview') }}' }, initial: @js($oldLines) })">
    @csrf @if($container->exists) @method('PUT') @endif

    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Datos del furgón</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="number" label="Número de furgón" :value="$container->number" :placeholder="'Automático: '.$nextNumber" hint="Déjelo vacío para usar el correlativo." />
            <x-field name="date" label="Fecha" type="date" :value="optional($container->date)->toDateString()" required />
            <div>
                <label for="warehouse_id" class="label">Almacén de origen <span class="text-red-500">*</span></label>
                <select id="warehouse_id" name="warehouse_id" x-model.number="warehouse" @change="refreshAll()" class="input" required>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select>
                @error('warehouse_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="driver_id" class="label">Chofer</label>
                <select id="driver_id" name="driver_id" class="input"><option value="">— Escribir nombre abajo —</option>@foreach($drivers as $d)<option value="{{ $d->id }}" @selected(old('driver_id', $container->driver_id) == $d->id)>{{ $d->name }}</option>@endforeach</select>
                @error('driver_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <x-field name="driver_name" label="Nombre del chofer (si no está en la lista)" :value="$container->driver_name" />
            <x-field name="phone" label="Teléfono" :value="$container->phone" inputmode="tel" />
            <div>
                <label for="vehicle_id" class="label">Vehículo (ficha)</label>
                <select id="vehicle_id" name="vehicle_id" class="input"><option value="">Sin vehículo</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(old('vehicle_id', $container->vehicle_id) == $v->id)>{{ $v->ficha }}{{ $v->plate ? ' · '.$v->plate : '' }}</option>@endforeach</select>
                @error('vehicle_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <x-field name="conduce" label="Conduce" :value="$container->conduce" />
            <div>
                <label for="responsible_id" class="label">Responsable</label>
                <select id="responsible_id" name="responsible_id" class="input"><option value="">—</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected(old('responsible_id', $container->responsible_id ?? auth()->id()) == $u->id)>{{ $u->name }}</option>@endforeach</select>
            </div>
            <x-field name="fuel_gallons" label="Gasoil (galones)" type="number" step="0.1" min="0" inputmode="decimal" :value="$container->fuel_gallons ?: ''" hint="Se descuenta de la reserva al cerrar el furgón." />
        </div>
        <x-field name="notes" label="Observaciones" type="textarea" :value="$container->notes" />

        <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
            <input type="hidden" name="deduct_on_close" value="0">
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="deduct_on_close" value="1" class="mt-0.5 rounded border-slate-300" @checked((bool) filter_var(old('deduct_on_close', $container->exists ? $container->deduct_on_close : true), FILTER_VALIDATE_BOOLEAN))>
                <span>
                    <b class="text-slate-900">Descontar estos materiales del inventario al cerrar el furgón</b>
                    <span class="mt-1 block text-slate-600">Si ya los descuentas con una <b>salida de almacén</b> (elige este furgón como destino), no se descuentan dos veces: al cerrar solo se descuenta lo que falte.</span>
                    <span class="mt-1 block text-slate-600">Desmárcalo si quieres usar el furgón solo como <b>lista de materiales</b>: el cierre no tocará el inventario.</span>
                </span>
            </label>
        </div>
    </div>

    @include('inventory._lines-editor', ['field' => 'materials', 'title' => 'Materiales utilizados'])

    <div class="sticky bottom-0 -mx-4 flex gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0">
        <button class="btn-primary flex-1 sm:flex-none">Guardar furgón</button>
        <a href="{{ $container->exists ? route('containers.show', $container) : route('containers.index') }}" class="btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
