<?php

namespace App\Support\Search;

use App\Models\Category;
use Illuminate\Support\Collection;

class CategorySearch implements SearchProvider
{
    public function key(): string { return 'categories'; }

    public function label(): string { return 'Categorías'; }

    public function permission(): string { return 'products.view'; }

    public function search(string $term, int $limit): Collection
    {
        return Category::withCount('products')->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (Category $c) => [
                'title' => $c->name,
                'subtitle' => $c->products_count.' producto(s)',
                'url' => route('products.index', ['category' => $c->id]),
                'badge' => null,
                'image' => null,
            ]);
    }
}
