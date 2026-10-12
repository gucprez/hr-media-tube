@extends('layouts.base')
@section('title', 'Acceso denegado')
@section('body')
<div class="flex min-h-screen items-center justify-center p-6 text-center">
    <div class="card max-w-md p-8">
        <p class="text-5xl font-bold text-red-500">403</p>
        <h1 class="mt-2 text-xl font-bold text-slate-900">Acceso denegado</h1>
        <p class="mt-2 text-sm text-slate-600">{{ $exception->getMessage() ?: 'No tiene permiso para ver esta página.' }}</p>
        <a href="{{ url('/') }}" class="btn-primary mt-6">Volver al inicio</a>
    </div>
</div>
@endsection
