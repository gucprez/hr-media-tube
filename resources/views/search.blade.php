@extends('layouts.app')
@section('title', 'Búsqueda')
@section('content')
<h1 class="page-title mb-1">Resultados de búsqueda</h1>
<p class="mb-4 text-sm text-slate-500">@if($term) Para «{{ $term }}» @else Escriba al menos 2 caracteres en la barra superior. @endif</p>
@forelse($groups as $group)
    <div class="card mb-4">
        <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ $group['label'] }}</h2>
        @foreach($group['results'] as $r)
            <a href="{{ $r['url'] }}" class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50">
                @if($r['image'])<img src="{{ $r['image'] }}" class="h-11 w-11 rounded-lg border border-slate-200 object-cover" alt="">@endif
                <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-slate-800">{{ $r['title'] }}</p><p class="truncate text-xs text-slate-500">{{ $r['subtitle'] }}</p></div>
                @if($r['badge'])<span class="badge-gray">{{ $r['badge'] }}</span>@endif
            </a>
        @endforeach
    </div>
@empty
    @if(strlen($term) >= 2)<div class="card card-body text-center text-slate-500">No se encontraron resultados.</div>@endif
@endforelse
@endsection
