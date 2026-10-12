@extends('layouts.app')
@section('title', $product->name)
@section('content')
@php $nf = fn ($n) => rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.'); @endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('products.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Productos</a>
    <div class="flex flex-wrap gap-2">
        @can('inventory.entry')<a href="{{ route('entries.create', ['product' => $product->id]) }}" class="btn-success btn-sm"><x-icon name="arrow-down" class="h-4 w-4" /> Entrada</a>@endcan
        @can('inventory.dispatch')<a href="{{ route('exits.create', ['product' => $product->id]) }}" class="btn-primary btn-sm"><x-icon name="arrow-up" class="h-4 w-4" /> Salida</a>@endcan
        @can('inventory.adjust')<a href="{{ route('adjustments.create', ['product' => $product->id]) }}" class="btn-secondary btn-sm"><x-icon name="scale" class="h-4 w-4" /> Ajustar</a>@endcan
        @can('products.edit')<a href="{{ route('products.edit', $product) }}" class="btn-secondary btn-sm"><x-icon name="pencil" class="h-4 w-4" /> Editar</a>@endcan
        @can('products.delete')
        <form method="POST" action="{{ route('products.destroy', $product) }}" x-data @submit="if (!confirm({{ \Illuminate\Support\Js::from('¿Eliminar el producto '.$product->name.'? El historial se conserva.') }})) $event.preventDefault()">@csrf @method('DELETE')<button class="btn-danger btn-sm"><x-icon name="trash" class="h-4 w-4" /> Eliminar</button></form>
        @endcan
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-[360px_1fr]">
    {{-- Foto y galería --}}
    <div class="card card-body space-y-3" x-data="{ current: '{{ $product->images->first()?->url() }}' }">
        @if($product->images->isNotEmpty())
            <img :src="current" alt="{{ $product->name }}" class="aspect-square w-full rounded-xl border border-slate-200 object-contain bg-slate-50">
            @if($product->images->count() > 1)
            <div class="flex gap-2 overflow-x-auto pb-1">
                @foreach($product->images as $img)
                    <button type="button" @click="current = '{{ $img->url() }}'" class="shrink-0 overflow-hidden rounded-lg border border-slate-200"><img src="{{ $img->url(true) }}" class="h-16 w-16 object-cover" alt=""></button>
                @endforeach
            </div>
            @endif
        @else
            <div class="flex aspect-square w-full flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-slate-400"><x-icon name="photo" class="h-14 w-14" /><p class="mt-2 text-sm">Sin fotografía</p></div>
        @endif
        @can('products.edit')
        <form method="POST" action="{{ route('products.images.store', $product) }}" enctype="multipart/form-data" class="border-t border-slate-100 pt-3">
            @csrf
            <x-photo-picker name="photos" label="Agregar foto" :max="max(0, 8 - $product->images->count())" />
            <button class="btn-primary btn-sm mt-3">Subir fotos</button>
        </form>
        @endcan
    </div>

    <div class="space-y-6">
        <div class="card card-body">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="page-title">{{ $product->name }}</h1>
                    <p class="text-sm text-slate-500">Código <b class="text-slate-700">{{ $product->sku }}</b> · {{ $product->category?->name ?? 'Sin categoría' }}
                        @unless($product->is_active) · <span class="font-semibold text-red-600">Inactivo</span>@endunless
                        @if($product->is_returnable) · Retornable @endif</p>
                    @if($product->description)<p class="mt-2 text-sm text-slate-600">{{ $product->description }}</p>@endif
                </div>
                <x-status-badge :status="$status" class="!text-sm !px-3 !py-1" />
            </div>

            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Existencia</p>
                <p class="text-3xl font-bold text-slate-900">{{ $converter->format($product, $total) }}</p>
                <p class="text-sm text-slate-500">Total: <b class="text-slate-700">{{ $converter->formatTotal($product, $total) }}</b>
                    @if($product->handling_mode !== 'units') · 1 {{ $converter->boxLabel($product) }} = {{ $product->units_per_box }} {{ $converter->unitLabel($product, $product->units_per_box) }} @endif</p>
            </div>

            <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mínimo</dt><dd class="mt-0.5 text-slate-800">{{ $converter->formatMinimum($product) }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Manejo</dt><dd class="mt-0.5 text-slate-800">{{ \App\Models\Product::MODES[$product->handling_mode] }} · base: {{ $product->unit_name }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ubicación</dt><dd class="mt-0.5 text-slate-800">{{ $product->location_label }}</dd></div>
                @if($product->barcode)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Código de barras</dt><dd class="mt-0.5 text-slate-800">{{ $product->barcode }}</dd></div>@endif
            </dl>

            @if($stocks->count() > 1)
            <div class="mt-5 border-t border-slate-100 pt-4">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Por almacén</p>
                <div class="grid gap-2 sm:grid-cols-2">@foreach($stocks as $s)<div class="flex justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm"><span>{{ $s->warehouse?->name }}</span><b>{{ $converter->format($product, $s->quantity_base) }}</b></div>@endforeach</div>
            </div>
            @endif
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Entradas este mes</p><p class="text-xl font-bold text-emerald-700">{{ $converter->format($product, $stats['entries_month']) }}</p></div>
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Salidas este mes</p><p class="text-xl font-bold text-blue-700">{{ $converter->format($product, $stats['exits_month']) }}</p></div>
            <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Consumo últimos 30 días</p><p class="text-xl font-bold text-slate-900">{{ $converter->formatTotal($product, $stats['consumption_30']) }}</p></div>
        </div>
    </div>
</div>

{{-- Últimos movimientos --}}
<div class="card mt-6">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
        <h2 class="font-semibold text-slate-900">Últimos movimientos</h2>
        @can('inventory.view')<a href="{{ route('movements.index', ['product' => $product->id]) }}" class="text-sm font-medium text-brand hover:underline">Ver historial completo</a>@endcan
    </div>
    <div class="divide-y divide-slate-100 md:hidden">
        @forelse($movements as $l)
            @php $in = $l->delta_base > 0; @endphp
            <div class="px-4 py-3 text-sm">
                <div class="flex items-center justify-between gap-2"><span class="{{ $in ? 'badge-green' : 'badge-blue' }}">{{ $l->type_label }}</span><time class="text-xs text-slate-400">{{ $l->occurred_at->format('d/m/Y H:i') }}</time></div>
                <p class="mt-1 font-bold {{ $in ? 'text-emerald-700' : 'text-blue-700' }}">{{ $in ? '+' : '−' }}{{ $converter->format($product, abs($l->delta_base)) }}</p>
                <p class="text-xs text-slate-500">Existencia: <b class="text-slate-700">{{ $converter->format($product, $l->balance_after_base) }}</b> · {{ $l->movement?->number }} · {{ $l->movement?->user?->name }}</p>
            </div>
        @empty
            <p class="px-4 py-8 text-center text-sm text-slate-500">Sin movimientos todavía.</p>
        @endforelse
    </div>
    <div class="table-wrap hidden md:block">
        <table class="table">
            <thead><tr><th>Fecha</th><th>Tipo</th><th class="text-right">Entrada</th><th class="text-right">Salida</th><th class="text-right">Existencia</th><th>Usuario</th><th>Referencia</th></tr></thead>
            <tbody>
            @forelse($movements as $l)
                <tr>
                    <td class="whitespace-nowrap text-xs">{{ $l->occurred_at->format('d/m/Y H:i') }}</td>
                    <td><span class="{{ $l->delta_base > 0 ? 'badge-green' : 'badge-blue' }}">{{ $l->type_label }}</span></td>
                    <td class="whitespace-nowrap text-right">{{ $l->delta_base > 0 ? $converter->format($product, $l->delta_base) : '' }}</td>
                    <td class="whitespace-nowrap text-right">{{ $l->delta_base < 0 ? $converter->format($product, abs($l->delta_base)) : '' }}</td>
                    <td class="whitespace-nowrap text-right font-semibold">{{ $converter->format($product, $l->balance_after_base) }}</td>
                    <td>{{ $l->movement?->user?->name }}</td>
                    <td>@can('inventory.view')<a href="{{ route('movements.show', $l->movement_id) }}" class="text-brand hover:underline">{{ $l->movement?->number }}</a>@endcan<span class="block text-xs text-slate-400">{{ $l->movement?->document_no ?? $l->movement?->destination }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-500">Sin movimientos todavía.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
