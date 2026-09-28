<?php

namespace App\Enums;

/**
 * Roles fijos dentro de una empresa (A-05). El administrador de la plataforma
 * no es un rol de empresa: es `users.is_platform_admin`.
 */
enum CompanyRole: string
{
    case CompanyAdmin = 'company_admin';
    case Seller = 'seller';

    public function label(): string
    {
        return match ($this) {
            self::CompanyAdmin => 'Administrador de empresa',
            self::Seller => 'Vendedor',
        };
    }
}
