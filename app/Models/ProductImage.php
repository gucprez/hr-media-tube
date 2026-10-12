<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'product_id', 'path', 'thumb_path', 'original_name', 'size', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(bool $thumb = false): string
    {
        return route('files.product-image', ['image' => $this->id, 'thumb' => $thumb ? 1 : null]);
    }
}
