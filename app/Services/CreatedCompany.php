<?php

namespace App\Services;

use App\Models\Company;

final readonly class CreatedCompany
{
    public function __construct(
        public Company $company,
        public IssuedInvitation $adminInvitation,
    ) {}
}
