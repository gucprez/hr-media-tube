<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «Editar» corrige el mismo documento (conserva su número); «anular» borra el documento y corrige el stock. */
class InventoryEditTest extends TestCase
{
    use RefreshDatabase;

    protected function world(string $role = 'company_admin'): array
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company, $role);
        $wh = $this->defaultWarehouse($company);
        $tape = $this->makeProduct($company);   // 36 por caja
        $this->actAs($company);

        return compact('company', 'user', 'wh', 'tape');
    }

    protected function entry(array $w, int $boxes)
    {
        return app(StockService::class)->entry(['warehouse_id' => $w['wh']->id, 'supplier' => 'Suplidora', 'document_no' => 'CD-1'], [['product_id' => $w['tape']->id, 'boxes' => $boxes]], $w['user']);
    }

    protected function exit(array $w, int $boxes)
    {
        return app(StockService::class)->exit(['warehouse_id' => $w['wh']->id, 'destination' => 'Taller', 'requested_by' => 'Pedro Pérez', 'destination_type' => 'other', 'reason' => 'Uso operativo'], [['product_id' => $w['tape']->id, 'boxes' => $boxes]], $w['user']);
    }

    protected function payload(array $w, int $boxes, array $extra = []): array
    {
        return $extra + [
            'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'supplier' => 'Suplidora', 'document_no' => 'CD-1',
            'lines' => [['product_id' => $w['tape']->id, 'boxes' => $boxes]], 'edit_reason' => 'Se contó mal la mercancía',
        ];
    }

    protected function exitPayload(array $w, int $boxes, array $extra = []): array
    {
        return $extra + [
            'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'requested_by' => 'Pedro Pérez', 'destination_type' => 'other', 'destination' => 'Taller', 'reason' => 'Uso operativo',
            'lines' => [['product_id' => $w['tape']->id, 'boxes' => $boxes]], 'edit_reason' => 'Cantidad mal digitada',
        ];
    }

    protected function stock(array $w): float
    {
        return $w['tape']->stockInWarehouse($w['wh']->id);
    }

    public function test_exit_can_be_reassigned_to_a_container_even_if_the_old_one_is_closed(): void
    {
        $w = $this->world();
        $this->entry($w, 10);
        $old = \App\Models\Container::create(['number' => 'A1', 'date' => now(), 'status' => 'closed', 'warehouse_id' => $w['wh']->id, 'deduct_on_close' => false]);
        $new = \App\Models\Container::create(['number' => 'B2', 'date' => now(), 'status' => 'open', 'warehouse_id' => $w['wh']->id, 'deduct_on_close' => false]);
        $exit = app(StockService::class)->exit(['warehouse_id' => $w['wh']->id, 'destination' => 'Furgón A1', 'requested_by' => 'Pedro Pérez', 'destination_type' => 'container', 'container_id' => $old->id, 'reason' => 'Carga'],
            [['product_id' => $w['tape']->id, 'boxes' => 2]], $w['user']);
        $number = $exit->number;
        $this->assertTrue($exit->fresh()->isEditable());

        $this->actingAs($w['user'])->get("/inventario/movimientos/{$exit->id}/editar")->assertOk()->assertSee('B2');
        $this->put("/inventario/movimientos/{$exit->id}", $this->exitPayload($w, 2, ['requested_by' => 'Pedro Pérez', 'destination_type' => 'container', 'container_id' => $new->id, 'destination' => null, 'reason' => 'Carga']))->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $fresh = $exit->fresh();
        $this->assertSame($new->id, $fresh->container_id);
        $this->assertSame($number, $fresh->number, 'sigue siendo el mismo documento');
        $this->assertSame(8 * 36.0, $this->stock($w));
        $this->assertSame(2, InventoryMovement::count(), 'la entrada y la salida: no se crea nada nuevo');
    }

    public function test_editing_an_entry_keeps_the_same_document_and_number(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);                                                    // 144
        $number = $e->number;

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6))->assertSessionHasNoErrors()->assertRedirect();

        $this->actAs($w['company']);
        $e->refresh();
        $this->assertSame($number, $e->number);
        $this->assertSame(1, InventoryMovement::count(), 'un solo documento: no se creó otro ni un reverso');
        $this->assertSame(216.0, $this->stock($w), '6 cajas, contadas una sola vez');
        $this->assertSame(1, $e->items()->count());
        $line = $e->items()->firstOrFail();
        $this->assertSame(0.0, $line->stock_before_base);
        $this->assertSame(216.0, $line->balance_after_base);
        $audit = AuditLog::where('action', 'inventory.edit')->firstOrFail();
        $this->assertStringContainsString('Se contó mal la mercancía', $audit->description);
        $this->assertSame(144.0, (float) $audit->old_values['lineas'][0]['delta'], 'la auditoría guarda lo que había antes');
        $this->assertSame(216.0, (float) $audit->new_values['lineas'][0]['delta']);
    }

    public function test_editing_a_document_can_be_repeated_and_recalculates_later_balances(): void
    {
        $w = $this->world();
        $a = $this->entry($w, 4);                                                    // +144
        $this->exit($w, 1);                                                          // -36 -> 108
        $this->entry($w, 2);                                                         // +72 -> 180

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$a->id}", $this->payload($w, 5))->assertSessionHasNoErrors();   // A pasa a 180
        $this->actingAs($w['user'])->put("/inventario/movimientos/{$a->id}", $this->payload($w, 6))->assertSessionHasNoErrors();   // y otra vez a 216

        $this->actAs($w['company']);
        $this->assertSame(252.0, $this->stock($w));
        $this->assertSame([216.0, 180.0, 252.0], $this->balances($w, 'balance_after_base'));
        $this->assertSame([0.0, 216.0, 180.0], $this->balances($w, 'stock_before_base'));
        $this->assertSame(3, InventoryMovement::count());
        $this->assertSame(0, $this->discrepancies());
    }

    public function test_editing_an_entry_whose_stock_was_already_used_works_when_the_history_stays_non_negative(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);                                                    // 144
        $this->exit($w, 4);                                                          // 0: todo usado
        $this->assertSame(0.0, $this->stock($w));

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6))->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $this->assertSame(72.0, $this->stock($w));
    }

    public function test_editing_an_entry_that_would_leave_negative_stock_is_rejected_and_nothing_changes(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);                                                    // 144
        $this->exit($w, 3);                                                          // 36

        // Bajar la entrada a 1 caja dejaría 36 − 144 + 36 = −72
        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 1))->assertSessionHasErrors('lines');
        $this->assertStringContainsString('No se puede editar', session('errors')->first('lines'));

        $this->actAs($w['company']);
        $this->assertSame(36.0, $this->stock($w));
        $this->assertSame(144.0, (float) $e->items()->firstOrFail()->delta_base, 'el documento quedó como estaba');
    }

    public function test_editing_an_exit_returns_the_old_quantity_and_takes_the_new_one(): void
    {
        $w = $this->world();
        $this->entry($w, 5);                                                         // 180
        $x = $this->exit($w, 2);                                                     // 108
        $number = $x->number;

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$x->id}", $this->exitPayload($w, 3))->assertSessionHasNoErrors()->assertRedirect();

        $this->actAs($w['company']);
        $this->assertSame(72.0, $this->stock($w), '180 − 3 cajas');
        $this->assertSame($number, $x->fresh()->number);
        $this->assertSame(2, InventoryMovement::count());
    }

    public function test_editing_an_exit_for_more_than_the_stock_is_rejected_and_the_original_stays(): void
    {
        $w = $this->world();
        $this->entry($w, 5);                                                         // 180
        $x = $this->exit($w, 2);                                                     // 108

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$x->id}", $this->exitPayload($w, 6))->assertSessionHasErrors('lines');   // 216 > 180

        $this->actAs($w['company']);
        $this->assertSame(108.0, $this->stock($w));
        $this->assertSame(-72.0, (float) $x->items()->firstOrFail()->delta_base);
    }

    public function test_editing_can_change_the_product_list_and_the_warehouse(): void
    {
        $w = $this->world();
        $glue = $this->makeProduct($w['company'], ['sku' => 'PEG-1', 'name' => 'Pegamento']);
        $wh2 = \App\Models\Warehouse::create(['name' => 'Bodega 2', 'code' => 'B2', 'is_active' => true]);
        $e = $this->entry($w, 4);                                                    // 144 de cinta en el almacén 1

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 3, [
            'warehouse_id' => $wh2->id, 'lines' => [['product_id' => $w['tape']->id, 'boxes' => 3], ['product_id' => $glue->id, 'boxes' => 2]],
        ]))->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $this->assertSame(0.0, $this->stock($w), 'el almacén anterior quedó en cero');
        $this->assertSame(108.0, $w['tape']->stockInWarehouse($wh2->id));
        $this->assertSame(2, $e->fresh()->items()->count());
        $this->assertSame(0, $this->discrepancies());
    }

    public function test_the_reason_is_required(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6, ['edit_reason' => '']))->assertSessionHasErrors('edit_reason');

        $this->actAs($w['company']);
        $this->assertSame(144.0, $this->stock($w));
    }

    public function test_only_users_with_the_edit_permission_can_edit(): void
    {
        $w = $this->world('warehouse_manager');                                      // inventory.* NO incluye el permiso sensible inventory.edit
        $e = $this->entry($w, 4);

        $this->actingAs($w['user'])->get("/inventario/movimientos/{$e->id}/editar")->assertForbidden();
        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6))->assertForbidden();
        $this->actingAs($w['user'])->get("/inventario/movimientos/{$e->id}")->assertOk()->assertDontSee('/editar');

        $this->actAs($w['company']);
        $this->assertSame(144.0, $this->stock($w));
    }

    public function test_adjustments_and_legacy_voided_documents_cannot_be_edited(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);
        app(StockService::class)->reverse($e, 'Anulado con el sistema anterior', $w['user']);                                           // documento anulado «a la antigua»
        $adj = app(StockService::class)->adjust(['warehouse_id' => $w['wh']->id, 'reason' => 'Conteo físico', 'notes' => 'Conteo de prueba'], [['product_id' => $w['tape']->id, 'boxes' => 3]], $w['user']);

        $this->actingAs($w['user'])->get("/inventario/movimientos/{$adj->id}/editar")->assertRedirect()->assertSessionHas('error');     // ajuste: otro flujo
        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 9))->assertRedirect()->assertSessionHas('error');
        $this->actingAs($w['user'])->get("/inventario/movimientos/{$e->id}/editar")->assertRedirect()->assertSessionHas('error');
    }

    public function test_attachments_stay_with_the_edited_document(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);
        Attachment::create(['attachable_type' => $e->getMorphClass(), 'attachable_id' => $e->id, 'collection' => 'document', 'disk' => 'local', 'path' => 'companies/1/movements/conduce.pdf',
            'original_name' => 'conduce.pdf', 'mime' => 'application/pdf', 'size' => 10, 'user_id' => $w['user']->id]);

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6))->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $this->assertSame(['conduce.pdf'], $e->fresh()->attachments->pluck('original_name')->all());
        $this->assertSame(1, Attachment::count(), 'no se duplicó');
    }

    public function test_edit_form_is_prefilled_and_the_detail_page_links_to_it(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);

        $this->actingAs($w['user'])->get("/inventario/movimientos/{$e->id}")->assertOk()->assertSee("/inventario/movimientos/{$e->id}/editar", false);
        $this->actingAs($w['user'])->get("/inventario/movimientos/{$e->id}/editar")->assertOk()->assertSee('Motivo de la edición')->assertSee('Suplidora')->assertSee('CD-1')->assertSee('conserva su número');
    }

    public function test_a_document_of_another_company_cannot_be_edited_or_voided(): void
    {
        $a = $this->world();
        $e = $this->entry($a, 4);
        $b = $this->world();                                                         // otra empresa

        $this->actingAs($b['user'])->get("/inventario/movimientos/{$e->id}/editar")->assertNotFound();
        $this->actingAs($b['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($b, 6))->assertNotFound();
        $this->actingAs($b['user'])->post("/inventario/movimientos/{$e->id}/anular", ['reason' => 'Intento cruzado'])->assertNotFound();
    }

    public function test_manual_exit_requires_the_employee_who_requested_it_and_shows_it_on_the_document(): void
    {
        $w = $this->world();
        $this->entry($w, 10);
        $base = ['date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'destination_type' => 'other', 'destination' => 'Taller', 'reason' => 'Uso',
            'lines' => [['product_id' => $w['tape']->id, 'boxes' => 1]]];

        $this->actingAs($w['user'])->post('/inventario/salidas', $base)->assertSessionHasErrors('requested_by');
        $this->assertSame(0, InventoryMovement::where('type', 'exit')->count());

        $this->post('/inventario/salidas', $base + ['requested_by' => 'María Gómez'])->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $exit = InventoryMovement::where('type', 'exit')->firstOrFail();
        $this->assertSame('María Gómez', $exit->requested_by);
        $this->actingAs($w['user'])->get("/inventario/movimientos/{$exit->id}")->assertOk()->assertSee('Solicitado por')->assertSee('María Gómez');
    }

    public function test_editing_an_exit_can_change_the_requester(): void
    {
        $w = $this->world();
        $this->entry($w, 10);
        $exit = $this->exit($w, 2);
        $this->assertSame('Pedro Pérez', $exit->fresh()->requested_by);

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$exit->id}", $this->exitPayload($w, 3, ['requested_by' => 'Luis Díaz']))->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $this->assertSame('Luis Díaz', $exit->fresh()->requested_by);
    }

    // ---------------------------------------------------------------- anular = borrar

    public function test_voiding_a_document_deletes_it_and_corrects_the_stock(): void
    {
        $w = $this->world();
        $this->entry($w, 5);                                                         // 180
        $x = $this->exit($w, 2);                                                     // 108
        Attachment::create(['attachable_type' => $x->getMorphClass(), 'attachable_id' => $x->id, 'collection' => 'document', 'disk' => 'local', 'path' => 'companies/1/movements/solicitud.pdf',
            'original_name' => 'solicitud.pdf', 'mime' => 'application/pdf', 'size' => 10, 'user_id' => $w['user']->id]);
        $number = $x->number;

        $this->actingAs($w['user'])->post("/inventario/movimientos/{$x->id}/anular", ['reason' => 'Salida registrada por error'])->assertRedirect(route('movements.index'))->assertSessionHas('success');

        $this->actAs($w['company']);
        $this->assertSame(180.0, $this->stock($w), 'el stock volvió a lo que había');
        $this->assertNull(InventoryMovement::find($x->id));
        $this->assertSame(0, \App\Models\InventoryMovementItem::where('movement_id', $x->id)->count());
        $this->assertSame(0, Attachment::count());
        $this->assertSame(1, InventoryMovement::count(), 'no queda ni el documento ni un reverso');
        $audit = AuditLog::where('action', 'inventory.delete')->firstOrFail();
        $this->assertStringContainsString($number, $audit->description);
        $this->assertStringContainsString('Salida registrada por error', $audit->description);
        $this->assertSame('Pedro Pérez', $audit->old_values['solicitado_por'], 'la auditoría conserva el contenido del documento borrado');
        $this->assertSame(0, $this->discrepancies());
    }

    public function test_voiding_recalculates_the_balances_of_the_later_lines(): void
    {
        $w = $this->world();
        $this->entry($w, 4);                                                         // 0 -> 144
        $x = $this->exit($w, 1);                                                     // 144 -> 108
        $this->entry($w, 2);                                                         // 108 -> 180

        $this->actingAs($w['user'])->post("/inventario/movimientos/{$x->id}/anular", ['reason' => 'Se anula la salida'])->assertSessionHas('success');

        $this->actAs($w['company']);
        $this->assertSame(216.0, $this->stock($w));
        $this->assertSame([0.0, 144.0], $this->balances($w, 'stock_before_base'));
        $this->assertSame([144.0, 216.0], $this->balances($w, 'balance_after_base'));
    }

    public function test_voiding_an_entry_whose_stock_was_used_is_rejected_and_nothing_is_deleted(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);                                                    // 144
        $this->exit($w, 3);                                                          // 36

        $this->actingAs($w['user'])->post("/inventario/movimientos/{$e->id}/anular", ['reason' => 'Entrada duplicada'])->assertSessionHas('error');
        $this->assertStringContainsString('No se puede anular', session('error'));

        $this->actAs($w['company']);
        $this->assertNotNull(InventoryMovement::find($e->id));
        $this->assertSame(36.0, $this->stock($w));
        $this->assertSame(2, InventoryMovement::count());
    }

    public function test_voiding_needs_a_reason_and_cannot_touch_container_documents(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);
        $this->actingAs($w['user'])->post("/inventario/movimientos/{$e->id}/anular", ['reason' => 'x'])->assertSessionHasErrors('reason');

        $c = \App\Models\Container::create(['number' => 'Z9', 'date' => now(), 'status' => 'open', 'warehouse_id' => $w['wh']->id, 'deduct_on_close' => false]);
        $consume = app(StockService::class)->consume(['warehouse_id' => $w['wh']->id, 'container_id' => $c->id, 'source_type' => $c->getMorphClass(), 'source_id' => $c->id], [['product_id' => $w['tape']->id, 'boxes' => 1]], $w['user']);
        $this->post("/inventario/movimientos/{$consume->id}/anular", ['reason' => 'Intento de anular el consumo'])->assertSessionHas('error');
        $this->actAs($w['company']);
        $this->assertNotNull(InventoryMovement::find($consume->id));
        $this->assertSame(108.0, $this->stock($w));
    }

    public function test_purging_legacy_voided_documents_removes_the_pairs_and_keeps_the_stock(): void
    {
        $w = $this->world();
        $this->entry($w, 4);                                                         // +144
        $b = $this->entry($w, 2);                                                    // +72  -> 216
        app(StockService::class)->reverse($b, 'Anulada con el sistema anterior', $w['user']);   // -72 -> 144 (original + reverso)
        $this->entry($w, 1);                                                         // +36  -> 180
        $this->assertSame(4, InventoryMovement::count());

        $dry = app(StockService::class)->purgeVoided(true);
        $this->assertSame(1, $dry['borrados']);
        $this->assertSame(4, InventoryMovement::count(), 'el ensayo no borra nada');

        $real = app(StockService::class)->purgeVoided();
        $this->assertSame(1, $real['borrados']);
        $this->assertSame(0, $real['omitidos']);
        $this->assertNull(InventoryMovement::find($b->id));
        $this->assertSame(2, InventoryMovement::count(), 'quedan solo la entrada A y la C');
        $this->assertSame(180.0, $this->stock($w), 'el stock no cambia');
        $this->assertSame([0.0, 144.0], $this->balances($w, 'stock_before_base'));
        $this->assertSame([144.0, 180.0], $this->balances($w, 'balance_after_base'));
        $this->assertSame(0, $this->discrepancies());
        $this->assertSame(1, AuditLog::where('action', 'inventory.purge')->count());
    }

    /** Saldos de las líneas del producto de prueba, en orden de registro. */
    protected function balances(array $w, string $column): array
    {
        return \App\Models\InventoryMovementItem::where('product_id', $w['tape']->id)->orderBy('id')->pluck($column)->map(fn ($v) => (float) $v)->all();
    }

    protected function discrepancies(): int
    {
        return count(app(\App\Services\Inventory\InventoryLedger::class)->discrepancies());
    }
}
