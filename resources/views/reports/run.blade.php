@extends('layouts.app')
@section('title', $def['title'])
@section('content')
@php $q = request()->query(); $url = fn ($fmt) => route('reports.run', [$sectionKey, $def['key']] + array_merge($q, ['format' => $fmt])); @endphp
<div class="no-print mb-3 flex flex-wrap items-center justify-between gap-2">
    <a href="{{ $sectionKey === 'warehouse' ? route('reports.warehouse') : route('reports.section', $sectionKey) }}" class="text-sm text-slate-500 hover:text-slate-800">← {{ $section['label'] }}</a>
    <div class="flex flex-wrap gap-2">
        @if($canExport)
            <a href="{{ $url('xlsx') }}" class="btn-secondary btn-sm">Excel</a>
            <a href="{{ $url('pdf') }}" class="btn-secondary btn-sm">PDF</a>
        @endif
        <button type="button" x-data @click="window.print()" class="btn-primary btn-sm"><x-icon name="printer" class="h-4 w-4" /> Imprimir</button>
    </div>
</div>

<form method="GET" action="{{ route('reports.run', [$sectionKey, $def['key']]) }}" class="card card-body no-print mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" x-data="{ range: @js($period->range) }">
    @if($def['period'])
        <label class="block text-xs font-medium text-slate-600">Período
            <select name="range" x-model="range" class="input mt-1">@foreach(\App\Services\Reports\Period::RANGES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        </label>
        <label class="block text-xs font-medium text-slate-600" x-show="range !== 'custom'">Fecha de referencia
            <input type="date" name="date" value="{{ $period->anchor->toDateString() }}" class="input mt-1">
        </label>
        <label class="block text-xs font-medium text-slate-600" x-show="range === 'custom'" x-cloak>Desde
            <input type="date" name="from" value="{{ $period->from->toDateString() }}" class="input mt-1">
        </label>
        <label class="block text-xs font-medium text-slate-600" x-show="range === 'custom'" x-cloak>Hasta
            <input type="date" name="to" value="{{ $period->to->toDateString() }}" class="input mt-1">
        </label>
    @endif
    @foreach($def['filters'] as $name => $spec)
        <label class="block text-xs font-medium text-slate-600">{{ $spec['label'] }}
            @if($spec['type'] === 'text')
                <input type="text" name="{{ $name }}" value="{{ $filters[$name] ?? '' }}" class="input mt-1" maxlength="100">
            @else
                @php $choices = $spec['type'] === 'select' ? $spec['options'] : ($options[$name] ?? []); @endphp
                <select name="{{ $name }}" class="input mt-1"><option value="">Todos</option>@foreach($choices as $k => $l)<option value="{{ $k }}" @selected(($filters[$name] ?? null) == $k)>{{ $l }}</option>@endforeach</select>
            @endif
        </label>
    @endforeach
    <div class="flex items-end"><button class="btn-primary w-full">Generar</button></div>
</form>

<div class="card card-body">
    <x-report-header :title="$def['title']" :subtitle="$def['description']" :meta="$meta" />

    @if($result->summary)
        <div class="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">@foreach($result->summary as $k => $v)<span class="text-slate-500">{{ $k }}: <strong class="text-slate-900">{{ $v }}</strong></span>@endforeach</div>
    @endif

    <div class="table-wrap">
        <table class="table">
            <thead><tr>@foreach($result->columns as $c)<th @class(['text-right' => in_array($c['type'], \App\Services\Reports\ReportResult::NUMERIC)])>{{ $c['label'] }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($result->rows as $row)
                <tr @class(['bg-red-50' => ($row['_tone'] ?? null) === 'red', 'bg-amber-50' => ($row['_tone'] ?? null) === 'yellow'])>
                    @foreach($result->columns as $c)<td @class(['text-right tabular-nums' => in_array($c['type'], \App\Services\Reports\ReportResult::NUMERIC), 'whitespace-nowrap' => in_array($c['type'], ['date', 'datetime'])])>{{ \App\Services\Reports\ReportResult::format($row[$c['key']] ?? null, $c['type']) }}</td>@endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($result->columns) }}" class="py-10 text-center text-slate-500">Sin datos para los filtros seleccionados.</td></tr>
            @endforelse
            </tbody>
            @if($result->totals && $result->rows)
                <tfoot><tr class="font-semibold">@foreach($result->columns as $i => $c)<td @class(['text-right tabular-nums' => in_array($c['type'], \App\Services\Reports\ReportResult::NUMERIC)])>{{ array_key_exists($c['key'], $result->totals) ? \App\Services\Reports\ReportResult::format($result->totals[$c['key']], $c['type']) : ($i === 0 ? 'TOTAL' : '') }}</td>@endforeach</tr></tfoot>
            @endif
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-500">{{ count($result->rows) }} fila(s).</p>
    @foreach($result->notes as $n)<p class="mt-1 text-xs text-slate-500">{{ $n }}</p>@endforeach
    <x-report-signatures />
</div>
@endsection
