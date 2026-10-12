@extends('layouts.base')
@section('title', 'Iniciar sesión')

@section('body')
<div class="flex min-h-screen items-center justify-center bg-gradient-to-br from-[#0f2a52] via-[#173c75] to-[#2563eb] p-4">
    <div class="w-full max-w-md">
        <div class="mb-6 text-center text-white">
            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-white/15 backdrop-blur"><x-icon name="archive" class="h-8 w-8" /></div>
            <h1 class="text-2xl font-bold">HR Media System</h1>
            <p class="text-sm text-blue-100">Almacén · Operaciones · Combustible · Flotilla</p>
        </div>
        <form method="POST" action="{{ route('login.store') }}" class="card space-y-4 p-6 shadow-xl">
            @csrf
            <h2 class="text-lg font-bold text-slate-900">Iniciar sesión</h2>
            @error('username')<div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $message }}</div>@enderror
            <x-field name="username" label="Usuario o correo" required autofocus autocomplete="username" />
            <x-field name="password" label="Contraseña" type="password" required autocomplete="current-password" />
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" value="1" class="rounded border-slate-300"> Mantener sesión iniciada</label>
            <button class="btn-primary w-full">Entrar</button>
        </form>
        <p class="mt-4 text-center text-xs text-blue-100/80">© {{ date('Y') }} HR Media System</p>
    </div>
</div>
@endsection
