<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Database\Seeder;

/** Datos DEMO para probar de inmediato (empresa Carmen Cargo Express). No usar en producción real. */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo12345';

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        if (Company::where('slug', 'carmen-cargo-express')->exists()) {
            $this->command?->info('La empresa demo ya existe.');

            return;
        }

        $company = app(CompanyProvisioner::class)->create([
            'name' => 'Carmen Cargo Express', 'slug' => 'carmen-cargo-express', 'legal_name' => 'Carmen Cargo Express SRL',
            'rnc' => '1-31-00000-0', 'address' => 'Santo Domingo, República Dominicana', 'phone' => '809-000-0000',
            'email' => 'info@carmencargo.example',
        ], [
            'first_name' => 'Carmen', 'last_name' => 'Administradora', 'username' => 'carmen', 'email' => 'carmen@carmencargo.example', 'password' => self::PASSWORD,
        ]);

        $roles = Role::where('company_id', $company->id)->pluck('id', 'slug');
        $branchId = $company->branches()->value('id');
        $people = [
            ['Juan', 'Pérez', 'juan', 'warehouse_manager'],
            ['Pedro', 'Operador', 'pedro', 'operator'],
            ['Luis', 'Supervisor', 'luis', 'supervisor'],
            ['Germán', 'Mantenimiento', 'german', 'maintenance'],
            ['Rosa', 'Combustible', 'rosa', 'fuel'],
            ['Ana', 'Auditora', 'ana', 'auditor'],
            ['Mario', 'Consulta', 'mario', 'read_only'],
        ];
        foreach ($people as [$first, $last, $username, $role]) {
            $user = new User([
                'company_id' => $company->id, 'branch_id' => $branchId, 'role_id' => $roles[$role],
                'first_name' => $first, 'last_name' => $last, 'username' => $username,
                'email' => "{$username}@carmencargo.example", 'password' => self::PASSWORD,
            ]);
            $user->is_active = true;
            $user->save();
        }

        $this->command?->info('Empresa demo creada. Usuarios: carmen, juan, pedro, luis, german, rosa, ana, mario — contraseña: '.self::PASSWORD);
    }
}
