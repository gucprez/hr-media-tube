<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelLedger extends Model
{
    use BelongsToCompany;

    protected $table = 'fuel_ledger';

    public const LABELS = [
        'receipt' => 'Recepción', 'dispatch' => 'Despacho', 'void_receipt' => 'Anulación de recepción',
        'void_dispatch' => 'Anulación de despacho', 'adjustment' => 'Ajuste de reserva',
    ];

    protected $fillable = ['company_id', 'fuel_tank_id', 'type', 'occurred_at', 'delta_gallons', 'reserve_before', 'reserve_after', 'source_type', 'source_id', 'vehicle_id', 'user_id', 'reason'];

    protected $casts = ['occurred_at' => 'datetime', 'delta_gallons' => 'float', 'reserve_before' => 'float', 'reserve_after' => 'float'];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }
}
