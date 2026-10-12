<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FuelDispatch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'fuel_tank_id', 'vehicle_id', 'container_id', 'dispatched_at', 'reading_start', 'reading_end', 'gallons', 'odometer',
        'reserve_before', 'reserve_after', 'user_id', 'notes', 'origin', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'dispatched_at' => 'date', 'reading_start' => 'float', 'reading_end' => 'float', 'gallons' => 'float', 'odometer' => 'float',
        'reserve_before' => 'float', 'reserve_after' => 'float', 'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => false);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class)->withTrashed();
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
