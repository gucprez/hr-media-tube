<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Tanque / reserva de gasoil. Solo FuelService modifica current_gallons (siempre junto a una línea del libro). */
class FuelTank extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'warehouse_id', 'name', 'capacity_gallons', 'current_gallons', 'is_active'];

    protected $casts = ['capacity_gallons' => 'float', 'current_gallons' => 'float', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        // Misma regla que el inventario: la reserva no se edita a mano. FuelService escribe con UPDATE directo
        // (sin eventos de Eloquent) junto a una línea del libro; cualquier escritura Eloquent es una violación.
        static::creating(function (FuelTank $tank) {
            if ((float) $tank->current_gallons != 0.0) {
                throw new \LogicException('La reserva inicial se registra con un ajuste de reserva, no escribiendo el número.');
            }
        });
        static::updating(function (FuelTank $tank) {
            if ($tank->isDirty('current_gallons')) {
                throw new \LogicException('La reserva de gasoil solo puede modificarse mediante recepciones, despachos, anulaciones o ajustes.');
            }
        });
    }

    public function getPercentAttribute(): ?float
    {
        return $this->capacity_gallons ? min(100, round($this->current_gallons / $this->capacity_gallons * 100, 1)) : null;
    }
}
