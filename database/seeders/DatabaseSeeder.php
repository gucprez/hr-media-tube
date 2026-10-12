<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder seguro para producción: catálogo de permisos + super administrador.
 * Las credenciales salen del .env (SUPERADMIN_*); si no existen se genera una
 * contraseña aleatoria que se muestra UNA sola vez en consola.
 * Datos de demostración: php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        CompanyProvisioner::syncPermissions();

        $email = env('SUPERADMIN_EMAIL', 'admin@hrmedia.local');
        if (User::withoutGlobalScopes()->where('is_super_admin', true)->exists()) {
            $this->command?->info('Super administrador ya existe.');

            return;
        }

        $password = env('SUPERADMIN_PASSWORD') ?: Str::password(14, symbols: false);
        $user = new User([
            'first_name' => 'HR Media', 'last_name' => 'System',
            'username' => env('SUPERADMIN_USERNAME', 'superadmin'), 'email' => $email, 'password' => $password,
        ]);
        $user->is_super_admin = true;
        $user->is_active = true;
        $user->save();

        $this->command?->warn("Super administrador creado: {$user->username} / {$email}");
        if (! env('SUPERADMIN_PASSWORD')) {
            $this->command?->warn("Contraseña generada (guárdela ahora): {$password}");
        }
    }
}
