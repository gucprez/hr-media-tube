<?php

namespace App\Services\Fleet;

use App\Models\Container;
use App\Models\User;

/**
 * Punto de integración furgón ↔ combustible. Al cerrar un furgón con gasoil se registra el despacho
 * correspondiente en la reserva; al anularlo se revierte. (Se completa en la fase de combustible.)
 */
class ContainerFuel
{
    public function onClose(Container $container, User $user): void
    {
    }

    public function onVoid(Container $container, string $reason, User $user): void
    {
    }
}
