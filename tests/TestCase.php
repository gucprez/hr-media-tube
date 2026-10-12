<?php

namespace Tests;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Crea una empresa completa (roles, almacén, numeraciones) con su administrador. */
    protected function makeCompany(string $name = 'Empresa Test'): Company
    {
        $slug = \Illuminate\Support\Str::slug($name).'-'.uniqid();
        $company = app(CompanyProvisioner::class)->create(
            ['name' => $name, 'slug' => $slug],
            ['first_name' => 'Admin', 'last_name' => $name, 'username' => 'admin_'.uniqid(), 'email' => uniqid().'@test.local', 'password' => 'Password123'],
        );
        app(CurrentCompany::class)->clear();

        return $company;
    }

    /** Crea un usuario de una empresa con el rol plantilla indicado (slug). */
    protected function makeUser(Company $company, string $roleSlug = 'company_admin', array $attrs = []): User
    {
        $role = Role::where('company_id', $company->id)->where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->make($attrs + ['company_id' => $company->id, 'role_id' => $role->id]);
        $user->save();
        app(CurrentCompany::class)->clear();

        return $user->fresh();
    }

    protected function makeSuperAdmin(): User
    {
        $user = User::factory()->make();
        $user->is_super_admin = true;
        $user->save();
        app(CurrentCompany::class)->clear();

        return $user;
    }
}
