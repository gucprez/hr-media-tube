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
                'destination_type' => $original->destination_type, 'employee_name' => $original->employee_name, 'requested_by' => $original->requested_by,
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

    // ---------------------------------------------------------------- anular (borrar) y editar en el mismo documento

    /**
     * ANULAR Y BORRAR una entrada, salida, ajuste o devolución hecha a mano: corrige el stock, borra el documento con sus líneas y
     * fotos, y recalcula los saldos «antes/después» del historial de cada producto. Queda constancia SOLO en la auditoría
     * (quién, cuándo, motivo y el contenido del documento). Rechaza la anulación si en cualquier punto del historial el stock
     * quedaría negativo (el material ya se usó), salvo autorización especial.
     */
    public function remove(Movement $movement, string $reason, ?User $user = null, bool $allowNegative = false, bool $fromSource = false): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InventoryException('Indique el motivo de la anulación.');
        }
        $companyId = app(CurrentCompany::class)->id();

        DB::transaction(function () use ($movement, $reason, $user, $allowNegative, $companyId, $fromSource) {
            $doc = Movement::lockForUpdate()->find($movement->id);
            if (! $doc) {
                throw new InventoryException('El documento no existe o no pertenece a su empresa.');
            }
            if ($doc->type === Movement::TYPE_VOID || $doc->voided_at) {
                throw new InventoryException('Este documento ya está anulado o es una anulación.');
            }
            if (! $fromSource && ($doc->source_type !== null || in_array($doc->type, [Movement::TYPE_CONTAINER, Movement::TYPE_MAINTENANCE], true))) {
                throw new InventoryException('Este documento se anula desde su origen (el furgón o el mantenimiento).');
            }
            $allowNegative = $this->negativeAllowed(Movement::TYPE_EXIT, ['allow_negative' => $allowNegative], $user);

            $items = Line::where('movement_id', $doc->id)->get();
            $warehouseId = (int) $doc->warehouse_id;
            $productIds = $items->pluck('product_id')->unique()->sort()->values()->all();
            $stocks = $this->lockStocks($warehouseId, $productIds, $companyId);
            $products = Product::withTrashed()->with('unit')->whereIn('id', $productIds)->get()->keyBy('id');

            foreach ($productIds as $pid) {
                $deltas = $this->chainRows($warehouseId, $pid, $companyId)->reject(fn ($r) => (int) $r->movement_id === (int) $doc->id)->pluck('delta_base')->all();
                if (! $allowNegative && $this->lowestBalance($deltas) < -0.0004) {
                    throw new InventoryException(sprintf('No se puede anular: «%s» quedaría en negativo en el historial (el material ya fue utilizado).', $products[$pid]->name));
                }
            }

            $snapshot = $this->documentSnapshot($doc, $items);
            foreach ($productIds as $pid) {
                $net = -1 * round((float) $items->where('product_id', $pid)->sum('delta_base'), 3);
                $this->applyDelta($stocks[$pid], $net, $allowNegative, $products[$pid]);
            }

            $this->deleteDocumentRows($doc);
            foreach ($productIds as $pid) {
                $this->rebuildChain($warehouseId, $pid, $companyId);
            }

            Audit::log('inventory.delete', sprintf('Anulado y borrado %s %s: %s', $doc->type_label, $doc->number, $reason), $doc, $snapshot, ['motivo' => $reason, 'borrado' => true]);
        }, 3);
    }

    /**
     * EDITAR un documento en SU MISMO registro: conserva el número, ajusta el stock solo por la diferencia y recalcula los saldos
     * del historial. Aplica a entradas y salidas hechas a mano. Si algo falla no queda nada a medias. La auditoría guarda el
     * antes y el después con el motivo.
     *
     * @param  array  $header  igual que entry()/exit(): warehouse_id, occurred_at, supplier, document_no, destination…, allow_negative
     * @param  array<int,array{product_id:int,boxes?:mixed,units?:mixed}>  $lines
     */
    public function amend(Movement $movement, array $header, array $lines, string $why, ?User $user = null): Movement
    {
        $why = trim($why);
        if (mb_strlen($why) < 5) {
            throw new InventoryException('Indique el motivo de la edición.');
        }
        $companyId = app(CurrentCompany::class)->id();
        if (! $companyId) {
            throw new InventoryException('No hay empresa activa.');
        }
        $specs = $this->quantityLines($lines);
        if (! $specs) {
            throw new InventoryException('Agregue al menos un producto.');
        }
        $warehouse = Warehouse::where('is_active', true)->find($header['warehouse_id'] ?? 0);
        if (! $warehouse) {
            throw new InventoryException('El almacén seleccionado no es válido.');
        }
        $occurredAt = $header['occurred_at'] ?? now();
        if (! $occurredAt instanceof CarbonInterface) {
            $occurredAt = now()->parse($occurredAt);
        }

        return DB::transaction(function () use ($movement, $header, $specs, $why, $user, $companyId, $warehouse, $occurredAt) {
            $doc = Movement::lockForUpdate()->find($movement->id);
            if (! $doc) {
                throw new InventoryException('El documento no existe o no pertenece a su empresa.');
            }
            if ($reason = $doc->editBlockReason()) {
                throw new InventoryException($reason);
            }
            $allowNegative = $this->negativeAllowed(Movement::TYPE_EXIT, $header, $user);
            $sign = $doc->type === Movement::TYPE_ENTRY ? 1 : -1;

            $oldItems = Line::where('movement_id', $doc->id)->get()->keyBy('product_id');
            $oldWh = (int) $doc->warehouse_id;
            $newWh = (int) $warehouse->id;
            $newBase = collect($specs)->mapWithKeys(fn ($s) => [$s['product_id'] => (float) $s['base']]);
            $allIds = $oldItems->keys()->merge($newBase->keys())->unique()->sort()->values();
            $products = Product::withTrashed()->with('unit')->whereIn('id', $allIds)->get()->keyBy('id');
            foreach ($newBase->keys() as $pid) {
                if (! $products->has($pid) || $products[$pid]->trashed()) {
                    throw new InventoryException('Uno de los productos no existe o no pertenece a su empresa.');
                }
                if ($doc->type === Movement::TYPE_ENTRY && ! $products[$pid]->is_active && ! $oldItems->has($pid)) {
                    throw new InventoryException("«{$products[$pid]->name}» está desactivado y no admite entradas.");
                }
            }

            // existencias bloqueadas en orden estable (almacén, producto)
            $stocks = [];
            foreach (collect([$oldWh, $newWh])->unique()->sort()->values() as $wh) {
                $stocks[$wh] = $this->lockStocks($wh, $allIds->all(), $companyId);
            }

            // 1) validar contra TODO el historial de cada (almacén, producto) afectado
            $newDelta = fn ($pid) => $sign * (float) $newBase[$pid];
            $changes = [];   // [wh][pid] => ajuste neto al stock actual
            foreach ($allIds as $pid) {
                $old = $oldItems->has($pid) ? round((float) $oldItems[$pid]->delta_base, 3) : 0.0;
                $new = $newBase->has($pid) ? round($newDelta($pid), 3) : 0.0;
                if ($oldWh === $newWh) {
                    $changes[$oldWh][$pid] = round($new - $old, 3);
                } else {
                    $changes[$oldWh][$pid] = round(-$old, 3);
                    $changes[$newWh][$pid] = round($new, 3);
                }
            }
            foreach ($changes as $wh => $perProduct) {
                foreach ($perProduct as $pid => $net) {
                    if ($net == 0.0 && $oldWh === $newWh) {
                        continue;
                    }
                    $deltas = [];
                    foreach ($this->chainRows((int) $wh, (int) $pid, $companyId) as $r) {
                        if ((int) $r->movement_id === (int) $doc->id) {
                            if ($oldWh === $newWh && $wh === $newWh && $newBase->has($pid)) {
                                $deltas[] = round($newDelta($pid), 3);   // su línea, ya corregida, en la misma posición
                            }
                            continue;                                        // si cambió de almacén o de producto, esta línea sale de aquí
                        }
                        $deltas[] = (float) $r->delta_base;
                    }
                    if ($wh === $newWh && $newBase->has($pid) && ! ($oldWh === $newWh && $oldItems->has($pid))) {
                        $deltas[] = round($newDelta($pid), 3);               // línea nueva en este historial: queda al final
                    }
                    if (! $allowNegative && $this->lowestBalance($deltas) < -0.0004) {
                        throw new InventoryException(sprintf(
                            'No se puede editar: «%s» quedaría en negativo en el historial (el material ya fue utilizado o no alcanza el stock).',
                            $products[$pid]->name,
                        ));
                    }
                }
            }

            // 2) aplicar: stock, encabezado y líneas
            $before = $this->documentSnapshot($doc, $oldItems->values());
            foreach ($changes as $wh => $perProduct) {
                foreach ($perProduct as $pid => $net) {
                    if ($net != 0.0) {
                        $this->applyDelta($stocks[$wh][$pid], $net, $allowNegative, $products[$pid]);
                    }
                }
            }

            $negative = $allowNegative && InventoryStock::where('warehouse_id', $newWh)->whereIn('product_id', $newBase->keys()->all())->where('quantity_base', '<', 0)->exists();
            $doc->forceFill([
                'warehouse_id' => $newWh, 'branch_id' => $warehouse->branch_id, 'occurred_at' => $occurredAt,
                'supplier' => $header['supplier'] ?? null, 'document_no' => $header['document_no'] ?? null,
                'destination' => $header['destination'] ?? null, 'destination_type' => $header['destination_type'] ?? null,
                'container_id' => $header['container_id'] ?? null, 'vehicle_id' => $header['vehicle_id'] ?? null,
                'employee_name' => $header['employee_name'] ?? null, 'requested_by' => $header['requested_by'] ?? null,
                'reason' => $header['reason'] ?? null, 'notes' => $header['notes'] ?? null,
                'negative_override' => $negative, 'authorized_by' => $negative ? $user?->id : null,
            ])->save();

            $lineType = $doc->type === Movement::TYPE_ENTRY ? Line::ENTRY : Line::EXIT;
            foreach ($oldItems as $pid => $item) {
                if (! $newBase->has($pid)) {
                    DB::table('inventory_movement_items')->where('id', $item->id)->delete();
                }
            }
            foreach ($newBase as $pid => $base) {
                $product = $products[$pid];
                [$boxes, $units] = $this->converter->split($product, abs($base));
                $row = [
                    'branch_id' => $warehouse->branch_id, 'warehouse_id' => $newWh, 'occurred_at' => $occurredAt, 'type' => $lineType,
                    'product_sku' => $product->sku, 'product_name' => $product->name, 'units_per_box' => $product->units_per_box,
                    'boxes' => abs($boxes), 'units' => abs($units), 'delta_base' => round($newDelta($pid), 3),
                ];
                if ($oldItems->has($pid)) {
                    DB::table('inventory_movement_items')->where('id', $oldItems[$pid]->id)->update($row + ['updated_at' => now()]);
                } else {
                    Line::create($row + ['company_id' => $companyId, 'movement_id' => $doc->id, 'product_id' => $pid, 'stock_before_base' => 0, 'balance_after_base' => 0]);
                }
            }
            foreach ($changes as $wh => $perProduct) {
                foreach (array_keys($perProduct) as $pid) {
                    $this->rebuildChain((int) $wh, (int) $pid, $companyId);
                }
            }

            $doc->load('items');
            Audit::log('inventory.edit', sprintf('Edición de %s %s (conserva su número): %s', $doc->type_label, $doc->number, $why), $doc,
                $before, $this->documentSnapshot($doc, $doc->items) + ['motivo' => $why]);

            return $doc;
        }, 3);
    }

    /** Líneas del historial de un producto en un almacén, en el orden en que se registraron. */
    protected function chainRows(int $warehouseId, int $productId, int $companyId)
    {
        return DB::table('inventory_movement_items')->where('company_id', $companyId)->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)->orderBy('id')->get(['id', 'movement_id', 'delta_base']);
    }

    /** Saldo más bajo que alcanza una sucesión de movimientos que parte de cero. */
    protected function lowestBalance(array $deltas): float
    {
        $run = 0.0;
        $low = 0.0;
        foreach ($deltas as $d) {
            $run = round($run + (float) $d, 3);
            $low = min($low, $run);
        }

        return $low;
    }

    /** Recalcula «stock antes» y «saldo después» de todas las líneas de un producto en un almacén (suma acumulada desde cero). */
    protected function rebuildChain(int $warehouseId, int $productId, int $companyId): void
    {
        $run = 0.0;
        foreach (DB::table('inventory_movement_items')->where('company_id', $companyId)->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)->orderBy('id')->get(['id', 'delta_base', 'stock_before_base', 'balance_after_base']) as $r) {
            $before = round($run, 3);
            $run = round($run + (float) $r->delta_base, 3);
            if (abs($before - (float) $r->stock_before_base) > 0.0004 || abs($run - (float) $r->balance_after_base) > 0.0004) {
                DB::table('inventory_movement_items')->where('id', $r->id)->update(['stock_before_base' => $before, 'balance_after_base' => $run]);
            }
        }
    }

    /** Contenido del documento para la auditoría (lo único que queda de un documento borrado). */
    protected function documentSnapshot(Movement $doc, $items): array
    {
        return [
            'numero' => $doc->number, 'tipo' => $doc->type_label, 'almacen_id' => $doc->warehouse_id, 'fecha' => optional($doc->occurred_at)->toDateTimeString(),
            'solicitado_por' => $doc->requested_by, 'destino' => $doc->destination, 'empleado' => $doc->employee_name, 'proveedor' => $doc->supplier,
            'documento' => $doc->document_no, 'motivo_documento' => $doc->reason, 'notas' => $doc->notes, 'registrado_por_id' => $doc->user_id,
            'lineas' => collect($items)->map(fn ($i) => ['producto' => $i->product_name, 'cajas' => (float) $i->boxes, 'unidades' => (float) $i->units, 'delta' => (float) $i->delta_base])->values()->all(),
        ];
    }

    /** Borra las fotos/documentos de un registro (y el archivo, si nadie más lo usa). */
    public function purgeAttachments(\Illuminate\Database\Eloquent\Model $model): void
    {
        foreach (DB::table('attachments')->where('attachable_type', $model->getMorphClass())->where('attachable_id', $model->getKey())->get() as $a) {
            DB::table('attachments')->where('id', $a->id)->delete();
            if (! DB::table('attachments')->where('disk', $a->disk)->where('path', $a->path)->exists()) {
                try {
                    \Illuminate\Support\Facades\Storage::disk($a->disk)->delete($a->path);
                } catch (Throwable) {
                    // el archivo ya no estaba: no es motivo para frenar la anulación
                }
            }
        }
    }

    /** Borra físicamente el encabezado, las líneas y las fotos/documentos de un documento (sin pasar por la protección de los modelos). */
    protected function deleteDocumentRows(Movement $doc): void
    {
        $this->purgeAttachments($doc);
        DB::table('inventory_movement_items')->where('movement_id', $doc->id)->delete();
        DB::table('inventory_movements')->where('id', $doc->id)->delete();
    }

    /**
     * Limpieza ÚNICA de documentos que ya estaban anulados con el sistema anterior (original + su reverso): borra los pares cuyo
     * efecto neto es cero y recalcula los saldos. Omite lo que pertenece a un furgón o mantenimiento. Devuelve un resumen.
     *
     * @return array{borrados:int,omitidos:int,detalle:array<int,string>}
     */
    public function purgeVoided(bool $dryRun = false): array
    {
        $out = ['borrados' => 0, 'omitidos' => 0, 'detalle' => []];

        foreach (Movement::whereNotNull('voided_at')->where('type', '!=', Movement::TYPE_VOID)->whereNull('source_type')->orderBy('id')->get() as $orig) {
            $voids = Movement::where('reversal_of_id', $orig->id)->get();
            $inUse = DB::table('containers')->where(fn ($q) => $q->where('movement_id', $orig->id)->orWhereIn('void_movement_id', $voids->pluck('id')->all() ?: [0]))->exists()
                || DB::table('maintenance_records')->where('movement_id', $orig->id)->exists();
            if ($inUse || ! $this->pairIsNeutral($orig, $voids)) {
                $out['omitidos']++;
                $out['detalle'][] = $orig->number.': omitido ('.($inUse ? 'pertenece a un furgón o mantenimiento' : 'su efecto neto no es cero').')';
                continue;
            }
            $out['detalle'][] = $orig->number.': '.($dryRun ? 'se borraría' : 'borrado').($voids->isNotEmpty() ? ' con '.$voids->pluck('number')->implode(', ') : '');
            $out['borrados']++;
            if ($dryRun) {
                continue;
            }

            $this->purgePair($orig, $voids);
        }

        return $out;
    }

    /** ¿El documento anulado y sus reversos se anulan entre sí (efecto neto cero en cada almacén/producto)? */
    public function pairIsNeutral(Movement $orig, $voids): bool
    {
        $ids = collect($voids)->pluck('id')->push($orig->id)->all();

        return ! Line::whereIn('movement_id', $ids)->get()->groupBy(fn ($l) => $l->warehouse_id.'-'.$l->product_id)->contains(fn ($g) => round((float) $g->sum('delta_base'), 3) != 0.0);
    }

    /** Borra un documento anulado y sus reversos y recalcula los saldos (el efecto neto es cero: el stock no cambia). */
    public function purgePair(Movement $orig, $voids): void
    {
        $companyId = app(CurrentCompany::class)->id();
        $voids = collect($voids);
        DB::transaction(function () use ($orig, $voids, $companyId) {
            $ids = $voids->pluck('id')->push($orig->id)->all();
            $pairs = Line::whereIn('movement_id', $ids)->get(['warehouse_id', 'product_id'])->map(fn ($l) => [(int) $l->warehouse_id, (int) $l->product_id])->unique()->values();
            $snapshot = $this->documentSnapshot($orig, Line::where('movement_id', $orig->id)->get());
            DB::table('inventory_movements')->where('replaces_id', $orig->id)->update(['replaces_id' => null]);
            DB::table('containers')->where('movement_id', $orig->id)->update(['movement_id' => null]);
            DB::table('containers')->whereIn('void_movement_id', $voids->pluck('id')->all() ?: [0])->update(['void_movement_id' => null]);
            foreach ($voids as $v) {
                $this->deleteDocumentRows($v);
            }
            $this->deleteDocumentRows($orig);
            foreach ($pairs as [$wh, $pid]) {
                $this->rebuildChain($wh, $pid, $companyId);
            }
            Audit::log('inventory.purge', sprintf('Limpieza de documento anulado %s (y su reverso %s)', $orig->number, $voids->pluck('number')->implode(', ') ?: '—'), $orig, $snapshot, ['borrado' => true, 'motivo_original' => $orig->reason]);
        });
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
                'requested_by' => $header['requested_by'] ?? null,
                'reason' => $header['reason'] ?? null,
                'notes' => $header['notes'] ?? null,
                'negative_override' => $negative,
                'replaces_id' => $header['replaces_id'] ?? null,
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
