@extends('layouts.app')
@section('title', 'Gomas')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Gomas nuevas y usadas</h1><p class="text-sm text-slate-500">Cada goma nueva entregada exige saber si se recibió la usada.</p></div>
    @can('tires.manage')<a href="{{ route('tires.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Entregar goma nueva</a>@endcan
</div>
<div class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
    @foreach(['pending' => 'Devoluciones pendientes ('.$pendingCount.')', 'new' => 'Gomas nuevas', 'used' => 'Gomas usadas', 'history' => 'Historial de entregas'] as $k => $l)
        <a href="{{ route('tires.index', ['tab' => $k]) }}" class="whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $k ? 'border-brand text-brand' : 'border-transparent text-slate-500 hover:text-slate-800' }}">{{ $l }}</a>
    @endforeach
</div>

@if($tab === 'pending')
<div class="space-y-3">
    @forelse($pending as $t)
        <div class="card card-body" x-data="{ open: false, waive: false }">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="font-bold text-slate-900"><span class="text-lg">{{ $t->vehicle?->ficha }}</span> · 1 goma usada pendiente <span class="badge-yellow">DEVOLUCIÓN PENDIENTE</span></p>
                    <p class="text-sm text-slate-500">Se entregó {{ $t->product_name }} el {{ $t->delivered_at->format('d/m/Y') }}{{ $t->odometer ? ' · '.number_format($t->odometer, 1).' km' : '' }} · hace {{ $t->delivered_at->diffForHumans(null, true) }}</p></div>
                @can('tires.manage')<div class="flex gap-2"><button type="button" @click="open = !open; waive = false" class="btn-success btn-sm">Recibir goma usada</button><button type="button" @click="waive = !waive; open = false" class="btn-secondary btn-sm">Cancelar devolución</button></div>@endcan
            </div>
            @can('tires.manage')
            <form x-show="open" x-cloak method="POST" action="{{ route('tires.receive', $t) }}" enctype="multipart/form-data" class="mt-4 space-y-3 border-t border-slate-100 pt-4">@csrf
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Estado de la goma usada <span class="text-red-500">*</span></label><select name="condition" class="input" required><option value="">Seleccione…</option>@foreach(\App\Models\TireReturn::CONDITIONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <x-field name="notes" label="Observaciones" /></div>
                <x-photo-picker name="photo" label="Fotografía de la goma usada" :max="3" />
                <p class="text-xs text-slate-500">Recibe: {{ auth()->user()->name }} · {{ now()->format('d/m/Y') }}</p>
                <button class="btn-success">Registrar recepción</button>
            </form>
            <form x-show="waive" x-cloak method="POST" action="{{ route('tires.waive', $t) }}" class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-[1fr_auto] sm:items-end">@csrf<x-field name="reason" label="Motivo de la cancelación" required /><button class="btn-danger">Cancelar devolución</button></form>
            @endcan
        </div>
    @empty
        <div class="card card-body text-center text-slate-500">No hay devoluciones pendientes. 🎉</div>
    @endforelse
</div>
@elseif($tab === 'new')
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    @forelse($newTires as $p)
        <a href="{{ route('products.show', $p) }}" class="card flex items-center gap-3 p-4 hover:shadow-md"><x-product-thumb :product="$p" size="h-14 w-14" /><div class="min-w-0 flex-1"><p class="truncate font-semibold text-slate-900">{{ $p->name }}</p><p class="text-xs text-slate-500">{{ $p->sku }}</p></div><div class="text-right"><p class="text-2xl font-bold text-slate-900">{{ $converter->format($p, $p->totalBase()) }}</p><x-status-badge :status="$p->status()" /></div></a>
    @empty
        <div class="card card-body text-center text-slate-500 sm:col-span-2 xl:col-span-3">No hay productos configurados como goma. En <b>Productos</b> marque «retornable» con tipo «Goma».</div>
    @endforelse
</div>
@elseif($tab === 'used')
<div class="mb-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
    @foreach(\App\Models\TireReturn::CONDITIONS as $k => $l)<div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $l }}</p><p class="text-3xl font-bold text-slate-900">{{ $usedInventory[$k] }}</p></div>@endforeach
</div>
<div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Recibida</th><th>Vehículo</th><th>Estado</th><th>Recibió</th><th>Observaciones</th><th>Foto</th></tr></thead><tbody>
    @forelse($received as $t)<tr><td>{{ $t->received_at?->format('d/m/Y H:i') }}</td><td class="font-semibold">{{ $t->vehicle?->ficha }}</td><td><span class="badge-gray">{{ \App\Models\TireReturn::CONDITIONS[$t->condition] ?? '' }}</span></td><td>{{ $t->receiver?->name }}</td><td>{{ $t->notes }}</td><td>@foreach($t->attachments as $a)<a href="{{ $a->url() }}" target="_blank" rel="noopener"><img src="{{ $a->url() }}" class="inline h-10 w-10 rounded border border-slate-200 object-cover" alt="Goma usada" loading="lazy"></a>@endforeach</td></tr>@empty<tr><td colspan="6" class="py-8 text-center text-slate-500">Aún no hay gomas usadas recibidas.</td></tr>@endforelse
</tbody></table></div></div>
@else
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-[1fr_auto]"><input type="hidden" name="tab" value="history"><select name="vehicle" class="input" aria-label="Vehículo"><option value="">Todos los vehículos</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') == $v->id)>{{ $v->ficha }}</option>@endforeach</select><button class="btn-primary">Filtrar</button></form>
<div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Fecha</th><th>Vehículo</th><th>Goma entregada</th><th class="text-right">Km</th><th>Responsable</th><th>Usada</th><th>Estado de la usada</th></tr></thead><tbody>
    @forelse($history as $t)<tr><td>{{ $t->delivered_at->format('d/m/Y') }}</td><td class="font-semibold">{{ $t->vehicle?->ficha }}</td><td>{{ $t->product_name }}</td><td class="text-right">{{ $t->odometer ? number_format($t->odometer, 1) : '—' }}</td><td>{{ $t->deliverer?->name }}</td>
        <td>@if($t->status === 'pending')<span class="badge-yellow">Pendiente</span>@elseif($t->status === 'received')<span class="badge-green">Recibida</span>@else<span class="badge-gray" title="{{ $t->waived_reason }}">Cancelada</span>@endif</td><td>{{ $t->condition ? \App\Models\TireReturn::CONDITIONS[$t->condition] : '—' }}</td></tr>@empty<tr><td colspan="7" class="py-8 text-center text-slate-500">Sin entregas registradas.</td></tr>@endforelse
</tbody></table></div></div>
<div class="mt-2">{{ $history->links() }}</div>
@endif
@endsection
