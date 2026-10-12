@extends('layouts.app')
@section('title', 'Mantenimiento '.$record->vehicle?->ficha)
@section('content')
<div class="no-print mb-3 flex items-center justify-between"><a href="{{ route('maintenance.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Mantenimiento</a><button type="button" @click="window.print()" class="btn-secondary btn-sm"><x-icon name="printer" class="h-4 w-4" /> Imprimir</button></div>
<div class="card card-body">
    <x-report-header title="Orden de mantenimiento" :subtitle="'Vehículo '.$record->vehicle?->ficha" :meta="['Fecha' => $record->performed_at->format('d/m/Y'), 'Tipo' => \App\Models\MaintenanceRecord::TYPES[$record->type] ?? '', 'Kilometraje' => $record->odometer ? number_format($record->odometer, 1) : '—']" />
    <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Responsable</dt><dd>{{ $record->responsible ?? '—' }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Registrado por</dt><dd>{{ $record->user?->name }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Avería</dt><dd>@if($record->incident)<a href="{{ route('incidents.show', $record->incident) }}" class="text-brand hover:underline">{{ $record->incident->title }}</a>@else — @endif</dd></div>
        <div><dt class="text-xs font-semibold uppercase text-slate-500">Movimiento de inventario</dt><dd>@if($record->movement)<a href="{{ route('movements.show', $record->movement) }}" class="text-brand hover:underline">{{ $record->movement->number }}</a>@else — @endif</dd></div>
    </dl>
    <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-800"><b>Descripción:</b> {{ $record->description }}</p>
    @if($record->notes)<p class="mt-2 text-sm text-slate-600"><b>Observaciones:</b> {{ $record->notes }}</p>@endif
    @if($record->isVoided())<div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"><b>ANULADO</b> el {{ $record->voided_at->format('d/m/Y H:i') }} por {{ $record->voider?->name }} — {{ $record->void_reason }}</div>@endif
    <h2 class="mt-5 mb-2 font-semibold text-slate-900">Piezas y materiales utilizados</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Producto</th><th class="text-right">Cantidad</th></tr></thead><tbody>
        @forelse($record->parts as $p)<tr><td class="font-medium text-slate-900">{{ $p->product_name }}<span class="block text-xs font-normal text-slate-500">{{ $p->product_sku }}</span></td><td class="text-right">{{ $converter->format($p->product, $p->quantity_base) }}</td></tr>@empty<tr><td colspan="2" class="py-6 text-center text-slate-500">Sin piezas.</td></tr>@endforelse
    </tbody></table></div>
    @if($record->attachments->isNotEmpty())<div class="no-print mt-4 flex flex-wrap gap-3">@foreach($record->attachments as $a)<a href="{{ $a->url() }}" target="_blank" rel="noopener"><img src="{{ $a->url() }}" class="h-28 w-28 rounded-lg border border-slate-200 object-cover" alt="Foto de la reparación" loading="lazy"></a>@endforeach</div>@endif
    <x-report-signatures />
</div>
@can('maintenance.manage')@unless($record->isVoided())
<form method="POST" action="{{ route('maintenance.void', $record) }}" class="no-print card card-body mt-6 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end" @submit="if (!confirm('¿Anular este mantenimiento? Las piezas volverán al inventario.')) $event.preventDefault()">@csrf
    <x-field name="reason" label="Anular mantenimiento (motivo obligatorio)" required /><button class="btn-danger">Anular</button></form>
@endunless @endcan
@endsection
