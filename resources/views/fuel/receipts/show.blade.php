@extends('layouts.app')
@section('title', 'Recepción '.$receipt->receipt_number)
@section('content')
<div class="no-print mb-3 flex items-center justify-between"><a href="{{ route('fuel.receipts.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Recepciones</a><span class="flex gap-2">@can('fuel.edit')@unless($receipt->isVoided())<a href="{{ route('fuel.receipts.edit', $receipt) }}" class="btn-secondary btn-sm">Editar</a>@endunless @endcan<button type="button" @click="window.print()" class="btn-secondary btn-sm"><x-icon name="printer" class="h-4 w-4" /> Imprimir</button></span></div>
<div class="card card-body">
    <x-report-header title="Recepción de gasoil" :subtitle="'No. '.$receipt->receipt_number" :meta="['Fecha' => $receipt->received_at->format('d/m/Y')]" />
    <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Metro inicial</dt><dd class="text-lg font-semibold">{{ number_format($receipt->meter_start, 0) }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Metro final</dt><dd class="text-lg font-semibold">{{ number_format($receipt->meter_end, 0) }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Gasoil recibido</dt><dd class="text-lg font-bold text-emerald-700">{{ number_format($receipt->gallons_received, 1) }} gal</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Suplidor / camión</dt><dd>{{ $receipt->supplier ?? '—' }} · {{ $receipt->truck_plate ?? '—' }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Reserva anterior</dt><dd class="text-lg">{{ number_format($receipt->reserve_before, 1) }} gal</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Reserva resultante</dt><dd class="text-lg font-bold">{{ number_format($receipt->reserve_after, 1) }} gal</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Registrado por</dt><dd>{{ $receipt->user?->name }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Tanque</dt><dd>{{ $receipt->tank?->name }}</dd></div>
    </dl>
    @if($receipt->notes)<p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700"><b>Observaciones:</b> {{ $receipt->notes }}</p>@endif
    @if($receipt->isVoided())<div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"><b>ANULADA</b> el {{ $receipt->voided_at->format('d/m/Y H:i') }} por {{ $receipt->voider?->name }} — Motivo: {{ $receipt->void_reason }}</div>@endif
    @if($receipt->attachments->isNotEmpty())
        <div class="no-print mt-5"><p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Fotografías</p>
            <div class="flex flex-wrap gap-3">@foreach($receipt->attachments as $a)<a href="{{ $a->url() }}" target="_blank" rel="noopener" class="block"><img src="{{ $a->url() }}" alt="{{ $photoTypes[$a->collection] ?? $a->collection }}" class="h-28 w-28 rounded-lg border border-slate-200 object-cover" loading="lazy"><span class="block text-center text-xs text-slate-500">{{ $photoTypes[$a->collection] ?? $a->collection }}</span></a>@endforeach</div></div>
    @endif
    <x-report-signatures />
</div>
@can('fuel.void')@unless($receipt->isVoided())
<form method="POST" action="{{ route('fuel.receipts.void', $receipt) }}" class="no-print card card-body mt-6 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end" @submit="if (!confirm('¿Anular esta recepción? Se descontarán los galones de la reserva.')) $event.preventDefault()">
    @csrf <x-field name="reason" label="Anular recepción (motivo obligatorio)" required /><button class="btn-danger">Anular recepción</button>
</form>
@endunless @endcan
@endsection
