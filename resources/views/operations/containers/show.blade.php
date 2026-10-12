@extends('layouts.app')
@section('title', 'Furgón '.$container->number)
@section('content')
@php $nf = fn ($n) => rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.'); @endphp
<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('containers.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Furgones</a>
    <div class="flex flex-wrap gap-2">
        @if($container->isEditable())
            @can('containers.create')<a href="{{ route('containers.edit', $container) }}" class="btn-secondary btn-sm"><x-icon name="pencil" class="h-4 w-4" /> Editar</a>@endcan
            @if($container->status === 'open')@can('containers.create')<form method="POST" action="{{ route('containers.start', $container) }}">@csrf<button class="btn-secondary btn-sm">Marcar en proceso</button></form>@endcan @endif
        @endif
        @can('tickets.create')@unless($container->status === 'voided')
            <a href="{{ route('tickets.create', [$container, 'type' => 'materials']) }}" class="btn-secondary btn-sm"><x-icon name="ticket" class="h-4 w-4" /> Ticket de materiales</a>
            <a href="{{ route('tickets.create', [$container, 'type' => 'fuel']) }}" class="btn-secondary btn-sm"><x-icon name="fuel" class="h-4 w-4" /> Ticket de gasoil</a>
        @endunless @endcan
        <button type="button" @click="window.print()" class="btn-secondary btn-sm"><x-icon name="printer" class="h-4 w-4" /> Imprimir</button>
    </div>
</div>

<div class="card card-body">
    <x-report-header :title="'Furgón #'.$container->number" :subtitle="$container->status_label" :meta="['Fecha' => $container->date->format('d/m/Y')]" />

    <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Estado</dt><dd><span class="{{ $container->status_badge }}">{{ $container->status_label }}</span></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Chofer</dt><dd>{{ $container->driver_display ?? '—' }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Teléfono</dt><dd>{{ $container->phone ?? '—' }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vehículo</dt><dd>@if($container->vehicle)<a href="{{ route('vehicles.show', $container->vehicle) }}" class="text-brand hover:underline">{{ $container->vehicle->ficha }}</a> {{ $container->vehicle->plate }}@else — @endif</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Conduce</dt><dd>{{ $container->conduce ?? '—' }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Responsable</dt><dd>{{ $container->responsible?->name ?? '—' }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Almacén</dt><dd>{{ $container->warehouse?->name }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gasoil</dt><dd>{{ $container->fuel_gallons > 0 ? number_format($container->fuel_gallons, 1).' galones' : '—' }}</dd></div>
        @if($container->closed_at)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cerrado</dt><dd>{{ $container->closed_at->format('d/m/Y H:i') }} · {{ $container->closer?->name }}</dd></div>@endif
        @if($container->movement)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Movimiento de inventario</dt><dd><a href="{{ route('movements.show', $container->movement) }}" class="text-brand hover:underline">{{ $container->movement->number }}</a></dd></div>@endif
    </dl>
    @if($container->notes)<p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700"><b>Observaciones:</b> {{ $container->notes }}</p>@endif
    @if($container->status === 'voided')
        <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"><b>ANULADO</b> el {{ $container->voided_at->format('d/m/Y H:i') }} por {{ $container->voider?->name }} — Motivo: {{ $container->void_reason }}</div>
    @endif

    <h2 class="mt-6 mb-2 font-semibold text-slate-900">Materiales utilizados</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Producto</th><th class="text-right">Cantidad</th><th class="text-right">Total unidades</th>@if($container->status === 'closed')<th class="text-right">Consumo real (libro)</th>@endif</tr></thead>
            <tbody>
            @forelse($container->materials as $m)
                @php $real = $consumption->firstWhere('product_id', $m->product_id); @endphp
                <tr>
                    <td><span class="font-medium text-slate-900">{{ $m->product_name }}</span><span class="block text-xs text-slate-500">{{ $m->product_sku }}</span></td>
                    <td class="text-right">{{ $converter->format($m->product, $m->quantity_base) }}</td>
                    <td class="text-right">{{ $converter->formatTotal($m->product, $m->quantity_base) }}</td>
                    @if($container->status === 'closed')<td class="text-right">{{ $real ? $converter->formatTotal($m->product, $real->quantity_base) : '—' }}</td>@endif
                </tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-slate-500">Sin materiales registrados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($linked->isNotEmpty())
    <h2 class="mt-6 mb-2 font-semibold text-slate-900">Otras salidas de almacén asociadas a este furgón</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Documento</th><th>Fecha</th><th>Productos</th><th>Usuario</th></tr></thead><tbody>
        @foreach($linked as $mv)
            <tr><td><a href="{{ route('movements.show', $mv) }}" class="text-brand hover:underline">{{ $mv->number }}</a> @if($mv->isVoided())<span class="badge-red">Anulado</span>@endif</td><td>{{ $mv->occurred_at->format('d/m/Y H:i') }}</td>
                <td>{{ $mv->items->map(fn ($i) => $i->product_name.' '.$nf(abs($i->delta_base)))->implode(', ') }}</td><td>{{ $mv->user?->name }}</td></tr>
        @endforeach
    </tbody></table></div>
    @endif

    @if($container->tickets->isNotEmpty())
    <h2 class="no-print mt-6 mb-2 font-semibold text-slate-900">Tickets</h2>
    <div class="no-print flex flex-wrap gap-2">
        @foreach($container->tickets as $t)
            <a href="{{ route('tickets.show', $t) }}" class="btn-secondary btn-sm {{ $t->isVoided() ? 'line-through opacity-60' : '' }}">{{ $t->type === 'fuel' ? 'Gasoil' : 'Materiales' }} {{ $t->number }} @if($t->isVoided())<span class="badge-red ml-1">Anulado</span>@endif</a>
        @endforeach
    </div>
    @endif

    <x-report-signatures />
</div>

@if($container->isEditable() || $container->status === 'closed')
<div class="no-print mt-6 grid gap-4 lg:grid-cols-2">
    @if($container->isEditable())
    @can('containers.close')
    <form method="POST" action="{{ route('containers.close', $container) }}" class="card card-body space-y-3" @submit="if (!confirm('¿Cerrar el furgón? Se descontarán los materiales del inventario.')) $event.preventDefault()">
        @csrf
        <h2 class="font-semibold text-slate-900">Cerrar furgón</h2>
        <p class="text-sm text-slate-500">Descuenta automáticamente todos los materiales del inventario y conserva el historial.</p>
        @if(auth()->user()->hasPermission('inventory.negative') && $currentCompany->setting('allow_negative_stock'))
            <label class="flex items-start gap-2 text-sm text-red-700"><input type="checkbox" name="allow_negative" value="1" class="mt-0.5 rounded border-red-300"> Autorización especial: permitir existencia negativa.</label>
        @endif
        <button class="btn-success">Cerrar y descontar inventario</button>
    </form>
    @endcan
    @endif
    @can('containers.void')
    <form method="POST" action="{{ route('containers.void', $container) }}" class="card card-body space-y-3" @submit="if (!confirm('¿Anular el furgón? Esta acción queda registrada.')) $event.preventDefault()">
        @csrf
        <h2 class="font-semibold text-slate-900">Anular furgón</h2>
        <p class="text-sm text-slate-500">{{ $container->status === 'closed' ? 'Se devolverán los materiales al inventario con un movimiento de anulación y se anularán sus tickets.' : 'Se anularán también sus tickets. No se borra nada.' }}</p>
        <x-field name="reason" label="Motivo de la anulación" required />
        @if($container->status === 'closed' && auth()->user()->hasPermission('inventory.negative') && $currentCompany->setting('allow_negative_stock'))
            <label class="flex items-start gap-2 text-sm text-red-700"><input type="checkbox" name="allow_negative" value="1" class="mt-0.5 rounded border-red-300"> Autorización especial (existencia negativa).</label>
        @endif
        <button class="btn-danger">Anular furgón</button>
    </form>
    @endcan
</div>
@endif

<div class="no-print card mt-6">
    <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">Historial del furgón</h2>
    <ul class="divide-y divide-slate-100">
        @forelse($history as $h)
            <li class="flex items-start justify-between gap-3 px-5 py-3 text-sm"><div><p class="font-medium text-slate-800">{{ $h->description ?? $h->action }}</p><p class="text-xs text-slate-500">{{ $h->user_name ?? 'Sistema' }}</p></div><time class="shrink-0 text-xs text-slate-400">{{ $h->created_at->format('d/m/Y H:i') }}</time></li>
        @empty
            <li class="px-5 py-6 text-center text-sm text-slate-500">Sin eventos.</li>
        @endforelse
    </ul>
</div>
@endsection
