@extends('layouts.app')
@section('title', 'Auditoría')
@section('content')
<h1 class="page-title mb-4">Log de auditoría</h1>
<form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_180px_160px_160px_auto]">
    <input name="q" value="{{ request('q') }}" placeholder="Usuario, acción o descripción…" class="input">
    <input name="action" value="{{ request('action') }}" placeholder="Acción (ej. user.)" class="input">
    <input type="date" name="from" value="{{ request('from') }}" class="input" aria-label="Desde">
    <input type="date" name="to" value="{{ request('to') }}" class="input" aria-label="Hasta">
    <button class="btn-secondary">Filtrar</button>
</form>
<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Fecha / hora</th><th>Usuario</th><th>IP</th><th>Acción</th><th>Detalle</th><th>Antes → Después</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr x-data="{ open: false }">
                    <td class="whitespace-nowrap text-xs">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                    <td>{{ $log->user_name ?? 'Sistema' }}</td>
                    <td class="text-xs text-slate-500">{{ $log->ip_address }}</td>
                    <td><span class="badge-blue">{{ $log->action }}</span></td>
                    <td>{{ $log->description }}@if($log->auditable_type)<p class="text-xs text-slate-400">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</p>@endif</td>
                    <td class="max-w-xs text-xs">
                        @if($log->old_values || $log->new_values)
                            <button type="button" @click="open = !open" class="text-brand hover:underline" x-text="open ? 'Ocultar' : 'Ver cambios'"></button>
                            <div x-show="open" x-cloak class="mt-1 space-y-1 font-mono">
                                @foreach(array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))) as $k)
                                    <p><b>{{ $k }}:</b> <span class="text-red-600">{{ is_array($v = $log->old_values[$k] ?? null) ? json_encode($v) : ($v ?? '∅') }}</span> → <span class="text-emerald-700">{{ is_array($v = $log->new_values[$k] ?? null) ? json_encode($v) : ($v ?? '∅') }}</span></p>
                                @endforeach
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-10 text-center text-slate-500">Sin registros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-100 px-4">{{ $logs->links() }}</div>
</div>
@endsection
