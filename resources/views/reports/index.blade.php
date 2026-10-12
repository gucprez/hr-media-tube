@extends('layouts.app')
@section('title', 'Reportes')
@section('content')
<div class="mb-6"><h1 class="page-title">Centro de reportes</h1><p class="text-sm text-slate-500">Cada módulo tiene sus reportes especializados. Todos se calculan desde el historial, no solo desde la existencia actual.</p></div>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @foreach($sections as $s)
        @php $available = collect($s['reports'])->where('available', true)->count(); @endphp
        <a href="{{ $s['url'] }}" class="card card-body flex flex-col transition hover:shadow-md">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand/10 text-brand"><x-icon :name="$s['icon']" class="h-6 w-6" /></span>
                <h2 class="font-semibold text-slate-900">{{ $s['label'] }}</h2>
            </div>
            <p class="mt-2 flex-1 text-sm text-slate-500">{{ $s['description'] }}</p>
            <p class="mt-3 text-xs">
                @if($available)<span class="badge-green">{{ $available }} disponible(s)</span>@endif
                @if(count($s['reports']) > $available)<span class="badge-gray">{{ count($s['reports']) - $available }} en desarrollo · Fase {{ $s['phase'] }}</span>@endif
            </p>
        </a>
    @endforeach
</div>
@endsection
