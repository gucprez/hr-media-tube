<?php

namespace App\Services\Fleet;

use App\Models\Product;
use App\Models\ReturnableMovement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Audit;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Materiales retornables (tóner, etc.): entrega (descuenta inventario) y seguimiento hasta su devolución, recarga o desecho. */
class ReturnableService
{
    public function __construct(protected StockService $stock) {}

    /** @param  array{product_id:int,quantity:mixed,warehouse_id:int,holder?:?string,vehicle_id?:?int,notes?:?string,allow_negative?:bool}  $d */
    public function deliver(array $d, ?User $user = null): ReturnableMovement
    {
        $product = $this->returnable($d['product_id']);
        [$vehicle, $holder] = $this->target($d);
        $qty = $this->qty($d['quantity']);

        return DB::transaction(function () use ($d, $user, $product, $vehicle, $holder, $qty) {
            $movement = $this->stock->maintenance([
                'warehouse_id' => $d['warehouse_id'], 'vehicle_id' => $vehicle?->id, 'destination' => $vehicle ? 'Retornable — '.$vehicle->ficha : 'Retornable — '.$holder,
                'destination_type' => $vehicle ? 'vehicle' : 'employee', 'employee_name' => $holder, 'reason' => 'Entrega de retornable', 'notes' => $d['notes'] ?? null,
                'allow_negative' => ! empty($d['allow_negative']),
            ], [['product_id' => $product->id, 'units' => $qty]], $user);

            $row = $this->row($product, 'delivered', $qty, $vehicle, $holder, $user, $d['notes'] ?? null, $movement->id);
            Audit::log('returnable.delivered', sprintf('Entregó %s %s a %s', $this->n($qty), $product->name, $vehicle?->ficha ?? $holder), $row, null, ['cantidad' => $qty, 'producto' => $product->name]);

            return $row;
        }, 3);
    }

    /**
     * Cierra (retornado / recargable / desechado) o marca como usado parte de lo entregado.
     *
     * @param  array{product_id:int,quantity:mixed,state:string,holder?:?string,vehicle_id?:?int,notes?:?string}  $d
     */
    public function transition(array $d, ?User $user = null): ReturnableMovement
    {
        $state = $d['state'];
        if (! in_array($state, ['used', 'returned', 'refillable', 'discarded'], true)) {
            throw new InventoryException('Estado no válido.');
        }
        $product = $this->returnable($d['product_id']);
        [$vehicle, $holder] = $this->target($d);
        $qty = $this->qty($d['quantity']);

        return DB::transaction(function () use ($d, $user, $product, $vehicle, $holder, $qty, $state) {
            $outstanding = $this->outstanding($product->id, $vehicle?->id, $holder);
            if ($qty > $outstanding + 0.0001) {
                throw new InventoryException(sprintf('Solo hay %s pendiente(s) de «%s» para %s.', $this->n($outstanding), $product->name, $vehicle?->ficha ?? $holder));
            }
            $row = $this->row($product, $state, $qty, $vehicle, $holder, $user, $d['notes'] ?? null);
            Audit::log('returnable.'.$state, sprintf('%s %s %s — %s', ReturnableMovement::STATES[$state], $this->n($qty), $product->name, $vehicle?->ficha ?? $holder), $row, null, ['cantidad' => $qty]);

            return $row;
        });
    }

    /** Pendiente de devolver = entregado − (retornado + recargable + desechado) para ese producto y portador. */
    public function outstanding(int $productId, ?int $vehicleId, ?string $holder): float
    {
        $q = ReturnableMovement::where('product_id', $productId)
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId), fn ($q) => $q->whereNull('vehicle_id')->where('holder', $holder));
        $delivered = (float) (clone $q)->where('state', 'delivered')->sum('quantity');
        $closed = (float) (clone $q)->whereIn('state', ReturnableMovement::CLOSING)->sum('quantity');

        return round($delivered - $closed, 3);
    }

    /** @return Collection<int,object{product_id:int,product_name:string,vehicle_id:?int,holder:?string,delivered:float,used:float,closed:float,outstanding:float}> */
    public function pending(): Collection
    {
        return ReturnableMovement::select('product_id', 'vehicle_id', 'holder')
            ->selectRaw('MAX(product_name) as product_name')
            ->selectRaw("SUM(CASE WHEN state = 'delivered' THEN quantity ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN state = 'used' THEN quantity ELSE 0 END) as used")
            ->selectRaw("SUM(CASE WHEN state IN ('returned','refillable','discarded') THEN quantity ELSE 0 END) as closed")
            ->groupBy('product_id', 'vehicle_id', 'holder')->get()
            ->map(function ($r) {
                $r->outstanding = round((float) $r->delivered - (float) $r->closed, 3);

                return $r;
            })->filter(fn ($r) => $r->outstanding > 0)->values();
    }

    // ---- internos --------------------------------------------------------------

    protected function returnable(int $productId): Product
    {
        $p = Product::with('unit')->find($productId) ?? throw new InventoryException('El producto no existe o no pertenece a su empresa.');
        if (! $p->is_returnable || $p->returnable_kind === 'tire') {
            throw new InventoryException("«{$p->name}» no es un retornable (las gomas se gestionan en el módulo de Gomas).");
        }

        return $p;
    }

    /** @return array{0:?Vehicle,1:?string} */
    protected function target(array $d): array
    {
        $vehicle = ! empty($d['vehicle_id']) ? (Vehicle::find($d['vehicle_id']) ?? throw new InventoryException('El vehículo no existe o no pertenece a su empresa.')) : null;
        $holder = trim((string) ($d['holder'] ?? '')) ?: null;
        if (! $vehicle && ! $holder) {
            throw new InventoryException('Indique el vehículo o la persona/área que recibe el material.');
        }

        return [$vehicle, $vehicle ? null : $holder];
    }

    protected function qty(mixed $q): float
    {
        if (! is_numeric($q) || (float) $q <= 0) {
            throw new InventoryException('Indique una cantidad mayor que cero.');
        }

        return round((float) $q, 3);
    }

    protected function row(Product $p, string $state, float $qty, ?Vehicle $v, ?string $holder, ?User $u, ?string $notes, ?int $movementId = null): ReturnableMovement
    {
        return ReturnableMovement::create([
            'product_id' => $p->id, 'product_name' => $p->name, 'vehicle_id' => $v?->id, 'holder' => $holder, 'movement_id' => $movementId, 'user_id' => $u?->id,
            'state' => $state, 'quantity' => $qty, 'occurred_at' => now(), 'notes' => $notes,
        ]);
    }

    protected function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ','), '0'), '.');
    }
}
