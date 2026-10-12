<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'key', 'prefix', 'next_number', 'padding'];
}
