<?php

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Crea un administrador de la plataforma. Es la única forma de crearlo:
 * no existe endpoint de inicialización (RF-050, constitución 1.1.1).
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin';

    protected $description = 'Crea un administrador de la plataforma (interactivo)';

    public function handle(AuditLogger $audit): int
    {
        $data = [
            'name' => $this->ask('Nombre'),
            'email' => User::normalizeEmail((string) $this->ask('Correo')),
            'password' => $this->secret('Contraseña'),
            'password_confirmation' => $this->secret('Repite la contraseña'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        $user->is_platform_admin = true;
        $user->save();

        $audit->record('platform_admin.created', $user, ['email' => $user->email]);

        $this->info("Administrador de la plataforma creado: {$user->email}");

        return self::SUCCESS;
    }
}
