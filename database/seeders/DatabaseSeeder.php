<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Datos oficiales: se cargan en todos los entornos.
        $this->call([
            UbiRegionesSeeder::class,
            UbiProvinciasSeeder::class,
            UbiDistritoSeeder::class,
        ]);

        // Cuentas de prueba: solo en desarrollo local.
        if (app()->environment('local')) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
