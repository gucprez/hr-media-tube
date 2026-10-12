<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Encabezado de un documento de inventario (entrada, salida o ajuste). Inmutable una vez registrado. */
class InventoryMovement extends Model
{
    use BelongsToCompany;

    public const TYPE_ENTRY = 'entry';
    public const TYPE_EXIT = 'exit';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_CONTAINER = 'container';     // consumo de furgón
    public const TYPE_MAINTENANCE = 'maintenance'; // piezas/materiales de una reparación
    public const TYPE_RETURN = 'return';           // devolución al almacén
    public const TYPE_VOID = 'void';               // anulación (reverso de otro documento)

    public const TYPES = [
        self::TYPE_ENTRY => 'Entrada',
        self::TYPE_EXIT => 'Salida',
        self::TYPE_ADJUSTMENT => 'Ajuste',
        self::TYPE_CONTAINER => 'Consumo de furgón',
        self::TYPE_MAINTENANCE => 'Mantenimiento',
        self::TYPE_RETURN => 'Devolución',
        self::TYPE_VOID => 'Anulación',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'number', 'reference', 'type', 'status', 'occurred_at', 'user_id',
        'supplier', 'document_no', 'destination', 'destination_type', 'container_id', 'vehicle_id', 'employee_name',
        'reason', 'notes', 'source_type', 'source_id', 'negative_override', 'authorized_by', 'reversal_of_id', 'voided_at',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'voided_at' => 'datetime', 'negative_override' => 'boolean'];

    protected static function booted(): void
    {
        // Libro contable: no se edita ni se borra. Las correcciones son nuevos movimientos.
        static::deleting(fn () => false);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryMovementItem::class, 'movement_id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
