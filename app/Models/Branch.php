<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'name', 'code', 'address', 'phone', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
