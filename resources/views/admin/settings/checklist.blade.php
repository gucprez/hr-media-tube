@extends('layouts.app')
@section('title', 'Checklist y tipos de vehículo')
@section('content')
@include('admin.settings._tabs')
<div class="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
    <section class="space-y-3">
        <h2 class="font-semibold text-slate-900">Checklist de inspección mensual</h2>
        <form method="POST" action="{{ route('admin.settings.checklist.store') }}" class="card card-body flex flex-wrap items-end gap-3">@csrf<x-field name="name" label="Nuevo punto de inspección" class="min-w-[220px] flex-1" required /><button class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Agregar</button></form>
        <div class="card divide-y divide-slate-100">
            @foreach($items as $it)
            <div class="flex flex-wrap items-center gap-2 px-4 py-2.5">
                <form method="POST" action="{{ route('admin.settings.checklist.update', $it) }}" class="flex min-w-0 flex-1 items-center gap-2">@csrf @method('PUT')
                    <input name="sort_order" type="number" value="{{ $it->sort_order }}" min="0" max="999" class="input !min-h-9 !w-20" aria-label="Orden">
                    <input name="name" value="{{ $it->name }}" class="input !min-h-9 min-w-0 flex-1" required aria-label="Nombre">
                    <label class="flex items-center gap-1 text-xs text-slate-500"><input type="checkbox" name="is_active" value="1" @checked($it->is_active) class="rounded border-slate-300"> Activo</label>
                    <button class="btn-secondary btn-sm">Guardar</button></form>
                <form method="POST" action="{{ route('admin.settings.checklist.destroy', $it) }}" @submit="if (!confirm('¿Eliminar este punto?')) $event.preventDefault()">@csrf @method('DELETE')<button class="btn-danger btn-sm" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button></form>
            </div>
            @endforeach
        </div>
    </section>
    <section class="space-y-3">
        <h2 class="font-semibold text-slate-900">Tipos de vehículo</h2>
        <form method="POST" action="{{ route('admin.settings.vehicle-types.store') }}" class="card card-body flex flex-wrap items-end gap-3">@csrf<x-field name="name" label="Nuevo tipo" class="min-w-[160px] flex-1" required /><button class="btn-primary">Agregar</button></form>
        <div class="card divide-y divide-slate-100">@foreach($types as $t)<div class="flex items-center justify-between px-4 py-2.5 text-sm"><span class="font-medium text-slate-800">{{ $t->name }}</span>
            <form method="POST" action="{{ route('admin.settings.vehicle-types.destroy', $t) }}" @submit="if (!confirm('¿Eliminar el tipo?')) $event.preventDefault()">@csrf @method('DELETE')<button class="btn-danger btn-sm" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button></form></div>@endforeach</div>
        <p class="text-xs text-slate-500">Estados de retornables, estados de gomas y prioridades de averías son fijos del sistema para mantener los reportes comparables.</p>
    </section>
</div>
@endsection
