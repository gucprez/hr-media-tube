<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement as Movement;
use App\Models\InventoryMovementItem as Line;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\DocumentNumbering;
use App\Support\CurrentCompany;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Motor de inventario. ÚNICA vía para cambiar existencias: cada cambio crea un documento
 * (InventoryMovement) con líneas (InventoryMovementItem) que guardan stock antes/después.
 *
 * Integridad:
 *  - todo ocurre en una transacción;
 *  - las filas de stock se bloquean (SELECT … FOR UPDATE) en orden de product_id (evita deadlocks);
 *  - además el UPDATE es relativo y condicionado (quantity_base + delta >= 0) como segunda barrera;
 *  - el stock negativo solo con permiso inventory.negative + opción de empresa + confirmación explícita.
 */
class StockService
{
    /** Tipo de documento -> secuencia de numeración. */
    public const NUMBERING_KEYS = [
        Movement::TYPE_ENTRY => 'entry', Movement::TYPE_EXIT => 'exit', Movement::TYPE_ADJUSTMENT => 'adjustment',
        Movement::TYPE_CONTAINER => 'container_move', Movement::TYPE_MAINTENANCE => 'maintenance_move',
        Movement::TYPE_RETURN => 'return', Movement::TYPE_VOID => 'void',
    ];

    public function __construct(
        protected QuantityConverter $converter,
        protected StockStatus $status,
        protected DocumentNumbering $numbering,
    ) {}

    // ---------------------------------------------------------------- API pública

    /**
     * @param  array{warehouse_id:int,occurred_at?:CarbonInterface,supplier?:?string,document_no?:?string,reason?:?string,notes?:?string,reference?:?string}  $header
     * @param  array<int,array{product_id:int,boxes?:mixed,units?:mixed}>  $lines
     */
    public function entry(array $header, array $lines, ?User $user = null): Movement
    {
        return $this->post(Movement::TYPE_ENTRY, $header, $this->quantityLines($lines), $user);
    }

    /** Salida. Preparada para destinos: container_id, vehicle_id, employee_name, destination_type (other|container|vehicle|maintenance|employee). */
    public function exit(array $header, array $lines, ?User $user = null): Movement
    {
        return $this->post(Movement::TYPE_EXIT, $header, $this->quantityLines($lines), $user);
    }

    /**
     * Ajuste por conteo físico. Cada línea indica el CONTEO FÍSICO; la diferencia se calcula contra el
     * stock real (bloqueado) y se registra como movimiento. Nunca se "sobrescribe" el número.
     */
    public function adjust(array $header, array $lines, ?User $user = null): Movement
    {
        if (blank($header['reason'] ?? null) || blank($header['notes'] ?? null)) {
            throw new InventoryException('El ajuste exige motivo y observación.');
        }

        return $this->post(Movement::TYPE_ADJUSTMENT, $header, $this->quantityLines($lines, allowZero: true), $user);
    }

    /** Consumo de materiales de un furgón (header: container_id obligatorio). */
    public function consume(array $header, array $lines, ?User $user = null): Movement
    {
        return $this->post(Movement::TYPE_CONTAINER, $header, $this->quantityLines($lines), $user);
    }

    /** Piezas/materiales usados en una reparación o entrega a vehículo (header: vehicle_id, source_*). */
    public function maintenance(array $header, array $lines, ?User $user = null): Movement
    {
        return $this->post(Movement::TYPE_MAINTENANCE, $header, $this->quantityLines($lines), $user);
    }

    /** Devolución de material al almacén (aumenta existencia). */
    public function returnToStock(array $header, array $lines, ?User $user = null): Movement
    {
        return $this->post(Movement::TYPE_RETURN, $header, $this->quantityLines($lines), $user);
    }

    /**
     * ANULACIÓN: crea un documento inverso (tipo «void») que devuelve cada línea a su estado anterior y marca el original
     * como anulado. Nada se borra ni se edita; el stock jamás queda negativo salvo autorización especial.
     */
    public function reverse(Movement $movement, string $reason, ?User $user = null, bool $allowNegative = false): Movement
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InventoryException('Indique el motivo de la anulación.');
        }
        $companyId = app(CurrentCompany::class)->id();

        return DB::transaction(function () use ($movement, $reason, $user, $allowNegative, $companyId) {
            $original = Movement::lockForUpdate()->find($movement->id);
            if (! $original) {
                throw new InventoryException('El documento no existe o no pertenece a su empresa.');
            }
            if ($original->voided_at || $original->type === Movement::TYPE_VOID) {
                throw new InventoryException('Este documento ya está anulado o es una anulación.');
            }
            $allowNegative = $this->negativeAllowed(Movement::TYPE_EXIT, ['allow_negative' => $allowNegative], $user);

            $items = Line::where('movement_id', $original->id)->get();
            $stocks = $this->lockStocks((int) $original->warehouse_id, $items->pluck('product_id')->unique()->sort()->values()->all(), $companyId);
            $products = Product::withTrashed()->with('unit')->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');

            $computed = [];
            foreach ($items->sortBy('product_id') as $line) {
                $stock = $stocks[$line->product_id];
                $before = round((float) $stock->quantity_base, 3);
                $delta = round(-1 * (float) $line->delta_base, 3);
                $after = round($before + $delta, 3);
                if ($after < 0 && ! $allowNegative) {
                    $p = $products[$line->product_id];
                    throw new InventoryException(sprintf(
                        'No se puede anular: «%s» quedaría en negativo (hay %s y se devolverían %s). El material ya fue utilizado.',
                        $p->name, $this->converter->format($p, $before), $this->converter->format($p, abs($delta)),
                    ));
                }
                $computed[] = compact('line', 'stock', 'delta', 'before', 'after');
            }

            $void = Movement::create([
                'company_id' => $companyId, 'branch_id' => $original->branch_id, 'warehouse_id' => $original->warehouse_id,
                'number' => $this->numbering->next('void', $companyId), 'type' => Movement::TYPE_VOID, 'status' => 'posted',
                'occurred_at' => now(), 'user_id' => $user?->id, 'reference' => $original->number, 'reason' => $reason,
                'notes' => 'Anulación de '.$original->number.' ('.$original->type_label.')',
                'container_id' => $original->container_id, 'vehicle_id' => $original->vehicle_id, 'destination' => $original->destination,
                'destination_type' => $original->destination_type, 'employee_name' => $original->employee_name,
                'source_type' => $original->source_type, 'source_id' => $original->source_id,
                'negative_override' => $allowNegative && collect($computed)->contains(fn ($c) => $c['after'] < 0),
                'authorized_by' => $allowNegative ? $user?->id : null, 'reversal_of_id' => $original->id,
            ]);

            foreach ($computed as $c) {
                $this->applyDelta($c['stock'], $c['delta'], $allowNegative, $products[$c['line']->product_id]);
                [$boxes, $units] = $this->converter->split($products[$c['line']->product_id], abs($c['delta']));
                Line::create([
                    'company_id' => $companyId, 'branch_id' => $original->branch_id, 'warehouse_id' => $original->warehouse_id,
                    'movement_id' => $void->id, 'type' => Line::VOID, 'occurred_at' => $void->occurred_at, 'product_id' => $c['line']->product_id,
                    'product_sku' => $c['line']->product_sku, 'product_name' => $c['line']->product_name, 'units_per_box' => $c['line']->units_per_box,
                    'boxes' => abs($boxes), 'units' => abs($units), 'delta_base' => $c['delta'], 'stock_before_base' => $c['before'], 'balance_after_base' => $c['after'],
                ]);
            }

            $original->forceFill(['voided_at' => now(), 'status' => 'voided'])->save();

            Audit::log('inventory.void', sprintf('Anulación %s de %s %s: %s', $void->number, $original->type_label, $original->number, $reason), $original,
                ['status' => 'posted'], ['status' => 'voided', 'motivo' => $reason, 'documento_anulacion' => $void->number]);

            return $void->load('items');
        }, 3);
    }

    /**
     * Vista previa sin escribir ni bloquear: cantidades, existencia resultante y errores por línea.
     *
     * @return array<int,array<string,mixed>>
     */
    public function preview(string $docType, int $warehouseId, array $lines): array
    {
        $out = [];
        foreach ($lines as $i => $raw) {
            $row = ['ok' => false, 'error' => null];
            try {
                $product = Product::with('unit')->findOrFail($raw['product_id'] ?? 0);
                $base = $this->converter->toBase($product, $raw['boxes'] ?? 0, $raw['units'] ?? 0);
                $current = $product->stockInWarehouse($warehouseId);
                $row['current_label'] = $this->converter->format($product, $current);
                $row['quantity_base'] = $base;
                $row['quantity_label'] = $this->converter->format($product, $base);

                if ($docType === Movement::TYPE_ADJUSTMENT) {
                    $row['delta_base'] = round($base - $current, 3);
                    $row['delta_label'] = ($row['delta_base'] > 0 ? '+' : '').$this->converter->formatTotal($product, $row['delta_base']);
                    $row['result_label'] = $this->converter->format($product, $base);
                    $row['ok'] = true;
                } else {
                    $result = in_array($docType, [Movement::TYPE_EXIT, Movement::TYPE_CONTAINER, Movement::TYPE_MAINTENANCE], true) ? $current - $base : $current + $base;
                    $row['result_label'] = $this->converter->format($product, $result);
                    $row['status'] = $this->status->label($this->status->for($product, max($result, 0)));
                    if ($base <= 0) {
                        $row['error'] = 'Indique una cantidad mayor que cero.';
                    } elseif ($result < 0) {
                        $row['error'] = 'Stock insuficiente: hay '.$row['current_label'].'.';
                    } else {
                        $row['ok'] = true;
                    }
                }
            } catch (\Throwable $e) {
                $row['error'] = $e instanceof InvalidArgumentException || $e instanceof InventoryException ? $e->getMessage() : 'Producto no válido.';
            }
            $out[$i] = $row;
        }

        return $out;
    }

    // ---------------------------------------------------------------- núcleo

    /**
     * @param  array<int,array{product_id:int,base:float}>  $lines  ya convertidas a unidad base
     */
    protected function post(string $docType, array $header, array $lines, ?User $user): Movement
    {
        $companyId = app(CurrentCompany::class)->id();
        if (! $companyId) {
            throw new InventoryException('No hay empresa activa.');
        }
        if (! $lines) {
            throw new InventoryException('Agregue al menos un producto.');
        }

        $warehouse = Warehouse::where('is_active', true)->find($header['warehouse_id'] ?? 0);
        if (! $warehouse) {
            throw new InventoryException('El almacén seleccionado no es válido.');
        }

        $allowNegative = $this->negativeAllowed($docType, $header, $user);
        $occurredAt = $header['occurred_at'] ?? now();
        if (! $occurredAt instanceof CarbonInterface) {
            $occurredAt = now()->parse($occurredAt);
        }

        return DB::transaction(function () use ($docType, $header, $lines, $user, $companyId, $warehouse, $allowNegative, $occurredAt) {
            // 1) productos (con alcance de empresa) y filas de stock bloqueadas en orden estable
            $productIds = collect($lines)->pluck('product_id')->unique()->sort()->values();
            $products = Product::with('unit')->whereIn('id', $productIds)->get()->keyBy('id');
            foreach ($productIds as $pid) {
                if (! $products->has($pid)) {
                    throw new InventoryException('Uno de los productos no existe o no pertenece a su empresa.');
                }
            }
            $stocks = $this->lockStocks((int) $warehouse->id, $productIds->all(), $companyId);

            // 2) cálculo de cada línea contra el stock bloqueado
            $computed = [];
            foreach ($lines as $spec) {
                $product = $products[$spec['product_id']];
                $stock = $stocks[$product->id];
                $before = round((float) $stock->quantity_base, 3);

                [$type, $delta] = $this->resolve($docType, $product, $spec, $before);
                if ($delta == 0.0) {
                    continue; // ajuste sin diferencia
                }
                if (in_array($docType, [Movement::TYPE_ENTRY, Movement::TYPE_RETURN], true) && ! $product->is_active) {
                    throw new InventoryException("«{$product->name}» está desactivado y no admite entradas.");
                }

                $after = round($before + $delta, 3);
                if ($after < 0 && ! $allowNegative) {
                    throw new InventoryException(sprintf(
                        'Stock insuficiente de «%s»: disponible %s, solicitado %s.',
                        $product->name, $this->converter->format($product, $before), $this->converter->format($product, abs($delta)),
                    ));
                }
                $computed[] = compact('product', 'stock', 'type', 'delta', 'before', 'after');
            }
            if (! $computed) {
                throw new InventoryException('El conteo coincide con el sistema: no hay diferencias que ajustar.');
            }

            // 3) encabezado
            $negative = $allowNegative && collect($computed)->contains(fn ($c) => $c['after'] < 0);
            $movement = Movement::create([
                'company_id' => $companyId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'number' => $this->numbering->next(self::NUMBERING_KEYS[$docType], $companyId),
                'reference' => $header['reference'] ?? null,
                'source_type' => $header['source_type'] ?? null,
                'source_id' => $header['source_id'] ?? null,
                'type' => $docType,
                'status' => 'posted',
                'occurred_at' => $occurredAt,
                'user_id' => $user?->id,
                'supplier' => $header['supplier'] ?? null,
                'document_no' => $header['document_no'] ?? null,
                'destination' => $header['destination'] ?? null,
                'destination_type' => $header['destination_type'] ?? null,
                'container_id' => $header['container_id'] ?? null,
                'vehicle_id' => $header['vehicle_id'] ?? null,
                'employee_name' => $header['employee_name'] ?? null,
                'reason' => $header['reason'] ?? null,
                'notes' => $header['notes'] ?? null,
                'negative_override' => $negative,
                'authorized_by' => $negative ? $user?->id : null,
            ]);

            // 4) líneas + actualización atómica del stock
            foreach ($computed as $c) {
                /** @var Product $product */
                $product = $c['product'];
                $this->applyDelta($c['stock'], $c['delta'], $allowNegative, $product);

                [$boxes, $units] = $this->converter->split($product, abs($c['delta']));
                Line::create([
                    'company_id' => $companyId,
                    'branch_id' => $warehouse->branch_id,
                    'warehouse_id' => $warehouse->id,
                    'movement_id' => $movement->id,
                    'type' => $c['type'],
                    'occurred_at' => $occurredAt,
                    'product_id' => $product->id,
                    'product_sku' => $product->sku,
                    'product_name' => $product->name,
                    'units_per_box' => $product->units_per_box,
                    'boxes' => abs($boxes),
                    'units' => abs($units),
                    'delta_base' => $c['delta'],
                    'stock_before_base' => $c['before'],
                    'balance_after_base' => $c['after'],
                ]);
            }

            // 5) auditoría
            $movement->load('items');
            Audit::log(
                'inventory.'.$docType,
                sprintf('%s %s: %s', Movement::TYPES[$docType], $movement->number, $this->summary($movement, $products)),
                $movement,
                null,
                [
                    'almacen' => $warehouse->name,
                    'lineas' => $movement->items->map(fn ($i) => [
                        'producto' => $i->product_name, 'delta' => $i->delta_base, 'antes' => $i->stock_before_base, 'despues' => $i->balance_after_base,
                    ])->all(),
                    'motivo' => $header['reason'] ?? null,
                ],
            );

            return $movement;
        }, 3);
    }

    /** Tipo de línea y delta (con signo) para una línea ya validada. */
    protected function resolve(string $docType, Product $product, array $spec, float $before): array
    {
        $base = $spec['base'];

        return match ($docType) {
            Movement::TYPE_ENTRY => [Line::ENTRY, $base],
            Movement::TYPE_EXIT => [Line::EXIT, -$base],
            Movement::TYPE_CONTAINER => [Line::CONTAINER, -$base],
            Movement::TYPE_MAINTENANCE => [Line::MAINTENANCE, -$base],
            Movement::TYPE_RETURN => [Line::RETURN, $base],
            default => (function () use ($base, $before) {
                $delta = round($base - $before, 3); // base = conteo físico
                return [$delta >= 0 ? Line::ADJUSTMENT_IN : Line::ADJUSTMENT_OUT, $delta];
            })(),
        };
    }

    /** Convierte cajas/unidades a base, agrupa productos repetidos y valida cantidades. */
    protected function quantityLines(array $lines, bool $allowZero = false): array
    {
        $merged = [];
        foreach ($lines as $line) {
            $product = Product::with('unit')->find($line['product_id'] ?? 0);
            if (! $product) {
                throw new InventoryException('Uno de los productos no existe o no pertenece a su empresa.');
            }
            try {
                $base = $this->converter->toBase($product, $line['boxes'] ?? 0, $line['units'] ?? 0);
            } catch (InvalidArgumentException $e) {
                throw new InventoryException($e->getMessage());
            }
            if (isset($merged[$product->id])) {
                if ($allowZero) {
                    throw new InventoryException("«{$product->name}» está repetido en el conteo.");
                }
                $merged[$product->id]['base'] += $base;
            } else {
                $merged[$product->id] = ['product_id' => $product->id, 'base' => $base];
            }
            if (! $allowZero && $merged[$product->id]['base'] <= 0) {
                throw new InventoryException("Indique una cantidad mayor que cero para «{$product->name}».");
            }
        }

        return array_values($merged);
    }

    protected function negativeAllowed(string $docType, array $header, ?User $user): bool
    {
        if (! in_array($docType, [Movement::TYPE_EXIT, Movement::TYPE_CONTAINER, Movement::TYPE_MAINTENANCE], true) || empty($header['allow_negative'])) {
            return false;
        }
        $company = app(CurrentCompany::class)->get();
        if (! $company?->setting('allow_negative_stock') || ! $user?->hasPermission('inventory.negative')) {
            throw new InventoryException('No tiene autorización para dejar stock negativo.');
        }

        return true;
    }

    protected function summary(Movement $movement, $products): string
    {
        return $movement->items->map(fn ($i) => $i->product_name.' '.($i->delta_base > 0 ? '+' : '−').$this->converter->format($products[$i->product_id], abs($i->delta_base)))->implode('; ');
    }

    /** Garantiza y bloquea (FOR UPDATE) las filas de stock en orden de producto. */
    protected function lockStocks(int $warehouseId, array $productIds, int $companyId)
    {
        sort($productIds);
        foreach ($productIds as $pid) {
            DB::table('inventory_stocks')->insertOrIgnore([
                'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'product_id' => $pid,
                'quantity_base' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return InventoryStock::where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)
            ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
    }

    /** UPDATE relativo y condicionado: segunda barrera contra stock negativo aunque fallara el bloqueo. */
    protected function applyDelta(InventoryStock $stock, float $delta, bool $allowNegative, Product $product): void
    {
        $query = InventoryStock::where('id', $stock->id);
        if (! $allowNegative) {
            $query->whereRaw('quantity_base + ? >= 0', [$delta]);
        }
        if ($query->update(['quantity_base' => DB::raw('quantity_base + ('.(float) $delta.')'), 'updated_at' => now()]) !== 1) {
            throw new InventoryException("El stock de «{$product->name}» cambió mientras se registraba. Intente de nuevo.");
        }
    }
}
