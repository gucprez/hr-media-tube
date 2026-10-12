<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>{{ $def['title'] }}</title>
<style>
    @page { margin: 14mm 12mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #0f172a; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #e2e8f0; text-align: left; padding: 4px 5px; border: 1px solid #cbd5e1; font-size: 8px; }
    td { padding: 3px 5px; border: 1px solid #e2e8f0; vertical-align: top; }
    tr.red td { background: #fee2e2; } tr.yellow td { background: #fef3c7; }
    .r { text-align: right; } .head td { border: 0; padding: 0; }
    h1 { font-size: 15px; margin: 0; text-transform: uppercase; } .muted { color: #64748b; }
    tfoot td { font-weight: bold; background: #f1f5f9; }
    .sign td { border: 0; border-top: 1px solid #94a3b8; text-align: center; padding-top: 3px; width: 28%; }
</style></head><body>
<table class="head"><tr>
    <td style="width:60%">@if($logo)<img src="{{ $logo }}" style="max-height:48px;max-width:150px;float:left;margin-right:10px">@endif<strong style="font-size:12px">{{ $company->name }}</strong>@if($company->rnc)<br><span class="muted">RNC {{ $company->rnc }}</span>@endif @if($company->address)<br><span class="muted">{{ $company->address }}</span>@endif</td>
    <td class="r"><h1>{{ $def['title'] }}</h1>@foreach($meta as $k => $v)<div class="muted">{{ $k }}: <strong>{{ $v }}</strong></div>@endforeach<div class="muted">Generado: {{ now()->format('d/m/Y H:i') }} · {{ $user->name }}</div></td>
</tr></table>
<br>
@if($result->summary)<p>@foreach($result->summary as $k => $v)<span class="muted">{{ $k }}:</span> <strong>{{ $v }}</strong> &nbsp; @endforeach</p>@endif
<table>
    <thead><tr>@foreach($result->columns as $c)<th @if(in_array($c['type'], \App\Services\Reports\ReportResult::NUMERIC)) class="r" @endif>{{ $c['label'] }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($result->rows as $row)
        <tr class="{{ $row['_tone'] ?? '' }}">@foreach($result->columns as $c)<td @if(in_array($c['type'], \App\Services\Reports\ReportResult::NUMERIC)) class="r" @endif>{{ \App\Services\Reports\ReportResult::format($row[$c['key']] ?? null, $c['type']) }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($result->columns) }}" style="text-align:center;padding:14px">Sin datos para los filtros seleccionados.</td></tr>
    @endforelse
    </tbody>
    @if($result->totals && $result->rows)
        <tfoot><tr>@foreach($result->columns as $i => $c)<td @if(in_array($c['type'], \App\Services\Reports\ReportResult::NUMERIC)) class="r" @endif>{{ array_key_exists($c['key'], $result->totals) ? \App\Services\Reports\ReportResult::format($result->totals[$c['key']], $c['type']) : ($i === 0 ? 'TOTAL' : '') }}</td>@endforeach</tr></tfoot>
    @endif
</table>
<p class="muted">{{ count($result->rows) }} fila(s).@foreach($result->notes as $n) {{ $n }}@endforeach</p>
<br><br>
<table class="sign"><tr><td>Preparado por</td><td style="border:0"></td><td>Revisado por</td><td style="border:0"></td><td>Aprobado por</td></tr></table>
</body></html>
