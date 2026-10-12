<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MaintenanceRecord extends Model
{
    use BelongsToCompany;

    public const TYPES = ['corrective' => 'Correctivo (reparación)', 'preventive' => 'Preventivo'];

    protected $fillable = [
        'company_id', 'vehicle_id', 'incident_id', 'type', 'user_id', 'performed_at', 'odometer', 'description', 'responsible', 'notes', 'movement_id',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = ['performed_at' => 'date', 'odometer' => 'float', 'voided_at' => 'datetime'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(VehicleIncident::class, 'incident_id')->withTrashed();
    }

    public function parts(): HasMany
    {
        return $this->hasMany(MaintenancePart::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
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
