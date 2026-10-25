<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\FuelDispatch;
use App\Models\FuelTank;
use App\Models\Ticket;
use App\Models\Vehicle;
use App\Services\Fuel\FuelService;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** El gasoil de un furgón se llena solo (ticket de gasoil o despacho de Combustible) y el cierre descuenta únicamente lo que falte. */
class ContainerFuelFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function world(): array
    {
        Storage::fake('local');
        $company = $this->makeCompany('Carmen Test');
        $user = $this->makeUser($company);
        $this->actAs($company);
        app(FuelService::class)->adjust(100.0, 'Reserva inicial de prueba', $user);
        $f3 = Vehicle::create(['ficha' => 'F3', 'plate' => 'L-3', 'odometer' => 1000, 'status' => 'operational']);
        $wh = $this->defaultWarehouse($company);
        $tape = $this->makeProduct($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 5]], $user);

        return compact('company', 'user', 'f3', 'wh', 'tape');
    }

    /** Un furgón en proceso, sin vehículo ni gasoil (como el #076 real). */
    protected function container(array $w, array $extra = []): Container
    {
        $this->actingAs($w['user'])->post('/operaciones/furgones', $extra + [
            'number' => '0076', 'date' => now()->toDateString(), 'warehouse_id' => $w['wh']->id, 'fuel_gallons' => '', 'materials' => [['product_id' => $w['tape']->id, 'boxes' => 1]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actAs($w['company']);

        return Container::where('number', $extra['number'] ?? '0076')->firstOrFail();
    }

    protected function fuelTicket(array $w, Container $c, array $rows): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($w['user'])->post("/operaciones/furgones/{$c->id}/tickets", ['type' => 'fuel', 'issued_at' => now()->toDateString(), 'rows' => $rows]);
    }

    public function test_a_fuel_ticket_puts_its_gasoil_and_vehicle_in_the_container_so_it_can_be_closed_at_once(): void
    {
        $w = $this->world();
        $c = $this->container($w);
        $this->assertSame(0.0, (float) $c->fuel_gallons);
        $this->assertNull($c->vehicle_id);

        $this->fuelTicket($w, $c, [['ficha' => 'F3', 'odometer' => 1000, 'gallons' => 25]])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->actAs($w['company']);
        $c->refresh();
        $this->assertSame(25.0, (float) $c->fuel_gallons, 'los galones del ticket pasaron al furgón');
        $this->assertSame($w['f3']->id, $c->vehicle_id, 'y la ficha del ticket lo ligó a su vehículo');
        $this->assertSame(1, AuditLog::where('action', 'container.fuel_from_ticket')->count());

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertSessionHas('success');
        $this->assertSame(75.0, FuelTank::first()->current_gallons);
        $d = FuelDispatch::where('container_id', $c->id)->firstOrFail();
        $this->assertSame(25.0, (float) $d->gallons);
        $this->assertSame($w['f3']->id, $d->vehicle_id);
        $this->assertCount(0, app(FuelService::class)->discrepancies());
    }

    public function test_several_fuel_tickets_add_up_and_deleting_one_recalculates(): void
    {
        $w = $this->world();
        $c = $this->container($w);
        $this->fuelTicket($w, $c, [['ficha' => 'F3', 'gallons' => 25]]);
        $this->fuelTicket($w, $c, [['ficha' => 'F3', 'gallons' => 10]]);
        $this->actAs($w['company']);
        $this->assertSame(35.0, (float) $c->fresh()->fuel_gallons);

        $second = Ticket::where('type', 'fuel')->orderByDesc('id')->firstOrFail();
        $this->post("/operaciones/tickets/{$second->id}/anular", ['reason' => 'Ticket duplicado por error'])->assertSessionHas('success');
        $this->assertSame(25.0, (float) $c->fresh()->fuel_gallons, 'quedó solo lo del primer ticket');

        $first = Ticket::where('type', 'fuel')->firstOrFail();
        $this->post("/operaciones/tickets/{$first->id}/anular", ['reason' => 'Ticket equivocado'])->assertSessionHas('success');
        $this->assertSame(0.0, (float) $c->fresh()->fuel_gallons);
    }

    public function test_a_manual_fuel_ticket_value_is_not_wiped_when_a_ticket_is_deleted(): void
    {
        $w = $this->world();
        $c = $this->container($w);
        $this->fuelTicket($w, $c, [['ficha' => 'F3', 'gallons' => 25]]);
        $this->actAs($w['company']);
        $c->fresh()->forceFill(['fuel_gallons' => 40])->save();                          // el usuario corrigió el dato a mano

        $t = Ticket::where('type', 'fuel')->firstOrFail();
        $this->post("/operaciones/tickets/{$t->id}/anular", ['reason' => 'Se anula el ticket'])->assertSessionHas('success');
        $this->assertSame(40.0, (float) $c->fresh()->fuel_gallons, 'no se pisa un dato que ya no venía del ticket');
    }

    public function test_a_materials_ticket_does_not_touch_the_container_fuel(): void
    {
        $w = $this->world();
        $c = $this->container($w);
        $this->actingAs($w['user'])->post("/operaciones/furgones/{$c->id}/tickets", ['type' => 'materials', 'issued_at' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $this->assertSame(0.0, (float) $c->fresh()->fuel_gallons);
    }

    public function test_a_fuel_dispatch_with_a_container_fills_its_data_and_the_close_does_not_count_it_twice(): void
    {
        $w = $this->world();
        $c = $this->container($w);

        $this->actingAs($w['user'])->get('/combustible/despachos/nuevo')->assertOk()->assertSee('Furgón (opcional)')->assertSee('#0076');
        $this->post('/combustible/despachos', ['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'container_id' => $c->id, 'reading_start' => 0, 'reading_end' => 25])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->actAs($w['company']);
        $c->refresh();
        $this->assertSame(25.0, (float) $c->fuel_gallons, 'los galones despachados pasaron a los datos del furgón');
        $this->assertSame($w['f3']->id, $c->vehicle_id);
        $this->assertSame(75.0, FuelTank::first()->current_gallons);

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertSessionHas('success');
        $this->assertSame(75.0, FuelTank::first()->current_gallons, 'el cierre no descontó otra vez');
        $this->assertSame(1, FuelDispatch::where('container_id', $c->id)->count());
        $this->assertCount(0, app(FuelService::class)->discrepancies());
    }

    public function test_closing_only_dispatches_the_gasoil_that_is_still_missing(): void
    {
        $w = $this->world();
        $c = $this->container($w, ['vehicle_id' => $w['f3']->id, 'fuel_gallons' => 25]);
        $this->actingAs($w['user'])->post('/combustible/despachos', ['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'container_id' => $c->id, 'reading_start' => 0, 'reading_end' => 10])
            ->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $this->assertSame(25.0, (float) $c->fresh()->fuel_gallons, 'lo despachado (10) no supera lo planeado (25): no cambia');

        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertSessionHas('success');
        $this->assertSame(75.0, FuelTank::first()->current_gallons, '10 antes + 15 al cerrar = 25 en total');
        $this->assertSame([10.0, 15.0], FuelDispatch::where('container_id', $c->id)->orderBy('id')->pluck('gallons')->map(fn ($g) => (float) $g)->all());
    }

    public function test_a_dispatch_cannot_be_linked_to_a_closed_container(): void
    {
        $w = $this->world();
        $c = $this->container($w);
        $this->actAs($w['company']);
        $this->post("/operaciones/furgones/{$c->id}/cerrar")->assertSessionHas('success');

        $this->post('/combustible/despachos', ['dispatched_at' => now()->toDateString(), 'vehicle_id' => $w['f3']->id, 'container_id' => $c->id, 'reading_start' => 0, 'reading_end' => 5])
            ->assertSessionHasErrors('container_id');
        $this->assertSame(0, FuelDispatch::count());
    }
}
