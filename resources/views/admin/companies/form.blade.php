@extends('layouts.app')
@section('title', $company->exists ? 'Editar empresa' : 'Nueva empresa')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <h1 class="page-title">{{ $company->exists ? 'Editar empresa' : 'Nueva empresa' }}</h1>
    <a href="{{ route('admin.companies.index') }}" class="btn-secondary">Volver</a>
</div>
<form method="POST" action="{{ $company->exists ? route('admin.companies.update', $company) : route('admin.companies.store') }}" class="space-y-6">
    @csrf @if($company->exists) @method('PUT') @endif
    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Datos de la empresa</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="name" label="Nombre comercial" :value="$company->name" required />
            <x-field name="rnc" label="RNC" :value="$company->rnc" />
            <x-field name="phone" label="Teléfono" :value="$company->phone" />
            <x-field name="email" label="Correo" type="email" :value="$company->email" />
            <x-field name="address" label="Dirección" :value="$company->address" class="sm:col-span-2" />
        </div>
        @if($company->exists)
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $company->is_active))> Empresa activa (si se desactiva, sus usuarios no pueden entrar)</label>
        @endif
    </div>
    @unless($company->exists)
    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Administrador inicial</h2>
        <p class="-mt-2 text-sm text-slate-500">Se crearán además los roles, la sucursal, el almacén y las numeraciones base.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="admin_first_name" label="Nombre" required />
            <x-field name="admin_last_name" label="Apellido" required />
            <x-field name="admin_username" label="Usuario" required autocomplete="off" />
            <x-field name="admin_email" label="Correo" type="email" required />
            <x-field name="admin_password" label="Contraseña" type="password" required autocomplete="new-password" hint="Mínimo 8 caracteres, con letras y números." />
        </div>
    </div>
    @endunless
    <button class="btn-primary">Guardar</button>
</form>
@endsection
