@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
@php $nf = fn ($n) => number_format($n, $n == floor($n) ? 0 : 1); @endphp
<div class="mb-6">
    <h1 class="page-title">Hola, {{ auth()->user()->first_name }} 👋</h1>
    <p class="text-sm text-slate-500">{{ $currentCompany->name }} · {{ now()->translatedFormat('l d \d\e F, Y') }}</p>
</div>

@isset($containers)
<div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <x-stat label="Furgones este mes" :value="$containers['month']" icon="truck" tone="blue" :href="route('containers.index', ['from' => now()->startOfMonth()->toDateString()])" />
    <x-stat label="Furgones abiertos / en proceso" :value="$containers['open']" icon="clipboard" tone="yellow" :href="route('containers.index', ['status' => 'open'])" />
</div>
@endisset

@isset($inventory)
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <x-stat label="Productos registrados" :value="$nf($inventory['products'])" icon="box" tone="blue" :href="route('products.index')" />
    <x-stat label="Unidades en existencia" :value="$nf($inventory['units'])" icon="archive" tone="green" hint="Suma en unidad base de todos los productos" :href="route('inventory.index')" />
    <x-stat label="Stock bajo" :value="$inventory['low']" icon="alert" tone="yellow" :hint="$inventory['critical'].' en nivel crítico'" :href="route('inventory.index', ['status' => 'low'])" />
    <x-stat label="Productos agotados" :value="$inventory['out']" icon="x" tone="red" :href="route('inventory.index', ['status' => 'out'])" />
    <x-stat label="Entradas del mes" :value="$inventory['entries_docs']" icon="arrow-down" tone="green" :hint="$nf($inventory['entries_units']).' unidades ingresadas'" :href="route('movements.index', ['type' => 'entry', 'from' => now()->startOfMonth()->toDateString()])" />
    <x-stat label="Salidas del mes" :value="$inventory['exits_docs']" icon="arrow-up" tone="blue" :hint="$nf($inventory['exits_units']).' unidades despachadas'" :href="route('movements.index', ['type' => 'exit', 'from' => now()->startOfMonth()->toDateString()])" />
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-2">
    <div class="card">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <h2 class="font-semibold text-slate-900">Alertas de inventario</h2>
            <a href="{{ route('inventory.index', ['status' => 'low']) }}" class="text-sm font-medium text-brand hover:underline">Ver todas</a>
        </div>
        @forelse($alerts as $a)
            @php $p = $a['product']; @endphp
            <a href="{{ route('products.show', $p) }}" class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50">
                <x-product-thumb :product="$p" size="h-11 w-11" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-800">{{ $p->name }}</p>
                    <p class="truncate text-xs text-slate-500">Hay {{ $converter->format($p, $p->totalBase()) }} · mínimo {{ $converter->formatMinimum($p) }}</p>
                </div>
                <x-status-badge :status="$a['status']" />
            </a>
        @empty
            <p class="px-5 py-8 text-center text-sm text-slate-500">Sin alertas: todos los productos están disponibles.</p>
        @endforelse
    </div>

    <div class="card">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <h2 class="font-semibold text-slate-900">Movimientos recientes</h2>
            <a href="{{ route('movements.index') }}" class="text-sm font-medium text-brand hover:underline">Ver historial</a>
        </div>
        @forelse($recent as $m)
            <a href="{{ route('movements.show', $m) }}" class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $m->type === 'entry' ? 'bg-emerald-50 text-emerald-600' : ($m->type === 'exit' ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600') }}">
                    <x-icon :name="$m->type === 'entry' ? 'arrow-down' : ($m->type === 'exit' ? 'arrow-up' : 'scale')" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-800">{{ $m->type_label }} {{ $m->number }}</p>
                    <p class="truncate text-xs text-slate-500">{{ $m->items_count }} producto(s) · {{ $m->warehouse?->name }} · {{ $m->user?->name }}</p>
                </div>
                <time class="shrink-0 text-xs text-slate-400">{{ $m->occurred_at->format('d/m H:i') }}</time>
            </a>
        @empty
            <p class="px-5 py-8 text-center text-sm text-slate-500">Aún no hay movimientos. Registre la primera entrada de almacén.</p>
        @endforelse
    </div>
</div>
@endisset

@isset($recentContainers)
@if($recentContainers->isNotEmpty())
<div class="card mt-6">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3"><h2 class="font-semibold text-slate-900">Furgones recientes</h2><a href="{{ route('containers.index') }}" class="text-sm font-medium text-brand hover:underline">Ver todos</a></div>
    @foreach($recentContainers as $c)
        <a href="{{ route('containers.show', $c) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50"><div class="min-w-0"><p class="text-sm font-semibold text-slate-800">Furgón #{{ $c->number }}</p><p class="truncate text-xs text-slate-500">{{ $c->date->format('d/m/Y') }} · {{ $c->driver_display ?? 'Sin chofer' }} {{ $c->vehicle ? '· '.$c->vehicle->ficha : '' }}</p></div><span class="{{ $c->status_badge }}">{{ $c->status_label }}</span></a>
    @endforeach
</div>
@endif
@endisset

{{-- Módulos futuros: sin cifras inventadas --}}
<h2 class="mb-3 mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">Próximos módulos</h2>
<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach([['Gasoil disponible', 'fuel', 'Fase 4'], ['Vehículos con averías', 'alert', 'Fase 5'], ['Mantenimientos pendientes', 'wrench', 'Fase 5']] as [$label, $icon, $phase])
        <div class="card flex items-center gap-3 border-dashed bg-slate-50/60 p-4 opacity-80">
            <x-icon :name="$icon" class="h-7 w-7 shrink-0 text-slate-400" />
            <div class="min-w-0"><p class="truncate text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p><p class="text-sm font-medium text-slate-400">Próximamente · {{ $phase }}</p></div>
        </div>
    @endforeach
</div>

@if($audit->isNotEmpty())
<div class="card mt-8">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
        <h2 class="font-semibold text-slate-900">Actividad reciente</h2>
        <a href="{{ route('admin.audit.index') }}" class="text-sm font-medium text-brand hover:underline">Ver auditoría</a>
    </div>
    <ul class="divide-y divide-slate-100">
        @foreach($audit as $log)
            <li class="flex items-start justify-between gap-3 px-5 py-3 text-sm">
                <div class="min-w-0"><p class="truncate font-medium text-slate-800">{{ $log->description ?? $log->action }}</p><p class="text-xs text-slate-500">{{ $log->user_name ?? 'Sistema' }}</p></div>
                <time class="shrink-0 text-xs text-slate-400">{{ $log->created_at->format('d/m/Y H:i') }}</time>
            </li>
        @endforeach
    </ul>
</div>
@endif
@endsection
