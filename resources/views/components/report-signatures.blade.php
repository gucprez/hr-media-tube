<div class="mt-10 grid grid-cols-3 gap-8 text-center text-xs text-slate-600 break-inside-avoid">
    @foreach(['Preparado por', 'Revisado por', 'Aprobado por'] as $role)
        <div><div class="mb-1 h-10 border-b border-slate-400"></div>{{ $role }}</div>
    @endforeach
</div>
@if($currentCompany?->setting('ticket_footer'))<p class="mt-4 text-center text-[11px] text-slate-500">{{ $currentCompany->setting('ticket_footer') }}</p>@endif
