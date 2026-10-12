@extends('layouts.app')
@section('title', 'Roles y permisos')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="page-title">Roles y permisos</h1>
    <a href="{{ route('admin.roles.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Nuevo rol</a>
</div>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
@foreach($roles as $role)
    <div class="card card-body flex flex-col">
        <div class="flex items-start justify-between gap-2">
            <h2 class="font-semibold text-slate-900">{{ $role->name }}</h2>
            @if($role->is_system)<span class="badge-blue">Base</span>@endif
        </div>
        <p class="mt-1 flex-1 text-sm text-slate-500">{{ $role->description ?: 'Sin descripción' }}</p>
        <p class="mt-3 text-xs text-slate-500">{{ $role->users_count }} usuario(s) · {{ $role->permissions_count }} permisos</p>
        <div class="mt-3 flex gap-2">
            <a href="{{ route('admin.roles.edit', $role) }}" class="btn-secondary btn-sm"><x-icon name="pencil" class="h-3.5 w-3.5" /> Editar permisos</a>
            @if(! $role->is_system)
            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" x-data @submit="if (!confirm({{ \Illuminate\Support\Js::from('¿Eliminar el rol '.$role->name.'?') }})) $event.preventDefault()">@csrf @method('DELETE')<button class="btn-danger btn-sm" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button></form>
            @endif
        </div>
    </div>
@endforeach
</div>
@endsection
