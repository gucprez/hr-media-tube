@extends('layouts.app')
@section('title', 'Usuarios')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="page-title">Usuarios</h1>
    <a href="{{ route('admin.users.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Nuevo usuario</a>
</div>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-[1fr_220px_auto]">
    <input name="q" value="{{ request('q') }}" placeholder="Buscar por nombre, usuario o correo…" class="input">
    <select name="role" class="input"><option value="">Todos los roles</option>@foreach($roles as $r)<option value="{{ $r->id }}" @selected(request('role') == $r->id)>{{ $r->name }}</option>@endforeach</select>
    <button class="btn-secondary">Filtrar</button>
</form>
<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sucursal</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead>
            <tbody>
            @forelse($users as $u)
                <tr>
                    <td><p class="font-medium text-slate-900">{{ $u->name }}</p><p class="text-xs text-slate-500">{{ $u->email }}</p></td>
                    <td>{{ $u->username }}</td>
                    <td>{{ $u->role?->name ?? '—' }}</td>
                    <td>{{ $u->branch?->name ?? '—' }}</td>
                    <td>@if($u->is_active)<span class="badge-green">Activo</span>@else<span class="badge-gray">Inactivo</span>@endif</td>
                    <td class="text-xs text-slate-500">{{ $u->last_login_at?->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('admin.users.edit', $u) }}" class="btn-secondary btn-sm"><x-icon name="pencil" class="h-3.5 w-3.5" /> Editar</a>
                        @if($u->id !== auth()->id())
                        <form method="POST" action="{{ route('admin.users.destroy', $u) }}" class="inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from('¿Eliminar al usuario '.$u->username.'?') }})">@csrf @method('DELETE')
                            <button class="btn-danger btn-sm" aria-label="Eliminar"><x-icon name="trash" class="h-3.5 w-3.5" /></button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-10 text-center text-slate-500">No hay usuarios.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-100 px-4">{{ $users->links() }}</div>
</div>
@endsection
