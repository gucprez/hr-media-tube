<?php

namespace Tests\Feature;

use App\Models\Container;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Ticket;
use App\Models\Vehicle;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\StockService;
use App\Services\Operations\ContainerConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerTest extends TestCase
{
    use RefreshDatabase;

    protected function world(string $role = 'company_admin'): array
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company, $role);
        $wh = $this->defaultWarehouse($company);
        $tape = $this->makeProduct($company);                                                      // 36/caja
        $plastic = $this->makeProduct($company, ['name' => 'Plástico', 'units_per_box' => 12, 'min_quantity' => 2]);
        $filter = $this->makeProduct($company, ['name' => 'Filtro', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 1]);
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [
            ['product_id' => $tape->id, 'boxes' => 5, 'units' => 8], ['product_id' => $plastic->id, 'boxes' => 3], ['product_id' => $filter->id, 'units' => 4],
        ], $user);
        $vehicle = Vehicle::create(['ficha' => 'F3', 'plate' => 'X-3', 'odometer' => 1000, 'status' => 'operational']);
        $driver = \App\Models\Driver::create(['name' => 'Pedro Chofer', 'phone' => '809-1']);

        return compact('company', 'user', 'wh', 'tape', 'plastic', 'filter', 'vehicle', 'driver');
    }

    protected function createContainer(array $w, array $materials, array $extra = []): Container
    {
        $this->actingAs($w['user'])->post('/operaciones/furgones', $extra + [
            'number' => '0111', 'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'driver_id' => $w['driver']->id, 'vehicle_id' => $w['vehicle']->id,
            'conduce' => 'CD-9', 'fuel_gallons' => 0, 'materials' => $materials,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actAs($w['company']);

        return Container::where('number', $extra['number'] ?? '0111')->firstOrFail();
    }

    public function test_container_can_be_saved_with_the_fuel_field_left_blank(): void
    {
        // El formulario real deja «Gasoil (galones)» vacío cuando es 0: antes daba error 500 (columna no admite null).
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1]], ['number' => '0113', 'fuel_gallons' => '']);

        $this->assertSame(0.0, (float) $c->fuel_gallons);

        $this->actingAs($w['user'])->put("/operaciones/furgones/{$c->id}", [
            'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'fuel_gallons' => '',
            'materials' => [['product_id' => $w['tape']->id, 'boxes' => 2]],
        ])->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $this->assertSame(0.0, (float) $c->fresh()->fuel_gallons);
    }

    public function test_empty_material_rows_are_ignored_when_saving(): void
    {
        $w = $this->world();
        $blank = ['product_id' => '', 'boxes' => '', 'units' => ''];
        $c = $this->createContainer($w, [$blank, ['product_id' => $w['tape']->id, 'boxes' => 1], $blank, $blank]);

        $this->assertCount(1, $c->materials);
        $this->assertSame($w['tape']->id, $c->materials->first()->product_id);
    }

    public function test_row_with_quantity_but_no_product_gets_a_readable_error(): void
    {
        $w = $this->world();
        $response = $this->actingAs($w['user'])->post('/operaciones/furgones', [
            'number' => '0112', 'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'fuel_gallons' => 0,
            'materials' => [['product_id' => $w['tape']->id, 'boxes' => 1], ['product_id' => '', 'boxes' => 2]],
        ]);

        $response->assertSessionHasErrors('materials.1.product_id');
        $this->assertSame('Material 2: elija un producto.', session('errors')->first('materials.1.product_id'));
        $this->actAs($w['company']);
        $this->assertNull(Container::where('number', '0112')->first(), 'con un error no se guarda nada');
    }

    public function test_create_container_with_materials_does_not_touch_stock(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1], ['product_id' => $w['plastic']->id, 'boxes' => 1, 'units' => 2], ['product_id' => $w['filter']->id, 'units' => 1]]);

        $this->assertSame('open', $c->status);
        $this->assertCount(3, $c->materials);
        $this->assertSame(36.0, (float) $c->materials->firstWhere('product_id', $w['tape']->id)->quantity_base);
        $this->assertSame(14.0, (float) $c->materials->firstWhere('product_id', $w['plastic']->id)->quantity_base);
        $this->assertSame('Pedro Chofer', $c->driver_name);
        $this->assertSame('809-1', $c->phone);
        $this->assertSame(188.0, $w['tape']->stockInWarehouse($w['wh']->id)); // nada descontado todavía
        $this->assertDatabaseHas('audit_logs', ['action' => 'container.created', 'auditable_id' => $c->id]);
    }

    public function test_auto_number_when_blank_and_unique_per_company(): void
    {
        $w = $this->world();
        $this->actingAs($w['user'])->post('/operaciones/furgones', ['date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'materials' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $this->assertSame('0001', Container::first()->number);

        $this->post('/operaciones/furgones', ['number' => '0001', 'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id])->assertSessionHasErrors('number');
    }

    /** La salida manual de almacén con destino el furgón (la que el usuario ya hizo a mano). */
    protected function manualExit(array $w, Container $c, array $lines)
    {
        $this->actAs($w['company']);

        return app(StockService::class)->exit([
            'warehouse_id' => $w['wh']->id, 'container_id' => $c->id, 'requested_by' => 'Pedro Pérez', 'destination_type' => 'container',
            'destination' => 'Furgón '.$c->number, 'reason' => 'Salida manual',
        ], $lines, $w['user']);
    }

    public function test_closing_only_deducts_what_manual_exits_linked_to_the_container_did_not(): void
    {
        $w = $this->world();
        // Lista del furgón: 2 cajas de cinta (72) + 1 caja de plástico (12)
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 2], ['product_id' => $w['plastic']->id, 'boxes' => 1]]);
        $this->manualExit($w, $c, [['product_id' => $w['tape']->id, 'boxes' => 1]]);          // ya descontó 36 a mano
        $this->assertSame(152.0, $w['tape']->stockInWarehouse($w['wh']->id));                  // 188 − 36

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertRedirect()->assertSessionHasNoErrors();

        // cinta: solo faltaban 36 (no 72); plástico: completo
        $this->assertSame(116.0, $w['tape']->stockInWarehouse($w['wh']->id));                  // 188 − 72, UNA sola vez
        $this->assertSame(24.0, $w['plastic']->stockInWarehouse($w['wh']->id));                // 36 − 12
        $this->assertCount(2, InventoryMovement::findOrFail($c->fresh()->movement_id)->items);
    }

    public function test_closing_when_everything_was_already_deducted_manually_creates_no_second_movement(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1]]);
        $this->manualExit($w, $c, [['product_id' => $w['tape']->id, 'boxes' => 1]]);

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $c->refresh();
        $this->assertSame('closed', $c->status);
        $this->assertNull($c->movement_id, 'no se crea un segundo movimiento');
        $this->assertSame(152.0, $w['tape']->stockInWarehouse($w['wh']->id));                  // 188 − 36, una sola vez
    }

    public function test_container_marked_as_record_only_does_not_touch_inventory_when_closed(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1]], ['number' => '0114', 'deduct_on_close' => 0]);
        $this->assertFalse($c->deduct_on_close);

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $c->refresh();
        $this->assertSame('closed', $c->status);
        $this->assertNull($c->movement_id);
        $this->assertSame(188.0, $w['tape']->stockInWarehouse($w['wh']->id));                  // intacto
        $this->assertCount(1, $c->materials, 'la lista de materiales se conserva como registro');
    }

    public function test_a_voided_manual_exit_no_longer_counts_as_already_deducted(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1]]);
        $exit = $this->manualExit($w, $c, [['product_id' => $w['tape']->id, 'boxes' => 1]]);
        app(StockService::class)->reverse($exit, 'Se anula la salida manual', $w['user']);
        $this->assertSame(188.0, $w['tape']->stockInWarehouse($w['wh']->id));                  // devuelta

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(152.0, $w['tape']->stockInWarehouse($w['wh']->id));                  // al cerrar sí descuenta todo
        $this->assertNotNull($c->fresh()->movement_id);
    }

    public function test_the_option_can_be_changed_while_the_container_is_open(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1]]);
        $this->assertTrue($c->deduct_on_close, 'por defecto descuenta al cerrar');

        $this->put("/operaciones/furgones/{$c->id}", [
            'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'deduct_on_close' => '0',
            'materials' => [['product_id' => $w['tape']->id, 'boxes' => 1]],
        ])->assertSessionHasNoErrors();
        $this->actAs($w['company']);

        $this->assertFalse($c->fresh()->deduct_on_close);
    }

    public function test_closing_deducts_all_materials_in_one_movement_and_keeps_history(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1], ['product_id' => $w['plastic']->id, 'boxes' => 1, 'units' => 2], ['product_id' => $w['filter']->id, 'units' => 1]]);

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $c->refresh();
        $this->assertSame('closed', $c->status);
        $this->assertNotNull($c->closed_at);
        $this->assertSame($w['user']->id, $c->closed_by);
        $this->assertSame(152.0, $w['tape']->stockInWarehouse($w['wh']->id));          // 188 − 36
        $this->assertSame(22.0, $w['plastic']->stockInWarehouse($w['wh']->id));        // 36 − 14
        $this->assertSame(3.0, $w['filter']->stockInWarehouse($w['wh']->id));
        $m = InventoryMovement::findOrFail($c->movement_id);
        $this->assertSame('container', $m->type);
        $this->assertSame($c->id, $m->container_id);
        $this->assertCount(3, $m->items);
        $this->assertTrue($m->items->every(fn ($i) => $i->type === 'container' && $i->delta_base < 0));
        $this->assertStringStartsWith('FUR-', $m->number);
        $this->assertCount(0, app(InventoryLedger::class)->discrepancies());
        // histórico del furgón sigue abriéndose con todo lo utilizado
        $this->get("/operaciones/furgones/{$c->id}")->assertOk()->assertSee('1 caja')->assertSee('1 caja + 2 rollos')->assertSee($m->number)->assertSee('Furgón 0111 cerrado');
    }

    public function test_cannot_close_when_stock_is_insufficient_and_nothing_changes(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1], ['product_id' => $w['filter']->id, 'units' => 9]]);

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertSessionHas('error');

        $c->refresh();
        $this->assertSame('open', $c->status);
        $this->assertNull($c->movement_id);
        $this->assertSame(188.0, $w['tape']->stockInWarehouse($w['wh']->id));      // la primera línea tampoco se aplicó
        $this->assertSame(4.0, $w['filter']->stockInWarehouse($w['wh']->id));
        $this->assertSame(1, InventoryMovement::count());                          // solo la entrada inicial
    }

    public function test_cannot_close_empty_container_or_edit_closed_one(): void
    {
        $w = $this->world();
        $this->actingAs($w['user'])->post('/operaciones/furgones', ['number' => '0200', 'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $empty = Container::where('number', '0200')->first();
        $this->post("/operaciones/furgones/{$empty->id}/cerrar")->assertSessionHas('error');

        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'units' => 5]], ['number' => '0201']);
        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertRedirect()->assertSessionHasNoErrors();
        $this->get("/operaciones/furgones/{$c->id}/editar")->assertForbidden();
        $this->put("/operaciones/furgones/{$c->id}", ['date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'materials' => []])->assertForbidden();
        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertForbidden();          // no se cierra dos veces
        $this->assertSame(183.0, $w['tape']->stockInWarehouse($w['wh']->id));
    }

    public function test_void_closed_container_restores_stock_and_voids_tickets(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 2]]);
        $this->post("/operaciones/furgones/{$c->id}/cerrar");
        $this->post("/operaciones/furgones/{$c->id}/tickets", ['type' => 'materials', 'issued_at' => now()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(116.0, $w['tape']->stockInWarehouse($w['wh']->id));

        $this->post("/operaciones/furgones/{$c->id}/anular", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post("/operaciones/furgones/{$c->id}/anular", ['reason' => 'Error de digitación'])->assertRedirect()->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('voided', $c->status);
        $this->assertSame('Error de digitación', $c->void_reason);
        $this->assertSame($w['user']->id, $c->voided_by);
        $this->assertSame(188.0, $w['tape']->stockInWarehouse($w['wh']->id));
        $void = InventoryMovement::findOrFail($c->void_movement_id);
        $this->assertSame('void', $void->type);
        $this->assertSame($c->movement_id, $void->reversal_of_id);
        $this->assertNotNull(InventoryMovement::find($c->movement_id)->voided_at);   // el original se conserva, marcado
        $ticket = Ticket::first();
        $this->assertSame('voided', $ticket->status);
        $this->assertStringContainsString('Furgón anulado', $ticket->void_reason);
        $this->assertCount(0, app(InventoryLedger::class)->discrepancies());
        // consumo neto del furgón = 0 (cierre + anulación)
        $this->assertCount(0, app(ContainerConsumption::class)->byProduct([$c->id]));
        $this->post("/operaciones/furgones/{$c->id}/anular", ['reason' => 'Otra vez'])->assertForbidden();
    }

    public function test_void_open_container_has_no_stock_effect(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1]]);
        $this->post("/operaciones/furgones/{$c->id}/anular", ['reason' => 'Cancelado por el cliente'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('voided', $c->fresh()->status);
        $this->assertSame(188.0, $w['tape']->stockInWarehouse($w['wh']->id));
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_void_closed_container_works_even_if_stock_was_used_up_afterwards(): void
    {
        // la anulación DEVUELVE material, así que nunca puede dejar existencia negativa
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['filter']->id, 'units' => 3]]);
        $this->post("/operaciones/furgones/{$c->id}/cerrar");                                     // filtros: 4 → 1
        app(StockService::class)->exit(['warehouse_id' => $w['wh']->id, 'destination' => 'X', 'reason' => 'Y'], [['product_id' => $w['filter']->id, 'units' => 1]], $w['user']); // → 0
        $this->post("/operaciones/furgones/{$c->id}/anular", ['reason' => 'Intento de anulación'])->assertSessionHas('success');
        $this->assertSame('voided', $c->fresh()->status);
        $this->assertSame(3.0, $w['filter']->stockInWarehouse($w['wh']->id));
        $this->assertCount(0, app(InventoryLedger::class)->discrepancies());
    }

    public function test_status_flow_open_in_progress_closed(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'units' => 1]]);
        $this->post("/operaciones/furgones/{$c->id}/iniciar")->assertSessionHas('success');
        $this->assertSame('in_progress', $c->fresh()->status);
        $this->get("/operaciones/furgones/{$c->id}/editar")->assertOk();                          // en proceso aún se edita
        $this->post("/operaciones/furgones/{$c->id}/iniciar")->assertForbidden();
        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $c->fresh()->status);
    }

    public function test_update_replaces_materials_and_audits_before_after(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'boxes' => 1]]);
        $this->put("/operaciones/furgones/{$c->id}", ['date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'vehicle_id' => $w['vehicle']->id, 'materials' => [['product_id' => $w['plastic']->id, 'boxes' => 2]]])->assertRedirect()->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertCount(1, $c->materials);
        $this->assertSame($w['plastic']->id, $c->materials->first()->product_id);
        $log = \App\Models\AuditLog::where('action', 'container.updated')->first();
        $this->assertSame('Tape Transparente', $log->old_values['materiales'][0]['producto']);
        $this->assertSame('Plástico', $log->new_values['materiales'][0]['producto']);
    }

    public function test_materials_are_validated_by_handling_mode_and_zero(): void
    {
        $w = $this->world();
        $base = ['date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id];
        $this->actingAs($w['user'])->post('/operaciones/furgones', $base + ['materials' => [['product_id' => $w['filter']->id, 'boxes' => 2]]])->assertSessionHasErrors('materials.0.units');
        $this->post('/operaciones/furgones', $base + ['materials' => [['product_id' => $w['tape']->id, 'boxes' => 0, 'units' => 0]]])->assertSessionHasErrors('materials.0.units');
        $this->assertSame(0, Container::count());
    }

    public function test_exit_with_container_destination_counts_in_container_consumption(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'units' => 1]]);
        $this->post('/inventario/salidas', ['date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'requested_by' => 'Pedro Pérez', 'destination_type' => 'container', 'container_id' => $c->id, 'reason' => 'Extra', 'lines' => [['product_id' => $w['plastic']->id, 'boxes' => 1]]])->assertRedirect()->assertSessionHasNoErrors();

        $m = InventoryMovement::where('type', 'exit')->first();
        $this->assertSame($c->id, $m->container_id);
        $this->assertSame('Furgón 0111', $m->destination);
        $net = app(ContainerConsumption::class)->byProduct([$c->id]);
        $this->assertSame(12.0, $net->firstWhere('product_id', $w['plastic']->id)->quantity_base);
        $this->get("/operaciones/furgones/{$c->id}")->assertOk()->assertSee('Otras salidas de almacén asociadas');
    }

    public function test_exit_destination_container_must_be_open_and_from_same_company(): void
    {
        $w = $this->world();
        $other = $this->makeCompany('Otra');
        $this->actAs($other);
        $foreign = Container::create(['number' => '0999', 'date' => now(), 'status' => 'open', 'warehouse_id' => $this->defaultWarehouse($other)->id]);
        $closed = $this->createContainer($w, [['product_id' => $w['tape']->id, 'units' => 1]], ['number' => '0300']);
        $this->actAs($w['company']);
        $this->post("/operaciones/furgones/{$closed->id}/cerrar");

        $payload = fn ($id) => ['date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'requested_by' => 'Pedro Pérez', 'destination_type' => 'container', 'container_id' => $id, 'reason' => 'Y', 'lines' => [['product_id' => $w['tape']->id, 'units' => 1]]];
        $this->actingAs($w['user'])->post('/inventario/salidas', $payload($foreign->id))->assertSessionHasErrors('container_id');
        $this->post('/inventario/salidas', $payload($closed->id))->assertSessionHasErrors('container_id');
        $this->post('/inventario/salidas', $payload(''))->assertSessionHasErrors('container_id');
    }

    public function test_generic_movement_void_restores_stock_and_requires_permission_and_reason(): void
    {
        $w = $this->world();
        $entry = InventoryMovement::where('type', 'entry')->first();
        $reader = $this->makeUser($w['company'], 'operator');

        $this->actingAs($reader)->post("/inventario/movimientos/{$entry->id}/anular", ['reason' => 'No autorizado'])->assertForbidden();

        // la entrada ya fue consumida parcialmente → no se puede anular sin dejar stock negativo
        app(StockService::class)->exit(['warehouse_id' => $w['wh']->id, 'destination' => 'X', 'reason' => 'Y'], [['product_id' => $w['filter']->id, 'units' => 4]], $w['user']);
        $this->actingAs($w['user'])->post("/inventario/movimientos/{$entry->id}/anular", ['reason' => 'Err'])->assertSessionHasErrors('reason');
        $this->post("/inventario/movimientos/{$entry->id}/anular", ['reason' => 'Entrada duplicada'])->assertSessionHas('error');
        $this->assertNull($entry->fresh()->voided_at);

        // una salida sí se puede anular
        $exit = InventoryMovement::where('type', 'exit')->first();
        $this->post("/inventario/movimientos/{$exit->id}/anular", ['reason' => 'Salida registrada por error'])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(4.0, $w['filter']->stockInWarehouse($w['wh']->id));
        $this->assertNull(InventoryMovement::find($exit->id), 'anular borra el documento');
        $this->post("/inventario/movimientos/{$exit->id}/anular", ['reason' => 'Otra vez'])->assertNotFound();
        $this->assertCount(0, app(InventoryLedger::class)->discrepancies());
    }

    public function test_permissions_for_containers(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'units' => 1]]);
        $reader = $this->makeUser($w['company'], 'read_only');
        $operator = $this->makeUser($w['company'], 'operator');

        $this->actingAs($reader)->get('/operaciones/furgones')->assertOk()->assertSee('0111');
        $this->get("/operaciones/furgones/{$c->id}")->assertOk()->assertDontSee('Cerrar y descontar')->assertDontSee('Anular furgón')->assertDontSee('Editar');
        $this->get('/operaciones/furgones/nuevo')->assertForbidden();
        $this->post('/operaciones/furgones', [])->assertForbidden();
        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertForbidden();
        $this->post("/operaciones/furgones/{$c->id}/anular", ['reason' => 'zzzzz'])->assertForbidden();

        $this->actingAs($operator)->get('/operaciones/furgones/nuevo')->assertOk();            // operador crea…
        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertForbidden();                // …pero no cierra
        $this->post("/operaciones/furgones/{$c->id}/anular", ['reason' => 'zzzzz'])->assertForbidden();
        $this->assertSame('open', $c->fresh()->status);
    }

    public function test_containers_are_isolated_between_companies(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'units' => 1]]);
        $b = $this->makeCompany('B');
        $userB = $this->makeUser($b);
        $whB = $this->defaultWarehouse($b);

        $this->actingAs($userB)->get('/operaciones/furgones')->assertOk()->assertDontSee('#0111');
        foreach (["/operaciones/furgones/{$c->id}", "/operaciones/furgones/{$c->id}/editar"] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertNotFound();
        $this->post("/operaciones/furgones/{$c->id}/anular", ['reason' => 'Intento cruzado'])->assertNotFound();
        $this->get("/operaciones/furgones/{$c->id}/tickets/nuevo")->assertNotFound();
        // mismo número permitido en otra empresa
        $this->post('/operaciones/furgones', ['number' => '0111', 'date' => now()->toDateString(), 'warehouse_id' => $whB->id])->assertRedirect()->assertSessionHasNoErrors();
        // vehículo / chofer de otra empresa rechazados
        $this->post('/operaciones/furgones', ['number' => '0112', 'date' => now()->toDateString(), 'warehouse_id' => $whB->id, 'vehicle_id' => $w['vehicle']->id, 'driver_id' => $w['driver']->id])->assertSessionHasErrors(['vehicle_id', 'driver_id']);
        $this->assertSame('open', $c->fresh()->status);
    }

    public function test_search_finds_containers_tickets_and_vehicles_respecting_permissions(): void
    {
        $w = $this->world();
        $c = $this->createContainer($w, [['product_id' => $w['tape']->id, 'units' => 1]]);
        $this->post("/operaciones/furgones/{$c->id}/tickets", ['type' => 'materials', 'issued_at' => now()->toDateString()]);

        $json = $this->getJson('/buscar?q=0111')->assertOk()->json();
        $labels = collect($json)->pluck('label')->all();
        $this->assertContains('Furgones', $labels);
        $this->assertContains('Tickets', $labels);
        $this->assertContains('Vehículos', collect($this->getJson('/buscar?q=F3')->json())->pluck('label')->all());

        $reader = $this->makeUser($w['company'], 'read_only');
        $reader->permissionOverrides()->attach(Permission::whereIn('key', ['containers.view', 'tickets.view'])->pluck('id'), ['granted' => false]);
        $labels = collect($this->actingAs($reader->fresh())->getJson('/buscar?q=0111')->json())->pluck('label')->all();
        $this->assertNotContains('Furgones', $labels);
        $this->assertNotContains('Tickets', $labels);
    }
}
