<?php

namespace App\Services\Fleet;

use App\Models\Container;
use App\Models\FuelDispatch;
use App\Models\User;
use App\Services\Fuel\FuelService;

/**
 * Integración furgón ↔ combustible. Al cerrar un furgón con gasoil se registra el despacho en la reserva
 * (sin lectura de contador, por galones); al anularlo, el despacho se anula y la reserva se restituye.
 * Corre dentro de la misma transacción del cierre: si no hay reserva suficiente, el furgón NO se cierra.
 */
class ContainerFuel
{
    public function __construct(protected FuelService $fuel) {}

    public function onClose(Container $container, User $user): void
    {
        if ((float) $container->fuel_gallons <= 0) {
            return;
        }
        $this->fuel->dispatch([
            'dispatched_at' => now()->toDateString(), 'vehicle_id' => $container->vehicle_id, 'container_id' => $container->id,
            'gallons' => $container->fuel_gallons, 'notes' => 'Gasoil del furgón '.$container->number, 'origin' => 'container',
        ], $user);
    }

    public function onVoid(Container $container, string $reason, User $user): void
    {
        $dispatch = FuelDispatch::where('container_id', $container->id)->where('origin', 'container')->whereNull('voided_at')->first();
        if ($dispatch) {
            $this->fuel->void($dispatch, 'Anulación del furgón '.$container->number.': '.$reason, $user);
        }
    }
}
