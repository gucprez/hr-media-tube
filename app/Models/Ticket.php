<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ticket correlativo. Nunca se elimina: se ANULA conservando usuario, fecha y motivo. */
class Ticket extends Model
{
    use BelongsToCompany;

    public const MATERIALS = 'materials';
    public const FUEL = 'fuel';
    public const TYPES = [self::MATERIALS => 'Ticket de materiales', self::FUEL => 'Ticket de gasoil'];

    protected $fillable = [
        'company_id', 'container_id', 'vehicle_id', 'type', 'number', 'status', 'issued_at', 'container_number', 'driver_name', 'conduce',
        'ficha', 'phone', 'dispatched_by', 'received_by', 'notes', 'print_count', 'print_layout', 'last_printed_at', 'created_by',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = ['issued_at' => 'date', 'voided_at' => 'datetime', 'last_printed_at' => 'datetime', 'print_count' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(fn () => false); // un ticket no se borra jamás
    }

    public function items(): HasMany
    {
        return $this->hasMany(TicketItem::class)->orderBy('id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
