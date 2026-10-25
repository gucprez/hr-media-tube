<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\FuelDispatch;
use App\Models\FuelReceipt;
use App\Models\FuelTank;
use App\Models\Vehicle;
use App\Services\Fuel\FuelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Editar recepciones y despachos de gasoil en su mismo registro: la reserva de todo el historial se recalcula. */
class FuelEditTest extends TestCase
{
    use RefreshDatabase;

    protected function world(string $role = 'company_admin'): array
    {
        Storage::fake('local');
        $company = $this->makeCompany('Carmen Test');
        $user = $this->makeUser($company, $role);
        $this->actAs($company);
        app(FuelService::class)->adjust(100.0, 'Reserva inicial de prueba', $user);
        $f3 = Vehicle::create(['ficha' => 'F3', 'plate' => 'L-3', 'odometer' => 1000, 'status' => 'operational']);
        $f4 = Vehicle::create(['ficha' => 'F4', 'plate' => 'L-4', 'odometer' => 2000, 'status' => 'operational']);

        return compact('company', 'user', 'f3', 'f4');
    }

    protected function receive(array $w, float $from = 0, float $to = 50): FuelReceipt
    {
        $this->actingAs($w['user'])->post('/combustible/recepciones', ['received_at' => now()->toDateString(), 'meter_start' => $from, 'meter_end' => $to, 'supplier' => 'Suplidora X'])->assertSessionHasNoErrors();
        $this->actAs($w['company']);

        return FuelReceipt::orderByDesc('id')->firstOrFail();
    }

    protected function dispatch(array $w, float $from = 0, float $to = 20, array $extra = []): FuelDispatch
    {
        $this->actingAs($w['user'])->post('/combustible/despachos', $extra + ['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => $from, 'reading_end' => $to, 'odometer' => 1100])->assertSessionHasNoErrors();
        $this->actAs($w['company']);

        return FuelDispatch::orderByDesc('id')->firstOrFail();
    }

    protected function receiptPayload(float $from, float $to, array $extra = []): array
    {
        return $extra + ['received_at' => now()->toDateString(), 'meter_start' => $from, 'meter_end' => $to, 'supplier' => 'Suplidora X', 'edit_reason' => 'Se leyó mal el metro'];
    }

    protected function dispatchPayload(array $w, float $from, float $to, array $extra = []): array
    {
        return $extra + ['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => $from, 'reading_end' => $to, 'odometer' => 1100, 'edit_reason' => 'Se leyó mal el contador'];
    }

    protected function reserve(): float
    {
        return (float) FuelTank::first()->current_gallons;
    }

    public function test_editing_a_receipt_keeps_its_number_and_recalculates_the_later_reserve(): void
    {
        $w = $this->world();
        $r = $this->receive($w, 0, 50);                                              // 100 -> 150
        $d = $this->dispatch($w, 0, 20);                                             // 150 -> 130
        $number = $r->receipt_number;
        $this->assertSame(130.0, $this->reserve());

        $this->actingAs($w['user'])->get("/combustible/recepciones/{$r->id}/editar")->assertOk()->assertSee('Editar recepción')->assertSee($number)->assertSee('conserva su número');
        $this->put("/combustible/recepciones/{$r->id}", $this->receiptPayload(0, 60))->assertSessionHasNoErrors()->assertRedirect(route('fuel.receipts.show', $r));

        $this->actAs($w['company']);
        $r->refresh();
        $d->refresh();
        $this->assertSame($number, $r->receipt_number);
        $this->assertSame(60.0, (float) $r->gallons_received);
        $this->assertSame([100.0, 160.0], [(float) $r->reserve_before, (float) $r->reserve_after]);
        $this->assertSame([160.0, 140.0], [(float) $d->reserve_before, (float) $d->reserve_after], 'el despacho posterior se recalculó');
        $this->assertSame(140.0, $this->reserve());
        $this->assertSame(1, FuelReceipt::count(), 'no se creó otra recepción');
        $this->assertCount(0, app(FuelService::class)->discrepancies());
        $audit = AuditLog::where('action', 'fuel.receipt_edit')->firstOrFail();
        $this->assertStringContainsString('Se leyó mal el metro', $audit->description);
        $this->assertSame(50.0, (float) $audit->old_values['galones']);
        $this->assertSame(60.0, (float) $audit->new_values['galones']);
    }

    public function test_editing_a_receipt_down_is_rejected_when_the_gasoil_was_already_dispatched(): void
    {
        $w = $this->world();
        $r = $this->receive($w, 0, 50);                                              // 150
        $this->dispatch($w, 0, 140);                                                 // 10

        $this->actingAs($w['user'])->put("/combustible/recepciones/{$r->id}", $this->receiptPayload(0, 30))->assertSessionHas('error');   // 130 - 140 < 0
        $this->assertStringContainsString('No se puede editar', session('error'));

        $this->actAs($w['company']);
        $this->assertSame(50.0, (float) $r->fresh()->gallons_received, 'quedó como estaba');
        $this->assertSame(10.0, $this->reserve());
    }

    public function test_editing_a_receipt_up_is_rejected_when_it_exceeds_the_tank_capacity(): void
    {
        $w = $this->world();
        FuelTank::first()->update(['capacity_gallons' => 160]);
        $r = $this->receive($w, 0, 50);                                              // 150 (cabe)

        $this->actingAs($w['user'])->put("/combustible/recepciones/{$r->id}", $this->receiptPayload(0, 80))->assertSessionHas('error');  // 180 > 160

        $this->actAs($w['company']);
        $this->assertSame(150.0, $this->reserve());
        $this->assertSame(50.0, (float) $r->fresh()->gallons_received);
    }

    public function test_editing_a_dispatch_recalculates_gallons_reserve_and_vehicle(): void
    {
        $w = $this->world();
        $r = $this->receive($w, 0, 50);                                              // 150
        $d = $this->dispatch($w, 0, 20);                                             // 130
        $later = $this->dispatch($w, 20, 30);                                        // 120

        $this->actingAs($w['user'])->get("/combustible/despachos/{$d->id}/editar")->assertOk()->assertSee('Editar despacho');
        $this->put("/combustible/despachos/{$d->id}", $this->dispatchPayload($w, 0, 35, ['vehicle_id' => $w['f4']->id, 'odometer' => 2100]))->assertSessionHasNoErrors()->assertRedirect(route('fuel.dispatches.index'));

        $this->actAs($w['company']);
        $d->refresh();
        $later->refresh();
        $this->assertSame(35.0, (float) $d->gallons);
        $this->assertSame($w['f4']->id, $d->vehicle_id);
        $this->assertSame([150.0, 115.0], [(float) $d->reserve_before, (float) $d->reserve_after]);
        $this->assertSame([115.0, 105.0], [(float) $later->reserve_before, (float) $later->reserve_after]);
        $this->assertSame(105.0, $this->reserve());
        $this->assertSame(2100.0, (float) $w['f4']->fresh()->odometer, 'el kilometraje del vehículo sube');
        $this->assertSame(2, FuelDispatch::count());
        $this->assertCount(0, app(FuelService::class)->discrepancies());
        $this->assertSame(1, AuditLog::where('action', 'fuel.dispatch_edit')->count());
    }

    public function test_editing_a_dispatch_for_more_than_the_reserve_is_rejected(): void
    {
        $w = $this->world();
        $this->receive($w, 0, 50);                                                   // 150
        $d = $this->dispatch($w, 0, 20);                                             // 130

        $this->actingAs($w['user'])->put("/combustible/despachos/{$d->id}", $this->dispatchPayload($w, 0, 500))->assertSessionHas('error');

        $this->actAs($w['company']);
        $this->assertSame(20.0, (float) $d->fresh()->gallons);
        $this->assertSame(130.0, $this->reserve());
    }

    public function test_editing_a_dispatch_can_link_it_to_a_container_and_fills_the_container_data(): void
    {
        $w = $this->world();
        $this->receive($w, 0, 50);
        $d = $this->dispatch($w, 0, 20);
        $c = Container::create(['number' => '0076', 'date' => now(), 'status' => 'in_progress', 'warehouse_id' => $this->defaultWarehouse($w['company'])->id, 'fuel_gallons' => 0]);

        $this->actingAs($w['user'])->put("/combustible/despachos/{$d->id}", $this->dispatchPayload($w, 0, 20, ['container_id' => $c->id]))->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $this->assertSame($c->id, $d->fresh()->container_id);
        $this->assertSame(20.0, (float) $c->fresh()->fuel_gallons);
    }

    public function test_voided_and_container_dispatches_cannot_be_edited(): void
    {
        $w = $this->world();
        $this->receive($w, 0, 50);
        $voided = $this->dispatch($w, 0, 10);
        $this->post("/combustible/despachos/{$voided->id}/anular", ['reason' => 'Se anula para la prueba']);
        $this->actAs($w['company']);
        $fromContainer = app(FuelService::class)->dispatch(['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'gallons' => 5, 'origin' => 'container'], $w['user']);

        $this->actingAs($w['user'])->get("/combustible/despachos/{$voided->id}/editar")->assertRedirect()->assertSessionHas('error');
        $this->put("/combustible/despachos/{$voided->id}", $this->dispatchPayload($w, 0, 12))->assertRedirect()->assertSessionHas('error');
        $this->get("/combustible/despachos/{$fromContainer->id}/editar")->assertRedirect()->assertSessionHas('error');
        $this->put("/combustible/despachos/{$fromContainer->id}", $this->dispatchPayload($w, 0, 12))->assertRedirect()->assertSessionHas('error');

        $voidedReceipt = $this->receive($w, 50, 60);
        $this->post("/combustible/recepciones/{$voidedReceipt->id}/anular", ['reason' => 'Se anula para la prueba']);
        $this->get("/combustible/recepciones/{$voidedReceipt->id}/editar")->assertRedirect()->assertSessionHas('error');
        $this->put("/combustible/recepciones/{$voidedReceipt->id}", $this->receiptPayload(50, 70))->assertRedirect()->assertSessionHas('error');
    }

    public function test_the_reason_is_required_and_only_users_with_fuel_edit_can_edit(): void
    {
        $w = $this->world();
        $r = $this->receive($w, 0, 50);
        $this->actingAs($w['user'])->put("/combustible/recepciones/{$r->id}", $this->receiptPayload(0, 60, ['edit_reason' => '']))->assertSessionHasErrors('edit_reason');
        $this->actAs($w['company']);
        $this->assertSame(50.0, (float) $r->fresh()->gallons_received);

        $operator = $this->makeUser($w['company'], 'fuel');                           // fuel.* NO incluye el permiso sensible fuel.edit
        $this->actingAs($operator)->get("/combustible/recepciones/{$r->id}/editar")->assertForbidden();
        $this->put("/combustible/recepciones/{$r->id}", $this->receiptPayload(0, 60))->assertForbidden();
        $this->get("/combustible/recepciones/{$r->id}")->assertOk()->assertDontSee('/editar');
    }

    public function test_a_record_of_another_company_cannot_be_edited(): void
    {
        $a = $this->world();
        $r = $this->receive($a, 0, 50);
        $b = $this->world();

        $this->actingAs($b['user'])->get("/combustible/recepciones/{$r->id}/editar")->assertNotFound();
        $this->put("/combustible/recepciones/{$r->id}", $this->receiptPayload(0, 60))->assertNotFound();
    }
}
