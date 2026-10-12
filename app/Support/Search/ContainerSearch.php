<?php

namespace App\Support\Search;

use App\Models\Container;
use Illuminate\Support\Collection;

class ContainerSearch implements SearchProvider
{
    public function key(): string { return 'containers'; }

    public function label(): string { return 'Furgones'; }

    public function permission(): string { return 'containers.view'; }

    public function search(string $term, int $limit): Collection
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return Container::with('vehicle')->where(fn ($q) => $q->where('number', 'like', $like)->orWhere('driver_name', 'like', $like)->orWhere('conduce', 'like', $like)
            ->orWhereHas('vehicle', fn ($v) => $v->where('ficha', 'like', $like)))
            ->orderByDesc('date')->limit($limit)->get()
            ->map(fn (Container $c) => [
                'title' => 'Furgón #'.$c->number,
                'subtitle' => $c->date->format('d/m/Y').' · '.($c->driver_display ?? 'Sin chofer').($c->vehicle ? ' · '.$c->vehicle->ficha : ''),
                'url' => route('containers.show', $c), 'badge' => $c->status_label, 'image' => null,
            ]);
    }
}
