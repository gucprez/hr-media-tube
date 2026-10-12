@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 px-1 py-3 text-sm">
        <p class="text-slate-500">Mostrando {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</p>
        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn-secondary btn-sm pointer-events-none opacity-50">‹ Anterior</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="btn-secondary btn-sm">‹ Anterior</a>
            @endif
            <span class="px-2 text-slate-600">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="btn-secondary btn-sm">Siguiente ›</a>
            @else
                <span class="btn-secondary btn-sm pointer-events-none opacity-50">Siguiente ›</span>
            @endif
        </div>
    </nav>
@endif
