{{-- Lista de existencias: tarjetas en móvil, tabla en escritorio. --}}
<div class="space-y-2 md:hidden">
    @forelse($products as $p)
        @php $base = $p->totalBase(); $st = $p->status($base); @endphp
        <a href="{{ route('products.show', $p) }}" class="card flex items-center gap-3 p-3">
            <x-product-thumb :product="$p" size="h-16 w-16" />
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold text-slate-900">{{ $p->name }}</p>
                <p class="truncate text-xs text-slate-500">{{ $p->sku }} · {{ $p->category?->name ?? 'Sin categoría' }}</p>
                <p class="mt-1 text-sm font-bold text-slate-900">{{ $converter->format($p, $base) }}</p>
                <p class="text-xs text-slate-500">Mín. {{ $converter->formatMinimum($p) }}</p>
            </div>
            <x-status-badge :status="$st" />
        </a>
    @empty
        <div class="card card-body text-center text-slate-500">No hay productos con esos filtros.</div>
    @endforelse
</div>
<div class="card hidden md:block">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Producto</th><th>Categoría</th><th class="text-right">Existencia</th><th class="text-right">Total (unid.)</th><th class="text-right">Mínimo</th><th>Estado</th><th>Ubicación</th></tr></thead>
            <tbody>
            @forelse($products as $p)
                @php $base = $p->totalBase(); $st = $p->status($base); @endphp
                <tr>
                    <td><a href="{{ route('products.show', $p) }}" class="flex items-center gap-3"><x-product-thumb :product="$p" /><span><span class="block font-medium text-slate-900">{{ $p->name }}</span><span class="block text-xs text-slate-500">{{ $p->sku }}</span></span></a></td>
                    <td>{{ $p->category?->name ?? '—' }}</td>
                    <td class="text-right font-semibold text-slate-900">{{ $converter->format($p, $base) }}</td>
                    <td class="text-right">{{ $converter->formatTotal($p, $base) }}</td>
                    <td class="text-right">{{ $converter->formatMinimum($p) }}</td>
                    <td><x-status-badge :status="$st" /></td>
                    <td class="text-xs text-slate-500">{{ $p->location_label }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-10 text-center text-slate-500">No hay productos con esos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-2">{{ $products->links() }}</div>
