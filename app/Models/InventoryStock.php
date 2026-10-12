<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Existencia actual por almacén y producto, en UNIDAD BASE.
 * Solo App\Services\Inventory\StockService debe escribir aquí (siempre junto a un movimiento).
 */
class InventoryStock extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'warehouse_id', 'product_id', 'quantity_base'];

    protected $casts = ['quantity_base' => 'float'];

    protected static function booted(): void
    {
        // StockService escribe con consultas directas (UPDATE relativo + movimiento). Cualquier escritura
        // Eloquent (->save(), ->update(), ->delete()) es un intento de cambiar el stock sin movimiento.
        $deny = fn () => throw new \LogicException('El stock solo puede modificarse mediante movimientos de inventario.');
        static::saving($deny);
        static::deleting($deny);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
