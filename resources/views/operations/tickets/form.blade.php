@extends('layouts.app')
@section('title', 'Emitir ticket')
@section('content')
@php $isFuel = $type === 'fuel'; @endphp
<div class="mb-4"><a href="{{ route('containers.show', $container) }}" class="text-sm text-slate-500 hover:text-slate-800">← Furgón #{{ $container->number }}</a>
    <h1 class="page-title mt-1">{{ $isFuel ? 'Ticket de gasoil' : 'Ticket de materiales' }} · Furgón #{{ $container->number }}</h1>
    <p class="text-sm text-slate-500">El ticket guarda una copia de los datos actuales; si el furgón cambia después, el ticket emitido no se modifica.</p></div>
<form method="POST" action="{{ route('tickets.store', $container) }}" class="space-y-6" x-data="{ rows: @js(old('rows', $items)) }">
    @csrf <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="source" value="{{ $source }}">
    <div class="card card-body grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-field name="issued_at" label="Fecha" type="date" :value="now()->toDateString()" required />
        <x-field name="dispatched_by" label="Despachado por" :value="auth()->user()->name" />
        <x-field name="received_by" label="Recibido por" :value="$container->driver_display" />
        <div class="sm:col-span-2 lg:col-span-3"><x-field name="notes" label="Observaciones" /></div>
        <dl class="grid gap-2 text-sm sm:col-span-2 lg:col-span-3 sm:grid-cols-4">
            <div><dt class="text-xs uppercase text-slate-500">Chofer</dt><dd>{{ $container->driver_display ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">Conduce</dt><dd>{{ $container->conduce ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">Ficha</dt><dd>{{ $container->vehicle?->ficha ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">Teléfono</dt><dd>{{ $container->phone ?? '—' }}</dd></div>
        </dl>
    </div>

    <div class="card card-body space-y-3">
        @if($isFuel)
            <h2 class="font-semibold text-slate-900">Gasoil entregado</h2>
            <template x-for="(row, i) in rows" :key="i">
                <div class="grid grid-cols-2 gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                    <div><label class="label">Ficha</label><input :name="`rows[${i}][ficha]`" x-model="row.ficha" class="input" required></div>
                    <div><label class="label">Kilometraje</label><input :name="`rows[${i}][odometer]`" x-model="row.odometer" type="number" step="0.1" min="0" inputmode="decimal" class="input"></div>
                    <div class="col-span-2 sm:col-span-1"><label class="label">Galones</label><input :name="`rows[${i}][gallons]`" x-model="row.gallons" type="number" step="0.1" min="0.1" inputmode="decimal" class="input" required></div>
                    <div class="col-span-2 flex items-end justify-end sm:col-span-1"><button type="button" class="rounded-lg p-2 text-red-600 hover:bg-red-50" @click="rows.splice(i, 1)" x-show="rows.length > 1" aria-label="Quitar"><x-icon name="trash" class="h-5 w-5" /></button></div>
                </div>
            </template>
            <button type="button" class="btn-secondary w-full sm:w-auto" @click="rows.push({ ficha: '', odometer: '', gallons: '' })"><x-icon name="plus" class="h-4 w-4" /> Agregar fila</button>
        @else
            <h2 class="font-semibold text-slate-900">Materiales del ticket</h2>
            <div class="flex flex-wrap gap-2 text-sm">
                <a href="{{ route('tickets.create', [$container, 'type' => 'materials', 'source' => 'all']) }}" class="{{ $source === 'all' ? 'btn-primary' : 'btn-secondary' }} btn-sm">Todos los materiales del furgón</a>
                <a href="{{ route('tickets.create', [$container, 'type' => 'materials', 'source' => 'used']) }}" class="{{ $source === 'used' ? 'btn-primary' : 'btn-secondary' }} btn-sm">Solo lo gastado (descontado del inventario)</a>
            </div>
            @forelse($items as $it)
                <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm"><span class="font-medium text-slate-800">{{ $it['description'] }}</span><span class="text-slate-600">{{ $it['quantity_label'] }} <span class="text-slate-400">· {{ rtrim(rtrim(number_format($it['total_units'], 3), '0'), '.') }} {{ $it['unit_label'] }}</span></span></div>
            @empty
                <p class="rounded-lg bg-amber-50 px-3 py-3 text-sm text-amber-800">Este furgón aún no tiene materiales. <a class="underline" href="{{ route('containers.edit', $container) }}">Agréguelos primero</a>.</p>
            @endforelse
        @endif
    </div>
    <div class="flex gap-2"><button class="btn-primary">Emitir ticket</button><a href="{{ route('containers.show', $container) }}" class="btn-secondary">Cancelar</a></div>
</form>
@endsection
