<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected function payload($company, array $over = []): array
    {
        $this->actAs($company);
        $unit = Unit::where('name', 'Rollo')->first();

        return $over + [
            'sku' => 'TAPE-01', 'name' => 'Tape Transparente', 'unit_id' => $unit->id, 'handling_mode' => 'both',
            'units_per_box' => 36, 'box_label' => 'caja', 'alert_unit' => 'boxes', 'min_quantity' => 1, 'is_active' => 1,
        ];
    }

    public function test_admin_creates_product_with_photos_and_derived_minimum(): void
    {
        Storage::fake('local');
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);
        $wh = $this->defaultWarehouse($company);
        $category = (function () use ($company) { $this->actAs($company); return Category::create(['name' => 'Embalaje']); })();

        $this->actingAs($admin)->post('/almacen/productos', $this->payload($company, [
            'category_id' => $category->id, 'default_warehouse_id' => $wh->id, 'aisle' => 'A', 'shelf' => '2',
            'photos' => [UploadedFile::fake()->image('a.jpg', 2400, 1800), UploadedFile::fake()->image('b.png', 800, 600)],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->actAs($company);
        $p = Product::with('images')->where('sku', 'TAPE-01')->firstOrFail();
        $this->assertSame(36.0, $p->min_stock_base);          // 1 caja × 36 calculado en servidor
        $this->assertCount(2, $p->images);
        $this->assertTrue($p->images[0]->is_primary);
        $this->assertFalse($p->images[1]->is_primary);
        Storage::disk('local')->assertExists($p->images[0]->path);
        Storage::disk('local')->assertExists($p->images[0]->thumb_path);
        $this->assertSame($admin->id, $p->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created', 'user_id' => $admin->id, 'company_id' => $company->id]);
        // la existencia inicial NO se escribe: empieza en 0 hasta que haya una entrada
        $this->assertSame(0.0, $p->totalBase());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_uploaded_image_is_reencoded_and_resized(): void
    {
        Storage::fake('local');
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);

        $this->actingAs($admin)->post('/almacen/productos', $this->payload($company, ['photos' => [UploadedFile::fake()->image('big.jpg', 4000, 3000)]]))->assertSessionHasNoErrors();

        $this->actAs($company);
        $img = Product::first()->images->first();
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($img->path));
        $this->assertSame(1600, max($w, $h));
        [$tw, $th] = getimagesizefromstring(Storage::disk('local')->get($img->thumb_path));
        $this->assertLessThanOrEqual(400, max($tw, $th));
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('local');
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);

        // archivo real (no "fake") con extensión .jpg pero contenido PHP: se valida por contenido, no por nombre
        $path = tempnam(sys_get_temp_dir(), 'evil');
        file_put_contents($path, '<?php echo 1;');
        $evil = new UploadedFile($path, 'x.jpg', 'image/jpeg', null, true);

        $this->actingAs($admin)->post('/almacen/productos', $this->payload($company, ['photos' => [$evil]]))
            ->assertSessionHasErrors('photos.0');
        $this->actAs($company);
        $this->assertSame(0, Product::count());
    }

    public function test_sku_is_unique_per_company_but_reusable_across_companies(): void
    {
        $a = $this->makeCompany('A');
        $b = $this->makeCompany('B');
        $adminA = $this->makeUser($a);
        $adminB = $this->makeUser($b);

        $this->actingAs($adminA)->post('/almacen/productos', $this->payload($a))->assertSessionHasNoErrors();
        $this->actingAs($adminA)->post('/almacen/productos', $this->payload($a))->assertSessionHasErrors('sku');
        $this->actingAs($adminB)->post('/almacen/productos', $this->payload($b))->assertSessionHasNoErrors();
        $this->assertSame(2, Product::withoutGlobalScopes()->where('sku', 'TAPE-01')->count());
    }

    public function test_foreign_category_unit_and_warehouse_are_rejected(): void
    {
        $a = $this->makeCompany('A');
        $b = $this->makeCompany('B');
        $adminA = $this->makeUser($a);
        $foreignWh = $this->defaultWarehouse($b);
        $this->actAs($b);
        $foreignCat = Category::create(['name' => 'Ajena']);
        $foreignUnit = Unit::first();

        $this->actingAs($adminA)->post('/almacen/productos', $this->payload($a, [
            'category_id' => $foreignCat->id, 'unit_id' => $foreignUnit->id, 'default_warehouse_id' => $foreignWh->id,
        ]))->assertSessionHasErrors(['category_id', 'unit_id', 'default_warehouse_id']);
    }

    public function test_units_only_product_forces_one_unit_per_box_and_unit_alert(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);

        $this->actingAs($admin)->post('/almacen/productos', $this->payload($company, ['sku' => 'BOM', 'name' => 'Bombilla', 'handling_mode' => 'units', 'units_per_box' => 12, 'alert_unit' => 'boxes', 'min_quantity' => 5]))->assertSessionHasNoErrors();
        $this->actAs($company);
        $p = Product::where('sku', 'BOM')->first();
        $this->assertSame(1, $p->units_per_box);
        $this->assertSame('units', $p->alert_unit);
        $this->assertSame(5.0, $p->min_stock_base);
    }

    public function test_update_audits_before_and_after_and_deactivation(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);
        $p = $this->makeProduct($company);

        $this->actingAs($admin)->put("/almacen/productos/{$p->id}", $this->payload($company, ['sku' => $p->sku, 'name' => 'Tape Reforzado', 'min_quantity' => 2, 'is_active' => 1]))->assertSessionHasNoErrors();
        $log = \App\Models\AuditLog::withoutGlobalScopes()->where('action', 'updated')->where('auditable_id', $p->id)->latest('id')->first();
        $this->assertSame('Tape Transparente', $log->old_values['name']);
        $this->assertSame('Tape Reforzado', $log->new_values['name']);
        $this->assertSame(72.0, Product::find($p->id)->min_stock_base);

        $payload = $this->payload($company, ['sku' => $p->sku, 'name' => 'Tape Reforzado', 'min_quantity' => 2]);
        unset($payload['is_active']);
        $this->actingAs($admin)->put("/almacen/productos/{$p->id}", $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.deactivated', 'auditable_id' => $p->id]);
        $this->assertFalse(Product::find($p->id)->is_active);
    }

    public function test_cannot_delete_product_with_stock_but_can_without(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);
        $wh = $this->defaultWarehouse($company);
        $p = $this->makeProduct($company);
        $q = $this->makeProduct($company, ['sku' => 'OTRO', 'name' => 'Otro']);
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $p->id, 'units' => 5]], $admin);

        $this->actingAs($admin)->delete("/almacen/productos/{$p->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted($p);

        $this->actingAs($admin)->delete("/almacen/productos/{$q->id}")->assertRedirect('/almacen/productos');
        $this->assertSoftDeleted($q);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.deleted', 'auditable_id' => $q->id]);
    }

    public function test_product_page_shows_box_and_unit_stock_and_month_statistics(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);
        $wh = $this->defaultWarehouse($company);
        $p = $this->makeProduct($company);
        $this->actAs($company);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $p->id, 'boxes' => 5, 'units' => 8]], $admin);
        $svc->exit(['warehouse_id' => $wh->id, 'destination' => 'X', 'reason' => 'Y'], [['product_id' => $p->id, 'boxes' => 1]], $admin);

        $this->actingAs($admin)->get("/almacen/productos/{$p->id}")->assertOk()
            ->assertSee('4 cajas + 8 rollos')->assertSee('152 rollos')->assertSee('Disponible')
            ->assertSee('ENT-000001')->assertSee('SAL-000001')->assertSee('Entradas este mes')->assertSee('Consumo últimos 30 días');
    }

    public function test_category_and_unit_catalogs(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);

        $this->actingAs($admin)->post('/almacen/categorias', ['name' => 'Gomas'])->assertSessionHasNoErrors();
        $this->post('/almacen/categorias', ['name' => 'Gomas'])->assertSessionHasErrors('name');
        $this->post('/almacen/unidades', ['name' => 'Caja grande', 'plural' => 'Cajas grandes'])->assertSessionHasNoErrors();
        $this->get('/almacen/categorias')->assertOk()->assertSee('Gomas')->assertSee('Caja grande');

        $this->actAs($company);
        $cat = Category::where('name', 'Gomas')->first();
        $this->makeProduct($company, ['category_id' => $cat->id]);
        $this->delete("/almacen/categorias/{$cat->id}")->assertSessionHas('error');
    }
}
