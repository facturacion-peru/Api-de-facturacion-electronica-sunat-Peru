<?php

namespace App\Console\Commands;

use App\Enums\TaxRegime;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Alta de empresa por consola (HU-1). Usa el mismo servicio y las mismas
 * reglas que POST /api/v1/platform/companies, y muestra el enlace de
 * invitación por si el correo aún no está configurado.
 */
class CreateCompany extends Command
{
    protected $signature = 'company:create';

    protected $description = 'Da de alta una empresa y envía la invitación a su administrador (interactivo)';

    public function handle(CompanyService $companies): int
    {
        $actor = User::where('email', User::normalizeEmail((string) $this->ask('Tu correo de administrador de la plataforma')))
            ->where('is_platform_admin', true)
            ->where('active', true)
            ->first();

        if ($actor === null) {
            $this->error('Ese correo no corresponde a un administrador de la plataforma activo.');

            return self::FAILURE;
        }

        $data = [
            'ruc' => trim((string) $this->ask('RUC')),
            'razon_social' => $this->ask('Razón social'),
            'nombre_comercial' => $this->ask('Nombre comercial (opcional)') ?: null,
            'tax_regime' => $this->choice('Régimen tributario', array_column(TaxRegime::cases(), 'value')),
            'email' => $this->ask('Correo de contacto de la empresa'),
            'phone' => $this->ask('Teléfono (opcional)') ?: null,
            'address' => $this->ask('Dirección fiscal'),
            'ubigeo' => $this->ask('Ubigeo del distrito (6 dígitos)'),
            'admin_email' => User::normalizeEmail((string) $this->ask('Correo del administrador de la empresa')),
        ];

        $request = new StoreCompanyRequest;
        $validator = Validator::make($data, $request->rules(), $request->messages());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $created = $companies->create($validator->validated(), $actor);

        $this->info("Empresa creada: {$created->company->razon_social} ({$created->company->ruc})");
        $this->line("Invitación enviada a {$created->adminInvitation->invitation->email}. Enlace (válido 72 h):");
        $this->line($created->adminInvitation->url());

        return self::SUCCESS;
    }
}
