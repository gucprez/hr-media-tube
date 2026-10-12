<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'plural', 'abbreviation', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
