<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Permisos de Fase 2 validados en el BACKEND (rutas escritas a mano, sin botones). */
class InventoryPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function world(): array
    {
        $company = $this->makeCompany();
        $wh = $this->defaultWarehouse($company);
        $admin = $this->makeUser($company);
        $product = $this->makeProduct($company);
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $product->id, 'boxes' => 5, 'units' => 8]], $admin);

        return [$company, $wh, $product];
    }

    public function test_read_only_user_can_view_but_not_write_anything(): void
    {
        [$company, $wh, $p] = $this->world();
        $reader = $this->makeUser($company, 'read_only');
        $d = now()->toDateString();

        // PUEDE
        $this->actingAs($reader);
        foreach (['/almacen/productos', "/almacen/productos/{$p->id}", '/inventario', '/inventario/movimientos', '/reportes/almacen/inventario-actual', '/reportes/almacen/movimiento-inventario', '/reportes', '/almacen/categorias', '/buscar?q=tape', '/dashboard'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->getJson('/buscar?q=tape')->assertOk()->assertJsonPath('0.results.0.title', 'Tape Transparente');
        $this->get("/almacen/productos/{$p->id}")->assertSee('5 cajas + 8 rollos')->assertDontSee('Eliminar')->assertDontSee('Editar')->assertDontSee('Subir fotos');
        $this->get('/inventario')->assertDontSee('Entrada</a>', false);

        // NO PUEDE
        $this->get('/almacen/productos/nuevo')->assertForbidden();
        $this->post('/almacen/productos', ['sku' => 'X', 'name' => 'X'])->assertForbidden();
        $this->get("/almacen/productos/{$p->id}/editar")->assertForbidden();
        $this->put("/almacen/productos/{$p->id}", ['sku' => 'X', 'name' => 'Hack'])->assertForbidden();
        $this->delete("/almacen/productos/{$p->id}")->assertForbidden();
        $this->post("/almacen/productos/{$p->id}/fotos", [])->assertForbidden();
        $this->post('/almacen/categorias', ['name' => 'Nueva'])->assertForbidden();
        $this->post('/almacen/unidades', ['name' => 'Nueva'])->assertForbidden();
        $this->get('/inventario/entradas/nueva')->assertForbidden();
        $this->post('/inventario/entradas', ['date' => $d, 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $p->id, 'units' => 1]]])->assertForbidden();
        $this->get('/inventario/salidas/nueva')->assertForbidden();
        $this->post('/inventario/salidas', ['date' => $d, 'warehouse_id' => $wh->id, 'destination' => 'X', 'reason' => 'Y', 'destination_type' => 'other', 'lines' => [['product_id' => $p->id, 'units' => 1]]])->assertForbidden();
        $this->get('/inventario/ajustes/nuevo')->assertForbidden();
        $this->post('/inventario/ajustes', ['date' => $d, 'warehouse_id' => $wh->id, 'reason' => 'Conteo físico', 'notes' => 'Intento no autorizado', 'lines' => [['product_id' => $p->id, 'units' => 1]]])->assertForbidden();
        $this->getJson('/inventario/api/productos?q=tape')->assertForbidden();
        $this->postJson('/inventario/api/vista-previa', ['type' => 'exit', 'warehouse_id' => $wh->id, 'lines' => []])->assertForbidden();
        $this->get('/administracion/auditoria')->assertForbidden();

        $this->actAs($company);
        $this->assertSame(188.0, $p->stockInWarehouse($wh->id)); // nada cambió
        $this->assertSame(1, \App\Models\InventoryMovement::count());
    }

    public function test_read_only_sees_product_photos(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        [$company, $wh, $p] = $this->world();
        $admin = \App\Models\User::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $reader = $this->makeUser($company, 'read_only');
        $this->actingAs($admin)->post("/almacen/productos/{$p->id}/fotos", ['photos' => [\Illuminate\Http\UploadedFile::fake()->image('p.jpg', 600, 400)]]);
        $this->actAs($company);
        $img = $p->fresh()->images->first();

        $this->actingAs($reader)->get("/archivos/producto/{$img->id}")->assertOk();
    }

    public function test_operator_can_enter_and_dispatch_but_not_adjust_or_manage_products(): void
    {
        [$company, $wh, $p] = $this->world();
        $op = $this->makeUser($company, 'operator');
        $d = now()->toDateString();

        $this->actingAs($op)->get('/inventario/entradas/nueva')->assertOk();
        $this->post('/inventario/entradas', ['date' => $d, 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $p->id, 'units' => 1]]])->assertSessionHasNoErrors();
        $this->post('/inventario/salidas', ['date' => $d, 'warehouse_id' => $wh->id, 'destination' => 'X', 'destination_type' => 'other', 'reason' => 'Y', 'lines' => [['product_id' => $p->id, 'units' => 1]]])->assertSessionHasNoErrors();
        $this->get('/inventario/ajustes/nuevo')->assertForbidden();
        $this->post('/inventario/ajustes', [])->assertForbidden();
        $this->get('/almacen/productos/nuevo')->assertForbidden();
        $this->get('/reportes/almacen/inventario-actual')->assertForbidden(); // operador no tiene reports.inventory.view
    }

    public function test_individual_overrides_apply_to_inventory_routes(): void
    {
        [$company, $wh, $p] = $this->world();
        $reader = $this->makeUser($company, 'read_only');
        $reader->permissionOverrides()->attach(Permission::where('key', 'inventory.entry')->value('id'), ['granted' => true]);
        $reader->permissionOverrides()->attach(Permission::where('key', 'products.view')->value('id'), ['granted' => false]);

        $this->actingAs($reader->fresh())->get('/inventario/entradas/nueva')->assertOk();
        $this->get('/almacen/productos')->assertForbidden();
        $this->getJson('/buscar?q=tape')->assertOk()->assertExactJson([]); // sin products.view la búsqueda no devuelve productos
    }

    public function test_reports_hub_shows_only_permitted_sections(): void
    {
        $company = $this->makeCompany();
        $fuel = $this->makeUser($company, 'fuel');
        $reader = $this->makeUser($company, 'read_only');
        $auditor = $this->makeUser($company, 'auditor');
        $admin = $this->makeUser($company);

        $this->actingAs($fuel)->get('/reportes')->assertOk()->assertSee('Reportes de Gasoil')->assertDontSee('Reportes de Almacén')->assertDontSee('Reportes de Auditoría');
        $this->get('/reportes/almacen')->assertForbidden();
        $this->get('/reportes/fuel')->assertOk()->assertSee('Recepciones de gasoil')->assertSee('Disponible');
        $this->get('/reportes/audit')->assertNotFound();

        $this->actingAs($reader)->get('/reportes')->assertOk()->assertSee('Reportes de Almacén')->assertSee('Reportes de Gasoil')->assertDontSee('Reportes de Auditoría');
        $this->actingAs($auditor)->get('/reportes')->assertSee('Reportes de Auditoría');
        foreach (['fuel', 'containers', 'vehicles', 'maintenance', 'tires', 'returnables', 'audit'] as $s) {
            $this->actingAs($admin)->get("/reportes/{$s}")->assertOk();
        }
        $this->actingAs($admin)->get('/reportes')->assertOk()->assertSee('Reportes de Mecánica / Mantenimiento')->assertSee('Reportes de Retornables')->assertSee('Reportes de Gomas')->assertSee('Reportes de Furgones')->assertSee('Reportes de Vehículos');
        $this->get('/reportes/inexistente')->assertNotFound();
    }

    public function test_sidebar_shows_the_eight_report_entries_to_admin(): void
    {
        $admin = $this->makeUser($this->makeCompany());
        $html = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();
        foreach (['Centro de reportes', 'Gasoil', 'Furgones', 'Vehículos', 'Mecánica / Mantenimiento', 'Gomas', 'Retornables'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString(route('reports.section', 'audit'), $html);
        $this->assertStringContainsString(route('reports.warehouse'), $html);
    }

    public function test_permission_sync_adds_new_permissions_to_existing_roles(): void
    {
        $company = $this->makeCompany();
        $reader = $this->makeUser($company, 'read_only');
        $perm = Permission::where('key', 'reports.tires.view')->first();
        \Illuminate\Support\Facades\DB::table('permission_role')->where('permission_id', $perm->id)->delete();
        $perm->delete(); // simula una instalación anterior que aún no conocía este permiso
        $this->assertFalse($reader->fresh()->hasPermission('reports.tires.view'));

        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertTrue($reader->fresh()->hasPermission('reports.tires.view'));
        $this->assertFalse($reader->fresh()->hasPermission('inventory.negative'));
    }

    public function test_wildcards_never_grant_sensitive_permissions(): void
    {
        $company = $this->makeCompany();
        $manager = $this->makeUser($company, 'warehouse_manager');
        $admin = $this->makeUser($company);
        $this->assertTrue($manager->hasPermission('inventory.adjust'));
        $this->assertFalse($manager->hasPermission('inventory.negative'));
        $this->assertFalse($manager->hasPermission('reports.audit.view'));
        $this->assertTrue($admin->hasPermission('inventory.negative'));
    }
}
