<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Crea una empresa lista para operar: roles, sucursal, almacén, numeraciones y administrador. */
class CompanyProvisioner
{
    public static function syncPermissions(): void
    {
        foreach (PermissionCatalog::groups() as $group => $perms) {
            foreach ($perms as $key => $label) {
                Permission::updateOrCreate(['key' => $key], ['group' => $group, 'label' => $label]);
            }
        }
        Permission::whereNotIn('key', PermissionCatalog::keys())->delete();
    }

    /** Crea o actualiza los roles de una empresa a partir de las plantillas. Devuelve slug => Role. */
    public static function syncRoles(Company $company, bool $resetExisting = false): array
    {
        self::syncPermissions();
        $ids = Permission::pluck('id', 'key');
        $roles = [];
        foreach (config('permissions.roles') as $slug => $tpl) {
            $role = Role::firstOrNew(['company_id' => $company->id, 'slug' => $slug]);
            $isNew = ! $role->exists;
            $role->fill(['name' => $tpl['name'], 'description' => $tpl['description'], 'is_system' => true])->save();
            if ($isNew || $resetExisting) {
                $role->permissions()->sync(collect(PermissionCatalog::expand($tpl['permissions']))->map(fn ($k) => $ids[$k])->all());
            }
            $roles[$slug] = $role;
        }

        return $roles;
    }

    /**
     * @param  array{name:string,slug?:string,rnc?:string,address?:string,phone?:string,email?:string}  $data
     * @param  array{first_name:string,last_name:string,username:string,email:string,password:string}  $admin
     */
    public function create(array $data, array $admin): Company
    {
        return DB::transaction(function () use ($data, $admin) {
            $company = Company::create($data + ['slug' => Str::slug($data['name'])]);

            $roles = self::syncRoles($company);

            $branch = Branch::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI']);
            Warehouse::withoutGlobalScopes()->create([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Almacén principal', 'code' => 'ALM1', 'is_default' => true,
            ]);

            foreach (DocumentNumbering::KEYS as $key => $label) {
                DocumentSequence::withoutGlobalScopes()->create([
                    'company_id' => $company->id, 'key' => $key, 'prefix' => '', 'next_number' => 1, 'padding' => 4,
                ]);
            }

            $user = new User($admin + ['company_id' => $company->id, 'branch_id' => $branch->id, 'role_id' => $roles['company_admin']->id]);
            $user->is_active = true;
            $user->save();

            return $company;
        });
    }
}
