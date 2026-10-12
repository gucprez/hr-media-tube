<?php

namespace Tests\Feature;

use App\Models\InspectionChecklistItem;
use App\Models\InventoryMovement;
use App\Models\MaintenanceRecord;
use App\Models\ReturnableMovement;
use App\Models\TireReturn;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use App\Models\VehicleInspection;
use App\Services\Fleet\ReturnableService;
use App\Services\Fleet\TireService;
use App\Services\Fleet\VehicleFile;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use RefreshDatabase;

    protected function world(string $role = 'company_admin'): array
    {
        Storage::fake('local');
        $company = $this->makeCompany('Carmen Test');
        $user = $this->makeUser($company, $role);
        $wh = $this->defaultWarehouse($company);
        $bulb = $this->makeProduct($company, ['name' => 'Bombilla', 'sku' => 'BOM', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 2]);
        $tire = $this->makeProduct($company, ['name' => 'Goma 22.5', 'sku' => 'GOMA', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 1, 'is_returnable' => true, 'returnable_kind' => 'tire']);
        $toner = $this->makeProduct($company, ['name' => 'Tóner HP', 'sku' => 'TON', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 1, 'is_returnable' => true, 'returnable_kind' => 'toner']);
        $this->actAs($company);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $bulb->id, 'units' => 5], ['product_id' => $tire->id, 'units' => 6], ['product_id' => $toner->id, 'units' => 4]], $user);
        $f3 = Vehicle::create(['ficha' => 'F3', 'plate' => 'L-3', 'odometer' => 415000, 'status' => 'operational']);

        return compact('company', 'user', 'wh', 'bulb', 'tire', 'toner', 'f3');
    }

    // ------------------------------------------------------------------ averías

    public function test_incident_report_creates_history_and_syncs_vehicle_status(): void
    {
        $w = $this->world();
        $this->actingAs($w['user'])->post('/flotilla/averias', [
            'vehicle_id' => $w['f3']->id, 'title' => 'Luz trasera derecha dañada', 'odometer' => 415540, 'priority' => 'low', 'description' => 'Se ve apagada',
            'photos' => [UploadedFile::fake()->image('luz.jpg', 800, 600)],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $i = VehicleIncident::with(['events', 'attachments'])->firstOrFail();
        $this->assertSame('reported', $i->status);
        $this->assertSame(415540.0, $i->odometer);
        $this->assertSame($w['user']->id, $i->reported_by);
        $this->assertCount(1, $i->events);
        $this->assertCount(1, $i->attachments);
        Storage::disk('local')->assertExists($i->attachments->first()->path);
        $this->assertSame('operational', $w['f3']->fresh()->status);       // avería baja: el camión sigue operativo
        $this->assertSame(415540.0, $w['f3']->fresh()->odometer);
        $this->assertDatabaseHas('audit_logs', ['action' => 'incident.reported', 'auditable_id' => $i->id]);

        // prioridad crítica → «Con avería»; en reparación → «En mantenimiento»; reparada → «Operativo»
        $this->post('/flotilla/averias', ['vehicle_id' => $w['f3']->id, 'title' => 'Frenos', 'priority' => 'critical'])->assertRedirect();
        $this->assertSame('breakdown', $w['f3']->fresh()->status);
        $crit = VehicleIncident::where('title', 'Frenos')->first();
        $this->post("/flotilla/averias/{$crit->id}/estado", ['status' => 'in_repair', 'note' => 'En taller'])->assertSessionHas('success');
        $this->assertSame('maintenance', $w['f3']->fresh()->status);
        $this->post("/flotilla/averias/{$crit->id}/estado", ['status' => 'repaired']);
        $this->assertSame('operational', $w['f3']->fresh()->status);
        $this->post("/flotilla/averias/{$crit->id}/estado", ['status' => 'closed']);
        $this->assertNotNull($crit->fresh()->closed_at);
        $this->assertSame(['reported', 'in_repair', 'repaired', 'closed'], $crit->fresh()->events->pluck('to_status')->all());
    }

    public function test_backwards_status_change_requires_note_and_inactive_vehicle_is_not_touched(): void
    {
        $w = $this->world();
        $svc = app(\App\Services\Fleet\IncidentService::class);
        $i = $svc->report($w['f3'], ['title' => 'Ruido', 'priority' => 'high'], $w['user']);
        $svc->changeStatus($i, 'repaired', null, $w['user']);

        $this->actingAs($w['user'])->post("/flotilla/averias/{$i->id}/estado", ['status' => 'pending'])->assertSessionHas('error');
        $this->assertSame('repaired', $i->fresh()->status);
        $this->post("/flotilla/averias/{$i->id}/estado", ['status' => 'pending', 'note' => 'Volvió a fallar'])->assertSessionHas('success');
        $this->assertSame('breakdown', $w['f3']->fresh()->status);
        $this->post("/flotilla/averias/{$i->id}/estado", ['status' => 'pending'])->assertSessionHas('error');   // mismo estado

        $w['f3']->forceFill(['status' => 'inactive'])->save();
        $svc->changeStatus($i->fresh(), 'repaired', null, $w['user']);
        $this->assertSame('inactive', $w['f3']->fresh()->status);
    }

    public function test_incident_permissions_and_isolation(): void
    {
        $w = $this->world();
        $svc = app(\App\Services\Fleet\IncidentService::class);
        $i = $svc->report($w['f3'], ['title' => 'Ruido secreto', 'priority' => 'low'], $w['user']);
        $reader = $this->makeUser($w['company'], 'read_only');
        $mech = $this->makeUser($w['company'], 'maintenance');
        $other = $this->makeUser($this->makeCompany('B'));

        $this->actingAs($reader)->get('/flotilla/averias')->assertOk()->assertSee('Ruido secreto');
        $this->get("/flotilla/averias/{$i->id}")->assertOk()->assertDontSee('Cambiar estado')->assertDontSee('Registrar reparación');
        $this->get('/flotilla/averias/nueva')->assertForbidden();
        $this->post('/flotilla/averias', ['vehicle_id' => $w['f3']->id, 'title' => 'x', 'priority' => 'low'])->assertForbidden();
        $this->post("/flotilla/averias/{$i->id}/estado", ['status' => 'repaired'])->assertForbidden();

        $this->actingAs($mech)->get('/flotilla/averias/nueva')->assertOk();
        $this->post("/flotilla/averias/{$i->id}/estado", ['status' => 'pending'])->assertSessionHas('success');

        $this->actingAs($other)->get('/flotilla/averias')->assertOk()->assertDontSee('Ruido secreto');
        $this->get("/flotilla/averias/{$i->id}")->assertNotFound();
        $this->post("/flotilla/averias/{$i->id}/estado", ['status' => 'closed'])->assertNotFound();
        $this->post('/flotilla/averias', ['vehicle_id' => $w['f3']->id, 'title' => 'Intruso', 'priority' => 'low'])->assertSessionHasErrors('vehicle_id');
    }

    // ------------------------------------------------------------------ inspecciones

    protected function inspectionPayload(array $w, array $overrides = []): array
    {
        $this->actingAs($w['user'])->get('/flotilla/inspecciones');            // crea el checklist base si hace falta
        $this->actAs($w['company']);
        $items = InspectionChecklistItem::orderBy('sort_order')->get();
        $statuses = [];
        foreach ($items as $it) {
            $statuses[$it->id] = ['status' => $overrides[$it->name] ?? 'good', 'notes' => isset($overrides[$it->name]) ? 'Obs '.$it->name : ''];
        }

        return ['vehicle_id' => $w['f3']->id, 'inspected_at' => now()->toDateString(), 'odometer' => 415100, 'items' => $statuses];
    }

    public function test_default_checklist_has_the_eighteen_points_of_the_spec(): void
    {
        $w = $this->world();
        $this->assertSame(18, InspectionChecklistItem::count());
        $names = InspectionChecklistItem::orderBy('sort_order')->pluck('name')->all();
        foreach (['Luces delanteras', 'Luces traseras', 'Direccionales', 'Luces de freno', 'Puertas', 'Cerraduras', 'Gomas', 'Frenos', 'Espejos', 'Cristales', 'Limpiavidrios', 'Batería', 'Aceite', 'Refrigerante', 'Bocina', 'Carrocería', 'Sistema eléctrico', 'Otros'] as $n) {
            $this->assertContains($n, $names);
        }
    }

    public function test_inspection_overall_status_items_photos_next_due_and_auto_incident(): void
    {
        $w = $this->world();
        $payload = $this->inspectionPayload($w, ['Luces traseras' => 'damaged', 'Gomas' => 'attention', 'Bocina' => 'na']);
        $lights = InspectionChecklistItem::where('name', 'Luces traseras')->first();
        $payload['create_incidents'] = 1;
        $payload["photo_{$lights->id}"] = [UploadedFile::fake()->image('p.jpg', 600, 400)];

        $this->actingAs($w['user'])->post('/flotilla/inspecciones', $payload)->assertRedirect()->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $i = VehicleInspection::with('items.attachments')->firstOrFail();
        $this->assertSame('damaged', $i->overall_status);
        $this->assertSame($w['user']->id, $i->inspector_id);
        $this->assertSame(now()->addMonth()->toDateString(), $i->next_due_at->toDateString());
        $this->assertCount(18, $i->items);
        $this->assertSame('damaged', $i->items->firstWhere('label', 'Luces traseras')->status);
        $this->assertSame('Obs Luces traseras', $i->items->firstWhere('label', 'Luces traseras')->notes);
        $this->assertCount(1, $i->items->firstWhere('label', 'Luces traseras')->attachments);
        $this->assertSame(415100.0, $w['f3']->fresh()->odometer);
        $inc = VehicleIncident::firstOrFail();
        $this->assertSame('Luces traseras dañado(a)', $inc->title);
        $this->assertSame(1, VehicleIncident::count());                    // solo el punto dañado crea avería, no el de «atención»
        $this->get("/flotilla/inspecciones/{$i->id}")->assertOk()->assertSee('Luces traseras')->assertSee('Dañado')->assertSee('No aplica');
    }

    public function test_inspection_requires_every_point_status_and_good_overall_is_good(): void
    {
        $w = $this->world();
        $payload = $this->inspectionPayload($w);
        $broken = $payload;
        array_shift($broken['items']);
        $this->actingAs($w['user'])->post('/flotilla/inspecciones', $broken)->assertSessionHasErrors();
        $this->actAs($w['company']);
        $this->assertSame(0, VehicleInspection::count());

        $this->post('/flotilla/inspecciones', $payload)->assertRedirect();
        $this->assertSame('good', VehicleInspection::first()->overall_status);
        $this->assertSame(0, VehicleIncident::count());
    }

    public function test_checklist_changes_affect_only_future_inspections(): void
    {
        $w = $this->world();
        $this->actingAs($w['user'])->post('/administracion/configuracion/checklist', ['name' => 'Extintor'])->assertSessionHasNoErrors();
        $this->actAs($w['company']);
        $this->assertSame(19, InspectionChecklistItem::count());
        $old = InspectionChecklistItem::where('name', 'Bocina')->first();
        $this->put("/administracion/configuracion/checklist/{$old->id}", ['name' => 'Bocina', 'sort_order' => 5])->assertSessionHasNoErrors();   // sin is_active → desactivada
        $this->assertSame(18, InspectionChecklistItem::where('is_active', true)->count());

        $this->post('/flotilla/inspecciones', $this->inspectionPayload($w))->assertRedirect();
        $this->actAs($w['company']);
        $labels = VehicleInspection::first()->items->pluck('label');
        $this->assertContains('Extintor', $labels);
        $this->assertNotContains('Bocina', $labels);
        $this->get('/administracion/configuracion/checklist')->assertOk()->assertSee('Extintor');
        // nombre histórico intacto aunque se renombre el punto
        $ext = InspectionChecklistItem::where('name', 'Extintor')->first();
        $this->put("/administracion/configuracion/checklist/{$ext->id}", ['name' => 'Extintor 10 lb', 'sort_order' => 99, 'is_active' => 1]);
        $this->assertContains('Extintor', VehicleInspection::first()->items()->pluck('label'));
    }

    public function test_inspections_pending_this_month_and_permissions(): void
    {
        $w = $this->world();
        Vehicle::create(['ficha' => 'F9', 'odometer' => 1, 'status' => 'operational']);
        Vehicle::create(['ficha' => 'F10', 'odometer' => 1, 'status' => 'inactive']);
        $reader = $this->makeUser($w['company'], 'read_only');
        $payload = $this->inspectionPayload($w);
        $this->actingAs($w['user'])->post('/flotilla/inspecciones', $payload);

        $pending = $this->get('/flotilla/inspecciones')->assertOk()->viewData('pending')->pluck('ficha')->all();
        $this->assertSame(['F9'], $pending);                         // F3 ya inspeccionado, F10 inactivo no cuenta

        $this->actingAs($reader)->get('/flotilla/inspecciones')->assertOk();
        $this->get('/flotilla/inspecciones/nueva')->assertForbidden();
        $this->post('/flotilla/inspecciones', $payload)->assertForbidden();
    }

    // ------------------------------------------------------------------ mantenimiento

    public function test_client_example_repair_consumes_the_bulb_and_closes_the_incident(): void
    {
        $w = $this->world();
        $incident = app(\App\Services\Fleet\IncidentService::class)->report($w['f3'], ['title' => 'Luz trasera dañada', 'odometer' => 415540, 'priority' => 'low'], $w['user']);

        $this->actingAs($w['user'])->post('/flotilla/mantenimiento', [
            'vehicle_id' => $w['f3']->id, 'incident_id' => $incident->id, 'performed_at' => now()->toDateString(), 'description' => 'Se reemplazó la bombilla trasera',
            'responsible' => 'Germán', 'warehouse_id' => $w['wh']->id, 'parts' => [['product_id' => $w['bulb']->id, 'units' => 1]],
            'photos' => [UploadedFile::fake()->image('r.jpg', 400, 300)],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $this->assertSame(4.0, $w['bulb']->stockInWarehouse($w['wh']->id));            // Bombilla −1
        $r = MaintenanceRecord::with(['parts', 'attachments'])->firstOrFail();
        $m = InventoryMovement::findOrFail($r->movement_id);
        $this->assertSame('maintenance', $m->type);
        $this->assertSame($w['f3']->id, $m->vehicle_id);                              // movimiento asociado al vehículo…
        $this->assertSame($r->id, $m->source_id);                                    // …y a la reparación
        $this->assertSame('maintenance', $m->items->first()->type);
        $this->assertSame('Bombilla', $r->parts->first()->product_name);
        $this->assertSame($m->id, $r->parts->first()->movement_id);
        $this->assertSame('corrective', $r->type);
        $this->assertCount(1, $r->attachments);
        $this->assertSame('repaired', $incident->fresh()->status);
        $this->assertStringStartsWith('MNT-', $m->number);
        $this->assertCount(0, app(InventoryLedger::class)->discrepancies());
        $this->get("/flotilla/mantenimiento/{$r->id}")->assertOk()->assertSee('Bombilla')->assertSee($m->number);
    }

    public function test_repair_with_insufficient_parts_is_rejected_atomically(): void
    {
        $w = $this->world();
        $incident = app(\App\Services\Fleet\IncidentService::class)->report($w['f3'], ['title' => 'Faros', 'priority' => 'high'], $w['user']);
        $this->actingAs($w['user'])->post('/flotilla/mantenimiento', [
            'vehicle_id' => $w['f3']->id, 'incident_id' => $incident->id, 'performed_at' => now()->toDateString(), 'description' => 'Cambio de bombillas',
            'warehouse_id' => $w['wh']->id, 'parts' => [['product_id' => $w['bulb']->id, 'units' => 99]],
        ])->assertSessionHas('error');

        $this->actAs($w['company']);
        $this->assertSame(0, MaintenanceRecord::count());
        $this->assertSame(5.0, $w['bulb']->stockInWarehouse($w['wh']->id));
        $this->assertSame('reported', $incident->fresh()->status);               // la avería sigue abierta
        $this->assertSame('breakdown', $w['f3']->fresh()->status);
    }

    public function test_preventive_maintenance_without_incident_and_without_parts(): void
    {
        $w = $this->world();
        $this->actingAs($w['user'])->post('/flotilla/mantenimiento', ['vehicle_id' => $w['f3']->id, 'performed_at' => now()->toDateString(), 'description' => 'Engrase general', 'odometer' => 416000])->assertRedirect();
        $this->actAs($w['company']);
        $r = MaintenanceRecord::firstOrFail();
        $this->assertSame('preventive', $r->type);
        $this->assertNull($r->movement_id);
        $this->assertSame(416000.0, $w['f3']->fresh()->odometer);
    }

    public function test_void_maintenance_returns_parts_and_reopens_incident(): void
    {
        $w = $this->world();
        $incident = app(\App\Services\Fleet\IncidentService::class)->report($w['f3'], ['title' => 'Luz', 'priority' => 'high'], $w['user']);
        $r = app(\App\Services\Fleet\MaintenanceService::class)->record($w['f3'], ['performed_at' => now()->toDateString(), 'description' => 'Cambio de luz', 'incident_id' => $incident->id, 'warehouse_id' => $w['wh']->id], [['product_id' => $w['bulb']->id, 'units' => 2]], $w['user']);
        $this->assertSame(3.0, $w['bulb']->stockInWarehouse($w['wh']->id));

        $this->actingAs($w['user'])->post("/flotilla/mantenimiento/{$r->id}/anular", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post("/flotilla/mantenimiento/{$r->id}/anular", ['reason' => 'Se registró en el vehículo equivocado'])->assertSessionHas('success');

        $this->assertSame(5.0, $w['bulb']->stockInWarehouse($w['wh']->id));
        $this->assertNotNull($r->fresh()->voided_at);
        $this->assertSame('in_repair', $incident->fresh()->status);
        $this->post("/flotilla/mantenimiento/{$r->id}/anular", ['reason' => 'Otra vez más'])->assertSessionHas('error');
        $this->assertCount(0, app(InventoryLedger::class)->discrepancies());
        // las piezas de un mantenimiento anulado no cuentan en el expediente
        $this->assertCount(0, app(VehicleFile::class)->partsReceived($w['f3']));
    }

    public function test_maintenance_permissions_and_isolation(): void
    {
        $w = $this->world();
        $reader = $this->makeUser($w['company'], 'read_only');
        $supervisor = $this->makeUser($w['company'], 'supervisor');
        $other = $this->makeUser($this->makeCompany('B'));
        $this->actAs($w['company']);
        $r = app(\App\Services\Fleet\MaintenanceService::class)->record($w['f3'], ['performed_at' => now()->toDateString(), 'description' => 'Trabajo secreto'], [], $w['user']);

        $this->actingAs($reader)->get('/flotilla/mantenimiento')->assertOk()->assertSee('Trabajo secreto');
        $this->get('/flotilla/mantenimiento/nuevo')->assertForbidden();
        $this->post('/flotilla/mantenimiento', ['vehicle_id' => $w['f3']->id, 'performed_at' => now()->toDateString(), 'description' => 'x'])->assertForbidden();
        $this->post("/flotilla/mantenimiento/{$r->id}/anular", ['reason' => 'No autorizado'])->assertForbidden();
        $this->actingAs($supervisor)->get('/flotilla/mantenimiento/nuevo')->assertForbidden();         // supervisor solo ve mantenimiento

        $this->actingAs($other)->get('/flotilla/mantenimiento')->assertOk()->assertDontSee('Trabajo secreto');
        $this->get("/flotilla/mantenimiento/{$r->id}")->assertNotFound();
        $this->post("/flotilla/mantenimiento/{$r->id}/anular", ['reason' => 'Intento de otra empresa'])->assertNotFound();
    }

    // ------------------------------------------------------------------ gomas

    public function test_tire_delivery_without_used_creates_pending_return_per_tire(): void
    {
        $w = $this->world();
        $this->actingAs($w['user'])->post('/flotilla/gomas/entrega', [
            'vehicle_id' => $w['f3']->id, 'product_id' => $w['tire']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 2, 'delivered_at' => now()->toDateString(),
            'odometer' => 415540, 'reason' => 'Desgaste', 'used_received' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actAs($w['company']);
        $this->assertSame(4.0, $w['tire']->stockInWarehouse($w['wh']->id));
        $rows = TireReturn::get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($t) => $t->status === 'pending' && ! $t->used_tire_received && $t->vehicle_id === $w['f3']->id && $t->odometer == 415540.0));
        $this->assertSame($rows[0]->movement_id, $rows[1]->movement_id);
        $this->assertSame('maintenance', InventoryMovement::find($rows[0]->movement_id)->type);
        $this->get('/flotilla/gomas')->assertOk()->assertSee('DEVOLUCIÓN PENDIENTE')->assertSee('F3');
        $this->get("/flotilla/vehiculos/{$w['f3']->id}")->assertOk()->assertSee('Usadas pendientes de devolver');
    }

    public function test_tire_delivery_with_used_received_requires_condition_and_photo_is_saved(): void
    {
        $w = $this->world();
        $base = ['vehicle_id' => $w['f3']->id, 'product_id' => $w['tire']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 1, 'delivered_at' => now()->toDateString(), 'used_received' => '1'];
        $this->actingAs($w['user'])->post('/flotilla/gomas/entrega', $base)->assertSessionHas('error');         // falta el estado de la usada
        $this->actAs($w['company']);
        $this->assertSame(0, TireReturn::count());
        $this->assertSame(6.0, $w['tire']->stockInWarehouse($w['wh']->id));                                    // nada se descontó

        $this->post('/flotilla/gomas/entrega', $base + ['used' => [['condition' => 'repairable', 'notes' => 'Reencauchable']], 'used_photos' => [UploadedFile::fake()->image('g.jpg', 500, 500)]])->assertRedirect()->assertSessionHasNoErrors();
        $t = TireReturn::with('attachments')->firstOrFail();
        $this->assertSame('received', $t->status);
        $this->assertSame('repairable', $t->condition);
        $this->assertSame($w['user']->id, $t->received_by);
        $this->assertCount(1, $t->attachments);
        $this->assertSame(['repairable' => 1], array_filter(app(TireService::class)->usedInventory()));
    }

    public function test_receive_pending_tire_and_waive_with_reason(): void
    {
        $w = $this->world();
        $rows = app(TireService::class)->deliver(['vehicle_id' => $w['f3']->id, 'product_id' => $w['tire']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 2, 'delivered_at' => now()->toDateString(), 'used_received' => false], [], $w['user']);

        $this->actingAs($w['user'])->post("/flotilla/gomas/{$rows[0]->id}/recibir", ['notes' => 'x'])->assertSessionHasErrors('condition');
        $this->post("/flotilla/gomas/{$rows[0]->id}/recibir", ['condition' => 'worn', 'notes' => 'Gastada', 'photo' => [UploadedFile::fake()->image('u.jpg', 400, 400)]])->assertSessionHas('success');
        $t = $rows[0]->fresh();
        $this->assertSame('received', $t->status);
        $this->assertSame($w['user']->id, $t->received_by);
        $this->assertNotNull($t->received_at);
        $this->assertCount(1, $t->attachments);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tire.received', 'auditable_id' => $t->id]);
        $this->post("/flotilla/gomas/{$rows[0]->id}/recibir", ['condition' => 'worn'])->assertSessionHas('error');   // ya recibida

        $this->post("/flotilla/gomas/{$rows[1]->id}/cancelar", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post("/flotilla/gomas/{$rows[1]->id}/cancelar", ['reason' => 'Se perdió en carretera'])->assertSessionHas('success');
        $this->assertSame('waived', $rows[1]->fresh()->status);
        $this->assertSame('Se perdió en carretera', $rows[1]->fresh()->waived_reason);
        $this->get('/flotilla/gomas')->assertOk()->assertSee('No hay devoluciones pendientes');
    }

    public function test_only_tire_products_can_be_delivered_as_tires_and_limits(): void
    {
        $w = $this->world();
        $svc = app(TireService::class);
        $d = ['vehicle_id' => $w['f3']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 1, 'delivered_at' => now()->toDateString(), 'used_received' => false];
        $this->assertThrows(fn () => $svc->deliver($d + ['product_id' => $w['bulb']->id], [], $w['user']), InventoryException::class);
        $this->assertThrows(fn () => $svc->deliver(array_merge($d, ['product_id' => $w['tire']->id, 'quantity' => 21]), [], $w['user']), InventoryException::class);
        $this->assertThrows(fn () => $svc->deliver(array_merge($d, ['product_id' => $w['tire']->id, 'quantity' => 7]), [], $w['user']), InventoryException::class);   // solo hay 6
        $this->assertSame(6.0, $w['tire']->stockInWarehouse($w['wh']->id));
        $this->assertSame(0, TireReturn::count());
    }

    public function test_tire_permissions_and_isolation(): void
    {
        $w = $this->world();
        $rows = app(TireService::class)->deliver(['vehicle_id' => $w['f3']->id, 'product_id' => $w['tire']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 1, 'delivered_at' => now()->toDateString(), 'used_received' => false], [], $w['user']);
        $reader = $this->makeUser($w['company'], 'read_only');
        $other = $this->makeUser($this->makeCompany('B'));

        $this->actingAs($reader)->get('/flotilla/gomas')->assertOk();
        $this->get('/flotilla/gomas')->assertDontSee('Recibir goma usada');
        $this->get('/flotilla/gomas/entrega')->assertForbidden();
        $this->post("/flotilla/gomas/{$rows[0]->id}/recibir", ['condition' => 'worn'])->assertForbidden();
        $this->post("/flotilla/gomas/{$rows[0]->id}/cancelar", ['reason' => 'No autorizado'])->assertForbidden();

        $this->actingAs($other)->get('/flotilla/gomas')->assertOk()->assertDontSee('F3');
        $this->post("/flotilla/gomas/{$rows[0]->id}/recibir", ['condition' => 'worn'])->assertNotFound();
        $this->assertSame('pending', $rows[0]->fresh()->status);
    }

    // ------------------------------------------------------------------ retornables

    public function test_returnable_toner_flow_deliver_use_return_and_limits(): void
    {
        $w = $this->world();
        $this->actingAs($w['user'])->post('/flotilla/retornables/entregar', ['product_id' => $w['toner']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 2, 'holder' => 'Oficina', 'notes' => 'Impresora'])->assertSessionHas('success');
        $this->actAs($w['company']);
        $this->assertSame(2.0, $w['toner']->stockInWarehouse($w['wh']->id));
        $svc = app(ReturnableService::class);
        $this->assertSame(2.0, $svc->outstanding($w['toner']->id, null, 'Oficina'));
        $this->assertSame(2.0, $svc->pending()->first()->outstanding);

        $this->post('/flotilla/retornables/estado', ['product_id' => $w['toner']->id, 'state' => 'used', 'quantity' => 1, 'holder' => 'Oficina'])->assertSessionHas('success');
        $this->post('/flotilla/retornables/estado', ['product_id' => $w['toner']->id, 'state' => 'returned', 'quantity' => 1, 'holder' => 'Oficina'])->assertSessionHas('success');
        $this->assertSame(1.0, $svc->outstanding($w['toner']->id, null, 'Oficina'));
        $this->post('/flotilla/retornables/estado', ['product_id' => $w['toner']->id, 'state' => 'refillable', 'quantity' => 5, 'holder' => 'Oficina'])->assertSessionHas('error');   // más de lo pendiente
        $this->post('/flotilla/retornables/estado', ['product_id' => $w['toner']->id, 'state' => 'discarded', 'quantity' => 1, 'holder' => 'Oficina'])->assertSessionHas('success');
        $this->assertCount(0, $svc->pending());
        $this->assertSame(['delivered', 'used', 'returned', 'discarded'], ReturnableMovement::orderBy('id')->pluck('state')->all());
        $this->get('/flotilla/retornables')->assertOk()->assertSee('Tóner HP')->assertSee('Retornado')->assertSee('Desechado');
    }

    public function test_returnable_rules(): void
    {
        $w = $this->world();
        $svc = app(ReturnableService::class);
        $d = ['warehouse_id' => $w['wh']->id, 'quantity' => 1, 'holder' => 'Oficina'];
        $this->assertThrows(fn () => $svc->deliver($d + ['product_id' => $w['bulb']->id], $w['user']), InventoryException::class);   // no retornable
        $this->assertThrows(fn () => $svc->deliver($d + ['product_id' => $w['tire']->id], $w['user']), InventoryException::class);   // gomas van por su módulo
        $this->assertThrows(fn () => $svc->deliver(['product_id' => $w['toner']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 1], $w['user']), InventoryException::class); // sin portador
        $this->assertThrows(fn () => $svc->deliver(array_merge($d, ['product_id' => $w['toner']->id, 'quantity' => 99]), $w['user']), InventoryException::class);               // sin stock
        $this->assertSame(4.0, $w['toner']->stockInWarehouse($w['wh']->id));
        $this->assertSame(0, ReturnableMovement::count());
        $row = $svc->deliver($d + ['product_id' => $w['toner']->id], $w['user']);
        $row->update(['quantity' => 99]);                       // el historial es inmutable
        $this->assertSame(1.0, $row->fresh()->quantity);
    }

    public function test_returnable_to_vehicle_and_permissions(): void
    {
        $w = $this->world();
        $reader = $this->makeUser($w['company'], 'read_only');
        $this->actingAs($w['user'])->post('/flotilla/retornables/entregar', ['product_id' => $w['toner']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 1, 'vehicle_id' => $w['f3']->id])->assertSessionHas('success');
        $this->actAs($w['company']);
        $this->assertSame(1.0, app(ReturnableService::class)->outstanding($w['toner']->id, $w['f3']->id, null));

        $this->actingAs($reader)->get('/flotilla/retornables')->assertOk()->assertDontSee('Entregar y descontar inventario');
        $this->post('/flotilla/retornables/entregar', ['product_id' => $w['toner']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 1, 'holder' => 'X'])->assertForbidden();
        $this->post('/flotilla/retornables/estado', ['product_id' => $w['toner']->id, 'state' => 'returned', 'quantity' => 1, 'vehicle_id' => $w['f3']->id])->assertForbidden();
    }

    // ------------------------------------------------------------------ expediente y panel

    public function test_vehicle_file_timeline_matches_the_spec_story(): void
    {
        $w = $this->world();
        $inc = app(\App\Services\Fleet\IncidentService::class)->report($w['f3'], ['title' => 'Luz trasera dañada', 'priority' => 'low', 'reported_at' => now()->subDays(2)], $w['user']);
        app(\App\Services\Fleet\MaintenanceService::class)->record($w['f3'], ['performed_at' => now()->subDay()->toDateString(), 'description' => 'Reemplazo de bombilla', 'incident_id' => $inc->id, 'warehouse_id' => $w['wh']->id], [['product_id' => $w['bulb']->id, 'units' => 1]], $w['user']);
        app(\App\Services\Fuel\FuelService::class)->adjust(100, 'Reserva inicial para la prueba', $w['user']);
        app(\App\Services\Fuel\FuelService::class)->dispatch(['dispatched_at' => now()->subDays(3)->toDateString(), 'vehicle_id' => $w['f3']->id, 'gallons' => 25], $w['user']);

        $titles = app(VehicleFile::class)->timeline($w['f3'])->pluck('title')->all();
        $this->assertContains('25.0 galones de gasoil', $titles);
        $this->assertContains('Luz trasera dañada reportada', $titles);
        $this->assertContains('Bombilla entregado (1)', $titles);
        $this->assertContains('Reparación completada', $titles);
        $order = array_flip($titles);
        $this->assertGreaterThan($order['Reparación completada'], $order['Luz trasera dañada reportada']);      // más reciente primero

        $this->actingAs($w['user'])->get("/flotilla/vehiculos/{$w['f3']->id}")->assertOk()->assertSee('Línea de tiempo')->assertSee('Próxima inspección')->assertSee('Materiales / piezas recibidas')->assertSee('Bombilla');
    }

    public function test_dashboard_fleet_cards_use_real_numbers(): void
    {
        $w = $this->world();
        $svc = app(\App\Services\Fleet\IncidentService::class);
        $svc->report($w['f3'], ['title' => 'Frenos', 'priority' => 'critical'], $w['user']);
        $f4 = Vehicle::create(['ficha' => 'F4', 'odometer' => 1, 'status' => 'operational']);
        $i = $svc->report($f4, ['title' => 'Ruido', 'priority' => 'low'], $w['user']);
        $svc->changeStatus($i, 'in_repair', null, $w['user']);
        app(TireService::class)->deliver(['vehicle_id' => $w['f3']->id, 'product_id' => $w['tire']->id, 'warehouse_id' => $w['wh']->id, 'quantity' => 2, 'delivered_at' => now()->toDateString(), 'used_received' => false], [], $w['user']);

        $fleet = $this->actingAs($w['user'])->get('/dashboard')->assertOk()->assertSee('Camiones con averías')->assertSee('Mantenimientos pendientes')->assertSee('Devoluciones pendientes')->assertSee('Vehículos operativos')->viewData('fleet');
        $this->assertSame(2, $fleet['with_incidents']);
        $this->assertSame(1, $fleet['pending_maintenance']);
        $this->assertSame(2, $fleet['pending_returns']);
        $this->assertSame(0, $fleet['operational']);                     // F3 averiado (crítica) y F4 en taller
        $this->assertSame(['F3', 'F4'], $fleet['overdue']->pluck('ficha')->all());     // nunca inspeccionados
        $this->assertSame(1, $fleet['critical']->count());
    }
}
