@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-2">
    <div>
        <h1 class="page-title">Hola, {{ auth()->user()->first_name }} 👋</h1>
        <p class="text-sm text-slate-500">{{ $currentCompany->name }} · {{ now()->translatedFormat('l d \d\e F, Y') }}</p>
    </div>
</div>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <x-stat label="Usuarios activos" :value="$stats['users']" icon="users" tone="blue" />
    <x-stat label="Sucursales" :value="$stats['branches']" icon="building" tone="green" />
    <x-stat label="Almacenes" :value="$stats['warehouses']" icon="archive" tone="slate" />
</div>

@if($recent->isNotEmpty())
<div class="card mt-6">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
        <h2 class="font-semibold text-slate-900">Actividad reciente</h2>
        <a href="{{ route('admin.audit.index') }}" class="text-sm font-medium text-brand hover:underline">Ver auditoría</a>
    </div>
    <ul class="divide-y divide-slate-100">
        @foreach($recent as $log)
            <li class="flex items-start justify-between gap-3 px-5 py-3 text-sm">
                <div><p class="font-medium text-slate-800">{{ $log->description ?? $log->action }}</p><p class="text-xs text-slate-500">{{ $log->user_name ?? 'Sistema' }}</p></div>
                <time class="shrink-0 text-xs text-slate-400">{{ $log->created_at->format('d/m/Y H:i') }}</time>
            </li>
        @endforeach
    </ul>
</div>
@endif
@endsection
