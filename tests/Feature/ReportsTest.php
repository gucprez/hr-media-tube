<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerMaterial;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use App\Services\Fleet\IncidentService;
use App\Services\Fleet\MaintenanceService;
use App\Services\Fleet\ReturnableService;
use App\Services\Fleet\TireService;
use App\Services\Fuel\FuelService;
use App\Services\Inventory\StockService;
use App\Services\Reports\Period;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\XlsxExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function world(): array
    {
        $company = $this->makeCompany('Carmen Reportes');
        $user = $this->makeUser($company, 'company_admin');
        $wh = $this->defaultWarehouse($company);
        $bulb = $this->makeProduct($company, ['name' => 'Bombilla', 'sku' => 'BOM', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 2]);
        $tire = $this->makeProduct($company, ['name' => 'Goma 22.5', 'sku' => 'GOMA', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 1, 'is_returnable' => true, 'returnable_kind' => 'tire']);
        $toner = $this->makeProduct($company, ['name' => 'Tóner HP', 'sku' => 'TON', 'handling_mode' => 'units', 'units_per_box' => 1, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 1, 'is_returnable' => true, 'returnable_kind' => 'toner']);
        $box = $this->makeProduct($company, ['name' => 'Caja Cartón', 'sku' => 'CAJ', 'handling_mode' => 'boxes', 'units_per_box' => 12, 'unit' => 'Unidad', 'alert_unit' => 'units', 'min_quantity' => 5]);
        $this->actAs($company);
        $stock = app(StockService::class);
        $stock->entry(['warehouse_id' => $wh->id], [['product_id' => $bulb->id, 'units' => 10], ['product_id' => $tire->id, 'units' => 6], ['product_id' => $toner->id, 'units' => 4], ['product_id' => $box->id, 'boxes' => 10]], $user);
        $stock->exit(['warehouse_id' => $wh->id, 'destination' => 'Oficina'], [['product_id' => $bulb->id, 'units' => 3]], $user);
        $f3 = Vehicle::create(['ficha' => 'F3', 'plate' => 'L-3', 'odometer' => 1000, 'status' => 'operational']);

        $containers = [];
        foreach ([['0001', 1], ['0002', 1], ['0003', 4]] as [$n, $qty]) {
            $c = Container::create(['number' => $n, 'date' => now()->toDateString(), 'status' => 'open', 'warehouse_id' => $wh->id, 'vehicle_id' => $f3->id, 'driver_name' => 'Juan', 'fuel_gallons' => 0]);
            ContainerMaterial::create(['container_id' => $c->id, 'product_id' => $box->id, 'units' => 0, 'boxes' => $qty, 'quantity_base' => $qty * 12]);
            $r = $this->actingAs($user)->post(route('containers.close', $c)); $this->assertTrue(session()->has('success'), (string) session('error'));
            $containers[] = $c;
        }
        $fuel = app(FuelService::class);
        $fuel->receive(['received_at' => now()->toDateString(), 'meter_start' => 1000, 'meter_end' => 1300, 'supplier' => 'Suplidor Z', 'truck_plate' => 'L-9'], $user);
        $fuel->dispatch(['dispatched_at' => now()->subDays(2)->toDateString(), 'vehicle_id' => $f3->id, 'gallons' => 20, 'odometer' => 1000], $user);
        $fuel->dispatch(['dispatched_at' => now()->toDateString(), 'vehicle_id' => $f3->id, 'gallons' => 25, 'odometer' => 1250], $user);
        $inc = app(IncidentService::class)->report($f3, ['title' => 'Luz trasera', 'priority' => 'low'], $user);
        app(MaintenanceService::class)->record($f3, ['performed_at' => now()->toDateString(), 'description' => 'Cambio bombilla', 'incident_id' => $inc->id, 'warehouse_id' => $wh->id], [['product_id' => $bulb->id, 'units' => 1]], $user);
        app(TireService::class)->deliver(['vehicle_id' => $f3->id, 'product_id' => $tire->id, 'warehouse_id' => $wh->id, 'quantity' => 1, 'delivered_at' => now()->toDateString(), 'used_received' => false], [], $user);
        app(ReturnableService::class)->deliver(['product_id' => $toner->id, 'warehouse_id' => $wh->id, 'quantity' => 2, 'holder' => 'Oficina'], $user);

        return compact('company', 'user', 'wh', 'bulb', 'tire', 'toner', 'box', 'f3', 'containers');
    }

    public function test_period_presets(): void
    {
        $p = Period::fromInput(['range' => 'quarter', 'date' => '2026-05-15']);
        $this->assertSame(['2026-04-01', '2026-06-30'], [$p->from->toDateString(), $p->to->toDateString()]);
        $p = Period::fromInput(['range' => 'semester', 'date' => '2026-08-02']);
        $this->assertSame(['2026-07-01', '2026-12-31'], [$p->from->toDateString(), $p->to->toDateString()]);
        $p = Period::fromInput(['range' => 'week', 'date' => '2026-10-14']);   // miércoles
        $this->assertSame(['2026-10-12', '2026-10-18'], [$p->from->toDateString(), $p->to->toDateString()]);
        $p = Period::fromInput(['range' => 'custom', 'from' => '2026-03-10', 'to' => '2026-03-01']);   // invertido: se corrige
        $this->assertSame(['2026-03-01', '2026-03-10'], [$p->from->toDateString(), $p->to->toDateString()]);
        $this->assertSame('month', Period::fromInput(['range' => 'basura', 'date' => 'no-es-fecha'])->range);
        $this->assertSame('2026-10-01', Period::fromInput(['range' => 'month', 'date' => '2026-10-20'])->from->toDateString());
    }

    public function test_every_report_renders_and_exports_without_errors(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $w = $this->world();
        $this->actingAs($w['user']);
        $registry = app(ReportRegistry::class);
        $count = 0;
        foreach ($registry->all() as $section => $defs) {
            foreach ($defs as $key => $def) {
                $count++;
                $q = ['range' => 'month'] + (isset($def['filters']['vehicle']) && $key === 'vehicle-history' ? ['vehicle' => $w['f3']->id] : []) + ($key === 'container-history' ? ['container' => $w['containers'][0]->id] : []);
                $this->get(route('reports.run', [$section, $key] + $q))->assertOk()->assertSee($def['title']);
                $x = $this->get(route('reports.run', [$section, $key] + $q + ['format' => 'xlsx']));
                $x->assertOk();
                $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));
                $pdf = $this->get(route('reports.run', [$section, $key] + $q + ['format' => 'pdf']));
                $pdf->assertOk();
                $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
            }
        }
        $this->assertGreaterThanOrEqual(35, $count);
    }

    public function test_sections_cover_the_spec_catalogue(): void
    {
        $all = app(ReportRegistry::class)->all();
        $this->assertSame(['audit', 'containers', 'fuel', 'maintenance', 'returnables', 'tires', 'vehicles', 'warehouse'], collect(array_keys($all))->sort()->values()->all());
        foreach (['inventory-current', 'low-stock', 'out-of-stock', 'entries', 'exits', 'movements', 'container-consumption', 'container-comparison', 'fuel-consumption', 'fuel-reserve', 'fuel-efficiency', 'vehicle-materials',
            'tires-delivered', 'tires-received', 'tires-pending', 'returnables-delivered', 'returnables-returned', 'inspections', 'incidents', 'maintenance-records', 'parts-used', 'vehicle-history', 'container-history',
            'audit-log', 'audit-users', 'audit-adjustments', 'audit-access'] as $key) {
            $found = collect($all)->flatten(1)->firstWhere('key', $key);
            $this->assertNotNull($found, $key);
        }
        $center = $this->actingAs($this->world()['user'])->get('/reportes')->assertOk()->getContent();
        $this->assertStringNotContainsString('en desarrollo', $center);
    }

    public function test_report_values_come_from_history(): void
    {
        $w = $this->world();
        $this->actingAs($w['user']);
        $get = fn (string $s, string $k, array $q = []) => $this->get(route('reports.run', [$s, $k] + $q))->assertOk()->viewData('result');

        $r = $get('warehouse', 'inventory-movement', ['range' => 'month']);
        $bulb = collect($r->rows)->firstWhere('sku', 'BOM');
        $this->assertSame([0.0, 10.0, 3.0, -1.0, 6.0], [$bulb['initial'], $bulb['entries'], $bulb['exits'], $bulb['others'], $bulb['final']]);

        $inv = $get('warehouse', 'inventory-current');
        $this->assertSame(['BOM', 'CAJ', 'GOMA', 'TON'], collect($inv->rows)->pluck('sku')->sort()->values()->all());
        $this->assertSame(48.0, (float) collect($inv->rows)->firstWhere('sku', 'CAJ')['total']);     // 120 − 72 consumidas por los furgones
        $this->assertCount(0, $get('warehouse', 'low-stock')->rows);
        $this->assertCount(0, $get('warehouse', 'out-of-stock')->rows);
        $cat = $get('warehouse', 'inventory-by-category');
        $this->assertSame(4.0, $cat->totals['products']);
    }

    public function test_container_comparison_flags_excess(): void
    {
        $w = $this->world();
        $r = $this->actingAs($w['user'])->get(route('reports.run', ['containers', 'container-comparison', 'range' => 'month']))->assertOk()->viewData('result');
        $flags = collect($r->rows)->mapWithKeys(fn ($x) => [$x['number'] => $x['flag']]);
        $this->assertSame('Exceso', $flags['0003']);      // 48 contra promedio 24
        $this->assertNull($flags['0001']);
        $this->assertNull($flags['0002']);
        $c = $this->get(route('reports.run', ['containers', 'container-consumption', 'range' => 'month']))->viewData('result');
        $this->assertSame(72.0, $c->totals['quantity']);
    }

    public function test_fuel_reports(): void
    {
        $w = $this->world();
        $this->actingAs($w['user']);
        $r = $this->get(route('reports.run', ['fuel', 'fuel-consumption', 'range' => 'month']))->viewData('result');
        $this->assertSame(45.0, $r->totals['gallons']);
        $this->assertSame(10.0, $r->rows[0]['km_gal']);         // 250 km / 25 gal
        $r = $this->get(route('reports.run', ['fuel', 'fuel-receipts', 'range' => 'month']))->viewData('result');
        $this->assertSame(300.0, $r->totals['gallons']);
        $r = $this->get(route('reports.run', ['fuel', 'fuel-reserve', 'range' => 'month']))->viewData('result');
        $this->assertSame(255.0, (float) end($r->rows)['after']);
    }

    public function test_fleet_reports(): void
    {
        $w = $this->world();
        $this->actingAs($w['user']);
        $parts = $this->get(route('reports.run', ['maintenance', 'parts-used', 'range' => 'month']))->viewData('result');
        $this->assertSame(1.0, $parts->totals['quantity']);
        $this->assertSame('Bombilla', $parts->rows[0]['name']);
        $mat = $this->get(route('reports.run', ['vehicles', 'vehicle-materials', 'range' => 'month']))->viewData('result');
        $this->assertSame(2.0, $mat->totals['quantity']);      // la bombilla del mantenimiento + la goma entregada
        $this->assertSame(1, count($this->get(route('reports.run', ['tires', 'tires-pending']))->viewData('result')->rows));
        $this->assertSame(2.0, $this->get(route('reports.run', ['returnables', 'returnables-pending']))->viewData('result')->totals['outstanding']);
        $hist = $this->get(route('reports.run', ['vehicles', 'vehicle-history', 'range' => 'month', 'vehicle' => $w['f3']->id]))->viewData('result');
        $this->assertGreaterThanOrEqual(5, count($hist->rows));
        $this->assertSame(1, count($this->get(route('reports.run', ['maintenance', 'incidents', 'range' => 'month', 'status' => 'repaired']))->viewData('result')->rows));
    }

    public function test_excel_has_real_numeric_cells_and_totals(): void
    {
        $w = $this->world();
        $res = $this->actingAs($w['user'])->get(route('reports.run', ['fuel', 'fuel-consumption', 'range' => 'month', 'format' => 'xlsx']));
        $file = $res->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($file);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        $this->assertSame('Carmen Reportes', $rows[0][0]);
        $data = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'F3');
        $this->assertTrue(is_float($data[3]) || is_int($data[3]));
        $this->assertEquals(45, $data[3]);
        $total = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'TOTAL');
        $this->assertEquals(45, $total[3]);
    }

    public function test_excel_neutralises_formulas(): void
    {
        $e = new XlsxExporter;
        $m = new \ReflectionMethod($e, 'safe');
        $this->assertSame("'=HYPERLINK(\"x\")", $m->invoke($e, '=HYPERLINK("x")'));
        $this->assertSame('normal', $m->invoke($e, 'normal'));
    }

    public function test_permissions_per_section_and_export(): void
    {
        $w = $this->world();
        $fuelOnly = $this->makeUser($w['company'], 'fuel');
        $this->actingAs($fuelOnly);
        $this->get(route('reports.run', ['fuel', 'fuel-receipts']))->assertOk();
        $this->get(route('reports.run', ['audit', 'audit-log']))->assertForbidden();
        $this->get(route('reports.run', ['vehicles', 'fleet-status']))->assertForbidden();
        $this->get(route('reports.run', ['fuel', 'fuel-receipts', 'format' => 'xlsx']))->assertForbidden();     // sin reports.export
        $this->get(route('reports.run', ['fuel', 'no-existe']))->assertNotFound();
        $this->get('/reportes/inventado/algo')->assertNotFound();

        $auditor = $this->makeUser($w['company'], 'auditor');
        $this->actingAs($auditor)->get(route('reports.run', ['audit', 'audit-log']))->assertOk();
        $this->get(route('reports.run', ['audit', 'audit-log', 'format' => 'pdf']))->assertOk();
        $this->actingAs($this->makeUser($w['company'], 'read_only'))->get(route('reports.run', ['audit', 'audit-log']))->assertForbidden();
    }

    public function test_exports_are_audited_and_isolated_by_company(): void
    {
        $w = $this->world();
        $this->actingAs($w['user'])->get(route('reports.run', ['fuel', 'fuel-receipts', 'format' => 'xlsx']))->assertOk();
        $this->assertTrue(AuditLog::where('action', 'report.export')->where('description', 'like', '%Recepciones de gasoil%')->exists());

        $other = $this->makeUser($this->makeCompany('Otra SA'), 'company_admin');
        $this->actingAs($other);
        foreach (['fuel-receipts', 'fuel-dispatches', 'fuel-consumption', 'fuel-reserve'] as $k) {
            $this->assertCount(0, $this->get(route('reports.run', ['fuel', $k, 'range' => 'month']))->viewData('result')->rows, $k);
        }
        $this->assertCount(0, $this->get(route('reports.run', ['warehouse', 'entries', 'range' => 'month']))->viewData('result')->rows);
        $this->assertCount(0, $this->get(route('reports.run', ['containers', 'containers-period', 'range' => 'month']))->viewData('result')->rows);
        $this->assertCount(0, $this->get(route('reports.run', ['audit', 'audit-log', 'range' => 'month']))->viewData('result')->rows);
        $this->get(route('reports.run', ['containers', 'container-history', 'container' => $w['containers'][0]->id]))->assertOk()->assertSee('Seleccione un furgón');
    }

    public function test_inventory_screens_offer_exports(): void
    {
        $w = $this->world();
        $this->actingAs($w['user'])->get('/reportes/almacen/inventario-actual')->assertOk()->assertSee('format=xlsx', false);
        $this->get('/reportes/almacen/movimiento-inventario')->assertOk()->assertSee('format=pdf', false);
    }
}
