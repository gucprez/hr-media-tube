<?php

namespace App\Support\Search;

use Illuminate\Support\Collection;

/** Contrato para agregar módulos a la búsqueda global (furgones, vehículos, tickets… en fases futuras). */
interface SearchProvider
{
    public function key(): string;

    public function label(): string;

    /** Permiso necesario para ver estos resultados. */
    public function permission(): string;

    /** @return Collection<int, array{title:string,subtitle:?string,url:string,badge:?string,image:?string}> */
    public function search(string $term, int $limit): Collection;
}
