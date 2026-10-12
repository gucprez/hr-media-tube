<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Borra por completo una empresa TEMPORAL (solo para las pruebas de concurrencia). Nunca se usa con empresas reales. */
class PurgeCompany
{
    public static function run(int $companyId): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            $userIds = DB::table('users')->where('company_id', $companyId)->pluck('id');
            $roleIds = DB::table('roles')->where('company_id', $companyId)->pluck('id');
            DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
            DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
            foreach (Schema::getTables() as $table) {
                $name = $table['name'];
                if ($name !== 'companies' && Schema::hasColumn($name, 'company_id')) {
                    DB::table($name)->where('company_id', $companyId)->delete();
                }
            }
            DB::table('companies')->where('id', $companyId)->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
