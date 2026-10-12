@extends('layouts.app')
@section('title', 'Entregar goma nueva')
@section('content')
<div class="mb-4"><a href="{{ route('tires.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Gomas</a><h1 class="page-title">Entregar goma nueva a un camión</h1><p class="text-sm text-slate-500">Descuenta la goma del inventario y deja el seguimiento de la goma usada.</p></div>
<form method="POST" action="{{ route('tires.store') }}" enctype="multipart/form-data" class="space-y-6" x-data="{ qty: {{ (int) old('quantity', 1) }}, used: '{{ old('used_received', '') }}' }">
    @csrf
    <div class="card card-body space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div><label for="vehicle_id" class="label">Vehículo (ficha) <span class="text-red-500">*</span></label><select id="vehicle_id" name="vehicle_id" class="input" required><option value="">Seleccione…</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(old('vehicle_id', $selected) == $v->id)>{{ $v->ficha }}{{ $v->plate ? ' · '.$v->plate : '' }}</option>@endforeach</select>@error('vehicle_id')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label for="product_id" class="label">Goma entregada <span class="text-red-500">*</span></label><select id="product_id" name="product_id" class="input" required><option value="">Seleccione…</option>@foreach($tires as $p)<option value="{{ $p->id }}" @selected(old('product_id') == $p->id)>{{ $p->name }} — hay {{ $converter->format($p, $p->totalBase()) }}</option>@endforeach</select>@error('product_id')<p class="field-error">{{ $message }}</p>@enderror
                @if($tires->isEmpty())<p class="mt-1 text-xs text-amber-700">No hay productos tipo goma: en Productos marque «retornable → Goma».</p>@endif</div>
            <div><label for="quantity" class="label">Cantidad <span class="text-red-500">*</span></label><input id="quantity" name="quantity" x-model.number="qty" type="number" min="1" max="20" inputmode="numeric" class="input" required>@error('quantity')<p class="field-error">{{ $message }}</p>@enderror</div>
            <x-field name="delivered_at" label="Fecha" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
            <x-field name="odometer" label="Kilometraje" type="number" step="0.1" min="0" inputmode="decimal" />
            <div><label for="warehouse_id" class="label">Almacén</label><select id="warehouse_id" name="warehouse_id" class="input">@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected(old('warehouse_id', $defaultWarehouse?->id) == $w->id)>{{ $w->name }}</option>@endforeach</select></div>
        </div>
        <x-field name="reason" label="Motivo" hint="Ej.: desgaste, reventón, rotación" />
        <p class="text-xs text-slate-500">Responsable: <b>{{ auth()->user()->name }}</b></p>
    </div>

    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">¿Goma usada recibida? <span class="text-red-500">*</span></h2>
        <div class="grid max-w-md grid-cols-2 gap-3">
            <label class="flex min-h-14 cursor-pointer items-center justify-center rounded-xl border-2 border-slate-300 text-lg font-bold has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 has-[:checked]:text-emerald-800"><input type="radio" name="used_received" value="1" x-model="used" class="sr-only" required> SÍ</label>
            <label class="flex min-h-14 cursor-pointer items-center justify-center rounded-xl border-2 border-slate-300 text-lg font-bold has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50 has-[:checked]:text-amber-800"><input type="radio" name="used_received" value="0" x-model="used" class="sr-only"> NO</label>
        </div>
        @error('used_received')<p class="field-error">{{ $message }}</p>@enderror
        <p x-show="used === '0'" x-cloak class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Se creará automáticamente una <b>DEVOLUCIÓN PENDIENTE</b> por cada goma usada que falte (<span x-text="qty"></span>).</p>
        <div x-show="used === '1'" x-cloak class="space-y-4">
            <template x-for="i in Math.min(Math.max(qty || 1, 1), 20)" :key="i">
                <div class="rounded-xl border border-slate-200 p-3"><p class="mb-2 text-sm font-semibold text-slate-800">Goma usada <span x-text="i"></span></p>
                    <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Estado</label><select :name="`used[${i - 1}][condition]`" class="input"><option value="">Seleccione…</option>@foreach($conditions as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                        <div><label class="label">Observaciones</label><input :name="`used[${i - 1}][notes]`" class="input"></div></div>
                    <div class="mt-3"><label class="label">Fotografía</label><input type="file" name="used_photos[]" accept="image/*" capture="environment" class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium"></div></div>
            </template>
        </div>
    </div>
    @if($canNegative)<label class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><input type="checkbox" name="allow_negative" value="1" class="mt-0.5 rounded border-red-300"> Autorización especial: permitir existencia negativa.</label>@endif
    <div class="flex gap-2"><button class="btn-primary">Registrar entrega</button><a href="{{ route('tires.index') }}" class="btn-secondary">Cancelar</a></div>
</form>
@endsection
