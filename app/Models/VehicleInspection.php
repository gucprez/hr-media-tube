<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleInspection extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['good' => 'Bien', 'attention' => 'Requiere atención', 'damaged' => 'Dañado', 'na' => 'No aplica'];
    public const OVERALL = ['good' => 'Sin novedades', 'attention' => 'Requiere atención', 'damaged' => 'Con daños'];
    public const OVERALL_BADGES = ['good' => 'badge-green', 'attention' => 'badge-yellow', 'damaged' => 'badge-red'];

    protected $fillable = ['company_id', 'vehicle_id', 'inspector_id', 'inspected_at', 'next_due_at', 'odometer', 'overall_status', 'notes'];

    protected $casts = ['inspected_at' => 'date', 'next_due_at' => 'date', 'odometer' => 'float'];

    public function items(): HasMany
    {
        return $this->hasMany(InspectionItem::class)->orderBy('id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
