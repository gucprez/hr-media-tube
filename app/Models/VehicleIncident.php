<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleIncident extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUSES = ['reported' => 'Reportada', 'pending' => 'Pendiente', 'in_repair' => 'En reparación', 'repaired' => 'Reparada', 'closed' => 'Cerrada'];
    public const OPEN = ['reported', 'pending', 'in_repair'];
    public const PRIORITIES = ['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Crítica'];
    public const PRIORITY_BADGES = ['low' => 'badge-gray', 'medium' => 'badge-blue', 'high' => 'badge-orange', 'critical' => 'badge-red'];
    public const STATUS_BADGES = ['reported' => 'badge-yellow', 'pending' => 'badge-orange', 'in_repair' => 'badge-blue', 'repaired' => 'badge-green', 'closed' => 'badge-gray'];

    protected $fillable = ['company_id', 'vehicle_id', 'reported_by', 'title', 'description', 'odometer', 'priority', 'status', 'reported_at', 'closed_at'];

    protected $casts = ['odometer' => 'float', 'reported_at' => 'datetime', 'closed_at' => 'datetime'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class, 'incident_id')->orderBy('id');
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class, 'incident_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITIES[$this->priority] ?? $this->priority;
    }
}
