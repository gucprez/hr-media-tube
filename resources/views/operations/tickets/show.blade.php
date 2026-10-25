@extends('layouts.app')
@section('title', $ticket->type_label.' '.$ticket->number)
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('tickets.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Tickets</a>
    @unless($ticket->isVoided())@can('tickets.print')
    <div class="flex flex-wrap gap-2">
        @foreach([1, 2, 4] as $n)<a href="{{ route('tickets.print', [$ticket, 'copies' => $n]) }}" target="_blank" class="btn-primary btn-sm"><x-icon name="printer" class="h-4 w-4" /> {{ $n }} por hoja</a>@endforeach
        <a href="{{ route('tickets.pdf', [$ticket, 'copies' => 4]) }}" class="btn-secondary btn-sm">PDF (4 por hoja)</a>
        <a href="{{ route('tickets.pdf', [$ticket, 'copies' => 2]) }}" class="btn-secondary btn-sm">PDF (2)</a>
        <a href="{{ route('tickets.pdf', [$ticket, 'copies' => 1]) }}" class="btn-secondary btn-sm">PDF (1)</a>
    </div>
    @endcan @endunless
</div>

<div class="grid gap-6 lg:grid-cols-[1fr_340px]">
    <div class="card card-body relative">
        @if($ticket->isVoided())<div class="pointer-events-none absolute inset-0 flex items-center justify-center"><span class="-rotate-12 rounded-xl border-4 border-red-500/70 px-6 py-2 text-5xl font-black tracking-widest text-red-500/60">ANULADO</span></div>@endif
        <x-report-header :title="$ticket->type_label" :subtitle="'N° '.$ticket->number" :meta="['Fecha' => $ticket->issued_at->format('d/m/Y')]" />
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Furgón</dt><dd><a href="{{ route('containers.show', $ticket->container_id) }}" class="font-semibold text-brand hover:underline">#{{ $ticket->container_number }}</a></dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Chofer</dt><dd>{{ $ticket->driver_name ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Conduce</dt><dd>{{ $ticket->conduce ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Ficha / Teléfono</dt><dd>{{ $ticket->ficha ?? '—' }} · {{ $ticket->phone ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Despachado por</dt><dd>{{ $ticket->dispatched_by ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Recibido por</dt><dd>{{ $ticket->received_by ?? '—' }}</dd></div>
        </dl>
        <div class="table-wrap mt-4"><table class="table">
            @if($ticket->type === 'fuel')
                <thead><tr><th>Ficha</th><th class="text-right">Kilometraje</th><th class="text-right">Galones</th></tr></thead>
                <tbody>@foreach($ticket->items as $i)<tr><td>{{ $i->ficha }}</td><td class="text-right">{{ $i->odometer !== null ? number_format($i->odometer, 1) : '' }}</td><td class="text-right">{{ number_format($i->gallons, 1) }}</td></tr>@endforeach
                <tr class="font-bold"><td colspan="2" class="text-right">Total galones</td><td class="text-right">{{ number_format($ticket->items->sum('gallons'), 1) }}</td></tr></tbody>
            @else
                <thead><tr><th>Producto</th><th>Cantidad</th><th class="text-right">Total unidades</th></tr></thead>
                <tbody>@foreach($ticket->items as $i)<tr><td class="font-medium text-slate-900">{{ $i->description }}</td><td>{{ $i->quantity_label }}</td><td class="text-right">{{ rtrim(rtrim(number_format($i->total_units, 3), '0'), '.') }} {{ $i->unit_label }}</td></tr>@endforeach</tbody>
            @endif
        </table></div>
        @if($ticket->notes)<p class="mt-3 text-sm text-slate-600"><b>Obs.:</b> {{ $ticket->notes }}</p>@endif
        @if($ticket->isVoided())
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"><b>ANULADO</b> el {{ $ticket->voided_at->format('d/m/Y H:i') }} por {{ $ticket->voider?->name }}<br>Motivo: {{ $ticket->void_reason }}</div>
        @endif
        <p class="mt-3 text-xs text-slate-500">Emitido por {{ $ticket->creator?->name }} · {{ $ticket->print_count }} impresión(es) @if($ticket->last_printed_at)· última {{ $ticket->last_printed_at->format('d/m/Y H:i') }}@endif</p>
    </div>

    <div class="space-y-4">
        @unless($ticket->isVoided())@can('tickets.void')
        <form method="POST" action="{{ route('tickets.void', $ticket) }}" class="card card-body space-y-3" @submit="if (!confirm('¿Anular el ticket? Se BORRARÁ; su número no se vuelve a usar. Solo queda constancia en la auditoría.')) $event.preventDefault()">
            @csrf
            <h2 class="font-semibold text-slate-900">Anular ticket</h2>
            <p class="text-sm text-slate-500">El ticket no se elimina: queda como ANULADO con su usuario, fecha y motivo.</p>
            <x-field name="reason" label="Motivo" required />
            <button class="btn-danger">Anular y borrar ticket</button>
        </form>
        @endcan @endunless
        <div class="card"><h2 class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-900">Historial</h2>
            <ul class="divide-y divide-slate-100">@forelse($history as $h)<li class="px-4 py-3 text-sm"><p class="font-medium text-slate-800">{{ $h->description ?? $h->action }}</p><p class="text-xs text-slate-500">{{ $h->user_name }} · {{ $h->created_at->format('d/m/Y H:i') }}</p></li>@empty<li class="px-4 py-6 text-center text-sm text-slate-500">Sin eventos.</li>@endforelse</ul></div>
    </div>
</div>
@endsection
