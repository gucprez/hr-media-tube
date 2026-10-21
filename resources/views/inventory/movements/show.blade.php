@extends('layouts.app')
@section('title', $movement->type_label.' '.$movement->number)
@section('content')
<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('movements.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Movimientos</a>
    <div class="flex flex-wrap items-center gap-2">
        @can('inventory.edit')@if($movement->isEditable())
            <a href="{{ route('movements.edit', $movement) }}" class="btn-primary btn-sm">Editar</a>
        @endif @endcan
        <button type="button" x-data @click="window.print()" class="btn-secondary btn-sm"><x-icon name="printer" class="h-4 w-4" /> Imprimir</button>
    </div>
</div>

<div class="card card-body">
    <x-report-header :title="$movement->type_label.' de almacén'" :subtitle="$movement->number" />

    <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Fecha</dt><dd>{{ $movement->occurred_at->format('d/m/Y H:i') }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Almacén</dt><dd>{{ $movement->warehouse?->name }} @if($movement->warehouse?->branch)<span class="text-slate-400">· {{ $movement->warehouse->branch->name }}</span>@endif</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Registrado por</dt><dd>{{ $movement->user?->name ?? '—' }}</dd></div>
        @if($movement->supplier)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Proveedor</dt><dd>{{ $movement->supplier }}</dd></div>@endif
        @if($movement->document_no)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Conduce / documento</dt><dd>{{ $movement->document_no }}</dd></div>@endif
        @if($movement->destination)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Destino</dt><dd>{{ $movement->destination }}@if($movement->employee_name) · {{ $movement->employee_name }}@endif</dd></div>@endif
        @if($movement->reason)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Motivo</dt><dd>{{ $movement->reason }}</dd></div>@endif
        @if($movement->container)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Furgón</dt><dd><a href="{{ route('containers.show', $movement->container) }}" class="text-brand hover:underline">#{{ $movement->container->number }}</a></dd></div>@endif
        @if($movement->reversalOf)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Anula el documento</dt><dd><a href="{{ route('movements.show', $movement->reversalOf) }}" class="text-brand hover:underline">{{ $movement->reversalOf->number }}</a></dd></div>@endif
        @if($movement->replaces)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Corrige (edición de)</dt><dd><a href="{{ route('movements.show', $movement->replaces) }}" class="text-brand hover:underline">{{ $movement->replaces->number }}</a> <span class="badge-yellow">Editado</span></dd></div>@endif
        @if($movement->replacedBy)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Editado: lo reemplaza</dt><dd><a href="{{ route('movements.show', $movement->replacedBy) }}" class="text-brand hover:underline">{{ $movement->replacedBy->number }}</a></dd></div>@endif
        @if($movement->isVoided())<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Estado</dt><dd><span class="badge-red">ANULADO {{ $movement->voided_at->format('d/m/Y H:i') }}</span></dd></div>@endif
        @if($movement->negative_override)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Autorización especial</dt><dd><span class="badge-red">Stock negativo autorizado</span></dd></div>@endif
    </dl>
    @if($movement->notes)<p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700"><b>Observaciones:</b> {{ $movement->notes }}</p>@endif

    <div class="table-wrap mt-5">
        <table class="table">
            <thead><tr><th>Producto</th><th class="text-right">Cantidad</th><th class="text-right">Total unid.</th><th class="text-right">Existencia anterior</th><th class="text-right">Existencia nueva</th></tr></thead>
            <tbody>
            @foreach($movement->items as $l)
                @php $p = $l->product; $in = $l->delta_base > 0; @endphp
                <tr>
                    <td><span class="block font-medium text-slate-900">{{ $l->product_name }}</span><span class="block text-xs text-slate-500">{{ $l->product_sku }} · {{ $l->type_label }}</span></td>
                    <td class="whitespace-nowrap text-right font-semibold {{ $in ? 'text-emerald-700' : 'text-blue-700' }}">{{ $in ? '+' : '−' }}{{ $converter->format($p, abs($l->delta_base)) }}</td>
                    <td class="whitespace-nowrap text-right">{{ $in ? '+' : '−' }}{{ $converter->formatTotal($p, abs($l->delta_base)) }}</td>
                    <td class="whitespace-nowrap text-right text-slate-500">{{ $converter->format($p, $l->stock_before_base) }}</td>
                    <td class="whitespace-nowrap text-right font-semibold">{{ $converter->format($p, $l->balance_after_base) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if($movement->attachments->isNotEmpty())
    <div class="no-print mt-5">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Fotos y documentos</p>
        <div class="flex flex-wrap gap-3">
            @foreach($movement->attachments as $a)
                <a href="{{ $a->url() }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                    @if($a->isImage())<img src="{{ $a->url() }}" alt="{{ $a->original_name }}" class="h-28 w-28 object-cover" loading="lazy">
                    @else<div class="flex h-28 w-28 flex-col items-center justify-center text-xs font-semibold text-slate-500"><span class="text-2xl">📄</span>PDF</div>@endif
                </a>
            @endforeach
        </div>
    </div>
    @endif

    @can('inventory.void')@unless($movement->isVoided() || $movement->type === 'void' || ($movement->type === 'container' && $movement->container_id))
    <form method="POST" action="{{ route('movements.void', $movement) }}" class="no-print mt-6 grid gap-3 rounded-xl border border-red-200 bg-red-50/50 p-4 sm:grid-cols-[1fr_auto] sm:items-end" @submit="if (!confirm('¿Anular este documento? Se generará un movimiento inverso.')) $event.preventDefault()">
        @csrf
        <x-field name="reason" label="Anular este documento (motivo obligatorio)" required />
        <button class="btn-danger">Anular documento</button>
        <p class="text-xs text-slate-500 sm:col-span-2">Se crea un documento de anulación que devuelve cada línea a su existencia anterior; el original se conserva marcado como anulado.</p>
    </form>
    @endunless @endcan

    <x-report-signatures />
</div>
@endsection
