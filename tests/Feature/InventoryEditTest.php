<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «Editar» una entrada/salida = anular el original y crear uno nuevo corregido (el libro nunca se modifica). */
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
        return app(StockService::class)->exit(['warehouse_id' => $w['wh']->id, 'destination' => 'Taller', 'destination_type' => 'other', 'reason' => 'Uso operativo'], [['product_id' => $w['tape']->id, 'boxes' => $boxes]], $w['user']);
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
            'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'destination_type' => 'other', 'destination' => 'Taller', 'reason' => 'Uso operativo',
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
        $exit = app(StockService::class)->exit(['warehouse_id' => $w['wh']->id, 'destination' => 'Furgón A1', 'destination_type' => 'container', 'container_id' => $old->id, 'reason' => 'Carga'],
            [['product_id' => $w['tape']->id, 'boxes' => 2]], $w['user']);
        $this->assertTrue($exit->fresh()->isEditable());

        $this->actingAs($w['user'])->get("/inventario/movimientos/{$exit->id}/editar")->assertOk()->assertSee('B2');
        $this->put("/inventario/movimientos/{$exit->id}", $this->exitPayload($w, 2, ['destination_type' => 'container', 'container_id' => $new->id, 'destination' => null, 'reason' => 'Carga']))->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $fresh = InventoryMovement::where('replaces_id', $exit->id)->firstOrFail();
        $this->assertSame($new->id, $fresh->container_id);
        $this->assertSame(8 * 36.0, $this->stock($w));
    }

    public function test_editing_an_entry_voids_the_original_and_creates_a_linked_corrected_one(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);                                                    // 144

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6))->assertSessionHasNoErrors()->assertRedirect();

        $this->actAs($w['company']);
        $e->refresh();
        $new = InventoryMovement::where('replaces_id', $e->id)->firstOrFail();
        $this->assertTrue($e->isVoided());
        $this->assertSame('entry', $new->type);
        $this->assertSame(216.0, $this->stock($w), '6 cajas, contadas una sola vez');
        $this->assertSame($new->id, $e->replacedBy->id);
        $this->assertSame($e->id, $new->replaces->id);
        $this->assertSame(3, InventoryMovement::count(), 'original + anulación + nuevo: el libro solo crece');
        $void = InventoryMovement::where('reversal_of_id', $e->id)->firstOrFail();
        $this->assertStringStartsWith('Edición: Se contó mal la mercancía', $void->reason);
        $this->assertGreaterThanOrEqual(1, AuditLog::where('action', 'inventory.edit')->count());
    }

    public function test_editing_an_entry_whose_stock_was_already_used_works_when_the_final_stock_is_not_negative(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);                                                    // 144
        $this->exit($w, 4);                                                          // 0: todo usado
        $this->assertSame(0.0, $this->stock($w));

        // Subir de 4 a 6 cajas: existencia final 72. Anular primero la original daría negativo transitorio: el orden lo evita.
        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6))->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $this->assertSame(72.0, $this->stock($w));
        $this->assertTrue($e->fresh()->isVoided());
    }

    public function test_editing_an_entry_that_would_leave_negative_stock_is_rejected_and_nothing_changes(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);                                                    // 144
        $this->exit($w, 3);                                                          // 36
        $before = InventoryMovement::count();

        // Bajar la entrada a 1 caja dejaría 36 − 144 + 36 = −72
        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 1))->assertSessionHasErrors('lines');
        $this->assertStringContainsString('No se puede editar', session('errors')->first('lines'));

        $this->actAs($w['company']);
        $this->assertFalse($e->fresh()->isVoided());
        $this->assertSame(36.0, $this->stock($w));
        $this->assertSame($before, InventoryMovement::count(), 'sin documentos a medias: se revirtió todo');
        $this->assertSame(0, InventoryMovement::where('replaces_id', $e->id)->count());
    }

    public function test_editing_an_exit_returns_the_old_quantity_and_takes_the_new_one(): void
    {
        $w = $this->world();
        $this->entry($w, 5);                                                         // 180
        $x = $this->exit($w, 2);                                                     // 108

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$x->id}", $this->exitPayload($w, 3))->assertSessionHasNoErrors()->assertRedirect();

        $this->actAs($w['company']);
        $this->assertSame(72.0, $this->stock($w), '180 − 3 cajas');
        $this->assertTrue($x->fresh()->isVoided());
        $this->assertSame('exit', InventoryMovement::where('replaces_id', $x->id)->firstOrFail()->type);
    }

    public function test_editing_an_exit_for_more_than_the_stock_is_rejected_and_the_original_stays(): void
    {
        $w = $this->world();
        $this->entry($w, 5);                                                         // 180
        $x = $this->exit($w, 2);                                                     // 108

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$x->id}", $this->exitPayload($w, 6))->assertSessionHasErrors('lines');   // 216 > 180

        $this->actAs($w['company']);
        $this->assertFalse($x->fresh()->isVoided());
        $this->assertSame(108.0, $this->stock($w));
        $this->assertSame(0, InventoryMovement::where('replaces_id', $x->id)->count());
    }

    public function test_the_reason_is_required(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6, ['edit_reason' => '']))->assertSessionHasErrors('edit_reason');

        $this->actAs($w['company']);
        $this->assertFalse($e->fresh()->isVoided());
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
        $this->assertFalse($e->fresh()->isVoided());
    }

    public function test_voided_adjustment_and_other_documents_cannot_be_edited(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);
        $adj = app(StockService::class)->adjust(['warehouse_id' => $w['wh']->id, 'reason' => 'Conteo físico', 'notes' => 'Conteo de prueba'], [['product_id' => $w['tape']->id, 'boxes' => 3]], $w['user']);

        $this->actingAs($w['user'])->get("/inventario/movimientos/{$adj->id}/editar")->assertRedirect()->assertSessionHas('error');     // ajuste: otro flujo

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6))->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 9))->assertRedirect()->assertSessionHas('error');  // ya anulado/editado
        $this->actingAs($w['user'])->get("/inventario/movimientos/{$e->id}/editar")->assertRedirect()->assertSessionHas('error');
    }

    public function test_attachments_of_the_original_follow_the_corrected_document(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);
        Attachment::create(['attachable_type' => $e->getMorphClass(), 'attachable_id' => $e->id, 'collection' => 'document', 'disk' => 'local', 'path' => 'companies/1/movements/conduce.pdf',
            'original_name' => 'conduce.pdf', 'mime' => 'application/pdf', 'size' => 10, 'user_id' => $w['user']->id]);

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6))->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $new = InventoryMovement::where('replaces_id', $e->id)->firstOrFail();
        $this->assertSame(['conduce.pdf'], $new->attachments->pluck('original_name')->all());
        $this->assertSame(1, $e->fresh()->attachments->count(), 'el original conserva el suyo');
    }

    public function test_edit_form_is_prefilled_and_detail_pages_link_both_documents(): void
    {
        $w = $this->world();
        $e = $this->entry($w, 4);

        $this->actingAs($w['user'])->get("/inventario/movimientos/{$e->id}")->assertOk()->assertSee("/inventario/movimientos/{$e->id}/editar", false);
        $this->actingAs($w['user'])->get("/inventario/movimientos/{$e->id}/editar")->assertOk()->assertSee('Motivo de la edición')->assertSee('Suplidora')->assertSee('CD-1');

        $this->actingAs($w['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($w, 6));
        $this->actAs($w['company']);
        $new = InventoryMovement::where('replaces_id', $e->id)->firstOrFail();
        $this->actingAs($w['user'])->get("/inventario/movimientos/{$e->id}")->assertOk()->assertSee($new->number)->assertSee('Editado: lo reemplaza');
        $this->actingAs($w['user'])->get("/inventario/movimientos/{$new->id}")->assertOk()->assertSee($e->number)->assertSee('Corrige (edición de)');
    }

    public function test_a_document_of_another_company_cannot_be_edited(): void
    {
        $a = $this->world();
        $e = $this->entry($a, 4);
        $b = $this->world();                                                         // otra empresa

        $this->actingAs($b['user'])->get("/inventario/movimientos/{$e->id}/editar")->assertNotFound();
        $this->actingAs($b['user'])->put("/inventario/movimientos/{$e->id}", $this->payload($b, 6))->assertNotFound();
    }
}
