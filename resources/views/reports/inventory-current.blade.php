@extends('layouts.app')
@section('title', 'Inventario actual')
@section('content')
<div class="no-print mb-3 flex flex-wrap items-center justify-between gap-2">
    <a href="{{ route('reports.warehouse') }}" class="text-sm text-slate-500 hover:text-slate-800">← Reportes de almacén</a>
    <div class="flex gap-2">
        @can('reports.export')
            <a href="{{ route('reports.run', ['warehouse', 'inventory-current'] + request()->only(['product', 'warehouse', 'category', 'status']) + ['format' => 'xlsx']) }}" class="btn-secondary btn-sm">Excel</a>
            <a href="{{ route('reports.run', ['warehouse', 'inventory-current'] + request()->only(['product', 'warehouse', 'category', 'status']) + ['format' => 'pdf']) }}" class="btn-secondary btn-sm">PDF</a>
        @endcan
    <button type="button" onclick="window.print()" class="btn-primary btn-sm"><x-icon name="printer" class="h-4 w-4" /> Imprimir</button>
    </div>
</div>

<form method="GET" action="{{ route('reports.inventory') }}" class="card card-body no-print mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.2fr_1fr_1fr_1fr_1fr_auto]">
    <select name="product" class="input" aria-label="Producto"><option value="">Todos los productos</option>@foreach($allProducts as $p)<option value="{{ $p->id }}" @selected(request('product') == $p->id)>{{ $p->name }} ({{ $p->sku }})</option>@endforeach</select>
    <select name="warehouse" class="input" aria-label="Almacén"><option value="">Todos los almacenes</option>@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected(request('warehouse') == $w->id)>{{ $w->name }}</option>@endforeach</select>
    <select name="category" class="input" aria-label="Categoría"><option value="">Todas las categorías</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach</select>
    <select name="status" class="input" aria-label="Estado"><option value="">Todos los estados</option>@foreach(\App\Services\Inventory\StockStatus::LABELS as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="active" class="input" aria-label="Activos"><option value="active" @selected(($filters['active'] ?? 'active') === 'active')>Solo activos</option><option value="" @selected(($filters['active'] ?? 'active') === '')>Activos e inactivos</option></select>
    <button class="btn-primary">Generar</button>
</form>

<div class="card card-body">
    <x-report-header title="Reporte de inventario" subtitle="Inventario actual" :meta="array_filter([
        'Almacén' => $warehouse?->name ?? 'Todos',
        'Estado' => ! empty($filters['status']) ? \App\Services\Inventory\StockStatus::LABELS[$filters['status']] : null,
        'Existencia al' => now()->format('d/m/Y H:i'),
    ])" />

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Código</th><th>Foto</th><th>Producto</th><th>Categoría</th><th class="text-right">Cajas</th><th class="text-right">Unidades</th><th class="text-right">Total unid.</th><th class="text-right">Mínimo</th><th>Estado</th><th>Ubicación</th></tr></thead>
            <tbody>
            @forelse($products as $p)
                @php $base = $p->totalBase(); [$boxes, $units] = $converter->split($p, $base); @endphp
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs">{{ $p->sku }}</td>
                    <td><x-product-thumb :product="$p" size="h-10 w-10" /></td>
                    <td class="font-medium text-slate-900">{{ $p->name }}</td>
                    <td>{{ $p->category?->name ?? '—' }}</td>
                    <td class="text-right">{{ $p->handling_mode === 'units' ? '—' : number_format($boxes) }}</td>
                    <td class="text-right">{{ $p->handling_mode === 'boxes' ? '—' : rtrim(rtrim(number_format($units, 3), '0'), '.') }}</td>
                    <td class="text-right font-semibold">{{ $converter->formatTotal($p, $base) }}</td>
                    <td class="text-right">{{ $converter->formatMinimum($p) }}</td>
                    <td><x-status-badge :status="$p->status($base)" /></td>
                    <td class="text-xs">{{ $p->location_label }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="py-10 text-center text-slate-500">Sin productos para los filtros seleccionados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-500">{{ $products->count() }} producto(s).</p>
    <x-report-signatures />
</div>
@endsection
