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

    /** Activa la empresa en el contexto (para usar servicios/modelos fuera de una petición HTTP). */
    protected function actAs(Company $company): void
    {
        app(CurrentCompany::class)->set($company);
        app(CurrentCompany::class)->enforce();
    }

    protected function makeProduct(Company $company, array $attrs = []): \App\Models\Product
    {
        $previous = app(CurrentCompany::class)->get();
        $this->actAs($company);
        $unit = \App\Models\Unit::where('name', $attrs['unit'] ?? 'Rollo')->first();
        unset($attrs['unit']);

        $product = \App\Models\Product::create($attrs + [
            'sku' => 'P'.uniqid(), 'name' => 'Tape Transparente', 'handling_mode' => 'both', 'box_label' => 'caja',
            'units_per_box' => 36, 'unit_id' => $unit?->id, 'alert_unit' => 'boxes', 'min_quantity' => 1, 'min_stock_base' => 36,
            'is_active' => true,
        ]);
        $previous ? $this->actAs($previous) : app(CurrentCompany::class)->clear();

        return $product;
    }

    protected function defaultWarehouse(Company $company): \App\Models\Warehouse
    {
        return \App\Models\Warehouse::withoutGlobalScopes()->where('company_id', $company->id)->where('is_default', true)->firstOrFail();
    }
}
