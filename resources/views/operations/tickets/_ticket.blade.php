{{-- Un ticket (tabla pura: se ve igual en navegador/impresora y en PDF). Variables: $ticket, $company, $logo, $label, $reprint, $n, $total --}}
@php
$isFuel = $ticket->type === 'fuel';
$fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
$gal = fn ($v) => number_format((float) $v, 1, '.', ',');
@endphp
<div class="tk">
    <table class="head">
        <tr>
            @if($logo)<td class="logo"><img src="{{ $logo }}" alt=""></td>@endif
            <td>
                <div class="co">{{ $company?->name }}</div>
                @if($company?->rnc)<div class="muted">RNC {{ $company->rnc }}</div>@endif
                @if($company?->phone)<div class="muted">{{ $company->phone }}</div>@endif
            </td>
            <td style="width:38%">
                <div class="title">{{ $isFuel ? 'Ticket de gasoil' : 'Ticket de materiales' }}</div>
                <div class="num">N° {{ $ticket->number }}</div>
            </td>
        </tr>
    </table>

    <table class="info">
        <tr><td class="k">Furgón No.</td><td><b>{{ $ticket->container_number }}</b></td><td class="k">Fecha</td><td>{{ $ticket->issued_at->format('d/m/Y') }}</td></tr>
        <tr><td class="k">Chofer</td><td>{{ $ticket->driver_name }}</td><td class="k">Conduce</td><td>{{ $ticket->conduce }}</td></tr>
        <tr><td class="k">Ficha</td><td>{{ $ticket->ficha }}</td><td class="k">Teléfono</td><td>{{ $ticket->phone }}</td></tr>
    </table>

    <table class="items">
        @if($isFuel)
            <thead><tr><th>Ficha</th><th class="r" style="text-align:right">Kilometraje</th><th class="r" style="text-align:right">Galones</th></tr></thead>
            <tbody>
            @foreach($ticket->items as $i)
                <tr><td>{{ $i->ficha }}</td><td class="r">{{ $i->odometer !== null ? number_format($i->odometer, 1, '.', ',') : '' }}</td><td class="r">{{ $gal($i->gallons) }}</td></tr>
            @endforeach
                <tr class="total"><td colspan="2" class="r">TOTAL GALONES</td><td class="r">{{ $gal($ticket->items->sum('gallons')) }}</td></tr>
            </tbody>
        @else
            <thead><tr><th>Producto</th><th>Cantidad</th><th style="text-align:right">Total unidades</th></tr></thead>
            <tbody>
            @foreach($ticket->items as $i)
                <tr><td>{{ $i->description }}</td><td>{{ $i->quantity_label }}</td><td class="r">{{ $fmt($i->total_units) }} {{ $i->unit_label }}</td></tr>
            @endforeach
            </tbody>
        @endif
    </table>

    @if($ticket->notes)<div class="notes"><b>Obs.:</b> {{ $ticket->notes }}</div>@endif

    <table class="sign">
        <tr>
            <td><div class="who">{{ $ticket->dispatched_by }}</div><div class="line">Despachado por</div></td>
            <td><div class="who">{{ $ticket->received_by }}</div><div class="line">Recibido por</div></td>
        </tr>
    </table>

    <div class="foot">
        <span class="tag">{{ $label }}</span>
        @if($reprint)<span class="tag">REIMPRESIÓN</span>@endif
        @if($company?->setting('ticket_footer')) · {{ $company->setting('ticket_footer') }}@endif
    </div>
</div>
