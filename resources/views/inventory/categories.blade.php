@extends('layouts.app')
@section('title', 'Categorías y unidades')
@section('content')
<h1 class="page-title mb-4">Categorías y unidades</h1>
<div class="grid gap-6 xl:grid-cols-2">
    <section class="space-y-3">
        <h2 class="font-semibold text-slate-900">Categorías de productos</h2>
        @can('products.create')
        <form method="POST" action="{{ route('categories.store') }}" class="card card-body flex flex-wrap items-end gap-3">
            @csrf
            <x-field name="name" label="Nueva categoría" class="min-w-[200px] flex-1" required />
            <button class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Agregar</button>
        </form>
        @endcan
        <div class="card divide-y divide-slate-100">
            @forelse($categories as $c)
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    @can('products.edit')
                    <form method="POST" action="{{ route('categories.update', $c) }}" class="flex min-w-0 flex-1 items-center gap-2">
                        @csrf @method('PUT')
                        <input name="name" value="{{ $c->name }}" class="input !min-h-9 min-w-0 flex-1" required aria-label="Nombre">
                        <label class="flex items-center gap-1 text-xs text-slate-500"><input type="checkbox" name="is_active" value="1" @checked($c->is_active) class="rounded border-slate-300"> Activa</label>
                        <button class="btn-secondary btn-sm">Guardar</button>
                    </form>
                    @else
                    <span class="flex-1 text-sm font-medium">{{ $c->name }}</span>
                    @endcan
                    <a href="{{ route('products.index', ['category' => $c->id]) }}" class="text-xs text-brand hover:underline">{{ $c->products_count }} producto(s)</a>
                    @can('products.delete')
                    <form method="POST" action="{{ route('categories.destroy', $c) }}" x-data @submit="if (!confirm({{ \Illuminate\Support\Js::from('¿Eliminar la categoría '.$c->name.'?') }})) $event.preventDefault()">@csrf @method('DELETE')<button class="btn-danger btn-sm" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button></form>
                    @endcan
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-slate-500">Aún no hay categorías.</p>
            @endforelse
        </div>
    </section>

    <section class="space-y-3">
        <h2 class="font-semibold text-slate-900">Unidades de medida</h2>
        @can('products.create')
        <form method="POST" action="{{ route('units.store') }}" class="card card-body grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            @csrf
            <x-field name="name" label="Unidad (singular)" required />
            <x-field name="plural" label="Plural" />
            <button class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Agregar</button>
        </form>
        @endcan
        <div class="card divide-y divide-slate-100">
            @foreach($units as $u)
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    @can('products.edit')
                    <form method="POST" action="{{ route('units.update', $u) }}" class="flex min-w-0 flex-1 items-center gap-2">
                        @csrf @method('PUT')
                        <input name="name" value="{{ $u->name }}" class="input !min-h-9 min-w-0 flex-1" required aria-label="Unidad">
                        <input name="plural" value="{{ $u->plural }}" class="input !min-h-9 min-w-0 flex-1" placeholder="Plural" aria-label="Plural">
                        <label class="flex items-center gap-1 text-xs text-slate-500"><input type="checkbox" name="is_active" value="1" @checked($u->is_active) class="rounded border-slate-300"> Activa</label>
                        <button class="btn-secondary btn-sm">Guardar</button>
                    </form>
                    @else
                    <span class="flex-1 text-sm">{{ $u->name }} <span class="text-slate-400">/ {{ $u->plural }}</span></span>
                    @endcan
                    @can('products.delete')
                    <form method="POST" action="{{ route('units.destroy', $u) }}" x-data @submit="if (!confirm({{ \Illuminate\Support\Js::from('¿Eliminar la unidad '.$u->name.'?') }})) $event.preventDefault()">@csrf @method('DELETE')<button class="btn-danger btn-sm" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button></form>
                    @endcan
                </div>
            @endforeach
        </div>
    </section>
</div>
@endsection
