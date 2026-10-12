<?php

namespace Tests\Feature;

use App\Models\Container;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\Vehicle;
use App\Services\Inventory\StockService;
use App\Services\Tickets\TicketRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    protected function world(): array
    {
        $company = $this->makeCompany('Carmen Test');
        $user = $this->makeUser($company);
        $wh = $this->defaultWarehouse($company);
        $tape = $this->makeProduct($company);
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $tape->id, 'boxes' => 10]], $user);
        $vehicle = Vehicle::create(['ficha' => 'F3', 'odometer' => 415540.5, 'status' => 'operational']);
        $driver = \App\Models\Driver::create(['name' => 'Pedro Chofer', 'phone' => '809-555-1']);
        $container = Container::create([
            'number' => '0111', 'date' => now(), 'status' => 'open', 'warehouse_id' => $wh->id, 'driver_id' => $driver->id, 'driver_name' => 'Pedro Chofer',
            'phone' => '809-555-1', 'vehicle_id' => $vehicle->id, 'conduce' => 'CD-77', 'fuel_gallons' => 25,
        ]);
        \App\Models\ContainerMaterial::create(['container_id' => $container->id, 'product_id' => $tape->id, 'product_sku' => $tape->sku, 'product_name' => $tape->name, 'boxes' => 1, 'units' => 8, 'quantity_base' => 44]);

        return compact('company', 'user', 'wh', 'tape', 'vehicle', 'container');
    }

    protected function issue(array $w, string $type = 'materials', array $extra = []): Ticket
    {
        $payload = ['type' => $type, 'issued_at' => now()->toDateString(), 'received_by' => 'Pedro Chofer'] + $extra;
        if ($type === 'fuel' && ! isset($payload['rows'])) {
            $payload['rows'] = [['ficha' => 'F3', 'odometer' => 415540.5, 'gallons' => 25]];
        }
        $this->actingAs($w['user'])->post("/operaciones/furgones/{$w['container']->id}/tickets", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->actAs($w['company']);

        return Ticket::latest('id')->firstOrFail();
    }

    public function test_materials_ticket_snapshots_container_and_items(): void
    {
        $w = $this->world();
        $t = $this->issue($w);

        $this->assertSame('0001', $t->number);
        $this->assertSame('materials', $t->type);
        $this->assertSame('0111', $t->container_number);
        $this->assertSame('Pedro Chofer', $t->driver_name);
        $this->assertSame('CD-77', $t->conduce);
        $this->assertSame('F3', $t->ficha);
        $this->assertSame('809-555-1', $t->phone);
        $this->assertSame($w['user']->name, $t->dispatched_by);
        $this->assertSame($w['user']->id, $t->created_by);
        $item = $t->items->first();
        $this->assertSame('Tape Transparente', $item->description);
        $this->assertSame('1 caja + 8 rollos', $item->quantity_label);
        $this->assertSame(44.0, $item->total_units);
        $this->assertSame('rollos', $item->unit_label);

        // el ticket emitido NO cambia si el furgón se modifica después
        $w['container']->materials()->delete();
        $w['container']->update(['conduce' => 'OTRO']);
        $this->assertSame('CD-77', $t->fresh()->conduce);
        $this->assertCount(1, $t->fresh()->items);
    }

    public function test_numbers_are_correlative_per_type_and_company(): void
    {
        $w = $this->world();
        $this->assertSame('0001', $this->issue($w)->number);
        $this->assertSame('0002', $this->issue($w)->number);
        $this->assertSame('0001', $this->issue($w, 'fuel')->number);        // correlativo propio del ticket de gasoil
        $this->assertSame('0003', $this->issue($w)->number);

        $b = $this->makeCompany('Otra');
        $userB = $this->makeUser($b);
        $this->actAs($b);
        $c = Container::create(['number' => '0111', 'date' => now(), 'status' => 'open', 'warehouse_id' => $this->defaultWarehouse($b)->id]);
        $p = $this->makeProduct($b);
        $this->actAs($b);
        \App\Models\ContainerMaterial::create(['container_id' => $c->id, 'product_id' => $p->id, 'product_sku' => 'x', 'product_name' => 'x', 'boxes' => 0, 'units' => 1, 'quantity_base' => 1]);
        $this->actingAs($userB)->post("/operaciones/furgones/{$c->id}/tickets", ['type' => 'materials', 'issued_at' => now()->toDateString()])->assertRedirect();
        $this->actAs($b);
        $this->assertSame('0001', Ticket::first()->number);
    }

    public function test_fuel_ticket_has_ficha_kilometraje_galones(): void
    {
        $w = $this->world();
        $t = $this->issue($w, 'fuel', ['rows' => [['ficha' => 'F3', 'odometer' => 415540.5, 'gallons' => 25], ['ficha' => 'F1', 'odometer' => '', 'gallons' => 10.3]]]);
        $this->assertSame('fuel', $t->type);
        $this->assertCount(2, $t->items);
        $this->assertEquals([25.0, 10.3], $t->items->pluck('gallons')->all());
        $this->assertSame(415540.5, $t->items->first()->odometer);
        $this->get("/operaciones/tickets/{$t->id}")->assertOk()->assertSee('Total galones')->assertSee('35.3');
    }

    public function test_ticket_validation(): void
    {
        $w = $this->world();
        $url = "/operaciones/furgones/{$w['container']->id}/tickets";
        $this->actingAs($w['user'])->post($url, ['type' => 'fuel', 'issued_at' => now()->toDateString()])->assertSessionHasErrors('rows');
        $this->post($url, ['type' => 'fuel', 'issued_at' => now()->toDateString(), 'rows' => [['ficha' => 'F3', 'gallons' => 0]]])->assertSessionHasErrors('rows.0.gallons');
        $w['container']->materials()->delete();
        $this->post($url, ['type' => 'materials', 'issued_at' => now()->toDateString()])->assertSessionHas('error');
        $this->assertSame(0, Ticket::count());
    }

    public function test_void_keeps_ticket_with_user_date_and_reason(): void
    {
        $w = $this->world();
        $t = $this->issue($w);

        $this->post("/operaciones/tickets/{$t->id}/anular", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post("/operaciones/tickets/{$t->id}/anular", ['reason' => 'Se emitió con el chofer equivocado'])->assertSessionHasNoErrors();

        $t->refresh();
        $this->assertSame('voided', $t->status);
        $this->assertSame($w['user']->id, $t->voided_by);
        $this->assertNotNull($t->voided_at);
        $this->assertSame('Se emitió con el chofer equivocado', $t->void_reason);
        $this->assertSame(1, Ticket::count());                                  // no desaparece
        $this->get('/operaciones/tickets')->assertOk()->assertSee('Anulado');
        $this->get("/operaciones/tickets/{$t->id}")->assertOk()->assertSee('ANULADO')->assertSee('Se emitió con el chofer equivocado')->assertSee($w['user']->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.voided', 'auditable_id' => $t->id]);
        $this->post("/operaciones/tickets/{$t->id}/anular", ['reason' => 'Otra vez más'])->assertForbidden();
        // el número no se reutiliza
        $this->assertSame('0002', $this->issue($w)->number);
    }

    public function test_voided_ticket_cannot_be_printed_or_downloaded(): void
    {
        $w = $this->world();
        $t = $this->issue($w);
        $this->post("/operaciones/tickets/{$t->id}/anular", ['reason' => 'Motivo de prueba']);
        $this->get("/operaciones/tickets/{$t->id}/imprimir")->assertRedirect("/operaciones/tickets/{$t->id}");
        $this->get("/operaciones/tickets/{$t->id}/pdf")->assertRedirect("/operaciones/tickets/{$t->id}");
        $this->assertSame(0, $t->fresh()->print_count);
    }

    public function test_ticket_can_never_be_deleted(): void
    {
        $w = $this->world();
        $t = $this->issue($w);
        $this->assertFalse($t->delete());
        $this->assertSame(1, Ticket::count());
    }

    public function test_print_layouts_one_two_four_per_sheet(): void
    {
        $w = $this->world();
        $t = $this->issue($w);
        foreach ([1 => 1, 2 => 2, 4 => 4] as $copies => $expected) {
            $html = $this->get("/operaciones/tickets/{$t->id}/imprimir?copies={$copies}&auto=0")->assertOk()->getContent();
            $this->assertSame($expected, substr_count($html, '<div class="tk">'), "{$copies} por hoja");
            $this->assertStringContainsString('class="sheet n'.$copies.' l'.$copies.'"', $html);
        }
        $html = $this->get("/operaciones/tickets/{$t->id}/imprimir?copies=4&auto=0")->getContent();
        $this->assertStringContainsString('ORIGINAL', $html);
        $this->assertStringContainsString('COPIA 4', $html);
        $this->assertStringContainsString('Despachado por', $html);
        $this->assertStringContainsString('Recibido por', $html);
        $this->assertStringContainsString('Carmen Test', $html);
        $this->assertStringContainsString('@page { size: letter portrait', $html);
        // columnas del ticket de materiales
        $this->assertStringContainsString('Total unidades', $html);
        $this->assertStringContainsString('Furgón No.', $html);
    }

    public function test_fuel_ticket_print_columns(): void
    {
        $w = $this->world();
        $t = $this->issue($w, 'fuel');
        $html = $this->get("/operaciones/tickets/{$t->id}/imprimir?copies=2&auto=0")->assertOk()->getContent();
        foreach (['Ticket de gasoil', 'Ficha', 'Kilometraje', 'Galones', 'TOTAL GALONES'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_first_print_then_reprint_is_flagged_and_counted(): void
    {
        $w = $this->world();
        $t = $this->issue($w);

        $first = $this->get("/operaciones/tickets/{$t->id}/imprimir?copies=4&auto=0")->getContent();
        $this->assertStringNotContainsString('REIMPRESIÓN', $first);
        $this->assertSame(1, $t->fresh()->print_count);

        $second = $this->get("/operaciones/tickets/{$t->id}/imprimir?copies=2&auto=0")->getContent();
        $this->assertStringContainsString('REIMPRESIÓN', $second);
        $t->refresh();
        $this->assertSame(2, $t->print_count);
        $this->assertSame('2', $t->print_layout);
        $this->assertNotNull($t->last_printed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.printed', 'auditable_id' => $t->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.reprinted', 'auditable_id' => $t->id]);
    }

    public function test_many_lines_reduce_copies_per_sheet_to_stay_legible(): void
    {
        $w = $this->world();
        $t = $this->issue($w, 'fuel', ['rows' => collect(range(1, 14))->map(fn ($i) => ['ficha' => "F$i", 'odometer' => 100 + $i, 'gallons' => $i])->all()]);
        $plan = app(TicketRenderer::class)->plan($t->load('items'), 4);
        $this->assertSame(2, $plan['copies']);
        $this->assertNotNull($plan['notice']);

        $html = $this->get("/operaciones/tickets/{$t->id}/imprimir?copies=4&auto=0")->getContent();
        $this->assertSame(2, substr_count($html, '<div class="tk">'));
        $this->assertStringContainsString('se imprime 2 por hoja', $html);
    }

    public function test_pdf_is_generated_for_each_layout(): void
    {
        $w = $this->world();
        $t = $this->issue($w);
        foreach ([1, 2, 4] as $copies) {
            $r = $this->get("/operaciones/tickets/{$t->id}/pdf?copies={$copies}")->assertOk();
            $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $r->getContent());
            $this->assertStringContainsString('ticket-materials-0001.pdf', $r->headers->get('Content-Disposition'));
        }
        $this->assertSame(3, $t->fresh()->print_count);
    }

    public function test_ticket_permissions(): void
    {
        $w = $this->world();
        $t = $this->issue($w);
        $reader = $this->makeUser($w['company'], 'read_only');
        $operator = $this->makeUser($w['company'], 'operator');

        $this->actingAs($reader)->get('/operaciones/tickets')->assertOk();
        $this->get("/operaciones/tickets/{$t->id}")->assertOk()->assertDontSee('Anular ticket')->assertDontSee('por hoja');
        $this->get("/operaciones/tickets/{$t->id}/imprimir")->assertForbidden();
        $this->get("/operaciones/tickets/{$t->id}/pdf")->assertForbidden();
        $this->post("/operaciones/tickets/{$t->id}/anular", ['reason' => 'No autorizado'])->assertForbidden();
        $this->get("/operaciones/furgones/{$w['container']->id}/tickets/nuevo")->assertForbidden();
        $this->post("/operaciones/furgones/{$w['container']->id}/tickets", ['type' => 'materials', 'issued_at' => now()->toDateString()])->assertForbidden();

        $this->actingAs($operator)->get("/operaciones/tickets/{$t->id}/imprimir?auto=0")->assertOk();   // operador imprime…
        $this->post("/operaciones/tickets/{$t->id}/anular", ['reason' => 'No autorizado'])->assertForbidden(); // …pero no anula
        $this->assertSame('issued', $t->fresh()->status);
    }

    public function test_tickets_are_isolated_between_companies(): void
    {
        $w = $this->world();
        $t = $this->issue($w);
        $b = $this->makeCompany('B');
        $userB = $this->makeUser($b);

        $this->actingAs($userB)->get('/operaciones/tickets')->assertOk()->assertDontSee('Pedro Chofer');
        foreach (['', '/imprimir', '/pdf'] as $suffix) {
            $this->get("/operaciones/tickets/{$t->id}{$suffix}")->assertNotFound();
        }
        $this->post("/operaciones/tickets/{$t->id}/anular", ['reason' => 'Intento de otra empresa'])->assertNotFound();
        $this->assertSame('issued', $t->fresh()->status);
    }

    public function test_ticket_list_filters(): void
    {
        $w = $this->world();
        $this->issue($w);
        $this->issue($w, 'fuel');
        $voided = $this->issue($w);
        $this->post("/operaciones/tickets/{$voided->id}/anular", ['reason' => 'Prueba de filtro']);

        $count = fn (string $qs) => $this->get('/operaciones/tickets'.$qs)->assertOk()->viewData('tickets')->count();
        $this->assertSame(3, $count(''));
        $this->assertSame(1, $count('?type=fuel'));
        $this->assertSame(1, $count('?status=voided'));
        $this->assertSame(3, $count('?q=0111'));
        $this->assertSame(3, $count('?q=Pedro'));
        $this->assertSame(0, $count('?q=ZZZ'));
    }

    public function test_logo_is_used_in_printed_tickets_when_uploaded(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $w = $this->world();
        $path = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 300, 300)->store('companies/1', 'public');
        $w['company']->update(['logo_path' => $path]);
        $t = $this->issue($w);

        $html = $this->get("/operaciones/tickets/{$t->id}/imprimir?copies=1&auto=0")->getContent();
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $noLogo = $this->makeCompany('Sin logo');
        $this->assertNull(app(TicketRenderer::class)->logoDataUri($noLogo));   // nunca se inventa un logo
    }
}
