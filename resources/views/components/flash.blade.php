@php
$types = ['success' => 'border-emerald-200 bg-emerald-50 text-emerald-800', 'error' => 'border-red-200 bg-red-50 text-red-800', 'warning' => 'border-amber-200 bg-amber-50 text-amber-800'];
@endphp
@foreach($types as $key => $classes)
    @if(session($key))
        <div x-data="{ show: true }" x-show="show" class="mb-4 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm {{ $classes }}" role="alert">
            <p class="flex-1">{{ session($key) }}</p>
            <button @click="show = false" class="opacity-60 hover:opacity-100" aria-label="Cerrar"><x-icon name="x" class="h-4 w-4" /></button>
        </div>
    @endif
@endforeach
@if($errors->any() && ! $errors->hasAny(['username']) )
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
        <p class="font-semibold">Revise los siguientes errores:</p>
        <ul class="mt-1 list-inside list-disc">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
