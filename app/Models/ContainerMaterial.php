<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerMaterial extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'container_id', 'product_id', 'product_sku', 'product_name', 'boxes', 'units', 'quantity_base', 'notes'];

    protected $casts = ['boxes' => 'float', 'units' => 'float', 'quantity_base' => 'float'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }
}
