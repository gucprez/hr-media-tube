<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Crea una empresa lista para operar: roles, sucursal, almacén, numeraciones y administrador. */
class CompanyProvisioner
{
    /** @return string[] claves de permisos que no existían antes de esta sincronización */
    public static function syncPermissions(): array
    {
        $created = [];
        foreach (PermissionCatalog::groups() as $group => $perms) {
            foreach ($perms as $key => $label) {
                $permission = Permission::updateOrCreate(['key' => $key], ['group' => $group, 'label' => $label]);
                if ($permission->wasRecentlyCreated) {
                    $created[] = $key;
                }
            }
        }
        Permission::whereNotIn('key', PermissionCatalog::keys())->delete();

        return $created;
    }

    /** Crea o actualiza los roles de una empresa a partir de las plantillas. Devuelve slug => Role. */
    public static function syncRoles(Company $company, bool $resetExisting = false, array $newPermissions = []): array
    {
        self::syncPermissions();
        $ids = Permission::pluck('id', 'key');
        $roles = [];
        foreach (config('permissions.roles') as $slug => $tpl) {
            $role = Role::firstOrNew(['company_id' => $company->id, 'slug' => $slug]);
            $isNew = ! $role->exists;
            $role->fill(['name' => $tpl['name'], 'description' => $tpl['description'], 'is_system' => true])->save();
            $template = PermissionCatalog::expand($tpl['permissions']);
            if ($isNew || $resetExisting) {
                $role->permissions()->sync(collect($template)->map(fn ($k) => $ids[$k])->all());
            } elseif ($newPermissions) {
                // Permisos nuevos del catálogo: se añaden a los roles base que su plantilla incluye.
                $role->permissions()->syncWithoutDetaching(
                    collect(array_intersect($template, $newPermissions))->map(fn ($k) => $ids[$k])->all()
                );
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
                DocumentSequence::withoutGlobalScopes()->create(
                    ['company_id' => $company->id, 'key' => $key, 'next_number' => 1] + DocumentNumbering::defaults($key)
                );
            }

            // Catálogos base editables por la empresa (unidades de medida).
            foreach (['Unidad' => 'Unidades', 'Rollo' => 'Rollos', 'Pieza' => 'Piezas', 'Galón' => 'Galones', 'Litro' => 'Litros', 'Par' => 'Pares', 'Kit' => 'Kits', 'Libra' => 'Libras'] as $name => $plural) {
                Unit::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => $name, 'plural' => $plural]);
            }

            foreach (['Camión', 'Cabezote', 'Camioneta', 'Furgoneta'] as $type) {
                \App\Models\VehicleType::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => $type]);
            }

            $user = new User($admin + ['company_id' => $company->id, 'branch_id' => $branch->id, 'role_id' => $roles['company_admin']->id]);
            $user->is_active = true;
            $user->save();

            return $company;
        });
    }
}
