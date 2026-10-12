<?php

namespace App\Support\Search;

use App\Models\User;

class GlobalSearch
{
    /** @param  SearchProvider[]  $providers */
    public function __construct(protected array $providers = []) {}

    public function register(SearchProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    /** @return array<string,array{label:string,results:\Illuminate\Support\Collection}> */
    public function search(User $user, string $term, int $limit = 8): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $out = [];
        foreach ($this->providers as $key => $provider) {
            if (! $user->hasPermission($provider->permission())) {
                continue; // los permisos mandan también en la búsqueda
            }
            $results = $provider->search($term, $limit);
            if ($results->isNotEmpty()) {
                $out[$key] = ['label' => $provider->label(), 'results' => $results];
            }
        }

        return $out;
    }
}
