<div class="card">
    <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">Línea de tiempo</h2>
    @php $tones = ['blue' => 'bg-blue-50 text-blue-600', 'green' => 'bg-emerald-50 text-emerald-600', 'red' => 'bg-red-50 text-red-600', 'yellow' => 'bg-amber-50 text-amber-600', 'slate' => 'bg-slate-100 text-slate-600']; @endphp
    <ol class="divide-y divide-slate-100">
        @forelse($timeline as $e)
            <li class="flex items-start gap-3 px-5 py-3">
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $tones[$e['tone']] ?? $tones['slate'] }}"><x-icon :name="$e['icon']" class="h-5 w-5" /></span>
                <div class="min-w-0 flex-1">
                    @if($e['url'])<a href="{{ $e['url'] }}" class="text-sm font-semibold text-slate-800 hover:text-brand">{{ $e['title'] }}</a>@else<p class="text-sm font-semibold text-slate-800">{{ $e['title'] }}</p>@endif
                    @if($e['detail'])<p class="text-xs text-slate-500">{{ $e['detail'] }}</p>@endif
                </div>
                <time class="shrink-0 text-xs text-slate-400">{{ $e['at']->format('d/m/Y') }}</time>
            </li>
        @empty
            <li class="px-5 py-8 text-center text-sm text-slate-500">Sin eventos registrados.</li>
        @endforelse
    </ol>
</div>
