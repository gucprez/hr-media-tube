@extends('layouts.base')
@section('title', 'No encontrado')
@section('body')
<div class="flex min-h-screen items-center justify-center p-6 text-center">
    <div class="card max-w-md p-8">
        <p class="text-5xl font-bold text-slate-400">404</p>
        <h1 class="mt-2 text-xl font-bold text-slate-900">Página no encontrada</h1>
        <p class="mt-2 text-sm text-slate-600">El registro o la página que busca no existe.</p>
        <a href="{{ url('/') }}" class="btn-primary mt-6">Volver al inicio</a>
    </div>
</div>
@endsection
