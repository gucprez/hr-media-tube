@extends('layouts.app')
@section('title', $incident->title)
@section('content')
<div class="mb-4"><a href="{{ route('incidents.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Averías</a></div>
<div class="grid gap-6 lg:grid-cols-[1fr_360px]">
    <div class="space-y-6">
        <div class="card card-body">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0"><h1 class="page-title">{{ $incident->title }}</h1><p class="text-sm text-slate-500">Vehículo <a href="{{ route('vehicles.show', $incident->vehicle) }}" class="font-semibold text-brand hover:underline">{{ $incident->vehicle?->ficha }}</a> · {{ $incident->reported_at->format('d/m/Y H:i') }} · {{ $incident->reporter?->name }}</p></div>
                <div class="flex gap-1.5"><span class="{{ \App\Models\VehicleIncident::PRIORITY_BADGES[$incident->priority] }}">{{ $incident->priority_label }}</span><span class="{{ \App\Models\VehicleIncident::STATUS_BADGES[$incident->status] }}">{{ $incident->status_label }}</span></div>
            </div>
            @if($incident->odometer)<p class="mt-2 text-sm text-slate-600">Kilometraje: <b>{{ number_format($incident->odometer, 1) }}</b></p>@endif
            @if($incident->description)<p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{{ $incident->description }}</p>@endif
            @if($incident->attachments->isNotEmpty())<div class="mt-4 flex flex-wrap gap-3">@foreach($incident->attachments as $a)<a href="{{ $a->url() }}" target="_blank" rel="noopener"><img src="{{ $a->url() }}" class="h-28 w-28 rounded-lg border border-slate-200 object-cover" alt="Foto de la avería" loading="lazy"></a>@endforeach</div>@endif
        </div>
        @if($incident->maintenance->isNotEmpty())
        <div class="card"><h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">Reparaciones registradas</h2>
            @foreach($incident->maintenance as $m)<a href="{{ route('maintenance.show', $m) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50 {{ $m->isVoided() ? 'opacity-60' : '' }}"><span class="text-sm font-medium text-slate-800">{{ \Illuminate\Support\Str::limit($m->description, 80) }} @if($m->isVoided())<span class="badge-red">Anulada</span>@endif</span><span class="text-xs text-slate-500">{{ $m->performed_at->format('d/m/Y') }}</span></a>@endforeach</div>
        @endif
        <div class="card"><h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">Historial de estados</h2>
            <ol class="divide-y divide-slate-100">@foreach($incident->events as $e)<li class="px-5 py-3 text-sm"><p class="font-medium text-slate-800">{{ $e->from_status ? \App\Models\VehicleIncident::STATUSES[$e->from_status].' → ' : '' }}<span class="{{ \App\Models\VehicleIncident::STATUS_BADGES[$e->to_status] }}">{{ \App\Models\VehicleIncident::STATUSES[$e->to_status] }}</span></p>@if($e->note)<p class="text-slate-600">{{ $e->note }}</p>@endif<p class="text-xs text-slate-400">{{ $e->user?->name }} · {{ $e->created_at->format('d/m/Y H:i') }}</p></li>@endforeach</ol></div>
    </div>
    <div class="space-y-4">
        @can('incidents.manage')
        <form method="POST" action="{{ route('incidents.status', $incident) }}" class="card card-body space-y-3">@csrf
            <h2 class="font-semibold text-slate-900">Cambiar estado</h2>
            <div><label for="status" class="label">Nuevo estado</label><select id="status" name="status" class="input">@foreach(\App\Models\VehicleIncident::STATUSES as $k => $l)@if($k !== $incident->status)<option value="{{ $k }}">{{ $l }}</option>@endif @endforeach</select></div>
            <x-field name="note" label="Nota" hint="Obligatoria si regresa a un estado anterior." />
            <button class="btn-primary">Actualizar estado</button>
        </form>
        @endcan
        @if($incident->isOpen())@can('maintenance.manage')<a href="{{ route('maintenance.create', ['incident' => $incident->id]) }}" class="btn-success w-full"><x-icon name="wrench" class="h-4 w-4" /> Registrar reparación</a>@endcan @endif
    </div>
</div>
@endsection
