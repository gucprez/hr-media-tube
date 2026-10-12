@extends('layouts.app')
@section('title', $section['label'])
@section('content')
<div class="mb-1 text-sm"><a href="{{ route('reports.index') }}" class="text-slate-500 hover:text-slate-800">← Centro de reportes</a></div>
<div class="mb-6"><h1 class="page-title">{{ $section['label'] }}</h1><p class="text-sm text-slate-500">{{ $section['description'] }}</p></div>
<div class="grid gap-3 sm:grid-cols-2">
    @foreach($section['reports'] as $r)
        @if($r['available'])
            <a href="{{ $r['url'] }}" class="card flex items-start gap-3 p-4 transition hover:shadow-md">
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand"><x-icon name="chart" class="h-5 w-5" /></span>
                <span class="min-w-0 flex-1"><span class="block font-semibold text-slate-900">{{ $r['label'] }}</span>@if(!empty($r['description']))<span class="block text-sm text-slate-500">{{ $r['description'] }}</span>@endif</span>
                <span class="badge-green">Disponible</span>
            </a>
        @else
            <div class="card flex items-start gap-3 border-dashed bg-slate-50/60 p-4 opacity-75">
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400"><x-icon name="chart" class="h-5 w-5" /></span>
                <span class="min-w-0 flex-1"><span class="block font-semibold text-slate-600">{{ $r['label'] }}</span>@if(!empty($r['description']))<span class="block text-sm text-slate-400">{{ $r['description'] }}</span>@endif</span>
                <span class="badge-gray">Fase {{ $r['phase'] }}</span>
            </div>
        @endif
    @endforeach
</div>
@endsection
