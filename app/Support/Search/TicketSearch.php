<?php

namespace App\Support\Search;

use App\Models\Ticket;
use Illuminate\Support\Collection;

class TicketSearch implements SearchProvider
{
    public function key(): string { return 'tickets'; }

    public function label(): string { return 'Tickets'; }

    public function permission(): string { return 'tickets.view'; }

    public function search(string $term, int $limit): Collection
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return Ticket::where(fn ($q) => $q->where('number', 'like', $like)->orWhere('container_number', 'like', $like)->orWhere('driver_name', 'like', $like)
            ->orWhere('conduce', 'like', $like)->orWhere('ficha', 'like', $like))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Ticket $t) => [
                'title' => $t->type_label.' N° '.$t->number,
                'subtitle' => 'Furgón #'.$t->container_number.' · '.$t->issued_at->format('d/m/Y').($t->driver_name ? ' · '.$t->driver_name : ''),
                'url' => route('tickets.show', $t), 'badge' => $t->isVoided() ? 'Anulado' : 'Emitido', 'image' => null,
            ]);
    }
}
