<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUSES = [
        'operational' => 'Operativo',
        'breakdown' => 'Con avería',
        'maintenance' => 'En mantenimiento',
        'inactive' => 'Inactivo',
    ];

    public const STATUS_BADGES = [
        'operational' => 'badge-green',
        'breakdown' => 'badge-red',
        'maintenance' => 'badge-yellow',
        'inactive' => 'badge-gray',
    ];

    protected $fillable = [
        'company_id', 'vehicle_type_id', 'usual_driver_id', 'ficha', 'plate', 'brand', 'model', 'year', 'photo_path',
        'odometer', 'status', 'notes',
    ];

    protected $casts = ['odometer' => 'float', 'year' => 'integer'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function usualDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'usual_driver_id')->withTrashed();
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::STATUS_BADGES[$this->status] ?? 'badge-gray';
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->ficha.($this->plate ? ' · '.$this->plate : '').' '.trim(($this->brand ?? '').' '.($this->model ?? '')));
    }
}
