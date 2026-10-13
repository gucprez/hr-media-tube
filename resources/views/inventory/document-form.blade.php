@extends('layouts.app')
@section('title', isset($movement) && $movement ? 'Editar '.$movement->number : ['entry' => 'Nueva entrada', 'exit' => 'Nueva salida', 'adjustment' => 'Ajuste de inventario'][$mode])
@section('content')
@php
$cfg = [
    'entry' => ['title' => 'Nueva entrada de almacén', 'sub' => 'Registre mercancía que ingresa al almacén. Puede agregar varios productos en el mismo documento.', 'action' => route('entries.store'), 'button' => 'Registrar entrada', 'btn' => 'btn-success'],
    'exit' => ['title' => 'Nueva salida de almacén', 'sub' => 'Registre materiales que salen del almacén. No se permite dejar el stock en negativo.', 'action' => route('exits.store'), 'button' => 'Registrar salida', 'btn' => 'btn-primary'],
    'adjustment' => ['title' => 'Ajuste de inventario', 'sub' => 'Indique el conteo físico. El sistema calcula la diferencia y la registra como movimiento; nunca se sobrescribe la existencia.', 'action' => route('adjustments.store'), 'button' => 'Registrar ajuste', 'btn' => 'btn-primary'],
][$mode];
$editing = isset($movement) && $movement;
if ($editing) {
    $cfg['title'] = 'Editar '.mb_strtolower($movement->type_label).' '.$movement->number;
    $cfg['sub'] = 'Al guardar, este documento se anula y se crea uno nuevo con los datos corregidos. Los dos quedan ligados y el stock se ajusta solo por la diferencia.';
    $cfg['action'] = route('movements.update', $movement);
    $cfg['button'] = 'Guardar cambios';
}
@endphp

<div class="mb-4"><h1 class="page-title">{{ $cfg['title'] }}</h1><p class="text-sm text-slate-500">{{ $cfg['sub'] }}</p></div>

<form method="POST" action="{{ $cfg['action'] }}" enctype="multipart/form-data" class="space-y-6"
      x-data="docLines({ mode: '{{ $mode }}', warehouse: {{ (int) old('warehouse_id', $defaultWarehouse?->id) }}, urls: { lookup: '{{ route('inventory.lookup') }}', preview: '{{ route('inventory.preview') }}' }, initial: @js($oldLines) })">
    @csrf
    @if($editing)@method('PUT')@endif


    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Datos del documento</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="date" label="Fecha" type="date" :value="$editing ? $movement->occurred_at->toDateString() : now()->toDateString()" :max="now()->toDateString()" required />
            <div>
                <label for="warehouse_id" class="label">Almacén <span class="text-red-500">*</span></label>
                <select id="warehouse_id" name="warehouse_id" x-model.number="warehouse" @change="refreshAll()" class="input" required>
                    @foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                </select>
                @error('warehouse_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            @if($mode === 'entry')
                <x-field name="supplier" label="Proveedor (opcional)" :value="$editing ? $movement->supplier : null" />
                <x-field name="document_no" label="Conduce / documento" :value="$editing ? $movement->document_no : null" />
            @elseif($mode === 'exit')
                <div x-data="{ type: '{{ old('destination_type', $editing ? ($movement->destination_type ?: 'other') : (request('container') ? 'container' : 'other')) }}' }" class="contents">
                    <div>
                        <label for="destination_type" class="label">Tipo de destino</label>
                        <select id="destination_type" name="destination_type" x-model="type" class="input">
                            <option value="other">Otro (taller, oficina…)</option><option value="employee">Empleado</option><option value="container">Furgón</option>
                        </select>
                    </div>
                    <div x-show="type === 'container'" x-cloak>
                        <label for="container_id" class="label">Furgón</label>
                        <select id="container_id" name="container_id" class="input"><option value="">Seleccione…</option>@foreach($containers as $c)<option value="{{ $c->id }}" @selected(old('container_id', $editing ? $movement->container_id : request('container')) == $c->id)>#{{ $c->number }} · {{ $c->date->format('d/m/Y') }}</option>@endforeach</select>
                        @error('container_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div x-show="type !== 'container'"><x-field name="destination" label="Destino" hint="Ej.: Taller, Oficina" :value="$editing && $movement->destination_type !== 'container' ? $movement->destination : null" /></div>
                    <div x-show="type === 'employee'" x-cloak><x-field name="employee_name" label="Empleado" :value="$editing ? $movement->employee_name : null" /></div>
                </div>
                <x-field name="reason" label="Motivo" required hint="Ej.: Consumo operativo, reposición" :value="$editing ? $movement->reason : null" />
            @else
                <div>
                    <label for="reason" class="label">Motivo del ajuste <span class="text-red-500">*</span></label>
                    <select id="reason" name="reason" class="input" required>
                        <option value="">Seleccione…</option>
                        @foreach($reasons as $r)<option value="{{ $r }}" @selected(old('reason') === $r)>{{ $r }}</option>@endforeach
                    </select>
                    @error('reason')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            @endif
        </div>
        <div>
            <label for="notes" class="label">{{ $mode === 'adjustment' ? 'Observación' : 'Observaciones' }} @if($mode === 'adjustment')<span class="text-red-500">*</span>@endif</label>
            <textarea id="notes" name="notes" rows="2" class="input" @if($mode === 'adjustment') required minlength="5" placeholder="Explique la diferencia encontrada" @endif>{{ old('notes', $editing ? $movement->notes : '') }}</textarea>
            @error('notes')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>

    @if($editing)
    <div class="card card-body space-y-2 border-amber-300 bg-amber-50/60">
        <x-field name="edit_reason" label="Motivo de la edición" required minlength="5" maxlength="200" hint="Obligatorio. Queda registrado en el documento anulado, en el nuevo y en la auditoría." />
    </div>
    @endif

    @include('inventory._lines-editor', ['field' => 'lines'])

    <div class="card card-body">
        <x-photo-picker name="files" label="Fotos o documentos (opcional)" :max="10" :documents="true" :hint="$editing ? 'Las fotos y documentos del original se conservan en el documento corregido; aquí puede añadir más.' : 'Conduce, factura o foto de la mercancía. Desde el celular puede usar la cámara.'" />
    </div>

    @if($canNegative)
    <label class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><input type="checkbox" name="allow_negative" value="1" class="mt-0.5 rounded border-red-300"> <span><b>Autorización especial:</b> permitir que {{ $editing && $mode === 'entry' ? 'esta edición deje existencia negativa (parte del material de la entrada ya se usó)' : 'esta salida deje existencia negativa' }} (quedará registrado y auditado).</span></label>
    @endif

    <div class="sticky bottom-0 -mx-4 flex gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0">
        <button class="{{ $cfg['btn'] }} flex-1 sm:flex-none" @click="$el.disabled || null">{{ $cfg['button'] }}</button>
        <a href="{{ $editing ? route('movements.show', $movement) : route('inventory.index') }}" class="btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
