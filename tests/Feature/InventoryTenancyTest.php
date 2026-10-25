<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryMovementItem;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Aislamiento multiempresa del módulo de inventario (productos, stock, movimientos, archivos, búsqueda, reportes). */
class InventoryTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected array $w = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $a = $this->makeCompany('Empresa A');
        $b = $this->makeCompany('Empresa B');
        $userA = $this->makeUser($a);
        $userB = $this->makeUser($b);
        $whA = $this->defaultWarehouse($a);
        $whB = $this->defaultWarehouse($b);
        $prodA = $this->makeProduct($a, ['sku' => 'SKU-A', 'name' => 'Producto Secreto A']);
        $prodB = $this->makeProduct($b, ['sku' => 'SKU-B', 'name' => 'Producto de B']);

        $this->actAs($a);
        $mA = app(StockService::class)->entry(['warehouse_id' => $whA->id, 'document_no' => 'DOC-A'], [['product_id' => $prodA->id, 'units' => 100]], $userA);
        $this->actAs($b);
        app(StockService::class)->entry(['warehouse_id' => $whB->id], [['product_id' => $prodB->id, 'units' => 7]], $userB);
        app(\App\Support\CurrentCompany::class)->clear();

        $this->w = compact('a', 'b', 'userA', 'userB', 'whA', 'whB', 'prodA', 'prodB', 'mA');
    }

    public function test_products_are_isolated(): void
    {
        ['userB' => $userB, 'prodA' => $prodA] = $this->w;

        $this->actingAs($userB)->get('/almacen/productos')->assertOk()->assertSee('Producto de B')->assertDontSee('Producto Secreto A');
        $this->get("/almacen/productos/{$prodA->id}")->assertNotFound();
        $this->get("/almacen/productos/{$prodA->id}/editar")->assertNotFound();
        $this->put("/almacen/productos/{$prodA->id}", ['sku' => 'X', 'name' => 'Hackeado'])->assertNotFound();
        $this->delete("/almacen/productos/{$prodA->id}")->assertNotFound();
        $this->assertSame('Producto Secreto A', Product::withoutGlobalScopes()->find($prodA->id)->name);
    }

    public function test_stock_is_isolated(): void
    {
        ['userB' => $userB, 'b' => $b, 'a' => $a] = $this->w;

        $this->actingAs($userB)->get('/inventario')->assertOk()->assertSee('Producto de B')->assertDontSee('Producto Secreto A');
        $this->actAs($b);
        $this->assertSame(7.0, (float) InventoryStock::sum('quantity_base'));
        $this->assertSame(1, InventoryStock::count());
        $this->actAs($a);
        $this->assertSame(100.0, (float) InventoryStock::sum('quantity_base'));
    }

    public function test_cannot_move_stock_of_another_company_product_or_warehouse(): void
    {
        ['userB' => $userB, 'prodA' => $prodA, 'prodB' => $prodB, 'whA' => $whA, 'whB' => $whB] = $this->w;
        $d = now()->toDateString();

        // producto ajeno
        $this->actingAs($userB)->post('/inventario/salidas', ['date' => $d, 'warehouse_id' => $whB->id, 'destination' => 'X', 'requested_by' => 'Pedro Pérez', 'destination_type' => 'other', 'reason' => 'Y', 'lines' => [['product_id' => $prodA->id, 'units' => 5]]])
            ->assertSessionHasErrors('lines.0.product_id');
        // almacén ajeno
        $this->post('/inventario/entradas', ['date' => $d, 'warehouse_id' => $whA->id, 'lines' => [['product_id' => $prodB->id, 'units' => 5]]])->assertSessionHasErrors('warehouse_id');
        $this->post('/inventario/ajustes', ['date' => $d, 'warehouse_id' => $whA->id, 'reason' => 'Conteo físico', 'notes' => 'Intento de ajuste cruzado', 'lines' => [['product_id' => $prodA->id, 'units' => 0]]])->assertSessionHasErrors();

        $this->assertSame(100.0, (float) InventoryStock::withoutGlobalScopes()->where('product_id', $prodA->id)->sum('quantity_base'));
        $this->assertSame(0, InventoryMovement::withoutGlobalScopes()->where('company_id', $this->w['b']->id)->where('type', '!=', 'entry')->count());
    }

    public function test_service_level_isolation_even_bypassing_form_validation(): void
    {
        ['userB' => $userB, 'prodA' => $prodA, 'whA' => $whA, 'whB' => $whB, 'b' => $b] = $this->w;
        $this->actAs($b);
        $svc = app(StockService::class);

        $this->assertThrows(fn () => $svc->exit(['warehouse_id' => $whB->id], [['product_id' => $prodA->id, 'units' => 1]], $userB), \App\Services\Inventory\InventoryException::class);
        $this->assertThrows(fn () => $svc->entry(['warehouse_id' => $whA->id], [['product_id' => $this->w['prodB']->id, 'units' => 1]], $userB), \App\Services\Inventory\InventoryException::class);
    }

    public function test_movements_and_documents_are_isolated(): void
    {
        ['userB' => $userB, 'mA' => $mA] = $this->w;

        $this->actingAs($userB)->get('/inventario/movimientos')->assertOk()->assertDontSee('DOC-A')->assertDontSee('Producto Secreto A');
        $this->get("/inventario/movimientos/{$mA->id}")->assertNotFound();
    }

    public function test_ledger_and_period_reports_are_isolated(): void
    {
        ['b' => $b, 'a' => $a, 'userB' => $userB] = $this->w;
        $this->actAs($b);
        $rows = app(InventoryLedger::class)->periodSummary(now()->startOfMonth(), now());
        $this->assertCount(1, $rows);
        $this->assertSame('SKU-B', $rows->first()->product_sku);
        $this->assertSame(7.0, $rows->first()->final);
        $this->assertSame(1, InventoryMovementItem::count());

        $this->actingAs($userB)->get('/reportes/almacen/movimiento-inventario')->assertOk()->assertSee('SKU-B')->assertDontSee('SKU-A');
        $this->get('/reportes/almacen/inventario-actual')->assertOk()->assertSee('Producto de B')->assertDontSee('Producto Secreto A');
        $this->get('/reportes/almacen/inventario-actual?product='.$this->w['prodA']->id)->assertOk()->assertDontSee('Producto Secreto A');
    }

    public function test_lookup_preview_and_search_do_not_leak(): void
    {
        ['userB' => $userB, 'prodA' => $prodA, 'whB' => $whB] = $this->w;

        $this->actingAs($userB)->getJson('/inventario/api/productos?q=Secreto')->assertOk()->assertExactJson([]);
        $this->getJson('/buscar?q=Secreto')->assertOk()->assertExactJson([]);
        $this->getJson('/buscar?q=SKU-A')->assertOk()->assertExactJson([]);
        $r = $this->postJson('/inventario/api/vista-previa', ['type' => 'exit', 'warehouse_id' => $whB->id, 'lines' => [['product_id' => $prodA->id, 'units' => 1]]]);
        $this->assertFalse($r->json('0.ok'));
        $this->assertStringNotContainsString('Secreto', json_encode($r->json()));
    }

    public function test_files_are_isolated_and_never_public(): void
    {
        ['userA' => $userA, 'userB' => $userB, 'a' => $a, 'whA' => $whA, 'prodA' => $prodA] = $this->w;

        // empresa A sube foto de producto y adjunto de movimiento
        $this->actingAs($userA)->post("/almacen/productos/{$prodA->id}/fotos", ['photos' => [UploadedFile::fake()->image('p.jpg', 600, 400)]])->assertSessionHasNoErrors();
        $this->post('/inventario/entradas', ['date' => now()->toDateString(), 'warehouse_id' => $whA->id, 'lines' => [['product_id' => $prodA->id, 'units' => 1]], 'files' => [UploadedFile::fake()->image('c.jpg', 600, 400)]])->assertSessionHasNoErrors();

        $this->actAs($a);
        $image = $prodA->fresh()->images->first();
        $attachment = \App\Models\Attachment::first();

        // A sí accede
        $this->get("/archivos/producto/{$image->id}")->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');
        $this->get("/archivos/producto/{$image->id}?thumb=1")->assertOk();
        $this->get("/archivos/adjunto/{$attachment->id}")->assertOk();

        // B no (404: ni siquiera sabe que existe), invitado va a login
        $this->actingAs($userB)->get("/archivos/producto/{$image->id}")->assertNotFound();
        $this->get("/archivos/adjunto/{$attachment->id}")->assertNotFound();
        auth()->logout();
        $this->get("/archivos/producto/{$image->id}")->assertRedirect('/login');

        // y las rutas físicas no están expuestas por URL pública
        $this->actingAs($userA)->get('/storage/'.$image->path)->assertNotFound();
        $this->get('/storage/private/'.$image->path)->assertNotFound();
    }

    public function test_delete_photo_of_other_company_is_impossible(): void
    {
        ['userA' => $userA, 'userB' => $userB, 'a' => $a, 'prodA' => $prodA] = $this->w;
        $this->actingAs($userA)->post("/almacen/productos/{$prodA->id}/fotos", ['photos' => [UploadedFile::fake()->image('p.jpg', 600, 400)]]);
        $this->actAs($a);
        $image = $prodA->fresh()->images->first();

        $this->actingAs($userB)->delete("/almacen/productos/{$prodA->id}/fotos/{$image->id}")->assertNotFound();
        $this->post("/almacen/productos/{$prodA->id}/fotos/{$image->id}/principal")->assertNotFound();
        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }

    public function test_dashboard_numbers_are_per_company(): void
    {
        ['userA' => $userA, 'userB' => $userB] = $this->w;
        $this->actingAs($userA)->get('/dashboard')->assertOk()->assertSee('100 unidades ingresadas');
        $this->actingAs($userB)->get('/dashboard')->assertOk()->assertSee('7 unidades ingresadas')->assertDontSee('100 unidades');
    }

    public function test_document_numbers_are_independent_between_companies(): void
    {
        $this->assertSame('ENT-000001', $this->w['mA']->number);
        $this->actAs($this->w['b']);
        $this->assertSame('ENT-000001', InventoryMovement::first()->number);
    }
}
