<?php

namespace App\Support\Search;

use App\Models\Vehicle;
use Illuminate\Support\Collection;

class VehicleSearch implements SearchProvider
{
    public function key(): string { return 'vehicles'; }

    public function label(): string { return 'Vehículos'; }

    public function permission(): string { return 'vehicles.view'; }

    public function search(string $term, int $limit): Collection
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return Vehicle::where(fn ($q) => $q->where('ficha', 'like', $like)->orWhere('plate', 'like', $like)->orWhere('brand', 'like', $like)->orWhere('model', 'like', $like))
            ->orderBy('ficha')->limit($limit)->get()
            ->map(fn (Vehicle $v) => [
                'title' => 'Vehículo '.$v->ficha.($v->plate ? ' · '.$v->plate : ''),
                'subtitle' => trim(($v->brand ?? '').' '.($v->model ?? '').' '.($v->year ?? '')) ?: 'Sin datos',
                'url' => route('vehicles.show', $v), 'badge' => $v->status_label,
                'image' => $v->photo_path ? route('files.vehicle-photo', $v) : null,
            ]);
    }
}
