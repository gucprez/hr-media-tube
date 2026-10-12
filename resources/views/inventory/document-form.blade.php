@extends('layouts.app')
@section('title', ['entry' => 'Nueva entrada', 'exit' => 'Nueva salida', 'adjustment' => 'Ajuste de inventario'][$mode])
@section('content')
@php
$cfg = [
    'entry' => ['title' => 'Nueva entrada de almacén', 'sub' => 'Registre mercancía que ingresa al almacén. Puede agregar varios productos en el mismo documento.', 'action' => route('entries.store'), 'button' => 'Registrar entrada', 'btn' => 'btn-success'],
    'exit' => ['title' => 'Nueva salida de almacén', 'sub' => 'Registre materiales que salen del almacén. No se permite dejar el stock en negativo.', 'action' => route('exits.store'), 'button' => 'Registrar salida', 'btn' => 'btn-primary'],
    'adjustment' => ['title' => 'Ajuste de inventario', 'sub' => 'Indique el conteo físico. El sistema calcula la diferencia y la registra como movimiento; nunca se sobrescribe la existencia.', 'action' => route('adjustments.store'), 'button' => 'Registrar ajuste', 'btn' => 'btn-primary'],
][$mode];
@endphp

<div class="mb-4"><h1 class="page-title">{{ $cfg['title'] }}</h1><p class="text-sm text-slate-500">{{ $cfg['sub'] }}</p></div>

<form method="POST" action="{{ $cfg['action'] }}" enctype="multipart/form-data" class="space-y-6"
      x-data="docLines({ mode: '{{ $mode }}', warehouse: {{ (int) old('warehouse_id', $defaultWarehouse?->id) }}, urls: { lookup: '{{ route('inventory.lookup') }}', preview: '{{ route('inventory.preview') }}' }, initial: @js($oldLines) })">
    @csrf


    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Datos del documento</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="date" label="Fecha" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
            <div>
                <label for="warehouse_id" class="label">Almacén <span class="text-red-500">*</span></label>
                <select id="warehouse_id" name="warehouse_id" x-model.number="warehouse" @change="refreshAll()" class="input" required>
                    @foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                </select>
                @error('warehouse_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            @if($mode === 'entry')
                <x-field name="supplier" label="Proveedor (opcional)" />
                <x-field name="document_no" label="Conduce / documento" />
            @elseif($mode === 'exit')
                <x-field name="destination" label="Destino" required hint="Ej.: Furgón 0111, Taller, Oficina" />
                <div x-data="{ type: '{{ old('destination_type', 'other') }}' }" class="space-y-3">
                    <div>
                        <label for="destination_type" class="label">Tipo de destino</label>
                        <select id="destination_type" name="destination_type" x-model="type" class="input">
                            <option value="other">Otro</option><option value="employee">Empleado</option>
                        </select>
                    </div>
                    <div x-show="type === 'employee'" x-cloak><x-field name="employee_name" label="Empleado" /></div>
                </div>
                <x-field name="reason" label="Motivo" required hint="Ej.: Consumo operativo, reposición" />
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
            <textarea id="notes" name="notes" rows="2" class="input" @if($mode === 'adjustment') required minlength="5" placeholder="Explique la diferencia encontrada" @endif>{{ old('notes') }}</textarea>
            @error('notes')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="card card-body space-y-3">
        <div class="flex items-center justify-between"><h2 class="font-semibold text-slate-900">Productos</h2><span class="text-xs text-slate-500" x-text="lines.length + ' línea(s)'"></span></div>

        <template x-for="(line, i) in lines" :key="line.key">
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-3" :data-line="i">
                <input type="hidden" :name="`lines[${i}][product_id]`" :value="line.product ? line.product.id : ''">

                {{-- selector de producto --}}
                <div x-show="!line.product" class="relative" @click.outside="line.open = false">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input type="search" x-model="line.q" @input="search(i)" @focus="search(i)" placeholder="Buscar producto por nombre o código…" autocomplete="off" class="input !pl-9" aria-label="Producto">
                    <div x-show="line.open" x-cloak class="absolute left-0 right-0 z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl">
                        <template x-for="r in line.results" :key="r.id">
                            <button type="button" @click="pick(i, r)" class="flex w-full items-center gap-3 border-b border-slate-100 px-3 py-2 text-left last:border-0 hover:bg-slate-50">
                                <img x-show="r.thumb" :src="r.thumb" class="h-10 w-10 rounded-md border border-slate-200 object-cover" alt="">
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-slate-800" x-text="r.name"></span><span class="block truncate text-xs text-slate-500" x-text="r.sku + ' · Hay ' + r.stock_label"></span></span>
                                <span class="badge-gray" x-text="r.status_label"></span>
                            </button>
                        </template>
                        <p x-show="!line.results.length" class="px-3 py-4 text-center text-sm text-slate-500">Sin resultados</p>
                    </div>
                </div>

                <div x-show="line.product" x-cloak class="space-y-3">
                    <div class="flex items-center gap-3">
                        <img x-show="line.product && line.product.thumb" :src="line.product ? line.product.thumb : ''" class="h-12 w-12 rounded-lg border border-slate-200 object-cover" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-900" x-text="line.product ? line.product.name : ''"></p>
                            <p class="truncate text-xs text-slate-500" x-text="line.product ? line.product.sku + ' · En este almacén: ' + (line.preview ? line.preview.current_label : line.product.stock_label) : ''"></p>
                        </div>
                        <button type="button" @click="line.product = null; line.preview = null" class="text-xs font-medium text-brand">Cambiar</button>
                        <button type="button" @click="remove(i)" class="rounded-lg p-2 text-red-600 hover:bg-red-50" aria-label="Quitar línea"><x-icon name="trash" class="h-5 w-5" /></button>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div x-show="showBoxes(line)">
                            <label class="label capitalize" x-text="(mode === 'adjustment' ? 'Conteo: ' : '') + (line.product ? line.product.box_label : '')"></label>
                            <input type="number" inputmode="numeric" min="0" step="1" x-model="line.boxes" @input="refresh(i)" :name="`lines[${i}][boxes]`" data-qty class="input" placeholder="0">
                        </div>
                        <div x-show="showUnits(line)" :class="showBoxes(line) ? '' : 'col-span-2'">
                            <label class="label capitalize" x-text="(mode === 'adjustment' ? 'Conteo: ' : '') + (line.product ? line.product.unit_label : '')"></label>
                            <input type="number" inputmode="decimal" min="0" step="any" x-model="line.units" @input="refresh(i)" :name="`lines[${i}][units]`" :data-qty="!showBoxes(line)" class="input" placeholder="0">
                        </div>
                    </div>

                    <div x-show="line.preview" x-cloak class="rounded-lg bg-white px-3 py-2 text-xs ring-1 ring-slate-200">
                        <template x-if="line.preview && line.preview.error"><p class="font-medium text-red-600" x-text="line.preview.error"></p></template>
                        <template x-if="line.preview && !line.preview.error && mode !== 'adjustment'">
                            <p class="text-slate-600"><b class="text-slate-900" x-text="line.preview.quantity_label"></b> · quedará en <b class="text-slate-900" x-text="line.preview.result_label"></b> <span class="badge-gray ml-1" x-text="line.preview.status"></span></p>
                        </template>
                        <template x-if="line.preview && !line.preview.error && mode === 'adjustment'">
                            <p class="text-slate-600">Sistema: <b class="text-slate-900" x-text="line.preview.current_label"></b> · Conteo: <b class="text-slate-900" x-text="line.preview.result_label"></b> · Diferencia: <b :class="line.preview.delta_base < 0 ? 'text-red-600' : (line.preview.delta_base > 0 ? 'text-emerald-700' : 'text-slate-900')" x-text="line.preview.delta_label"></b></p>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <button type="button" @click="add()" class="btn-secondary w-full sm:w-auto"><x-icon name="plus" class="h-4 w-4" /> Agregar otro producto</button>
    </div>

    <div class="card card-body">
        <x-photo-picker name="files" label="Fotos o documentos (opcional)" :max="10" :documents="true" hint="Conduce, factura o foto de la mercancía. Desde el celular puede usar la cámara." />
    </div>

    @if($canNegative)
    <label class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><input type="checkbox" name="allow_negative" value="1" class="mt-0.5 rounded border-red-300"> <span><b>Autorización especial:</b> permitir que esta salida deje existencia negativa (quedará registrado y auditado).</span></label>
    @endif

    <div class="sticky bottom-0 -mx-4 flex gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0">
        <button class="{{ $cfg['btn'] }} flex-1 sm:flex-none" @click="$el.disabled || null">{{ $cfg['button'] }}</button>
        <a href="{{ route('inventory.index') }}" class="btn-secondary">Cancelar</a>
    </div>
</form>
@endsection
