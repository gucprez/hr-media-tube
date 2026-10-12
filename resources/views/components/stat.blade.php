@props(['label', 'value', 'icon' => 'box', 'tone' => 'blue', 'hint' => null, 'href' => null])
@php
$tones = [
    'blue' => 'bg-blue-50 text-blue-600', 'green' => 'bg-emerald-50 text-emerald-600',
    'yellow' => 'bg-amber-50 text-amber-600', 'red' => 'bg-red-50 text-red-600', 'slate' => 'bg-slate-100 text-slate-600',
];
@endphp
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif class="card flex items-center gap-4 p-4 sm:p-5 {{ $href ? 'transition hover:shadow-md' : '' }}">
    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl {{ $tones[$tone] ?? $tones['blue'] }}"><x-icon :name="$icon" class="h-6 w-6" /></div>
    <div class="min-w-0">
        <p class="truncate text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
        <p class="text-2xl font-bold leading-tight text-slate-900 sm:text-3xl">{{ $value }}</p>
        @if($hint)<p class="truncate text-xs text-slate-500">{{ $hint }}</p>@endif
    </div>
</{{ $tag }}>
