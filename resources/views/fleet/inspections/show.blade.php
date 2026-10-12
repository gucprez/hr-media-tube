@extends('layouts.app')
@section('title', 'Inspección '.$inspection->vehicle?->ficha)
@section('content')
<div class="no-print mb-3 flex items-center justify-between"><a href="{{ route('inspections.index') }}" class="text-sm text-slate-500 hover:text-slate-800">← Inspecciones</a><button type="button" @click="window.print()" class="btn-secondary btn-sm"><x-icon name="printer" class="h-4 w-4" /> Imprimir</button></div>
<div class="card card-body">
    <x-report-header title="Inspección mensual" :subtitle="'Vehículo '.$inspection->vehicle?->ficha" :meta="['Fecha' => $inspection->inspected_at->format('d/m/Y'), 'Kilometraje' => $inspection->odometer ? number_format($inspection->odometer, 1) : '—', 'Inspector' => $inspection->inspector?->name]" />
    <p class="mb-3 text-sm">Resultado: <span class="{{ \App\Models\VehicleInspection::OVERALL_BADGES[$inspection->overall_status] }}">{{ \App\Models\VehicleInspection::OVERALL[$inspection->overall_status] }}</span> · Próxima inspección: <b>{{ $inspection->next_due_at?->format('d/m/Y') }}</b></p>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Punto</th><th>Estado</th><th>Observación</th><th class="no-print">Fotos</th></tr></thead>
        <tbody>
        @php $badges = ['good' => 'badge-green', 'attention' => 'badge-yellow', 'damaged' => 'badge-red', 'na' => 'badge-gray']; @endphp
        @foreach($inspection->items as $it)
            <tr><td class="font-medium text-slate-900">{{ $it->label }}</td><td><span class="{{ $badges[$it->status] }}">{{ \App\Models\VehicleInspection::STATUSES[$it->status] }}</span></td><td>{{ $it->notes }}</td>
                <td class="no-print">@foreach($it->attachments as $a)<a href="{{ $a->url() }}" target="_blank" rel="noopener"><img src="{{ $a->url() }}" class="mr-1 inline h-12 w-12 rounded border border-slate-200 object-cover" alt="Foto {{ $it->label }}" loading="lazy"></a>@endforeach</td></tr>
        @endforeach
        </tbody></table></div>
    @if($inspection->notes)<p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700"><b>Observaciones:</b> {{ $inspection->notes }}</p>@endif
    <x-report-signatures />
</div>
@endsection
