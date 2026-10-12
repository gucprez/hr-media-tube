<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenancePart extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'maintenance_record_id', 'product_id', 'product_sku', 'product_name', 'movement_id', 'quantity_base'];

    protected $casts = ['quantity_base' => 'float'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
