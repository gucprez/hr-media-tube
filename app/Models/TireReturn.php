<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Una goma nueva entregada a un camión y el seguimiento de la goma usada que debe regresar. */
class TireReturn extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['pending' => 'Devolución pendiente', 'received' => 'Usada recibida', 'waived' => 'Sin devolución'];
    public const CONDITIONS = ['worn' => 'Gastada', 'damaged' => 'Dañada', 'repairable' => 'Reparable', 'reusable' => 'Reutilizable', 'scrap' => 'Desecho'];

    protected $fillable = [
        'company_id', 'vehicle_id', 'product_id', 'product_name', 'movement_id', 'quantity', 'odometer', 'delivered_at', 'delivered_by', 'reason',
        'used_tire_received', 'status', 'condition', 'received_at', 'received_by', 'notes', 'waived_reason',
    ];

    protected $casts = ['delivered_at' => 'date', 'received_at' => 'datetime', 'used_tire_received' => 'boolean', 'odometer' => 'float'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
