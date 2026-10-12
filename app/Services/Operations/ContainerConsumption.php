<?php

namespace App\Services\Operations;

use App\Models\InventoryMovementItem as Line;
use Illuminate\Support\Collection;

/**
 * Consumo de materiales por furgón calculado SIEMPRE desde el libro de movimientos (no desde el stock):
 * consumos de furgón y salidas con destino el furgón, netos de sus anulaciones.
 */
class ContainerConsumption
{
    /** Tipos de línea que cuentan como consumo (la anulación `void` revierte, por eso suma con signo contrario). */
    public const TYPES = [Line::CONTAINER, Line::EXIT, Line::VOID];

    /**
     * @param  int[]  $containerIds
     * @return Collection<int, object{container_id:int,product_id:int,product_sku:?string,product_name:?string,quantity_base:float}>
     */
    public function byProduct(array $containerIds): Collection
    {
        if (! $containerIds) {
            return collect();
        }

        return Line::query()
            ->join('inventory_movements', 'inventory_movements.id', '=', 'inventory_movement_items.movement_id')
            ->whereIn('inventory_movements.container_id', $containerIds)
            ->whereIn('inventory_movement_items.type', self::TYPES)
            ->groupBy('inventory_movements.container_id', 'inventory_movement_items.product_id')
            ->selectRaw('inventory_movements.container_id as container_id, inventory_movement_items.product_id as product_id')
            ->selectRaw('MAX(inventory_movement_items.product_sku) as product_sku, MAX(inventory_movement_items.product_name) as product_name')
            ->selectRaw('-SUM(inventory_movement_items.delta_base) as net_base')
            ->get()
            // objetos planos: el modelo de línea tiene un accesor `quantity_base` que taparía el alias
            ->map(fn ($r) => (object) [
                'container_id' => (int) $r->container_id, 'product_id' => (int) $r->product_id,
                'product_sku' => $r->product_sku, 'product_name' => $r->product_name, 'quantity_base' => round((float) $r->net_base, 3),
            ])
            ->filter(fn ($r) => $r->quantity_base != 0.0)
            ->values();
    }
}
