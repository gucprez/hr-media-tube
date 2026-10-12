@extends('layouts.app')
@section('title', 'Movimientos de inventario')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Movimientos de inventario</h1><p class="text-sm text-slate-500">Libro histórico: cada cambio de existencia queda registrado con su existencia anterior y resultante.</p></div>
    <div class="flex flex-wrap gap-2">
        @can('inventory.entry')<a href="{{ route('entries.create') }}" class="btn-success btn-sm"><x-icon name="arrow-down" class="h-4 w-4" /> Entrada</a>@endcan
        @can('inventory.dispatch')<a href="{{ route('exits.create') }}" class="btn-primary btn-sm"><x-icon name="arrow-up" class="h-4 w-4" /> Salida</a>@endcan
    </div>
</div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Producto, SKU, documento…" class="input sm:col-span-2" aria-label="Buscar">
    <select name="type" class="input" aria-label="Tipo"><option value="">Todos los tipos</option>
        @foreach(['entry' => 'Entradas', 'exit' => 'Salidas', 'adjustment' => 'Ajustes'] as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="warehouse" class="input" aria-label="Almacén"><option value="">Todos los almacenes</option>@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected(request('warehouse') == $w->id)>{{ $w->name }}</option>@endforeach</select>
    <select name="user" class="input" aria-label="Usuario"><option value="">Todos los usuarios</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected(request('user') == $u->id)>{{ $u->name }}</option>@endforeach</select>
    <div class="flex gap-2"><button class="btn-primary flex-1">Filtrar</button><a href="{{ route('movements.index') }}" class="btn-secondary" title="Limpiar">✕</a></div>
    <div><label class="mb-0.5 block text-[11px] text-slate-500">Desde</label><input type="date" name="from" value="{{ request('from') }}" class="input"></div>
    <div><label class="mb-0.5 block text-[11px] text-slate-500">Hasta</label><input type="date" name="to" value="{{ request('to') }}" class="input"></div>
    @if(request('product'))<input type="hidden" name="product" value="{{ request('product') }}">@endif
</form>

{{-- móvil --}}
<div class="space-y-2 md:hidden">
    @forelse($lines as $l)
        @php $p = $l->product; $in = $l->delta_base > 0; @endphp
        <a href="{{ route('movements.show', $l->movement_id) }}" class="card block p-3">
            <div class="flex items-center justify-between gap-2"><span class="{{ $in ? 'badge-green' : 'badge-blue' }}">{{ $l->type_label }}</span><time class="text-xs text-slate-400">{{ $l->occurred_at->format('d/m/Y H:i') }}</time></div>
            <p class="mt-1.5 truncate font-semibold text-slate-900">{{ $l->product_name }}</p>
            <p class="text-sm {{ $in ? 'text-emerald-700' : 'text-blue-700' }} font-bold">{{ $in ? '+' : '−' }}{{ $converter->format($p, abs($l->delta_base)) }}</p>
            <p class="text-xs text-slate-500">Existencia: {{ $converter->format($p, $l->balance_after_base) }} · {{ $l->movement?->number }} · {{ $l->movement?->user?->name }}</p>
        </a>
    @empty
        <div class="card card-body text-center text-slate-500">No hay movimientos con esos filtros.</div>
    @endforelse
</div>
<div class="card hidden md:block">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Documento</th><th>Producto</th><th class="text-right">Entrada</th><th class="text-right">Salida</th><th class="text-right">Existencia</th><th>Almacén</th><th>Usuario</th><th>Referencia</th></tr></thead>
            <tbody>
            @forelse($lines as $l)
                @php $p = $l->product; $in = $l->delta_base > 0; @endphp
                <tr>
                    <td class="whitespace-nowrap text-xs">{{ $l->occurred_at->format('d/m/Y H:i') }}</td>
                    <td><span class="{{ $in ? 'badge-green' : 'badge-blue' }}">{{ $l->type_label }}</span></td>
                    <td><a href="{{ route('movements.show', $l->movement_id) }}" class="font-medium text-brand hover:underline">{{ $l->movement?->number }}</a></td>
                    <td><span class="block font-medium text-slate-900">{{ $l->product_name }}</span><span class="block text-xs text-slate-500">{{ $l->product_sku }}</span></td>
                    <td class="whitespace-nowrap text-right text-emerald-700">{{ $in ? $converter->format($p, $l->delta_base) : '' }}</td>
                    <td class="whitespace-nowrap text-right text-blue-700">{{ ! $in ? $converter->format($p, abs($l->delta_base)) : '' }}</td>
                    <td class="whitespace-nowrap text-right font-semibold">{{ $converter->format($p, $l->balance_after_base) }}</td>
                    <td class="text-xs">{{ $l->warehouse?->name }}</td>
                    <td class="text-xs">{{ $l->movement?->user?->name }}</td>
                    <td class="max-w-[160px] truncate text-xs text-slate-500">{{ $l->movement?->document_no ?? $l->movement?->destination ?? $l->movement?->reason }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="py-10 text-center text-slate-500">No hay movimientos con esos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-2">{{ $lines->links() }}</div>
@endsection
