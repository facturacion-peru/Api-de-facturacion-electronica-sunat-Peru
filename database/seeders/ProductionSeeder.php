<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class ProductionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Configurando sistema para producción...');

        $this->createPermissions();
        $this->createRoles();

        $this->command->info('Sistema configurado para producción.');
        $this->command->warn('NO se han creado usuarios por defecto.');
        $this->command->warn('Crear usuarios administradores después del deployment.');
    }

    /**
     * Crear permisos del sistema
     */
    private function createPermissions(): void
    {
        $this->command->info('Creando permisos del sistema...');

        $permissions = Permission::getSystemPermissions();

        foreach ($permissions as $category => $categoryPermissions) {
            foreach ($categoryPermissions as $name => $data) {
                Permission::updateOrCreate(
                    ['name' => $name],
                    [
                        'display_name' => $data['display_name'],
                        'description' => $data['description'],
                        'category' => $category,
                        'is_system' => true,
                        'active' => true,
                    ]
                );
            }
        }

        $this->command->info('Permisos creados: ' . Permission::count());
    }

    /**
     * Crear roles del sistema
     */
    private function createRoles(): void
    {
        $this->command->info('Creando roles del sistema...');

        $roles = Role::getSystemRoles();

        foreach ($roles as $name => $data) {
            $role = Role::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $data['display_name'],
                    'description' => $data['description'],
                    'permissions' => $data['permissions'],
                    'is_system' => $data['is_system'],
                    'active' => true,
                ]
            );

            $this->assignPermissionsToRole($role, $data['permissions']);
        }

        $this->command->info('Roles creados: ' . Role::count());
    }

    /**
     * Asignar permisos específicos a un rol
     */
    private function assignPermissionsToRole(Role $role, array $permissions): void
    {
        if (in_array('*', $permissions)) {
            $allPermissions = Permission::active()->get();
            $role->permissions()->sync($allPermissions->pluck('id'));
            return;
        }

        $permissionIds = [];

        foreach ($permissions as $permission) {
            if (str_contains($permission, '*')) {
                $expandedPermissions = Permission::expandWildcardPermission($permission);
                
                foreach ($expandedPermissions as $expandedPermission) {
                    $permissionModel = Permission::where('name', $expandedPermission)->first();
                    if ($permissionModel) {
                        $permissionIds[] = $permissionModel->id;
                    }
                }
            } else {
                $permissionModel = Permission::where('name', $permission)->first();
                if ($permissionModel) {
                    $permissionIds[] = $permissionModel->id;
                }
            }
        }

        $role->permissions()->sync(array_unique($permissionIds));
    }
}
