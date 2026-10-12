<?php

namespace App\Services\Tickets;

use App\Models\Company;
use App\Models\Ticket;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/** Disposición de tickets en hoja carta (1, 2 o 4 por hoja) y generación de PDF. */
class TicketRenderer
{
    /** Máximo de líneas que caben legibles según tickets por hoja. */
    public const MAX_LINES = [4 => 10, 2 => 24, 1 => 60];

    /**
     * @return array{copies:int,requested:int,rows:int,cols:int,notice:?string,labels:string[]}
     */
    public function plan(Ticket $ticket, int $requested): array
    {
        $requested = in_array($requested, [1, 2, 4], true) ? $requested : 4;
        $lines = $ticket->items->count();
        $copies = $requested;
        while ($copies > 1 && $lines > self::MAX_LINES[$copies]) {
            $copies = $copies === 4 ? 2 : 1;
        }

        return [
            'copies' => $copies,
            'requested' => $requested,
            'rows' => $copies === 1 ? 1 : 2,
            'cols' => $copies === 4 ? 2 : 1,
            'notice' => $copies < $requested ? "El ticket tiene {$lines} líneas: para que sea legible se imprime {$copies} por hoja en lugar de {$requested}." : null,
            'labels' => collect(range(1, $copies))->map(fn ($i) => $i === 1 ? 'ORIGINAL' : 'COPIA '.$i)->all(),
        ];
    }

    public function logoDataUri(?Company $company = null): ?string
    {
        $company ??= app(CurrentCompany::class)->get();
        if (! $company?->logo_path || ! Storage::disk('public')->exists($company->logo_path)) {
            return null;
        }
        $mime = Storage::disk('public')->mimeType($company->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($company->logo_path));
    }

    public function pdf(Ticket $ticket, int $copies, bool $reprint)
    {
        $plan = $this->plan($ticket->load('items'), $copies);

        return Pdf::loadView('operations.tickets.pdf', [
            'ticket' => $ticket, 'plan' => $plan, 'reprint' => $reprint, 'company' => app(CurrentCompany::class)->get(), 'logo' => $this->logoDataUri(),
        ])->setPaper('letter', 'portrait')->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    }
}
