{{-- Filtros compartidos de existencias. Variables: $categories, $warehouses, $action, $withActive --}}
<form method="GET" action="{{ $action }}" class="card card-body no-print mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_auto]">
    <div class="relative sm:col-span-2 lg:col-span-1">
        <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Producto, código o código de barras…" class="input !pl-9" aria-label="Buscar">
    </div>
    <select name="warehouse" class="input" aria-label="Almacén">
        <option value="">Todos los almacenes</option>
        @foreach($warehouses as $w)<option value="{{ $w->id }}" @selected(request('warehouse') == $w->id)>{{ $w->name }}</option>@endforeach
    </select>
    <select name="category" class="input" aria-label="Categoría">
        <option value="">Todas las categorías</option>
        @foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach
    </select>
    <select name="status" class="input" aria-label="Estado">
        <option value="">Todos los estados</option>
        @foreach(\App\Services\Inventory\StockStatus::LABELS as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach
    </select>
    <div class="flex gap-2">
        <button class="btn-primary flex-1">Filtrar</button>
        <a href="{{ $action }}" class="btn-secondary" title="Limpiar">✕</a>
    </div>
</form>
