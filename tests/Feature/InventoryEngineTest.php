<?php

namespace Tests\Feature;

use App\Models\InventoryMovementItem as Line;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockService;
use App\Services\Inventory\StockStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUpCompany(): array
    {
        $company = $this->makeCompany('Carmen Test');
        $wh = $this->defaultWarehouse($company);
        $user = $this->makeUser($company, 'warehouse_manager');
        $this->actAs($company);

        return [$company, $wh, $user];
    }

    // ---- conversión caja/unidad -------------------------------------------------

    public function test_converter_boxes_and_units_roundtrip(): void
    {
        [$company] = $this->setUpCompany();
        $tape = Product::find($this->makeProduct($company)->id);
        $c = app(QuantityConverter::class);

        $this->assertSame(188.0, $c->toBase($tape, 5, 8));
        $this->assertSame([5, 8.0], $c->split($tape, 188));
        $this->assertSame('5 cajas + 8 rollos', $c->format($tape, 188));
        $this->assertSame('188 rollos', $c->formatTotal($tape, 188));
        // 188 − 36 (1 caja) = 152 ; − 7 rollos = 145 → 4 cajas + 1 rollo
        $this->assertSame('4 cajas + 1 rollo', $c->format($tape, 188 - 36 - 7));
        $this->assertSame('3 cajas', $c->format($tape, 108));
        $this->assertSame('8 rollos', $c->format($tape, 8));
        $this->assertSame('0 rollos', $c->format($tape, 0));
    }

    public function test_converter_respects_handling_modes(): void
    {
        [$company] = $this->setUpCompany();
        $this->actAs($company);
        $c = app(QuantityConverter::class);
        $unitsOnly = Product::find($this->makeProduct($company, ['name' => 'Bombilla', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad'])->id);
        $boxesOnly = Product::find($this->makeProduct($company, ['name' => 'Plástico', 'handling_mode' => 'boxes', 'units_per_box' => 12])->id);

        $this->assertSame('7 unidades', $c->format($unitsOnly, 7));
        $this->assertSame('1 unidad', $c->format($unitsOnly, 1));
        $this->assertSame(24.0, $c->toBase($boxesOnly, 2, 0));
        $this->assertSame('2 cajas', $c->format($boxesOnly, 24));

        $this->expectException(\InvalidArgumentException::class);
        $c->toBase($unitsOnly, 1, 0); // cajas en un producto solo-unidades
    }

    public function test_boxes_only_product_rejects_loose_units_and_negative_values(): void
    {
        [$company] = $this->setUpCompany();
        $this->actAs($company);
        $c = app(QuantityConverter::class);
        $boxesOnly = Product::find($this->makeProduct($company, ['handling_mode' => 'boxes', 'units_per_box' => 12])->id);

        $this->assertThrows(fn () => $c->toBase($boxesOnly, 1, 3), \InvalidArgumentException::class);
        $this->assertThrows(fn () => $c->toBase($boxesOnly, -1, 0), \InvalidArgumentException::class);
        $this->assertThrows(fn () => $c->toBase($boxesOnly, 1.5, 0), \InvalidArgumentException::class);
    }

    // ---- entradas / salidas -----------------------------------------------------

    public function test_entry_increases_stock_and_writes_ledger_with_before_after(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $svc = app(StockService::class);

        $m = $svc->entry(['warehouse_id' => $wh->id, 'supplier' => 'Proveedor X', 'document_no' => 'C-1'], [['product_id' => $tape->id, 'boxes' => 5, 'units' => 8]], $user);

        $this->assertSame('ENT-000001', $m->number);
        $this->assertSame(188.0, $tape->stockInWarehouse($wh->id));
        $line = $m->items->first();
        $this->assertSame(Line::ENTRY, $line->type);
        $this->assertEquals([0.0, 188.0, 188.0], [$line->stock_before_base, $line->delta_base, $line->balance_after_base]);
        $this->assertSame($wh->id, $line->warehouse_id);
        $this->assertSame($wh->branch_id, $line->branch_id);
        $this->assertSame('Tape Transparente', $line->product_name);
        $this->assertNotNull($line->occurred_at);
    }

    public function test_exit_reduces_stock_per_spec_example(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 5, 'units' => 8]], $user); // 188

        $svc->exit(['warehouse_id' => $wh->id, 'destination' => 'Furgón 0111'], [['product_id' => $tape->id, 'boxes' => 1]], $user);
        $this->assertSame(152.0, $tape->stockInWarehouse($wh->id));
        $this->assertSame(-36.0, Line::where('type', 'exit')->first()->delta_base);

        $svc->exit(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 7]], $user);
        $this->assertSame(145.0, $tape->stockInWarehouse($wh->id));
        $this->assertSame('4 cajas + 1 rollo', app(QuantityConverter::class)->format($tape, 145));
    }

    public function test_exit_above_stock_is_rejected_and_nothing_changes(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 10]], $user);
        $before = \App\Models\InventoryMovement::count();

        $this->expectException(InventoryException::class);
        try {
            $svc->exit(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 11]], $user);
        } finally {
            $this->assertSame(10.0, $tape->stockInWarehouse($wh->id));
            $this->assertSame($before, \App\Models\InventoryMovement::count());
        }
    }

    public function test_multi_product_exit_is_atomic(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $filter = $this->makeProduct($company, ['name' => 'Filtro', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad']);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 50], ['product_id' => $filter->id, 'units' => 2]], $user);

        try {
            // la segunda línea falla → la primera NO debe aplicarse
            $svc->exit(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 10], ['product_id' => $filter->id, 'units' => 5]], $user);
            $this->fail('Debió rechazarse');
        } catch (InventoryException) {
        }
        $this->assertSame(50.0, $tape->stockInWarehouse($wh->id));
        $this->assertSame(2.0, $filter->stockInWarehouse($wh->id));
    }

    public function test_multiple_products_in_one_entry_one_document(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $plastic = $this->makeProduct($company, ['name' => 'Plástico', 'units_per_box' => 12]);
        $toner = $this->makeProduct($company, ['name' => 'Tóner', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad']);

        $m = app(StockService::class)->entry(['warehouse_id' => $wh->id], [
            ['product_id' => $tape->id, 'boxes' => 4],
            ['product_id' => $plastic->id, 'boxes' => 2, 'units' => 3],
            ['product_id' => $toner->id, 'units' => 5],
        ], $user);

        $this->assertCount(3, $m->items);
        $this->assertSame(1, \App\Models\InventoryMovement::count());
        $this->assertSame(144.0, $tape->stockInWarehouse($wh->id));
        $this->assertSame(27.0, $plastic->stockInWarehouse($wh->id));
        $this->assertSame(5.0, $toner->stockInWarehouse($wh->id));
    }

    public function test_duplicate_product_lines_are_merged(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $m = app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 5], ['product_id' => $tape->id, 'units' => 6]], $user);
        $this->assertCount(1, $m->items);
        $this->assertSame(11.0, $tape->stockInWarehouse($wh->id));
    }

    public function test_zero_quantity_is_rejected(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $this->expectException(InventoryException::class);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 0, 'units' => 0]], $user);
    }

    // ---- stock negativo ---------------------------------------------------------

    public function test_negative_stock_requires_permission_and_company_setting(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 5]], $user);

        // sin ajuste de empresa → denegado aunque el usuario tenga permiso
        $this->assertThrows(fn () => $svc->exit(['warehouse_id' => $wh->id, 'allow_negative' => true], [['product_id' => $tape->id, 'units' => 8]], $user), InventoryException::class);

        $company->update(['settings' => ['allow_negative_stock' => true]]);
        $this->actAs($company->fresh());
        // usuario sin permiso inventory.negative → denegado
        $operator = $this->makeUser($company, 'operator');
        $this->actAs($company->fresh());
        $this->assertThrows(fn () => $svc->exit(['warehouse_id' => $wh->id, 'allow_negative' => true], [['product_id' => $tape->id, 'units' => 8]], $operator), InventoryException::class);

        // con permiso + empresa + confirmación → se registra con marca de autorización
        $user->permissionOverrides()->attach(\App\Models\Permission::where('key', 'inventory.negative')->value('id'), ['granted' => true]);
        $user = $user->fresh();
        $m = $svc->exit(['warehouse_id' => $wh->id, 'allow_negative' => true], [['product_id' => $tape->id, 'units' => 8]], $user);
        $this->assertSame(-3.0, $tape->stockInWarehouse($wh->id));
        $this->assertTrue($m->negative_override);
        $this->assertSame($user->id, $m->authorized_by);
    }

    // ---- ajustes ----------------------------------------------------------------

    public function test_adjustment_creates_movement_instead_of_overwriting(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $p = $this->makeProduct($company, ['name' => 'Cinta', 'handling_mode' => 'units', 'units_per_box' => 1]);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $p->id, 'units' => 120]], $user);

        $m = $svc->adjust(['warehouse_id' => $wh->id, 'reason' => 'Conteo físico', 'notes' => 'Faltan 3 rollos'], [['product_id' => $p->id, 'units' => 117]], $user);

        $line = $m->items->first();
        $this->assertSame(Line::ADJUSTMENT_OUT, $line->type);
        $this->assertEquals([120.0, -3.0, 117.0], [$line->stock_before_base, $line->delta_base, $line->balance_after_base]);
        $this->assertSame(117.0, $p->stockInWarehouse($wh->id));
        $this->assertSame('AJU-000001', $m->number);
        $this->assertSame($user->id, $m->user_id);

        $up = $svc->adjust(['warehouse_id' => $wh->id, 'reason' => 'Conteo físico', 'notes' => 'Aparecieron'], [['product_id' => $p->id, 'units' => 120]], $user);
        $this->assertSame(Line::ADJUSTMENT_IN, $up->items->first()->type);
    }

    public function test_adjustment_requires_reason_and_notes_and_a_difference(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $p = $this->makeProduct($company, ['handling_mode' => 'units', 'units_per_box' => 1]);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $p->id, 'units' => 10]], $user);

        $this->assertThrows(fn () => $svc->adjust(['warehouse_id' => $wh->id, 'reason' => '', 'notes' => 'x'], [['product_id' => $p->id, 'units' => 9]], $user), InventoryException::class);
        $this->assertThrows(fn () => $svc->adjust(['warehouse_id' => $wh->id, 'reason' => 'Conteo', 'notes' => ''], [['product_id' => $p->id, 'units' => 9]], $user), InventoryException::class);
        $this->assertThrows(fn () => $svc->adjust(['warehouse_id' => $wh->id, 'reason' => 'Conteo', 'notes' => 'ok'], [['product_id' => $p->id, 'units' => 10]], $user), InventoryException::class);
        $this->assertSame(10.0, $p->stockInWarehouse($wh->id));
    }

    // ---- integridad -------------------------------------------------------------

    public function test_stock_always_equals_sum_of_ledger(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 10]], $user);
        $svc->exit(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 2, 'units' => 5]], $user);
        $svc->adjust(['warehouse_id' => $wh->id, 'reason' => 'Conteo', 'notes' => 'n'], [['product_id' => $tape->id, 'units' => 300]], $user);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 9]], $user);

        $this->assertCount(0, app(InventoryLedger::class)->discrepancies());
        $this->assertEquals(Line::sum('delta_base'), InventoryStock::sum('quantity_base'));

        // si alguien manipulara el stock a mano, la verificación lo detecta
        InventoryStock::query()->update(['quantity_base' => 999]);
        $this->assertCount(1, app(InventoryLedger::class)->discrepancies());
        $this->artisan('inventory:verify')->assertFailed();
    }

    public function test_ledger_rows_are_immutable(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company);
        $m = app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 10]], $user);
        $line = $m->items->first();

        $line->update(['delta_base' => 999]);
        $this->assertSame(10.0, $line->fresh()->delta_base);
        $this->assertFalse($line->delete());
        $this->assertFalse($m->delete());
        $this->assertSame(1, \App\Models\InventoryMovement::count());
    }

    public function test_stale_concurrent_exit_cannot_oversell(): void
    {
        // Simula dos empleados: el segundo "vio" 10 unidades antes de que el primero despachara 8.
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company, ['handling_mode' => 'units', 'units_per_box' => 1]);
        $svc = app(StockService::class);
        $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 10]], $user);

        $preview = $svc->preview('exit', $wh->id, [['product_id' => $tape->id, 'units' => 8]]);
        $this->assertTrue($preview[0]['ok']);          // ambos ven stock suficiente
        $svc->exit(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 8]], $user);

        $this->assertThrows(fn () => $svc->exit(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'units' => 8]], $user), InventoryException::class);
        $this->assertSame(2.0, $tape->stockInWarehouse($wh->id));
        $this->assertCount(0, app(InventoryLedger::class)->discrepancies());
    }

    // ---- alertas ----------------------------------------------------------------

    public function test_stock_status_thresholds(): void
    {
        [$company] = $this->setUpCompany();
        $this->actAs($company);
        $status = app(StockStatus::class);
        // Tape: mínimo 1 caja = 36 ; crítico = 18 (ratio 0.5)
        $tape = Product::find($this->makeProduct($company)->id);
        $this->assertSame('out', $status->for($tape, 0));
        $this->assertSame('critical', $status->for($tape, 18));
        $this->assertSame('low', $status->for($tape, 19));
        $this->assertSame('low', $status->for($tape, 36));
        $this->assertSame('available', $status->for($tape, 37));
        // Bombilla: mínimo 5 unidades
        $bulb = Product::find($this->makeProduct($company, ['name' => 'Bombilla', 'handling_mode' => 'units', 'units_per_box' => 1, 'alert_unit' => 'units', 'min_quantity' => 5, 'unit' => 'Unidad'])->id);
        $this->assertSame('low', $status->for($bulb, 5));
        $this->assertSame('critical', $status->for($bulb, 2));
        $this->assertSame('available', $status->for($bulb, 6));
        // Plástico: mínimo 2 cajas de 12
        $plastic = Product::find($this->makeProduct($company, ['name' => 'Plástico', 'units_per_box' => 12, 'min_quantity' => 2])->id);
        $this->assertSame('low', $status->for($plastic, 24));
        $this->assertSame('available', $status->for($plastic, 25));
    }

    public function test_status_sql_filter_matches_php_logic(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $svc = app(StockService::class);
        $status = app(StockStatus::class);
        $specs = ['out' => 0, 'critical' => 10, 'low' => 30, 'available' => 100];
        $ids = [];
        foreach ($specs as $expected => $units) {
            $p = $this->makeProduct($company, ['name' => ucfirst($expected)]);
            $this->actAs($company);
            if ($units) {
                $svc->entry(['warehouse_id' => $wh->id], [['product_id' => $p->id, 'units' => $units]], $user);
            }
            $ids[$expected] = $p->id;
        }
        foreach ($specs as $expected => $units) {
            $found = $status->scopeStatus(Product::query(), $expected)->pluck('id')->all();
            $this->assertSame([$ids[$expected]], $found, "filtro {$expected}");
        }
    }

    // ---- libro por rangos de fechas -------------------------------------------

    public function test_period_summary_initial_entries_exits_adjustments_final(): void
    {
        [$company, $wh, $user] = $this->setUpCompany();
        $tape = $this->makeProduct($company, ['handling_mode' => 'units', 'units_per_box' => 1]);
        $svc = app(StockService::class);
        $at = fn ($d) => \Carbon\Carbon::parse($d.' 10:00');

        $svc->entry(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-09-15')], [['product_id' => $tape->id, 'units' => 300]], $user);
        $svc->exit(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-09-20')], [['product_id' => $tape->id, 'units' => 50]], $user);  // inicial oct = 250
        $svc->entry(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-10-02')], [['product_id' => $tape->id, 'units' => 100]], $user);
        $svc->entry(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-10-20')], [['product_id' => $tape->id, 'units' => 44]], $user);   // entradas = 144
        $svc->exit(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-10-05')], [['product_id' => $tape->id, 'units' => 120]], $user);
        $svc->exit(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-10-25')], [['product_id' => $tape->id, 'units' => 60]], $user);   // salidas = 180
        $svc->adjust(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-10-30'), 'reason' => 'Conteo', 'notes' => 'n'], [['product_id' => $tape->id, 'units' => 212]], $user); // sistema 214 → -2
        $svc->entry(['warehouse_id' => $wh->id, 'occurred_at' => $at('2026-11-03')], [['product_id' => $tape->id, 'units' => 1000]], $user); // fuera del rango

        $row = app(InventoryLedger::class)->periodSummary(\Carbon\Carbon::parse('2026-10-01'), \Carbon\Carbon::parse('2026-10-31'), $wh->id)->first();

        $this->assertSame(250.0, $row->initial);
        $this->assertSame(144.0, $row->entries);
        $this->assertSame(180.0, $row->exits);
        $this->assertSame(-2.0, $row->adjustments);
        $this->assertSame(212.0, $row->final);
        $this->assertSame($row->final, $row->initial + $row->entries - $row->exits + $row->adjustments + $row->others);
        $this->assertSame(212.0, app(InventoryLedger::class)->stockAt($tape->id, \Carbon\Carbon::parse('2026-10-31 23:59')));
        $this->assertSame(250.0, app(InventoryLedger::class)->stockAt($tape->id, \Carbon\Carbon::parse('2026-09-30 23:59')));
    }
}
