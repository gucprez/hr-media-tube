<?php

namespace Tests\Feature;

use App\Models\Container;
use App\Models\FuelDispatch;
use App\Models\FuelLedger;
use App\Models\FuelReceipt;
use App\Models\FuelTank;
use App\Models\Vehicle;
use App\Services\Fleet\VehicleFile;
use App\Services\Fuel\FuelException;
use App\Services\Fuel\FuelService;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FuelTest extends TestCase
{
    use RefreshDatabase;

    protected function world(float $reserve = 108.0, string $role = 'company_admin'): array
    {
        Storage::fake('local');
        $company = $this->makeCompany('Carmen Test');
        $user = $this->makeUser($company, $role);
        $this->actAs($company);
        $fuel = app(FuelService::class);
        if ($reserve > 0) {
            $fuel->adjust($reserve, 'Reserva inicial de prueba', $user);
        }
        $f3 = Vehicle::create(['ficha' => 'F3', 'plate' => 'L-3', 'odometer' => 123000, 'status' => 'operational']);

        return compact('company', 'user', 'fuel', 'f3');
    }

    protected function receipt(array $w, array $extra = [])
    {
        return $this->actingAs($w['user'])->post('/combustible/recepciones', $extra + [
            'received_at' => now()->toDateString(), 'meter_start' => 1842237, 'meter_end' => 1843038, 'supplier' => 'Suplidora X', 'truck_plate' => 'L-445566',
        ]);
    }

    public function test_client_real_example_receipt_and_dispatch_numbers(): void
    {
        $w = $this->world(108.0);

        $this->receipt($w)->assertRedirect()->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $r = FuelReceipt::firstOrFail();
        $this->assertSame(801.0, $r->gallons_received);        // 1,843,038 − 1,842,237
        $this->assertSame(108.0, $r->reserve_before);
        $this->assertSame(909.0, $r->reserve_after);
        $this->assertSame('REC-0001', $r->receipt_number);
        $this->assertSame('Suplidora X', $r->supplier);
        $this->assertSame($w['user']->id, $r->user_id);

        $this->post('/combustible/despachos', ['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => 123880.2, 'reading_end' => 123890.5, 'odometer' => 123500.0])
            ->assertRedirect()->assertSessionHasNoErrors();
        $d = FuelDispatch::firstOrFail();
        $this->assertSame(10.3, $d->gallons);
        $this->assertSame(909.0, $d->reserve_before);
        $this->assertSame(898.7, $d->reserve_after);
        $this->assertSame(898.7, FuelTank::first()->current_gallons);
        $this->assertSame($w['f3']->id, $d->vehicle_id);
        $this->assertSame(123500.0, $d->odometer);
        $this->assertSame(123500.0, $w['f3']->fresh()->odometer);     // kilometraje del vehículo actualizado
        $this->assertCount(0, app(FuelService::class)->discrepancies());
        $this->assertDatabaseHas('audit_logs', ['action' => 'fuel.receipt']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fuel.dispatch']);
    }

    public function test_gallons_are_always_calculated_never_trusted_from_the_client(): void
    {
        $w = $this->world(0);
        $this->receipt($w, ['gallons_received' => 99999, 'reserve_after' => 5, 'gallons' => 1]);
        $this->actAs($w['company']);
        $this->assertSame(801.0, FuelReceipt::first()->gallons_received);
        $this->assertSame(801.0, FuelTank::first()->current_gallons);
    }

    public function test_receipt_validation_and_capacity(): void
    {
        $w = $this->world(100.0);
        $this->receipt($w, ['meter_end' => 1842000])->assertSessionHasErrors('meter_end');
        $this->receipt($w, ['meter_end' => 1842237])->assertSessionHasErrors('meter_end');
        $this->receipt($w, ['received_at' => now()->addDay()->toDateString()])->assertSessionHasErrors('received_at');

        $this->actAs($w['company']);
        FuelTank::first()->update(['capacity_gallons' => 500]);
        $this->receipt($w)->assertSessionHas('error');                  // 100 + 801 > 500
        $this->assertSame(100.0, FuelTank::first()->current_gallons);
        $this->assertSame(0, FuelReceipt::count());
    }

    public function test_receipt_numbers_auto_or_manual_and_unique(): void
    {
        $w = $this->world(0);
        $this->receipt($w, ['meter_start' => 0, 'meter_end' => 100]);
        $this->receipt($w, ['meter_start' => 100, 'meter_end' => 200, 'receipt_number' => '2026-77']);
        $this->receipt($w, ['meter_start' => 200, 'meter_end' => 300, 'receipt_number' => '2026-77'])->assertSessionHasErrors('receipt_number');
        $this->receipt($w, ['meter_start' => 300, 'meter_end' => 400]);
        $this->actAs($w['company']);
        $this->assertSame(['REC-0001', '2026-77', 'REC-0002'], FuelReceipt::orderBy('id')->pluck('receipt_number')->all());
    }

    public function test_receipt_photos_are_stored_privately_by_type(): void
    {
        $w = $this->world(0);
        $this->receipt($w, [
            'meter' => [UploadedFile::fake()->image('m.jpg', 800, 600)], 'truck' => [UploadedFile::fake()->image('t.jpg', 800, 600)],
            'invoice' => [UploadedFile::fake()->image('f.jpg', 800, 600)], 'conduce' => [UploadedFile::fake()->image('c.jpg', 800, 600)],
        ])->assertRedirect();
        $this->actAs($w['company']);
        $r = FuelReceipt::with('attachments')->first();
        $this->assertEqualsCanonicalizing(['meter', 'truck', 'invoice', 'conduce'], $r->attachments->pluck('collection')->all());
        foreach ($r->attachments as $a) {
            Storage::disk('local')->assertExists($a->path);
            $this->get("/archivos/adjunto/{$a->id}")->assertOk();
        }
        $this->get("/combustible/recepciones/{$r->id}")->assertOk()->assertSee('Medidor')->assertSee('Factura');
    }

    public function test_dispatch_rules(): void
    {
        $w = $this->world(50.0);
        $base = ['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => 100, 'reading_end' => 110];

        $this->actingAs($w['user'])->post('/combustible/despachos', array_merge($base, ['reading_end' => 100]))->assertSessionHasErrors('reading_end');
        $this->post('/combustible/despachos', array_merge($base, ['vehicle_id' => null]))->assertSessionHasErrors('vehicle_id');
        $this->post('/combustible/despachos', array_merge($base, ['reading_end' => 200]))->assertSessionHas('error');        // 100 gal > 50 de reserva
        $this->post('/combustible/despachos', array_merge($base, ['odometer' => 5000]))->assertSessionHas('error');          // km menor al del vehículo (123000)
        $this->actAs($w['company']);
        $this->assertSame(50.0, FuelTank::first()->current_gallons);
        $this->assertSame(0, FuelDispatch::count());
        $this->post('/combustible/despachos', array_merge($base, ['odometer' => 123400]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(40.0, FuelTank::first()->current_gallons);
    }

    public function test_reserve_can_never_go_negative_even_with_sequential_requests(): void
    {
        $w = $this->world(20.0);
        $svc = app(FuelService::class);
        $svc->dispatch(['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => 0, 'reading_end' => 12], $w['user']);
        $this->assertThrows(fn () => $svc->dispatch(['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => 12, 'reading_end' => 24], $w['user']), FuelException::class);
        $this->assertSame(8.0, FuelTank::first()->current_gallons);
        $this->assertCount(0, $svc->discrepancies());
    }

    public function test_void_receipt_and_dispatch_restore_reserve_with_reason_and_user(): void
    {
        $w = $this->world(100.0);
        $svc = app(FuelService::class);
        $rec = $svc->receive(['received_at' => now()->toDateString(), 'meter_start' => 0, 'meter_end' => 50], $w['user']);        // 150
        $dis = $svc->dispatch(['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => 0, 'reading_end' => 30], $w['user']); // 120

        $this->actingAs($w['user'])->post("/combustible/despachos/{$dis->id}/anular", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post("/combustible/despachos/{$dis->id}/anular", ['reason' => 'Lectura mal digitada'])->assertSessionHas('success');
        $dis->refresh();
        $this->assertNotNull($dis->voided_at);
        $this->assertSame($w['user']->id, $dis->voided_by);
        $this->assertSame('Lectura mal digitada', $dis->void_reason);
        $this->assertSame(150.0, FuelTank::first()->current_gallons);

        $this->post("/combustible/recepciones/{$rec->id}/anular", ['reason' => 'Recepción duplicada'])->assertSessionHas('success');
        $this->assertSame(100.0, FuelTank::first()->current_gallons);
        $this->post("/combustible/recepciones/{$rec->id}/anular", ['reason' => 'Otra vez más'])->assertSessionHas('error');
        $this->assertSame(1, FuelReceipt::count());                                           // nada se borra
        $this->assertSame('void_dispatch', FuelLedger::where('type', 'void_dispatch')->first()->type);
        $this->assertCount(0, $svc->discrepancies());
    }

    public function test_cannot_void_receipt_whose_gasoil_was_already_dispatched(): void
    {
        $w = $this->world(0);
        $svc = app(FuelService::class);
        $rec = $svc->receive(['received_at' => now()->toDateString(), 'meter_start' => 0, 'meter_end' => 100], $w['user']);
        $svc->dispatch(['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => 0, 'reading_end' => 70], $w['user']);

        $this->actingAs($w['user'])->post("/combustible/recepciones/{$rec->id}/anular", ['reason' => 'Intento de anular'])->assertSessionHas('error');
        $this->assertNull($rec->fresh()->voided_at);
        $this->assertSame(30.0, FuelTank::first()->current_gallons);
    }

    public function test_adjustment_requires_reason_and_records_difference(): void
    {
        $w = $this->world(100.0);
        $this->actingAs($w['user'])->post('/combustible/ajuste', ['physical' => 95])->assertSessionHasErrors('reason');
        $this->post('/combustible/ajuste', ['physical' => 100, 'reason' => 'Medición con varilla'])->assertSessionHas('error');   // sin diferencia
        $this->post('/combustible/ajuste', ['physical' => 95.5, 'reason' => 'Medición con varilla'])->assertSessionHas('success');
        $this->actAs($w['company']);
        $line = FuelLedger::where('type', 'adjustment')->latest('id')->first();
        $this->assertEquals([-4.5, 100.0, 95.5], [$line->delta_gallons, $line->reserve_before, $line->reserve_after]);
        $this->assertSame($w['user']->id, $line->user_id);
        $this->assertSame('Medición con varilla', $line->reason);
        $this->assertSame(95.5, FuelTank::first()->current_gallons);
    }

    public function test_reserve_cannot_be_edited_directly(): void
    {
        $w = $this->world(100.0);
        $tank = FuelTank::first();
        $this->assertThrows(fn () => $tank->update(['current_gallons' => 9999]), \LogicException::class);
        $this->assertThrows(fn () => FuelTank::create(['name' => 'Trampa', 'current_gallons' => 500]), \LogicException::class);
        $this->assertSame(100.0, FuelTank::first()->current_gallons);
        FuelTank::first()->update(['name' => 'Renombrado', 'capacity_gallons' => 3000]);    // otros campos sí
        $this->assertSame('Renombrado', FuelTank::first()->name);
        // ledger inmutable
        $line = FuelLedger::first();
        $line->update(['delta_gallons' => 1]);
        $this->assertSame(100.0, $line->fresh()->delta_gallons);
    }

    public function test_verify_detects_tampering_and_reserve_history_is_rebuilt_from_ledger(): void
    {
        $w = $this->world(100.0);
        $svc = app(FuelService::class);
        $svc->receive(['received_at' => now()->subDays(10)->toDateString(), 'meter_start' => 0, 'meter_end' => 50], $w['user']);
        $svc->dispatch(['dispatched_at' => now()->subDays(2)->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => 0, 'reading_end' => 20], $w['user']);

        $this->assertSame(50.0, $svc->reserveAt(now()->subDays(5)));   // la reserva inicial (ajuste) se registró hoy
        $this->assertSame(130.0, $svc->reserveAt(now()));
        $this->artisan('fuel:verify')->assertSuccessful();
        \DB::table('fuel_tanks')->update(['current_gallons' => 999]);
        $this->assertCount(1, $svc->discrepancies());
        $this->artisan('fuel:verify')->assertFailed();
    }

    public function test_closing_container_dispatches_its_gasoil_and_voiding_restores_it(): void
    {
        $w = $this->world(100.0);
        $wh = $this->defaultWarehouse($w['company']);
        $tape = $this->makeProduct($w['company']);
        $this->actAs($w['company']);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 5]], $w['user']);
        $post = fn (array $e = []) => $this->actingAs($w['user'])->post('/operaciones/furgones', $e + ['number' => '0111', 'date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'vehicle_id' => $w['f3']->id, 'fuel_gallons' => 25, 'materials' => [['product_id' => $tape->id, 'units' => 10]]])->assertRedirect();
        $post();
        $this->actAs($w['company']);
        $c = Container::where('number', '0111')->first();

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertSessionHas('success');
        $this->assertSame(75.0, FuelTank::first()->current_gallons);
        $d = FuelDispatch::where('container_id', $c->id)->first();
        $this->assertSame('container', $d->origin);
        $this->assertSame(25.0, $d->gallons);
        $this->assertNull($d->reading_start);
        $this->assertSame($w['f3']->id, $d->vehicle_id);
        $this->assertSame(170.0, $tape->stockInWarehouse($wh->id));

        // el despacho del furgón no se anula suelto
        $this->post("/combustible/despachos/{$d->id}/anular", ['reason' => 'Intento directo'])->assertSessionHas('error');

        $this->post("/operaciones/furgones/{$c->id}/anular", ['reason' => 'Furgón cancelado'])->assertSessionHas('success');
        $this->assertSame(100.0, FuelTank::first()->current_gallons);
        $this->assertNotNull($d->fresh()->voided_at);
        $this->assertSame(180.0, $tape->stockInWarehouse($wh->id));
        $this->assertCount(0, app(FuelService::class)->discrepancies());
    }

    public function test_container_without_enough_reserve_cannot_close_and_inventory_is_untouched(): void
    {
        $w = $this->world(10.0);
        $wh = $this->defaultWarehouse($w['company']);
        $tape = $this->makeProduct($w['company']);
        $this->actAs($w['company']);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 5]], $w['user']);
        $this->actingAs($w['user'])->post('/operaciones/furgones', ['number' => '0200', 'date' => now()->toDateString(), 'warehouse_id' => $wh->id, 'fuel_gallons' => 25, 'materials' => [['product_id' => $tape->id, 'units' => 10]]])->assertRedirect();
        $this->actAs($w['company']);
        $c = Container::where('number', '0200')->first();

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertSessionHas('error');
        $this->assertSame('open', $c->fresh()->status);
        $this->assertSame(180.0, $tape->stockInWarehouse($wh->id));        // el consumo de materiales se revirtió por completo
        $this->assertSame(10.0, FuelTank::first()->current_gallons);
        $this->assertSame(1, \App\Models\InventoryMovement::count());
        $this->assertSame(0, FuelDispatch::count());
    }

    public function test_permissions(): void
    {
        $w = $this->world(100.0, 'company_admin');
        $reader = $this->makeUser($w['company'], 'read_only');
        $operator = $this->makeUser($w['company'], 'operator');
        $fuelUser = $this->makeUser($w['company'], 'fuel');
        $payload = ['received_at' => now()->toDateString(), 'meter_start' => 0, 'meter_end' => 10];

        $this->actingAs($reader);
        foreach (['/combustible', '/combustible/recepciones', '/combustible/despachos', '/combustible/libro'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/combustible')->assertDontSee('Ajustar reserva');
        $this->get('/combustible/recepciones/nueva')->assertForbidden();
        $this->post('/combustible/recepciones', $payload)->assertForbidden();
        $this->get('/combustible/despachos/nuevo')->assertForbidden();
        $this->post('/combustible/despachos', [])->assertForbidden();
        $this->post('/combustible/ajuste', ['physical' => 1, 'reason' => 'No autorizado'])->assertForbidden();
        $this->put('/combustible/tanque', ['name' => 'X'])->assertForbidden();

        $this->actingAs($operator)->get('/combustible')->assertForbidden();                 // operador no tiene fuel.view

        $this->actingAs($fuelUser)->post('/combustible/recepciones', $payload)->assertRedirect();
        $this->assertSame(110.0, FuelTank::first()->current_gallons);
        $this->post('/combustible/ajuste', ['physical' => 50, 'reason' => 'Medición física hecha'])->assertRedirect();

        $this->actAs($w['company']);
        $this->assertSame(1, FuelReceipt::count());
        $this->assertSame(50.0, FuelTank::first()->current_gallons);
    }

    public function test_fuel_is_isolated_between_companies(): void
    {
        $w = $this->world(100.0);
        $svc = app(FuelService::class);
        $rec = $svc->receive(['received_at' => now()->toDateString(), 'meter_start' => 0, 'meter_end' => 10, 'supplier' => 'Suplidor Secreto'], $w['user']);
        $dis = $svc->dispatch(['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => 0, 'reading_end' => 5], $w['user']);

        $b = $this->makeCompany('B');
        $userB = $this->makeUser($b);
        $this->actAs($b);
        $this->assertSame(0.0, app(FuelService::class)->tank()->current_gallons);           // cada empresa tiene su propia reserva

        $this->actingAs($userB)->get('/combustible')->assertOk()->assertDontSee('Suplidor Secreto');
        $this->get('/combustible/recepciones')->assertOk()->assertDontSee('Suplidor Secreto');
        $this->get('/combustible/libro')->assertOk()->assertDontSee('Medición');
        $this->get("/combustible/recepciones/{$rec->id}")->assertNotFound();
        $this->post("/combustible/recepciones/{$rec->id}/anular", ['reason' => 'Intento de otra empresa'])->assertNotFound();
        $this->post("/combustible/despachos/{$dis->id}/anular", ['reason' => 'Intento de otra empresa'])->assertNotFound();
        $this->post('/combustible/despachos', ['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => 0, 'reading_end' => 5])->assertSessionHasErrors('vehicle_id');
        $this->actAs($w['company']);
        $this->assertSame(105.0, FuelTank::first()->current_gallons);   // 100 + 10 − 5, intacta
        $this->assertNull($rec->fresh()->voided_at);
    }

    public function test_vehicle_file_shows_fuel_stats_efficiency_and_timeline(): void
    {
        $w = $this->world(500.0);
        $w['f3']->forceFill(['odometer' => 100000.0])->save();
        $svc = app(FuelService::class);
        foreach ([[10, 123000, 100000.0, 10], [8, 123010, 100300.0, 30], [5, 123040, 100900.0, 60]] as [$ago, $start, $km, $gal]) {
            $svc->dispatch(['dispatched_at' => now()->subDays($ago)->toDateString(), 'vehicle_id' => $w['f3']->id, 'reading_start' => $start, 'reading_end' => $start + $gal, 'odometer' => $km], $w['user']);
        }
        $w['f3']->forceFill(['odometer' => 100900.0])->save();
        // (100300−100000)/30 + (100900−100300)/60 → 900 km / 90 gal = 10 km/gal
        $this->assertSame(10.0, app(VehicleFile::class)->efficiency($w['f3']->fresh()));

        $this->actingAs($w['user'])->get("/flotilla/vehiculos/{$w['f3']->id}")->assertOk()
            ->assertSee('10.00 km/gal')->assertSee('Línea de tiempo')->assertSee('60.0 galones de gasoil')->assertSee('Último despacho de gasoil');
        $this->get('/dashboard')->assertOk()->assertSee('Gasoil disponible')->assertSee('Consumo de gasoil del mes');
    }

    public function test_dashboard_gasoil_card_shows_real_reserve(): void
    {
        $w = $this->world(909.0);
        $this->actingAs($w['user'])->get('/dashboard')->assertOk()->assertSee('909.0 gal');
        $reader = $this->makeUser($w['company'], 'operator');
        $this->actingAs($reader)->get('/dashboard')->assertOk()->assertDontSee('Gasoil disponible');
    }
}
