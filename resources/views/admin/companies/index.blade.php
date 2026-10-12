@extends('layouts.app')
@section('title', 'Empresas')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="page-title">Empresas</h1><p class="text-sm text-slate-500">Clientes de la plataforma. Cada empresa tiene sus datos completamente aislados.</p></div>
    <a href="{{ route('admin.companies.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Nueva empresa</a>
</div>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
@forelse($companies as $c)
    <div class="card card-body flex flex-col {{ $c->trashed() ? 'opacity-60' : '' }}">
        <div class="flex items-start gap-3">
            @if($c->logo_url)<img src="{{ $c->logo_url }}" class="h-11 w-11 rounded-lg border border-slate-200 object-contain" alt="">
            @else<div class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand text-sm font-bold text-white" style="background: {{ $c->primary_color }}">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($c->name, 0, 2)) }}</div>@endif
            <div class="min-w-0 flex-1"><h2 class="truncate font-semibold text-slate-900">{{ $c->name }}</h2><p class="truncate text-xs text-slate-500">{{ $c->rnc ? 'RNC '.$c->rnc : $c->slug }}</p></div>
            @if($c->trashed())<span class="badge-red">Eliminada</span>@elseif($c->is_active)<span class="badge-green">Activa</span>@else<span class="badge-gray">Inactiva</span>@endif
        </div>
        <p class="mt-3 text-sm text-slate-600">{{ $c->users_count }} usuario(s)</p>
        @unless($c->trashed())
        <div class="mt-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('admin.companies.enter', $c) }}">@csrf<button class="btn-primary btn-sm">Entrar</button></form>
            <a href="{{ route('admin.companies.edit', $c) }}" class="btn-secondary btn-sm">Editar</a>
            <form method="POST" action="{{ route('admin.companies.destroy', $c) }}" x-data @submit="if (!confirm({{ \Illuminate\Support\Js::from('¿Eliminar la empresa '.$c->name.'?') }})) $event.preventDefault()">@csrf @method('DELETE')<button class="btn-danger btn-sm" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button></form>
        </div>
        @endunless
    </div>
@empty
    <p class="text-slate-500">Aún no hay empresas.</p>
@endforelse
</div>
@endsection
