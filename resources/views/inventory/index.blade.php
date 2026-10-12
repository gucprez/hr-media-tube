@extends('layouts.app')
@section('title', 'Inventario')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Inventario</h1><p class="text-sm text-slate-500">Existencia actual por producto. Los cambios se hacen únicamente con movimientos.</p></div>
    <div class="flex flex-wrap gap-2">
        @can('inventory.entry')<a href="{{ route('entries.create') }}" class="btn-success"><x-icon name="arrow-down" class="h-4 w-4" /> Entrada</a>@endcan
        @can('inventory.dispatch')<a href="{{ route('exits.create') }}" class="btn-primary"><x-icon name="arrow-up" class="h-4 w-4" /> Salida</a>@endcan
        @can('inventory.adjust')<a href="{{ route('adjustments.create') }}" class="btn-secondary"><x-icon name="scale" class="h-4 w-4" /> Ajuste</a>@endcan
    </div>
</div>
@include('inventory._stock-filters', ['action' => route('inventory.index')])

@include('inventory._stock-list', ['products' => $products, 'converter' => $converter])
@endsection
