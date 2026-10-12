@extends('layouts.app')
@section('title', 'Productos')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Productos</h1><p class="text-sm text-slate-500">Catálogo del almacén con fotografías y existencia.</p></div>
    <div class="flex gap-2">
        <a href="{{ route('categories.index') }}" class="btn-secondary"><x-icon name="tag" class="h-4 w-4" /> Categorías</a>
        @can('products.create')<a href="{{ route('products.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Nuevo producto</a>@endcan
    </div>
</div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_auto]">
    <div class="relative sm:col-span-2 lg:col-span-1">
        <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Producto, código o código de barras…" class="input !pl-9" aria-label="Buscar">
    </div>
    <select name="category" class="input" aria-label="Categoría"><option value="">Todas las categorías</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach</select>
    <select name="status" class="input" aria-label="Estado de stock"><option value="">Todos los estados</option>@foreach(\App\Services\Inventory\StockStatus::LABELS as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="active" class="input" aria-label="Activo"><option value="">Activos e inactivos</option><option value="active" @selected(request('active') === 'active')>Solo activos</option><option value="inactive" @selected(request('active') === 'inactive')>Solo inactivos</option></select>
    <div class="flex gap-2"><button class="btn-primary flex-1">Filtrar</button><a href="{{ route('products.index') }}" class="btn-secondary" title="Limpiar">✕</a></div>
</form>

<div class="space-y-2 md:hidden">
    @forelse($products as $p)
        @php $base = $p->totalBase(); @endphp
        <a href="{{ route('products.show', $p) }}" class="card flex items-center gap-3 p-3 {{ $p->is_active ? '' : 'opacity-60' }}">
            <x-product-thumb :product="$p" size="h-16 w-16" />
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold text-slate-900">{{ $p->name }}</p>
                <p class="truncate text-xs text-slate-500">{{ $p->sku }} · {{ $p->category?->name ?? 'Sin categoría' }}</p>
                <p class="mt-1 text-sm font-bold text-slate-900">{{ $converter->format($p, $base) }}</p>
            </div>
            <x-status-badge :status="$p->status($base)" />
        </a>
    @empty
        <div class="card card-body text-center text-slate-500">No hay productos. @can('products.create')<a href="{{ route('products.create') }}" class="text-brand underline">Cree el primero</a>.@endcan</div>
    @endforelse
</div>
<div class="card hidden md:block">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Producto</th><th>Categoría</th><th>Manejo</th><th class="text-right">Existencia</th><th class="text-right">Mínimo</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            @forelse($products as $p)
                @php $base = $p->totalBase(); @endphp
                <tr class="{{ $p->is_active ? '' : 'opacity-60' }}">
                    <td><a href="{{ route('products.show', $p) }}" class="flex items-center gap-3"><x-product-thumb :product="$p" /><span><span class="block font-medium text-slate-900">{{ $p->name }}</span><span class="block text-xs text-slate-500">{{ $p->sku }}@unless($p->is_active) · <b>Inactivo</b>@endunless @if($p->is_returnable) · Retornable @endif</span></span></a></td>
                    <td>{{ $p->category?->name ?? '—' }}</td>
                    <td class="text-xs">{{ \App\Models\Product::MODES[$p->handling_mode] }}@if($p->handling_mode !== 'units')<br><span class="text-slate-500">{{ $p->units_per_box }} por {{ $p->box_label }}</span>@endif</td>
                    <td class="text-right font-semibold text-slate-900">{{ $converter->format($p, $base) }}</td>
                    <td class="text-right">{{ $converter->formatMinimum($p) }}</td>
                    <td><x-status-badge :status="$p->status($base)" /></td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('products.show', $p) }}" class="btn-secondary btn-sm">Ver ficha</a>
                        @can('products.edit')<a href="{{ route('products.edit', $p) }}" class="btn-secondary btn-sm" aria-label="Editar"><x-icon name="pencil" class="h-3.5 w-3.5" /></a>@endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-10 text-center text-slate-500">No hay productos con esos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-2">{{ $products->links() }}</div>
@endsection
