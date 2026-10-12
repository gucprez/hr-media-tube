@props(['title', 'subtitle' => null, 'meta' => []])
{{-- Encabezado de reporte (pantalla e impresión): logo REAL de la empresa, nombre, título y datos de generación. --}}
<div class="mb-4 flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-4">
    <div class="flex items-center gap-4">
        @if($currentCompany?->logo_url)
            <img src="{{ $currentCompany->logo_url }}" alt="{{ $currentCompany->name }}" class="h-16 max-w-[160px] object-contain">
        @endif
        <div>
            <p class="text-lg font-bold text-slate-900">{{ $currentCompany?->name }}</p>
            @if($currentCompany?->rnc)<p class="text-xs text-slate-500">RNC {{ $currentCompany->rnc }}</p>@endif
            @if($currentCompany?->address)<p class="text-xs text-slate-500">{{ $currentCompany->address }}</p>@endif
        </div>
    </div>
    <div class="text-right">
        <p class="text-xl font-bold uppercase tracking-wide text-slate-900">{{ $title }}</p>
        @if($subtitle)<p class="text-sm text-slate-600">{{ $subtitle }}</p>@endif
        @foreach($meta as $k => $v)<p class="text-xs text-slate-500">{{ $k }}: <span class="font-medium text-slate-700">{{ $v }}</span></p>@endforeach
        <p class="text-xs text-slate-500">Generado: <span class="font-medium text-slate-700">{{ now()->format('d/m/Y H:i') }}</span> · por <span class="font-medium text-slate-700">{{ auth()->user()->name }}</span></p>
    </div>
</div>
