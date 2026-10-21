<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Furgón = operación. Al cerrarse descuenta sus materiales del inventario mediante un movimiento «consumo de furgón». */
class Container extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const CLOSED = 'closed';
    public const VOIDED = 'voided';

    public const STATUSES = [
        self::OPEN => 'Abierto',
        self::IN_PROGRESS => 'En proceso',
        self::CLOSED => 'Cerrado',
        self::VOIDED => 'Anulado',
    ];

    public const BADGES = [
        self::OPEN => 'badge-blue',
        self::IN_PROGRESS => 'badge-yellow',
        self::CLOSED => 'badge-green',
        self::VOIDED => 'badge-red',
    ];

    protected $fillable = [
        'company_id', 'warehouse_id', 'number', 'date', 'driver_id', 'vehicle_id', 'driver_name', 'conduce', 'phone', 'fuel_gallons', 'deduct_on_close',
        'responsible_id', 'movement_id', 'void_movement_id', 'status', 'notes', 'closed_at', 'closed_by', 'started_at',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'date' => 'date', 'fuel_gallons' => 'float', 'deduct_on_close' => 'boolean', 'closed_at' => 'datetime', 'started_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function materials(): HasMany
    {
        return $this->hasMany(ContainerMaterial::class)->orderBy('id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->orderBy('id');
    }

    /** Salidas de almacén asociadas (destino = este furgón) y sus anulaciones. */
    public function linkedMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'container_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::OPEN, self::IN_PROGRESS], true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::BADGES[$this->status] ?? 'badge-gray';
    }

    public function getDriverDisplayAttribute(): ?string
    {
        return $this->driver?->name ?? $this->driver_name;
    }
}
