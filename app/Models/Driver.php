<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'name', 'phone', 'license_no', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
