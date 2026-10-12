<?php

namespace App\Support;

use App\Models\Company;

/**
 * Empresa activa de la petición. Se resuelve por el usuario autenticado
 * (o por la empresa elegida en sesión si es super administrador).
 */
class CurrentCompany
{
    protected ?int $id = null;
    protected ?Company $company = null;
    protected bool $enforced = false;

    public function set(?Company $company): void
    {
        $this->company = $company;
        $this->id = $company?->id;
    }

    /** Forzar aislamiento aunque no haya empresa (usuario autenticado sin tenant). */
    public function enforce(bool $enforced = true): void
    {
        $this->enforced = $enforced;
    }

    public function isEnforced(): bool
    {
        return $this->enforced;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function clear(): void
    {
        $this->id = null;
        $this->company = null;
        $this->enforced = false;
    }
}
