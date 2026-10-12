<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_role_can_view_but_not_create_edit_or_delete(): void
    {
        $user = $this->makeUser($this->makeCompany(), 'read_only');

        foreach (['products.view', 'inventory.view', 'reports.view', 'fuel.view', 'vehicles.view'] as $p) {
            $this->assertTrue($user->hasPermission($p), $p);
        }
        foreach (['products.create', 'products.edit', 'products.delete', 'inventory.entry', 'inventory.dispatch', 'inventory.adjust', 'users.manage', 'reports.export', 'audit.view'] as $p) {
            $this->assertFalse($user->hasPermission($p), $p);
        }
    }

    public function test_backend_enforces_permission_even_if_url_is_typed_manually(): void
    {
        $company = $this->makeCompany();
        $reader = $this->makeUser($company, 'read_only');
        $operator = $this->makeUser($company, 'operator');

        $this->actingAs($reader)->get('/administracion/usuarios')->assertForbidden();
        $this->actingAs($reader)->post('/administracion/usuarios', [])->assertForbidden();
        $this->actingAs($reader)->get('/administracion/roles')->assertForbidden();
        $this->actingAs($reader)->get('/administracion/configuracion')->assertForbidden();
        $this->actingAs($reader)->get('/administracion/auditoria')->assertForbidden();
        $this->actingAs($operator)->get('/administracion/usuarios')->assertForbidden();
        $this->actingAs($reader)->get('/dashboard')->assertOk();
    }

    public function test_user_override_can_grant_and_deny_on_top_of_role(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company, 'read_only');
        $id = fn ($k) => Permission::where('key', $k)->value('id');

        $user->permissionOverrides()->attach($id('products.create'), ['granted' => true]);
        $user->permissionOverrides()->attach($id('reports.view'), ['granted' => false]);
        $user->flushPermissionCache();

        $this->assertTrue($user->hasPermission('products.create'));
        $this->assertFalse($user->hasPermission('reports.view'));
        $this->assertTrue($user->hasPermission('products.view'));
    }

    public function test_gate_and_blade_can_use_individual_permissions(): void
    {
        $user = $this->makeUser($this->makeCompany(), 'read_only');
        $this->assertTrue($user->can('products.view'));
        $this->assertFalse($user->can('products.delete'));
    }

    public function test_inactive_user_has_no_permissions(): void
    {
        $user = $this->makeUser($this->makeCompany(), 'company_admin', ['is_active' => false]);
        $this->assertFalse($user->hasPermission('dashboard.view'));
    }

    public function test_admin_cannot_delegate_permissions_they_do_not_have(): void
    {
        $company = $this->makeCompany();
        // Un "administrador" limitado: solo gestiona usuarios y roles.
        $limited = $this->makeUser($company, 'read_only');
        $limited->permissionOverrides()->attach(Permission::whereIn('key', ['users.manage', 'roles.manage'])->pluck('id'), ['granted' => true]);
        $target = $this->makeUser($company, 'operator');
        $limitedRole = Role::where('company_id', $company->id)->where('slug', 'read_only')->first();

        $this->actingAs($limited)->put("/administracion/usuarios/{$target->id}", [
            'first_name' => 'Op', 'last_name' => 'Erador', 'username' => $target->username, 'email' => $target->email,
            'role_id' => $limitedRole->id, 'is_active' => 1,
            'permissions' => ['users.manage' => 'grant', 'products.delete' => 'grant', 'inventory.adjust' => 'grant'],
        ])->assertRedirect();

        $target = $target->fresh();
        $this->assertTrue($target->hasPermission('users.manage'));
        $this->assertFalse($target->hasPermission('products.delete'));
        $this->assertFalse($target->hasPermission('inventory.adjust'));

        $this->actingAs($limited)->put("/administracion/roles/{$limitedRole->id}", [
            'name' => 'Solo consulta', 'permissions' => ['products.delete', 'products.view'],
        ])->assertRedirect();
        $this->assertFalse($limitedRole->fresh()->permissions()->where('key', 'products.delete')->exists());
    }

    public function test_cannot_deactivate_or_delete_own_account(): void
    {
        $admin = $this->makeUser($this->makeCompany());

        $this->actingAs($admin)->delete("/administracion/usuarios/{$admin->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted($admin);
    }

    public function test_role_with_users_cannot_be_deleted(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);
        $custom = Role::create(['company_id' => $company->id, 'name' => 'Custom', 'slug' => 'custom']);
        $this->makeUser($company, 'operator')->update(['role_id' => $custom->id]);

        $this->actingAs($admin)->delete("/administracion/roles/{$custom->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('roles', ['id' => $custom->id]);
    }

    public function test_pages_render_for_admin(): void
    {
        $admin = $this->makeUser($this->makeCompany());
        foreach (['/dashboard', '/administracion/usuarios', '/administracion/usuarios/create', '/administracion/roles', '/administracion/roles/create',
            '/administracion/auditoria', '/administracion/configuracion', '/administracion/configuracion/sucursales', '/administracion/configuracion/numeracion', '/perfil'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }
}
