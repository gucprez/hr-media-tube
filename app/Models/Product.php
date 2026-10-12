<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Inventory\StockStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const MODE_UNITS = 'units';
    public const MODE_BOXES = 'boxes';
    public const MODE_BOTH = 'both';

    public const MODES = [
        self::MODE_UNITS => 'Solo unidades',
        self::MODE_BOXES => 'Solo cajas',
        self::MODE_BOTH => 'Cajas + unidades',
    ];

    protected $fillable = [
        'company_id', 'category_id', 'unit_id', 'default_warehouse_id', 'sku', 'barcode', 'name', 'description', 'handling_mode',
        'box_label', 'presentation', 'units_per_box', 'alert_unit', 'min_quantity', 'min_stock_base', 'critical_stock_base',
        'location', 'aisle', 'shelf', 'is_returnable', 'returnable_kind', 'is_active', 'created_by',
    ];

    protected $casts = [
        'units_per_box' => 'integer', 'min_quantity' => 'float', 'min_stock_base' => 'float', 'critical_stock_base' => 'float',
        'is_returnable' => 'boolean', 'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function movementItems(): HasMany
    {
        return $this->hasMany(InventoryMovementItem::class);
    }

    /** Existencia total (todas las bodegas) en unidad base. Usa withSum('stocks as total_base') si está cargado. */
    public function totalBase(): float
    {
        if (array_key_exists('total_base', $this->attributes)) {
            return (float) $this->attributes['total_base'];
        }

        return (float) $this->stocks()->sum('quantity_base');
    }

    public function stockInWarehouse(int $warehouseId): float
    {
        return (float) $this->stocks()->where('warehouse_id', $warehouseId)->value('quantity_base');
    }

    public function status(?float $base = null): string
    {
        return app(StockStatus::class)->for($this, $base ?? $this->totalBase());
    }

    public function getUnitNameAttribute(): string
    {
        return $this->unit?->name ?? 'unidad';
    }

    /** Ubicación legible: almacén · pasillo · estante · libre. */
    public function getLocationLabelAttribute(): string
    {
        return collect([
            $this->defaultWarehouse?->name,
            $this->aisle ? 'Pasillo '.$this->aisle : null,
            $this->shelf ? 'Estante '.$this->shelf : null,
            $this->location,
        ])->filter()->implode(' · ') ?: '—';
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! filled($term)) {
            return $q;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', 'like', $like)->orWhere('description', 'like', $like));
    }
}
