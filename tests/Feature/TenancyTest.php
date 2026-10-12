<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_list_only_shows_own_company(): void
    {
        $a = $this->makeCompany('Empresa A');
        $b = $this->makeCompany('Empresa B');
        $adminA = $this->makeUser($a, 'company_admin');
        $this->makeUser($a, 'operator', ['first_name' => 'Visible', 'last_name' => 'Aaa']);
        $this->makeUser($b, 'operator', ['first_name' => 'Oculto', 'last_name' => 'Bbb']);

        $this->actingAs($adminA)->get('/administracion/usuarios')
            ->assertOk()->assertSee('Visible')->assertDontSee('Oculto');
    }

    public function test_cannot_edit_or_delete_user_of_other_company(): void
    {
        $a = $this->makeCompany('Empresa A');
        $b = $this->makeCompany('Empresa B');
        $adminA = $this->makeUser($a);
        $victim = $this->makeUser($b, 'operator');

        $this->actingAs($adminA)->get("/administracion/usuarios/{$victim->id}/edit")->assertNotFound();
        $this->actingAs($adminA)->put("/administracion/usuarios/{$victim->id}", ['first_name' => 'X'])->assertNotFound();
        $this->actingAs($adminA)->delete("/administracion/usuarios/{$victim->id}")->assertNotFound();
        $this->assertNotSoftDeleted($victim);
    }

    public function test_cannot_assign_role_or_branch_from_other_company(): void
    {
        $a = $this->makeCompany('Empresa A');
        $b = $this->makeCompany('Empresa B');
        $adminA = $this->makeUser($a);
        $foreignRole = Role::where('company_id', $b->id)->where('slug', 'company_admin')->first();
        $foreignBranch = Branch::withoutGlobalScopes()->where('company_id', $b->id)->first();

        $this->actingAs($adminA)->post('/administracion/usuarios', [
            'first_name' => 'Nuevo', 'last_name' => 'Usuario', 'username' => 'nuevo1', 'email' => 'nuevo1@test.local',
            'password' => 'Password123', 'role_id' => $foreignRole->id, 'branch_id' => $foreignBranch->id, 'is_active' => 1,
        ])->assertSessionHasErrors(['role_id', 'branch_id']);

        $this->assertDatabaseMissing('users', ['username' => 'nuevo1']);
    }

    public function test_new_user_is_created_in_actors_company(): void
    {
        $a = $this->makeCompany('Empresa A');
        $adminA = $this->makeUser($a);
        $role = Role::where('company_id', $a->id)->where('slug', 'operator')->first();

        $this->actingAs($adminA)->post('/administracion/usuarios', [
            'first_name' => 'Nuevo', 'last_name' => 'Usuario', 'username' => 'nuevo2', 'email' => 'nuevo2@test.local',
            'password' => 'Password123', 'role_id' => $role->id, 'is_active' => 1,
            // intento de inyección de company_id
            'company_id' => 9999,
        ])->assertRedirect('/administracion/usuarios');

        $this->assertDatabaseHas('users', ['username' => 'nuevo2', 'company_id' => $a->id]);
    }

    public function test_roles_are_isolated_between_companies(): void
    {
        $a = $this->makeCompany('Empresa A');
        $b = $this->makeCompany('Empresa B');
        $adminA = $this->makeUser($a);
        $roleB = Role::where('company_id', $b->id)->first();

        $this->actingAs($adminA)->get("/administracion/roles/{$roleB->id}/edit")->assertNotFound();
        $this->actingAs($adminA)->delete("/administracion/roles/{$roleB->id}")->assertNotFound();
    }

    public function test_audit_log_is_isolated_between_companies(): void
    {
        $a = $this->makeCompany('Empresa A');
        $b = $this->makeCompany('Empresa B');
        $adminA = $this->makeUser($a);
        $adminB = $this->makeUser($b);

        $this->actingAs($adminB)->put('/perfil', ['first_name' => 'SoloEnB', 'last_name' => 'Zzz', 'email' => $adminB->email]);
        $this->actingAs($adminA)->get('/administracion/auditoria')->assertOk()->assertDontSee('SoloEnB');
    }

    public function test_global_scope_hides_other_company_rows_and_autofills_company_id(): void
    {
        $a = $this->makeCompany('Empresa A');
        $b = $this->makeCompany('Empresa B');
        $ctx = app(\App\Support\CurrentCompany::class);

        $ctx->set($a);
        $created = Branch::create(['name' => 'Sucursal A2']);
        $this->assertSame($a->id, $created->company_id);
        $this->assertTrue(Branch::pluck('company_id')->every(fn ($id) => $id === $a->id));

        $ctx->set($b);
        $this->assertFalse(Branch::where('name', 'Sucursal A2')->exists());
    }

    public function test_super_admin_must_pick_company_and_then_sees_only_that_one(): void
    {
        $a = $this->makeCompany('Empresa A');
        $b = $this->makeCompany('Empresa B');
        $this->makeUser($a, 'operator', ['first_name' => 'DeA', 'last_name' => 'Uno']);
        $this->makeUser($b, 'operator', ['first_name' => 'DeB', 'last_name' => 'Dos']);
        $super = $this->makeSuperAdmin();

        $this->actingAs($super)->get('/dashboard')->assertRedirect('/plataforma/empresas');
        $this->actingAs($super)->post("/plataforma/empresas/{$a->id}/entrar")->assertRedirect('/dashboard');
        $this->get('/administracion/usuarios')->assertOk()->assertSee('DeA')->assertDontSee('DeB');
    }

    public function test_only_super_admin_manages_companies(): void
    {
        $a = $this->makeCompany('Empresa A');
        $admin = $this->makeUser($a);

        $this->actingAs($admin)->get('/plataforma/empresas')->assertForbidden();
        $this->actingAs($admin)->post('/plataforma/empresas', ['name' => 'Hack'])->assertForbidden();
    }

    public function test_super_admin_creates_company_with_admin_roles_and_warehouse(): void
    {
        $super = $this->makeSuperAdmin();

        $this->actingAs($super)->post('/plataforma/empresas', [
            'name' => 'Nueva Empresa SRL', 'admin_first_name' => 'Ada', 'admin_last_name' => 'Lovelace',
            'admin_username' => 'ada', 'admin_email' => 'ada@nueva.test', 'admin_password' => 'Password123',
        ])->assertRedirect('/plataforma/empresas');

        $company = \App\Models\Company::where('name', 'Nueva Empresa SRL')->firstOrFail();
        $admin = User::withoutGlobalScopes()->where('username', 'ada')->first();
        $this->assertSame($company->id, $admin->company_id);
        $this->assertTrue($admin->hasPermission('products.delete'));
        $this->assertSame(8, Role::where('company_id', $company->id)->count());
        $this->assertDatabaseHas('warehouses', ['company_id' => $company->id, 'is_default' => true]);
        $this->assertDatabaseHas('document_sequences', ['company_id' => $company->id, 'key' => 'ticket_materials']);
    }
}
