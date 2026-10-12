<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea del libro de movimientos. Guarda snapshot completo (tipo, almacén, fecha, stock antes/después,
 * producto) para reconstruir cualquier fecha sin depender del stock actual.
 */
class InventoryMovementItem extends Model
{
    use BelongsToCompany;

    // Tipos de línea (nomenclatura del libro)
    public const ENTRY = 'entry';                 // ENTRADA
    public const EXIT = 'exit';                   // SALIDA
    public const ADJUSTMENT_IN = 'adjustment_in'; // AJUSTE_POSITIVO
    public const ADJUSTMENT_OUT = 'adjustment_out'; // AJUSTE_NEGATIVO
    public const CONTAINER = 'container';         // CONSUMO_FURGON   (Fase 3)
    public const MAINTENANCE = 'maintenance';     // MANTENIMIENTO    (Fase 5)
    public const RETURN = 'return';               // DEVOLUCION       (Fase 5)
    public const VOID = 'void';                   // ANULACION

    public const LABELS = [
        self::ENTRY => 'Entrada',
        self::EXIT => 'Salida',
        self::ADJUSTMENT_IN => 'Ajuste positivo',
        self::ADJUSTMENT_OUT => 'Ajuste negativo',
        self::CONTAINER => 'Consumo furgón',
        self::MAINTENANCE => 'Mantenimiento',
        self::RETURN => 'Devolución',
        self::VOID => 'Anulación',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'movement_id', 'type', 'occurred_at', 'product_id', 'product_sku', 'product_name',
        'units_per_box', 'boxes', 'units', 'delta_base', 'stock_before_base', 'balance_after_base',
    ];

    protected $casts = [
        'occurred_at' => 'datetime', 'delta_base' => 'float', 'stock_before_base' => 'float', 'balance_after_base' => 'float',
        'boxes' => 'float', 'units' => 'float', 'units_per_box' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function getQuantityBaseAttribute(): float
    {
        return abs($this->delta_base);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }

    /** Alias semántico: existencia resultante (unidad base). */
    public function getStockAfterBaseAttribute(): float
    {
        return $this->balance_after_base;
    }
}
