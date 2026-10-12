<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Ticket {{ $ticket->number }} · {{ $company?->name }}</title>
    @include('operations.tickets._styles')
    <style>
        body { background: #e5e7eb; margin: 0; }
        .bar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 10px 14px; background: #0f2a52; color: #fff; font-family: Arial, sans-serif; font-size: 14px; }
        .bar a, .bar button { background: #fff; color: #0f2a52; border: 0; border-radius: 8px; padding: 8px 14px; font-weight: 600; text-decoration: none; cursor: pointer; font-size: 14px; }
        .bar a.on { background: #2563eb; color: #fff; }
        .bar .grow { flex: 1; }
        .notice { background: #fef3c7; color: #92400e; padding: 8px 14px; font-family: Arial, sans-serif; font-size: 13px; }
        .paper { width: 215.9mm; min-height: 279.4mm; margin: 12px auto; background: #fff; padding: 8mm; box-shadow: 0 2px 12px rgba(0,0,0,.25); }
        @media print { body { background: #fff; } .bar, .notice { display: none !important; } .paper { margin: 0; padding: 0; box-shadow: none; width: auto; min-height: 0; } }
        @media (max-width: 900px) { .paper { width: auto; margin: 0; padding: 4mm; overflow-x: auto; } }
    </style>
</head>
<body class="tk-doc" x-data x-init="{{ $auto ? "setTimeout(() => window.print(), 400)" : '' }}">
<div class="bar">
    <b>Ticket {{ $ticket->number }}</b>
    <span class="grow"></span>
    @foreach([1 => '1 por hoja', 2 => '2 por hoja', 4 => '4 por hoja'] as $n => $label)
        <a href="{{ route('tickets.print', [$ticket, 'copies' => $n, 'auto' => 0]) }}" class="{{ $plan['requested'] === $n ? 'on' : '' }}">{{ $label }}</a>
    @endforeach
    <a href="{{ route('tickets.pdf', [$ticket, 'copies' => $plan['requested']]) }}">Descargar PDF</a>
    <button type="button" @click="window.print()">Imprimir</button>
    <a href="{{ route('tickets.show', $ticket) }}">Volver</a>
</div>
@if($plan['notice'])<div class="notice">{{ $plan['notice'] }}</div>@endif
<div class="paper">
    @include('operations.tickets._sheet', ['logo' => app(\App\Services\Tickets\TicketRenderer::class)->logoDataUri($company)])
</div>
@vite(['resources/js/app.js'])
</body>
</html>
