<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyProvisioner;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync {--reset-roles : Restablece los roles base de cada empresa a la plantilla}';

    protected $description = 'Sincroniza el catálogo de permisos (config/permissions.php) con la base de datos';

    public function handle(): int
    {
        CompanyProvisioner::syncPermissions();
        $this->info('Catálogo de permisos sincronizado.');

        foreach (Company::all() as $company) {
            CompanyProvisioner::syncRoles($company, (bool) $this->option('reset-roles'));
            $this->line("  · Roles de {$company->name} verificados");
        }

        return self::SUCCESS;
    }
}
