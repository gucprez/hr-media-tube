<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Services\Inventory\StockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_cannot_be_changed_directly_through_eloquent(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $wh = $this->defaultWarehouse($company);
        $p = $this->makeProduct($company);
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $p->id, 'units' => 10]], $user);
        $stock = InventoryStock::first();

        $this->assertThrows(fn () => $stock->update(['quantity_base' => 999]), \LogicException::class);
        $this->assertThrows(fn () => $stock->delete(), \LogicException::class);
        $this->assertThrows(fn () => InventoryStock::create(['warehouse_id' => $wh->id, 'product_id' => $p->id, 'quantity_base' => 5]), \LogicException::class);
        $this->assertSame(10.0, $p->stockInWarehouse($wh->id));
    }

    public function test_dashboard_shows_real_numbers_and_no_invented_figures(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $wh = $this->defaultWarehouse($company);

        // empresa vacía: ceros reales y módulos futuros marcados "Próximamente"
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Productos registrados')->assertSee('Aún no hay movimientos')
            ->assertSee('Próximamente')->assertSee('Furgones este mes')->assertSee('Gasoil disponible')->assertDontSee('galones');

        $tape = $this->makeProduct($company);                                                                 // mínimo 36
        $bulb = $this->makeProduct($company, ['name' => 'Bombilla', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 5]);
        $filter = $this->makeProduct($company, ['name' => 'Filtro Aire', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 2]);
        $this->makeProduct($company, ['name' => 'Aceite Agotado']);
        $this->actAs($company);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 5, 'units' => 8], ['product_id' => $bulb->id, 'units' => 4], ['product_id' => $filter->id, 'units' => 1]], $user);
        $svc->exit(['warehouse_id' => $wh->id, 'destination' => 'X', 'reason' => 'Y'], [['product_id' => $tape->id, 'boxes' => 1]], $user);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('>4<', preg_replace('/\s+/', '', $html) ? $html : $html);
        $this->actingAs($user)->get('/dashboard')
            ->assertSee('157')->assertSee('Entradas del mes')->assertSee('193 unidades ingresadas')->assertSee('36 unidades despachadas')
            ->assertSee('Bombilla')->assertSee('Filtro Aire')->assertSee('Aceite Agotado')->assertSee('Agotado')->assertSee('Crítico')->assertSee('Stock bajo')
            ->assertSee('ENT-000001')->assertSee('SAL-000001');
    }

    public function test_dashboard_for_user_without_inventory_permission_hides_inventory_cards(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company, 'read_only');
        $user->permissionOverrides()->attach(\App\Models\Permission::whereIn('key', ['products.view', 'inventory.view'])->pluck('id'), ['granted' => false]);

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->assertDontSee('Productos registrados')->assertSee('Próximos módulos');
    }

    public function test_current_inventory_report_columns_filters_and_print(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $wh = $this->defaultWarehouse($company);
        $this->actAs($company);
        $cat = \App\Models\Category::create(['name' => 'Embalaje']);
        $tape = $this->makeProduct($company, ['sku' => 'TAPE-1', 'category_id' => $cat->id, 'aisle' => 'A', 'shelf' => '1']);
        $bulb = $this->makeProduct($company, ['sku' => 'BOM-1', 'name' => 'Bombilla', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 5]);
        $this->makeProduct($company, ['sku' => 'VACIO-1', 'name' => 'Sin existencia']);
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 5, 'units' => 8], ['product_id' => $bulb->id, 'units' => 3]], $user);

        $this->actingAs($user)->get('/reportes/almacen/inventario-actual')->assertOk()
            ->assertSeeInOrder(['Código', 'Foto', 'Producto', 'Categoría', 'Cajas', 'Unidades', 'Total unid.', 'Mínimo', 'Estado', 'Ubicación'])
            ->assertSee('TAPE-1')->assertSee('188 rollos')->assertSee('Embalaje')->assertSee('Pasillo A · Estante 1')
            ->assertSee('Imprimir')->assertSee('Reporte de inventario')->assertSee('Preparado por')->assertSee('Revisado por')->assertSee('Aprobado por')
            ->assertSee($company->name)->assertSee($user->name);

        $names = fn (string $qs) => $this->get('/reportes/almacen/inventario-actual'.$qs)->assertOk()->viewData('products')->pluck('sku')->sort()->values()->all();
        $this->assertSame(['VACIO-1'], $names('?status=out'));
        $this->assertSame(['BOM-1'], $names('?status=low'));
        $this->assertSame(['TAPE-1'], $names('?category='.$cat->id));
        $this->assertSame(['BOM-1'], $names('?product='.$bulb->id));
        $this->assertSame(['TAPE-1'], $names('?warehouse='.$wh->id.'&q=tape'));
        $this->assertSame(['BOM-1', 'TAPE-1', 'VACIO-1'], $names(''));
    }

    public function test_status_filter_per_warehouse(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $wh1 = $this->defaultWarehouse($company);
        $this->actAs($company);
        $wh2 = \App\Models\Warehouse::create(['name' => 'Bodega 2', 'code' => 'B2', 'is_active' => true]);
        $p = $this->makeProduct($company, ['name' => 'Cinta', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 5]);
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh1->id], [['product_id' => $p->id, 'units' => 50]], $user);
        app(StockService::class)->entry(['warehouse_id' => $wh2->id], [['product_id' => $p->id, 'units' => 2]], $user);

        $names = fn (string $qs) => $this->get('/reportes/almacen/inventario-actual'.$qs)->assertOk()->viewData('products')->pluck('name')->all();
        // total 52 = disponible; solo en Bodega 2 (2 unidades, mínimo 5) = crítico
        $this->actingAs($user);
        $this->assertSame(['Cinta'], $names('?status=available'));
        $this->assertSame(['Cinta'], $names('?status=critical&warehouse='.$wh2->id));
        $this->assertSame([], $names('?status=critical&warehouse='.$wh1->id));
        $this->assertSame([], $names('?status=critical'));
        $this->get('/reportes/almacen/inventario-actual?warehouse='.$wh2->id)->assertSee('2 unidades');
    }

    public function test_movement_report_matches_spec_example(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $wh = $this->defaultWarehouse($company);
        $tape = $this->makeProduct($company, ['handling_mode' => 'units', 'units_per_box' => 1, 'sku' => 'TAPE-X']);
        $this->actAs($company);
        $svc = app(StockService::class);
        $at = fn ($d) => Carbon::parse($d.' 10:00');
        $svc->entry(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-09-10')], [['product_id' => $tape->id, 'units' => 300]], $user);
        $svc->exit(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-09-12')], [['product_id' => $tape->id, 'units' => 50]], $user);
        $svc->entry(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-10-03')], [['product_id' => $tape->id, 'units' => 144]], $user);
        $svc->exit(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-10-10')], [['product_id' => $tape->id, 'units' => 180]], $user);
        $svc->adjust(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-10-31'), 'reason' => 'Conteo físico', 'notes' => 'ajuste de prueba'], [['product_id' => $tape->id, 'units' => 212]], $user);
        $svc->entry(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-11-05')], [['product_id' => $tape->id, 'units' => 500]], $user);

        $r = $this->actingAs($user)->get('/reportes/almacen/movimiento-inventario?from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertSee('Movimiento de inventario')->assertSee('Del 01/10/2026 al 31/10/2026')->assertSee('TAPE-X');
        $html = preg_replace('/\s+/', ' ', strip_tags($r->getContent()));
        $this->assertStringContainsString('250 144 180 -2', $html);   // inicial, entradas, salidas, ajustes
        $this->assertStringContainsString('212', $html);              // final
        $this->assertStringNotContainsString('712', $html);           // el ingreso de noviembre no cuenta

        $this->get('/reportes/almacen/movimiento-inventario?from=2026-10-31&to=2026-10-01')->assertSessionHasErrors('to');
    }

    public function test_search_by_name_code_barcode_and_category(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $this->actAs($company);
        $cat = \App\Models\Category::create(['name' => 'Lubricantes']);
        $this->makeProduct($company, ['sku' => 'ACE-15W40', 'name' => 'Aceite Motor', 'barcode' => '7461234567890', 'category_id' => $cat->id]);

        $this->actingAs($user);
        $this->getJson('/buscar?q=aceite')->assertOk()->assertJsonPath('0.label', 'Productos')->assertJsonPath('0.results.0.title', 'Aceite Motor');
        $this->getJson('/buscar?q=15W40')->assertJsonPath('0.results.0.title', 'Aceite Motor');
        $this->getJson('/buscar?q=7461234')->assertJsonPath('0.results.0.title', 'Aceite Motor');
        $json = $this->getJson('/buscar?q=lubric')->assertOk()->json();
        $this->assertSame('Categorías', collect($json)->firstWhere('label', 'Categorías')['label']);
        $this->getJson('/buscar?q=a')->assertExactJson([]);            // mínimo 2 caracteres
        $this->getJson('/buscar?q=%25')->assertExactJson([]);          // comodín LIKE escapado
        $this->get('/buscar?q=aceite')->assertOk()->assertSee('Aceite Motor')->assertSee('ACE-15W40');
        $this->get('/buscar?q=zzzz')->assertOk()->assertSee('No se encontraron resultados');
    }

    public function test_search_requires_permission(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company, 'read_only');
        $user->permissionOverrides()->attach(\App\Models\Permission::where('key', 'search.use')->value('id'), ['granted' => false]);
        $this->actingAs($user->fresh())->get('/buscar?q=tape')->assertForbidden();
    }

    public function test_global_search_is_extensible_with_new_providers(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $search = app(\App\Support\Search\GlobalSearch::class);
        $search->register(new class implements \App\Support\Search\SearchProvider {
            public function key(): string { return 'containers'; }
            public function label(): string { return 'Furgones'; }
            public function permission(): string { return 'containers.view'; }
            public function search(string $term, int $limit): \Illuminate\Support\Collection
            {
                return collect([['title' => 'Furgón 0111', 'subtitle' => null, 'url' => '/x', 'badge' => null, 'image' => null]]);
            }
        });

        $this->assertArrayHasKey('containers', $search->search($user, 'furgon'));
        $reader = $this->makeUser($company, 'read_only');
        $reader->permissionOverrides()->attach(\App\Models\Permission::where('key', 'containers.view')->value('id'), ['granted' => false]);
        $this->assertArrayNotHasKey('containers', $search->search($reader->fresh(), 'furgon'));
    }

    public function test_malicious_product_name_is_escaped_everywhere(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $wh = $this->defaultWarehouse($company);
        $evil = "x');alert(1);//<script>alert(2)</script>";
        $p = $this->makeProduct($company, ['name' => $evil, 'sku' => 'EVIL']);
        $this->actAs($company);
        $movement = app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $p->id, 'units' => 3]], $user);

        foreach (["/almacen/productos/{$p->id}", '/almacen/productos', '/inventario', '/inventario/movimientos', '/dashboard', '/reportes/almacen/inventario-actual', "/inventario/movimientos/{$movement->id}", '/buscar?q=EVIL'] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(2)', $html, $url);
            $this->assertStringNotContainsString("');alert(1)", $html, $url);
        }
        $this->actingAs($user)->getJson('/buscar?q=EVIL')->assertOk(); // JSON: el cliente lo pinta con x-text (nunca como HTML)
    }
}
