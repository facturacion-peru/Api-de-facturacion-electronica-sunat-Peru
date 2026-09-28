<?php

namespace App\Tenancy;

use App\Models\Company;

/**
 * Empresa actual de la petición o del proceso. La fija el middleware
 * ResolveTenant; en consola y colas se fija explícitamente con run().
 */
class TenantContext
{
    private ?Company $company = null;

    public function set(?Company $company): void
    {
        $this->company = $company;
    }

    public function clear(): void
    {
        $this->company = null;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    /**
     * Ejecuta el callback con otra empresa como contexto y restaura la anterior.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Company $company, callable $callback): mixed
    {
        $previous = $this->company;
        $this->company = $company;

        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }
}
