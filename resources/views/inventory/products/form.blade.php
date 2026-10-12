@extends('layouts.app')
@section('title', $product->exists ? 'Editar producto' : 'Nuevo producto')
@section('content')
@php $mode = old('handling_mode', $product->handling_mode); @endphp
<div class="mb-4 flex items-center justify-between gap-3">
    <h1 class="page-title">{{ $product->exists ? 'Editar producto' : 'Nuevo producto' }}</h1>
    <a href="{{ $product->exists ? route('products.show', $product) : route('products.index') }}" class="btn-secondary">Volver</a>
</div>

<form method="POST" enctype="multipart/form-data" action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}"
      x-data="{ mode: '{{ $mode }}', returnable: {{ old('is_returnable', $product->is_returnable) ? 'true' : 'false' }} }" class="space-y-6">
    @csrf @if($product->exists) @method('PUT') @endif

    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Datos del producto</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="sku" label="Código / SKU" :value="$product->sku" required autocomplete="off" />
            <x-field name="barcode" label="Código de barras (opcional)" :value="$product->barcode" inputmode="numeric" />
            <x-field name="name" label="Nombre" :value="$product->name" required class="sm:col-span-2" />
            <x-field name="description" label="Descripción" type="textarea" :value="$product->description" class="sm:col-span-2" />
            <div>
                <label for="category_id" class="label">Categoría</label>
                <select id="category_id" name="category_id" class="input"><option value="">Sin categoría</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</select>
                @error('category_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="unit_id" class="label">Unidad base <span class="text-red-500">*</span></label>
                <select id="unit_id" name="unit_id" class="input" required><option value="">Seleccione…</option>@foreach($units as $u)<option value="{{ $u->id }}" @selected(old('unit_id', $product->unit_id) == $u->id)>{{ $u->name }}</option>@endforeach</select>
                @error('unit_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </div>

    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Manejo de cantidades</h2>
        @if($hasMovements)<p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Este producto ya tiene movimientos. Cambiar «unidades por caja» o el tipo de manejo solo cambia cómo se <b>muestra</b> la existencia; la cantidad en unidad base no se modifica y el historial conserva su registro original.</p>@endif
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="label">Tipo de manejo</label>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach(\App\Models\Product::MODES as $k => $l)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-sm has-[:checked]:border-brand has-[:checked]:bg-brand/5">
                            <input type="radio" name="handling_mode" value="{{ $k }}" x-model="mode" class="text-brand"> {{ $l }}
                        </label>
                    @endforeach
                </div>
                @error('handling_mode')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div x-show="mode !== 'units'" x-cloak><x-field name="units_per_box" label="Unidades por caja" type="number" min="1" step="1" inputmode="numeric" :value="$product->units_per_box" hint="Ej.: Tape = 36 rollos por caja." /></div>
            <div x-show="mode !== 'units'" x-cloak><x-field name="box_label" label="Nombre del empaque" :value="$product->box_label" hint="caja, paquete, bulto…" /></div>
            <div>
                <x-field name="min_quantity" label="Cantidad mínima (alerta)" type="number" min="0" step="any" inputmode="decimal" :value="$product->min_quantity" required />
            </div>
            <div x-show="mode === 'both'" x-cloak>
                <label for="alert_unit" class="label">El mínimo está expresado en</label>
                {{-- Para «solo unidades» / «solo cajas» el servidor fija este valor automáticamente. --}}
                <select id="alert_unit" name="alert_unit" class="input"><option value="boxes" @selected(old('alert_unit', $product->alert_unit) === 'boxes')>Cajas</option><option value="units" @selected(old('alert_unit', $product->alert_unit) === 'units')>Unidades</option></select>
            </div>
        </div>
        <p class="text-xs text-slate-500">La existencia se guarda internamente en <b>unidad base</b>. La existencia inicial se carga con una <b>entrada de almacén</b>, nunca escribiendo un número.</p>
    </div>

    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Ubicación</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="default_warehouse_id" class="label">Almacén</label>
                <select id="default_warehouse_id" name="default_warehouse_id" class="input"><option value="">—</option>@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected(old('default_warehouse_id', $product->default_warehouse_id) == $w->id)>{{ $w->name }}</option>@endforeach</select>
            </div>
            <x-field name="aisle" label="Pasillo" :value="$product->aisle" />
            <x-field name="shelf" label="Estante" :value="$product->shelf" />
            <x-field name="location" label="Ubicación libre" :value="$product->location" hint="Ej.: Esquina izquierda, segundo nivel" />
        </div>
    </div>

    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Fotografías</h2>
        @if($product->exists && $product->images->isNotEmpty())
            <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6">
                @foreach($product->images as $img)
                    <div class="overflow-hidden rounded-lg border {{ $img->is_primary ? 'border-brand ring-2 ring-brand/30' : 'border-slate-200' }}">
                        <img src="{{ $img->url(true) }}" alt="" class="aspect-square w-full object-cover">
                        <div class="flex items-center justify-between gap-1 bg-white p-1">
                            @if($img->is_primary)<span class="badge-blue">Principal</span>@else
                                <button type="submit" form="img-primary-{{ $img->id }}" class="text-[11px] font-medium text-brand">Hacer principal</button>@endif
                            <button type="submit" form="img-del-{{ $img->id }}" class="text-red-600" aria-label="Eliminar foto" onclick="return confirm('¿Eliminar esta foto?')"><x-icon name="trash" class="h-4 w-4" /></button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
        <x-photo-picker name="photos" label="Agregar fotografías" :max="$maxImages - ($product->exists ? $product->images->count() : 0)" hint="La primera foto será la principal. Se comprimen automáticamente." />
    </div>

    <div class="card card-body space-y-3">
        <h2 class="font-semibold text-slate-900">Opciones</h2>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_returnable" value="1" x-model="returnable" class="rounded border-slate-300"> Producto <b>retornable</b> (gomas, tóner…)</label>
        <div x-show="returnable" x-cloak class="max-w-xs">
            <label for="returnable_kind" class="label">Tipo de retornable</label>
            <select id="returnable_kind" name="returnable_kind" class="input"><option value="">—</option>@foreach(['tire' => 'Goma', 'toner' => 'Tóner', 'other' => 'Otro'] as $k => $l)<option value="{{ $k }}" @selected(old('returnable_kind', $product->returnable_kind) === $k)>{{ $l }}</option>@endforeach</select>
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $product->is_active))> Producto activo</label>
    </div>

    <div class="sticky bottom-0 -mx-4 flex gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0">
        <button class="btn-primary flex-1 sm:flex-none">Guardar producto</button>
        <a href="{{ $product->exists ? route('products.show', $product) : route('products.index') }}" class="btn-secondary">Cancelar</a>
    </div>
</form>

{{-- formularios auxiliares (fuera del formulario principal para no anidar <form>) --}}
@if($product->exists)
    @foreach($product->images as $img)
        <form id="img-primary-{{ $img->id }}" method="POST" action="{{ route('products.images.primary', [$product, $img]) }}">@csrf</form>
        <form id="img-del-{{ $img->id }}" method="POST" action="{{ route('products.images.destroy', [$product, $img]) }}">@csrf @method('DELETE')</form>
    @endforeach
@endif
@endsection
