@extends('layouts.app')
@section('title', 'Mi perfil')
@section('content')
<h1 class="page-title mb-6">Mi perfil</h1>
<div class="grid gap-6 lg:grid-cols-2">
    <form method="POST" action="{{ route('profile.update') }}" class="card card-body space-y-4">
        @csrf @method('PUT')
        <h2 class="font-semibold text-slate-900">Datos personales</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="first_name" label="Nombre" :value="$user->first_name" required />
            <x-field name="last_name" label="Apellido" :value="$user->last_name" required />
        </div>
        <x-field name="email" label="Correo" type="email" :value="$user->email" required />
        <p class="text-xs text-slate-500">Usuario: <b>{{ $user->username }}</b> · Rol: <b>{{ $user->is_super_admin ? 'Super administrador' : $user->role?->name }}</b></p>
        <button class="btn-primary">Guardar</button>
    </form>
    <form method="POST" action="{{ route('profile.password') }}" class="card card-body space-y-4">
        @csrf @method('PUT')
        <h2 class="font-semibold text-slate-900">Cambiar contraseña</h2>
        <x-field name="current_password" label="Contraseña actual" type="password" required autocomplete="current-password" />
        <x-field name="password" label="Nueva contraseña" type="password" required hint="Mínimo 8 caracteres, con letras y números." autocomplete="new-password" />
        <x-field name="password_confirmation" label="Confirmar nueva contraseña" type="password" required autocomplete="new-password" />
        <button class="btn-primary">Actualizar contraseña</button>
    </form>
</div>
@endsection
