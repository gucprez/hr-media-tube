<?php

namespace App\Support\Search;

use App\Models\Product;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockStatus;
use Illuminate\Support\Collection;

class ProductSearch implements SearchProvider
{
    public function key(): string { return 'products'; }

    public function label(): string { return 'Productos'; }

    public function permission(): string { return 'products.view'; }

    public function search(string $term, int $limit): Collection
    {
        $converter = app(QuantityConverter::class);
        $status = app(StockStatus::class);

        return Product::with(['unit', 'category', 'primaryImage'])->withSum('stocks as total_base', 'quantity_base')
            ->search($term)->orderBy('name')->limit($limit)->get()
            ->map(fn (Product $p) => [
                'title' => $p->name,
                'subtitle' => $p->sku.' · '.($p->category?->name ?? 'Sin categoría').' · '.$converter->format($p, $p->totalBase()),
                'url' => route('products.show', $p),
                'badge' => $status->label($p->status()),
                'image' => $p->primaryImage?->url(true),
            ]);
    }
}
