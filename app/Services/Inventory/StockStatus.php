<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Estados de alerta: AGOTADO (0) · CRÍTICO (≤ umbral crítico) · STOCK BAJO (≤ mínimo) · DISPONIBLE.
 * Umbral crítico = critical_stock_base del producto o, por defecto, mínimo × critical_ratio de la empresa.
 */
class StockStatus
{
    public const AVAILABLE = 'available';
    public const LOW = 'low';
    public const CRITICAL = 'critical';
    public const OUT = 'out';

    public const LABELS = [
        self::AVAILABLE => 'Disponible',
        self::LOW => 'Stock bajo',
        self::CRITICAL => 'Crítico',
        self::OUT => 'Agotado',
    ];

    public const BADGES = [
        self::AVAILABLE => 'badge-green',
        self::LOW => 'badge-yellow',
        self::CRITICAL => 'badge-orange',
        self::OUT => 'badge-red',
    ];

    public function __construct(protected QuantityConverter $converter) {}

    public function ratio(): float
    {
        $ratio = (float) (app(CurrentCompany::class)->get()?->setting('critical_ratio', 0.5) ?? 0.5);

        return $ratio > 0 ? min($ratio, 1) : 0.5;
    }

    public function minimumBase(Product $product): float
    {
        return $this->converter->minimumBase($product);
    }

    public function criticalBase(Product $product): float
    {
        return $product->critical_stock_base !== null
            ? (float) $product->critical_stock_base
            : round($this->minimumBase($product) * $this->ratio(), 3);
    }

    public function for(Product $product, float $base): string
    {
        $min = $this->minimumBase($product);

        if ($base <= 0) {
            return self::OUT;
        }
        if ($min <= 0) {
            return self::AVAILABLE;
        }
        if ($base <= $this->criticalBase($product)) {
            return self::CRITICAL;
        }

        return $base <= $min ? self::LOW : self::AVAILABLE;
    }

    public function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public function badge(string $status): string
    {
        return self::BADGES[$status] ?? 'badge-gray';
    }

    /**
     * Filtra productos por estado en SQL (portable MySQL/SQLite).
     * Expresión del total: suma de existencias del producto (opcionalmente de un almacén).
     */
    public function scopeStatus(Builder $q, string $status, ?int $warehouseId = null): Builder
    {
        $totalSql = '(SELECT COALESCE(SUM(s.quantity_base),0) FROM inventory_stocks s WHERE s.product_id = products.id'
            .($warehouseId ? ' AND s.warehouse_id = '.(int) $warehouseId : '').')';
        // mínimo en unidad base (cajas × unidades por caja, o unidades)
        $minSql = "(CASE WHEN products.alert_unit = 'boxes' THEN products.min_quantity * products.units_per_box ELSE products.min_quantity END)";
        $ratio = $this->ratio();
        $critSql = "COALESCE(products.critical_stock_base, {$minSql} * {$ratio})";

        return match ($status) {
            self::OUT => $q->whereRaw("{$totalSql} <= 0"),
            self::CRITICAL => $q->whereRaw("{$totalSql} > 0 AND {$minSql} > 0 AND {$totalSql} <= {$critSql}"),
            self::LOW => $q->whereRaw("{$totalSql} > {$critSql} AND {$totalSql} <= {$minSql} AND {$minSql} > 0"),
            self::AVAILABLE => $q->whereRaw("{$totalSql} > 0 AND ({$minSql} <= 0 OR {$totalSql} > {$minSql})"),
            default => $q,
        };
    }
}
