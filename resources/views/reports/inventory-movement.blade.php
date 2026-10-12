@extends('layouts.app')
@section('title', 'Movimiento de inventario')
@section('content')
<div class="no-print mb-3 flex flex-wrap items-center justify-between gap-2">
    <a href="{{ route('reports.warehouse') }}" class="text-sm text-slate-500 hover:text-slate-800">← Reportes de almacén</a>
    <button type="button" onclick="window.print()" class="btn-primary btn-sm"><x-icon name="printer" class="h-4 w-4" /> Imprimir</button>
</div>

<form method="GET" action="{{ route('reports.inventory-movement') }}" class="card card-body no-print mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.2fr_1fr_1fr_auto]">
    <div><label class="mb-0.5 block text-[11px] text-slate-500">Desde</label><input type="date" name="from" value="{{ $from->toDateString() }}" class="input" required></div>
    <div><label class="mb-0.5 block text-[11px] text-slate-500">Hasta</label><input type="date" name="to" value="{{ $to->toDateString() }}" class="input" required></div>
    <div><label class="mb-0.5 block text-[11px] text-slate-500">Producto</label><select name="product" class="input"><option value="">Todos</option>@foreach($allProducts as $p)<option value="{{ $p->id }}" @selected(($filters['product'] ?? '') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
    <div><label class="mb-0.5 block text-[11px] text-slate-500">Almacén</label><select name="warehouse" class="input"><option value="">Todos</option>@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected(($filters['warehouse'] ?? '') == $w->id)>{{ $w->name }}</option>@endforeach</select></div>
    <div><label class="mb-0.5 block text-[11px] text-slate-500">Categoría</label><select name="category" class="input"><option value="">Todas</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(($filters['category'] ?? '') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
    <div class="flex items-end"><button class="btn-primary w-full">Generar</button></div>
</form>
@error('to')<p class="field-error mb-3">{{ $message }}</p>@enderror

<div class="card card-body">
    <x-report-header title="Movimiento de inventario" :subtitle="'Del '.$from->format('d/m/Y').' al '.$to->format('d/m/Y')" :meta="['Almacén' => $warehouse?->name ?? 'Todos']" />

    <p class="mb-3 text-xs text-slate-500">Inventario inicial + Entradas − Salidas ± Ajustes ± Otros movimientos = Inventario final. Calculado desde el libro de movimientos (no desde la existencia actual). Cantidades en unidad base.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Código</th><th>Producto</th><th class="text-right">Inicial</th><th class="text-right">Entradas</th><th class="text-right">Salidas</th><th class="text-right">Ajustes</th><th class="text-right">Otros</th><th class="text-right">Final</th></tr></thead>
            <tbody>
            @php $fmt = fn ($n) => rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.'); $signed = fn ($n) => ($n > 0 ? '+' : '').$fmt($n); @endphp
            @forelse($rows as $r)
                @php $p = $products[$r->product_id] ?? null; @endphp
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs">{{ $r->product_sku }}</td>
                    <td class="font-medium text-slate-900">{{ $r->product_name }}@if($p)<span class="block text-xs font-normal text-slate-400">{{ $converter->unitLabel($p, 2) }}</span>@endif</td>
                    <td class="text-right">{{ $fmt($r->initial) }}</td>
                    <td class="text-right text-emerald-700">{{ $fmt($r->entries) }}</td>
                    <td class="text-right text-blue-700">{{ $fmt($r->exits) }}</td>
                    <td class="text-right {{ $r->adjustments < 0 ? 'text-red-600' : '' }}">{{ $signed($r->adjustments) }}</td>
                    <td class="text-right">{{ $signed($r->others) }}</td>
                    <td class="text-right font-bold text-slate-900">{{ $fmt($r->final) }}@if($p)<span class="block text-[11px] font-normal text-slate-400">{{ $converter->format($p, $r->final) }}</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-10 text-center text-slate-500">Sin movimientos ni existencia en el período.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <x-report-signatures />
</div>
@endsection
