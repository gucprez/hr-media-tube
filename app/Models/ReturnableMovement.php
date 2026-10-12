<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnableMovement extends Model
{
    use BelongsToCompany;

    public const STATES = [
        'new' => 'Nuevo', 'delivered' => 'Entregado', 'used' => 'Usado', 'returned' => 'Retornado', 'refillable' => 'Recargable', 'discarded' => 'Desechado',
    ];
    /** Estados que cierran la obligación de devolver. */
    public const CLOSING = ['returned', 'refillable', 'discarded'];

    protected $fillable = ['company_id', 'product_id', 'product_name', 'vehicle_id', 'holder', 'movement_id', 'user_id', 'state', 'quantity', 'occurred_at', 'notes'];

    protected $casts = ['quantity' => 'float', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
