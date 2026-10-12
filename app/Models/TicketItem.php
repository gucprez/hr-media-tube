<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TicketItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'ticket_id', 'product_id', 'description', 'quantity_label', 'total_units', 'unit_label', 'ficha', 'odometer', 'gallons'];

    protected $casts = ['total_units' => 'float', 'odometer' => 'float', 'gallons' => 'float'];
}
