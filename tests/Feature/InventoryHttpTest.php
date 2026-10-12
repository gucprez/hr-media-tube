<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryMovementItem;
use App\Models\Permission;
use App\Models\Product;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InventoryHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function world(string $role = 'warehouse_manager'): array
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company, $role);
        $wh = $this->defaultWarehouse($company);
        $tape = $this->makeProduct($company);
        $plastic = $this->makeProduct($company, ['name' => 'Plástico Stretch', 'units_per_box' => 12, 'min_quantity' => 2]);
        $toner = $this->makeProduct($company, ['name' => 'Tóner HP 85A', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 2]);

        return [$company, $user, $wh, $tape, $plastic, $toner];
    }

    public function test_entry_with_several_products_and_attachment(): void
    {
        Storage::fake('local');
        [$company, $user, $wh, $tape, $plastic, $toner] = $this->world();

        $this->actingAs($user)->post('/inventario/entradas', [
            'date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'supplier' => 'Suplidora', 'document_no' => 'CD-77', 'notes' => 'Llegó completo',
            'lines' => [['product_id' => $tape->id, 'boxes' => 4], ['product_id' => $plastic->id, 'boxes' => 2, 'units' => 3], ['product_id' => $toner->id, 'units' => 5]],
            'files' => [UploadedFile::fake()->image('conduce.jpg', 1200, 900), UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf')],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->actAs($company);
        $m = InventoryMovement::with(['items', 'attachments'])->firstOrFail();
        $this->assertSame('ENT-000001', $m->number);
        $this->assertSame($user->id, $m->user_id);
        $this->assertCount(3, $m->items);
        $this->assertSame('Suplidora', $m->supplier);
        $this->assertSame(144.0, $tape->stockInWarehouse($wh->id));
        $this->assertSame(27.0, $plastic->stockInWarehouse($wh->id));
        $this->assertCount(2, $m->attachments);
        foreach ($m->attachments as $a) {
            Storage::disk('local')->assertExists($a->path);
            $this->assertSame('local', $a->disk);
        }
        $log = \App\Models\AuditLog::where('action', 'inventory.entry')->first();
        $this->assertSame($user->id, $log->user_id);
        $this->assertCount(3, $log->new_values['lineas']);
        $this->get("/inventario/movimientos/{$m->id}")->assertOk()->assertSee('ENT-000001')->assertSee('4 cajas')->assertSee('2 cajas + 3 rollos');
    }

    public function test_exit_reduces_and_over_stock_exit_is_rejected_without_side_effects(): void
    {
        [$company, $user, $wh, $tape] = $this->world();
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 5, 'units' => 8]], $user);

        $this->actingAs($user)->post('/inventario/salidas', [
            'date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'destination' => 'Furgón 0111', 'destination_type' => 'other', 'reason' => 'Consumo',
            'lines' => [['product_id' => $tape->id, 'boxes' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(152.0, $tape->stockInWarehouse($wh->id));

        $before = [InventoryMovement::count(), InventoryMovementItem::count()];
        $this->post('/inventario/salidas', [
            'date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'destination' => 'X', 'destination_type' => 'other', 'reason' => 'Y',
            'lines' => [['product_id' => $tape->id, 'boxes' => 5]],
        ])->assertSessionHasErrors('lines');
        $this->assertSame(152.0, $tape->stockInWarehouse($wh->id));
        $this->assertSame($before, [InventoryMovement::count(), InventoryMovementItem::count()]);
    }

    public function test_exit_requires_destination_and_reason(): void
    {
        [$company, $user, $wh, $tape] = $this->world();
        $this->actingAs($user)->post('/inventario/salidas', [
            'date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'destination_type' => 'other', 'lines' => [['product_id' => $tape->id, 'units' => 1]],
        ])->assertSessionHasErrors(['destination', 'reason']);
    }

    public function test_negative_override_via_http_needs_permission(): void
    {
        [$company, $user, $wh, $tape] = $this->world();
        $company->update(['settings' => ['allow_negative_stock' => true]]);
        $this->actAs($company->fresh());
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 2]], $user);
        $payload = ['date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'destination' => 'X', 'destination_type' => 'other', 'reason' => 'Y', 'allow_negative' => 1, 'lines' => [['product_id' => $tape->id, 'units' => 5]]];

        $this->actingAs($user)->post('/inventario/salidas', $payload)->assertSessionHasErrors('lines');
        $this->assertSame(2.0, $tape->stockInWarehouse($wh->id));

        $user->permissionOverrides()->attach(Permission::where('key', 'inventory.negative')->value('id'), ['granted' => true]);
        $this->actingAs($user->fresh())->post('/inventario/salidas', $payload)->assertSessionHasNoErrors();
        $this->assertSame(-3.0, $tape->stockInWarehouse($wh->id));
        $this->assertTrue(InventoryMovement::latest('id')->first()->negative_override);
    }

    public function test_adjustment_flow_requires_reason_and_observation_and_records_actor(): void
    {
        [$company, $user, $wh, $tape] = $this->world();
        $tape->update(['handling_mode' => 'units', 'units_per_box' => 1]);
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 120]], $user);
        $base = ['date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $tape->id, 'units' => 117]]];

        $this->actingAs($user)->post('/inventario/ajustes', $base)->assertSessionHasErrors(['reason', 'notes']);
        $this->post('/inventario/ajustes', $base + ['reason' => 'Conteo físico', 'notes' => 'ok'])->assertSessionHasErrors('notes'); // observación demasiado corta
        $this->assertSame(120.0, $tape->stockInWarehouse($wh->id));

        $this->post('/inventario/ajustes', $base + ['reason' => 'Conteo físico', 'notes' => 'Faltan 3 rollos en estante'])->assertSessionHasNoErrors();
        $this->assertSame(117.0, $tape->stockInWarehouse($wh->id));
        $line = InventoryMovementItem::where('type', 'adjustment_out')->first();
        $this->assertEquals([120.0, -3.0, 117.0], [$line->stock_before_base, $line->delta_base, $line->balance_after_base]);
        $this->assertSame($user->id, $line->movement->user_id);
        $this->assertSame('Conteo físico', $line->movement->reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjustment', 'user_id' => $user->id]);
    }

    public function test_adjustment_line_without_count_is_rejected(): void
    {
        [$company, $user, $wh, $tape] = $this->world();
        $this->actingAs($user)->post('/inventario/ajustes', [
            'date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'reason' => 'Conteo físico', 'notes' => 'Observación válida',
            'lines' => [['product_id' => $tape->id, 'boxes' => '', 'units' => '']],
        ])->assertSessionHasErrors('lines.0.units');
    }

    public function test_future_date_and_invalid_quantities_are_rejected(): void
    {
        [$company, $user, $wh, $tape] = $this->world();
        $ok = ['warehouse_id' => $wh->id, 'lines' => [['product_id' => $tape->id, 'units' => 1]]];

        $this->actingAs($user)->post('/inventario/entradas', $ok + ['date' => now()->addDay()->toDateString()])->assertSessionHasErrors('date');
        $this->post('/inventario/entradas', ['date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $tape->id, 'units' => -4]]])->assertSessionHasErrors('lines.0.units');
        $this->post('/inventario/entradas', ['date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $tape->id, 'boxes' => 1.5]]])->assertSessionHasErrors('lines');
        $this->post('/inventario/entradas', ['date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'lines' => []])->assertSessionHasErrors('lines');
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_units_only_product_rejects_boxes_and_boxes_only_rejects_loose_units(): void
    {
        [$company, $user, $wh, $tape, $plastic, $toner] = $this->world();
        $this->actAs($company);
        $plastic->update(['handling_mode' => 'boxes']);

        $this->actingAs($user)->post('/inventario/entradas', ['date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $toner->id, 'boxes' => 2]]])->assertSessionHasErrors('lines');
        $this->post('/inventario/entradas', ['date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $plastic->id, 'units' => 3]]])->assertSessionHasErrors('lines');
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_inactive_product_blocks_entries_but_allows_exits(): void
    {
        [$company, $user, $wh, $tape] = $this->world();
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 10]], $user);
        $tape->update(['is_active' => false]);

        $this->actingAs($user)->post('/inventario/entradas', ['date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $tape->id, 'units' => 1]]])->assertSessionHasErrors('lines');
        $this->post('/inventario/salidas', ['date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'destination' => 'X', 'destination_type' => 'other', 'reason' => 'Y', 'lines' => [['product_id' => $tape->id, 'units' => 4]]])->assertSessionHasNoErrors();
        $this->assertSame(6.0, $tape->stockInWarehouse($wh->id));
    }

    public function test_preview_and_lookup_endpoints_use_central_converter(): void
    {
        [$company, $user, $wh, $tape] = $this->world();
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 5, 'units' => 8]], $user);

        $r = $this->actingAs($user)->postJson('/inventario/api/vista-previa', ['type' => 'exit', 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $tape->id, 'boxes' => 1, 'units' => 7]]])->assertOk();
        $this->assertSame('1 caja + 7 rollos', $r->json('0.quantity_label'));
        $this->assertSame('4 cajas + 1 rollo', $r->json('0.result_label'));
        $this->assertTrue($r->json('0.ok'));

        $over = $this->postJson('/inventario/api/vista-previa', ['type' => 'exit', 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $tape->id, 'boxes' => 9]]]);
        $this->assertFalse($over->json('0.ok'));
        $this->assertStringContainsString('Stock insuficiente', $over->json('0.error'));

        $adj = $this->postJson('/inventario/api/vista-previa', ['type' => 'adjustment', 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $tape->id, 'units' => 185]]]);
        $this->assertSame('-3 rollos', $adj->json('0.delta_label'));

        $this->getJson('/inventario/api/productos?q=tape&warehouse_id='.$wh->id)->assertOk()
            ->assertJsonPath('0.sku', $tape->sku)->assertJsonPath('0.stock_label', '5 cajas + 8 rollos');
    }

    public function test_movements_ledger_filters_and_shows_balances(): void
    {
        [$company, $user, $wh, $tape, $plastic] = $this->world();
        $this->actAs($company);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 2], ['product_id' => $plastic->id, 'boxes' => 1]], $user);
        $svc->exit(['warehouse_id' => $wh->id, 'destination' => 'X', 'reason' => 'Y'], [['product_id' => $tape->id, 'units' => 10]], $user);

        $this->actingAs($user)->get('/inventario/movimientos')->assertOk()->assertSee('Tape Transparente')->assertSee('Plástico Stretch')->assertSee('1 caja + 26 rollos');
        $this->get('/inventario/movimientos?type=exit')->assertOk()->assertSee('SAL-000001')->assertDontSee('Plástico Stretch');
        $this->get('/inventario/movimientos?product='.$plastic->id)->assertOk()->assertSee('Plástico Stretch')->assertDontSee('SAL-000001');
        $this->get('/inventario/movimientos?type=nada')->assertOk()->assertSee('No hay movimientos');
    }

    public function test_document_forms_render_with_preselected_product(): void
    {
        [$company, $user, $wh, $tape] = $this->world();
        foreach (['/inventario/entradas/nueva', '/inventario/salidas/nueva', '/inventario/ajustes/nuevo'] as $url) {
            $this->actingAs($user)->get($url.'?product='.$tape->id)->assertOk()->assertSee('Tape Transparente');
        }
    }

    public function test_stock_total_always_equals_ledger_after_mixed_http_operations(): void
    {
        [$company, $user, $wh, $tape, $plastic] = $this->world();
        $d = now()->toDateString();
        $this->actingAs($user);
        $this->post('/inventario/entradas', ['date' => $d, 'warehouse_id' => $wh->id, 'lines' => [['product_id' => $tape->id, 'boxes' => 3], ['product_id' => $plastic->id, 'units' => 20]]]);
        $this->post('/inventario/salidas', ['date' => $d, 'warehouse_id' => $wh->id, 'destination' => 'A', 'destination_type' => 'other', 'reason' => 'B', 'lines' => [['product_id' => $tape->id, 'units' => 50]]]);
        $this->post('/inventario/salidas', ['date' => $d, 'warehouse_id' => $wh->id, 'destination' => 'A', 'destination_type' => 'other', 'reason' => 'B', 'lines' => [['product_id' => $plastic->id, 'units' => 999]]]); // rechazada
        $this->post('/inventario/ajustes', ['date' => $d, 'warehouse_id' => $wh->id, 'reason' => 'Conteo físico', 'notes' => 'Conteo de prueba', 'lines' => [['product_id' => $plastic->id, 'units' => 18]]]);

        $this->actAs($company);
        $this->assertCount(0, app(\App\Services\Inventory\InventoryLedger::class)->discrepancies());
        $this->assertSame(58.0, $tape->stockInWarehouse($wh->id));
        $this->assertSame(18.0, $plastic->stockInWarehouse($wh->id));
    }
}
