<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FuelReceipt extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'fuel_tank_id', 'receipt_number', 'received_at', 'meter_start', 'meter_end', 'gallons_received', 'reserve_before', 'reserve_after',
        'supplier', 'truck_plate', 'user_id', 'notes', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'received_at' => 'date', 'meter_start' => 'float', 'meter_end' => 'float', 'gallons_received' => 'float',
        'reserve_before' => 'float', 'reserve_after' => 'float', 'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => false);
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'fuel_tank_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
