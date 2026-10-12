<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockService;
use App\Support\CurrentCompany;
use App\Services\CompanyProvisioner;
use Illuminate\Database\Seeder;

/** Datos DEMO para probar de inmediato (empresa Carmen Cargo Express). No usar en producción real. */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo12345';

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        if (Company::where('slug', 'carmen-cargo-express')->exists()) {
            $this->command?->info('La empresa demo ya existe.');

            return;
        }

        $company = app(CompanyProvisioner::class)->create([
            'name' => 'Carmen Cargo Express', 'slug' => 'carmen-cargo-express', 'legal_name' => 'Carmen Cargo Express SRL',
            'rnc' => '1-31-00000-0', 'address' => 'Santo Domingo, República Dominicana', 'phone' => '809-000-0000',
            'email' => 'info@carmencargo.example',
        ], [
            'first_name' => 'Carmen', 'last_name' => 'Administradora', 'username' => 'carmen', 'email' => 'carmen@carmencargo.example', 'password' => self::PASSWORD,
        ]);

        $roles = Role::where('company_id', $company->id)->pluck('id', 'slug');
        $branchId = $company->branches()->value('id');
        $people = [
            ['Juan', 'Pérez', 'juan', 'warehouse_manager'],
            ['Pedro', 'Operador', 'pedro', 'operator'],
            ['Luis', 'Supervisor', 'luis', 'supervisor'],
            ['Germán', 'Mantenimiento', 'german', 'maintenance'],
            ['Rosa', 'Combustible', 'rosa', 'fuel'],
            ['Ana', 'Auditora', 'ana', 'auditor'],
            ['Mario', 'Consulta', 'mario', 'read_only'],
        ];
        foreach ($people as [$first, $last, $username, $role]) {
            $user = new User([
                'company_id' => $company->id, 'branch_id' => $branchId, 'role_id' => $roles[$role],
                'first_name' => $first, 'last_name' => $last, 'username' => $username,
                'email' => "{$username}@carmencargo.example", 'password' => self::PASSWORD,
            ]);
            $user->is_active = true;
            $user->save();
        }

        $this->seedInventory($company);

        $this->command?->info('Empresa demo creada. Usuarios: carmen, juan, pedro, luis, german, rosa, ana, mario — contraseña: '.self::PASSWORD);
    }

    /** Productos y movimientos ficticios (solo demo) generados con el motor real de inventario. */
    protected function seedInventory(Company $company): void
    {
        $actor = User::withoutGlobalScopes()->where('username', 'juan')->first();
        $admin = User::withoutGlobalScopes()->where('username', 'carmen')->first();
        auth()->setUser($actor);
        app(CurrentCompany::class)->set($company);
        app(CurrentCompany::class)->enforce();

        $cat = fn (string $n) => Category::create(['name' => $n])->id;
        $packaging = $cat('Embalaje');
        $filters = $cat('Filtros y lubricantes');
        $office = $cat('Oficina y tóner');
        $tires = $cat('Gomas');
        $parts = $cat('Piezas eléctricas');

        $unit = fn (string $n) => Unit::where('name', $n)->value('id');
        $wh = Warehouse::where('is_default', true)->first();

        $defs = [
            ['TAPE-TR', 'Tape Transparente', $packaging, 'Rollo', 'both', 36, 'boxes', 1, ['aisle' => 'A', 'shelf' => '1']],
            ['PLAS-ST', 'Plástico Stretch', $packaging, 'Rollo', 'both', 12, 'boxes', 2, ['aisle' => 'A', 'shelf' => '2']],
            ['FLEJE', 'Fleje', $packaging, 'Rollo', 'units', 1, 'units', 5, ['aisle' => 'A', 'shelf' => '3']],
            ['TONER-85A', 'Tóner HP 85A', $office, 'Unidad', 'units', 1, 'units', 2, ['aisle' => 'B', 'shelf' => '1', 'ret' => 'toner']],
            ['GOMA-225', 'Goma Camión 22.5', $tires, 'Unidad', 'units', 1, 'units', 2, ['aisle' => 'C', 'location' => 'Patio techado', 'ret' => 'tire']],
            ['FIL-AIRE', 'Filtro de Aire', $filters, 'Unidad', 'units', 1, 'units', 2, ['aisle' => 'B', 'shelf' => '2']],
            ['FIL-ACEITE', 'Filtro de Aceite', $filters, 'Unidad', 'units', 1, 'units', 2, ['aisle' => 'B', 'shelf' => '2']],
            ['ACEITE-15W40', 'Aceite 15W40', $filters, 'Galón', 'units', 1, 'units', 5, ['aisle' => 'B', 'shelf' => '3']],
            ['BOMBILLA', 'Bombilla', $parts, 'Unidad', 'units', 1, 'units', 5, ['aisle' => 'B', 'shelf' => '4']],
        ];
        $products = [];
        foreach ($defs as [$sku, $name, $category, $unitName, $mode, $perBox, $alertUnit, $min, $extra]) {
            $p = new Product([
                'sku' => $sku, 'name' => $name, 'category_id' => $category, 'unit_id' => $unit($unitName), 'default_warehouse_id' => $wh->id,
                'handling_mode' => $mode, 'units_per_box' => $perBox, 'box_label' => 'caja', 'alert_unit' => $alertUnit, 'min_quantity' => $min,
                'aisle' => $extra['aisle'] ?? null, 'shelf' => $extra['shelf'] ?? null, 'location' => $extra['location'] ?? null,
                'is_returnable' => isset($extra['ret']), 'returnable_kind' => $extra['ret'] ?? null, 'is_active' => true,
            ]);
            $p->created_by = $admin->id;
            $p->min_stock_base = app(QuantityConverter::class)->minimumBase($p);
            $p->save();
            $products[$sku] = $p->id;
        }

        $stock = app(StockService::class);
        $at = fn (int $daysAgo) => now()->subDays($daysAgo)->setTime(10, 30);
        $L = fn (string $sku, $boxes = 0, $units = 0) => ['product_id' => $products[$sku], 'boxes' => $boxes, 'units' => $units];

        // Entradas
        $stock->entry(['warehouse_id' => $wh->id, 'occurred_at' => $at(40), 'supplier' => 'Suplidora Nacional', 'document_no' => 'CD-1001', 'notes' => 'Inventario inicial demo'],
            [$L('TAPE-TR', 8), $L('PLAS-ST', 4), $L('FLEJE', 0, 60), $L('TONER-85A', 0, 6), $L('GOMA-225', 0, 8), $L('FIL-AIRE', 0, 10), $L('FIL-ACEITE', 0, 10), $L('ACEITE-15W40', 0, 40), $L('BOMBILLA', 0, 12)], $actor);
        $stock->entry(['warehouse_id' => $wh->id, 'occurred_at' => $at(12), 'supplier' => 'Suplidora Nacional', 'document_no' => 'CD-1042'],
            [$L('TAPE-TR', 2, 8), $L('PLAS-ST', 1)], $actor);

        // Salidas (consumo operativo)
        $stock->exit(['warehouse_id' => $wh->id, 'occurred_at' => $at(30), 'destination' => 'Furgón 0101', 'destination_type' => 'other', 'reason' => 'Consumo operativo'],
            [$L('TAPE-TR', 1), $L('PLAS-ST', 0, 14), $L('FLEJE', 0, 2)], $actor);
        $stock->exit(['warehouse_id' => $wh->id, 'occurred_at' => $at(21), 'destination' => 'Taller', 'destination_type' => 'other', 'reason' => 'Mantenimiento F3'],
            [$L('FIL-AIRE', 0, 9), $L('FIL-ACEITE', 0, 4), $L('ACEITE-15W40', 0, 12), $L('BOMBILLA', 0, 12)], $actor);
        $stock->exit(['warehouse_id' => $wh->id, 'occurred_at' => $at(9), 'destination' => 'Furgón 0111', 'destination_type' => 'other', 'reason' => 'Consumo operativo'],
            [$L('TAPE-TR', 1, 7), $L('PLAS-ST', 1, 2), $L('FLEJE', 0, 3)], $actor);
        $stock->exit(['warehouse_id' => $wh->id, 'occurred_at' => $at(5), 'destination' => 'Oficina', 'destination_type' => 'employee', 'employee_name' => 'Rosa Combustible', 'reason' => 'Reposición de tóner'],
            [$L('TONER-85A', 0, 4), $L('GOMA-225', 0, 2)], $actor);

        // Ajuste por conteo físico
        $stock->adjust(['warehouse_id' => $wh->id, 'occurred_at' => $at(2), 'reason' => 'Conteo físico', 'notes' => 'Conteo mensual: faltan 2 rollos de fleje'],
            [$L('FLEJE', 0, 53)], $actor);

        $this->seedOperations($company, $actor, $products);

        auth()->logout();
        app(CurrentCompany::class)->clear();
    }

    /** Choferes, vehículos F1–F3, furgones (abierto y cerrados) y tickets de ejemplo. */
    protected function seedOperations(Company $company, User $actor, array $products): void
    {
        $type = \App\Models\VehicleType::where('name', 'Camión')->first();
        $drivers = [];
        foreach ([['Pedro Martínez', '809-555-0101'], ['Luis Gómez', '809-555-0102'], ['José Rodríguez', '809-555-0103']] as [$name, $phone]) {
            $drivers[] = \App\Models\Driver::create(['name' => $name, 'phone' => $phone, 'is_active' => true]);
        }
        $vehicles = [];
        foreach ([['F1', 'ABC-1001', 'Freightliner', 'Cascadia', 2018, 415200.5], ['F2', 'ABC-1002', 'International', 'LT', 2017, 389100.0], ['F3', 'ABC-1003', 'Kenworth', 'T680', 2019, 415540.0]] as $i => [$ficha, $plate, $brand, $model, $year, $km]) {
            $vehicles[] = \App\Models\Vehicle::create([
                'ficha' => $ficha, 'plate' => $plate, 'brand' => $brand, 'model' => $model, 'year' => $year, 'odometer' => $km,
                'vehicle_type_id' => $type?->id, 'usual_driver_id' => $drivers[$i]->id, 'status' => 'operational',
            ]);
        }

        $wh = Warehouse::where('is_default', true)->first();
        $stock = app(StockService::class);
        $converter = app(QuantityConverter::class);
        $lines = fn (array $spec) => collect($spec)->map(fn ($q, $sku) => ['product_id' => $products[$sku], 'boxes' => $q[0] ?? 0, 'units' => $q[1] ?? 0])->values()->all();

        $make = function (string $number, int $daysAgo, int $v, array $spec, float $fuel, string $conduce, bool $close) use ($wh, $drivers, $vehicles, $actor, $lines, $converter, $stock) {
            $c = \App\Models\Container::create([
                'number' => $number, 'date' => now()->subDays($daysAgo)->toDateString(), 'warehouse_id' => $wh->id, 'driver_id' => $drivers[$v]->id,
                'driver_name' => $drivers[$v]->name, 'phone' => $drivers[$v]->phone, 'vehicle_id' => $vehicles[$v]->id, 'conduce' => $conduce,
                'fuel_gallons' => $fuel, 'responsible_id' => $actor->id, 'status' => 'open',
            ]);
            foreach ($lines($spec) as $l) {
                $p = Product::with('unit')->find($l['product_id']);
                $base = $converter->toBase($p, $l['boxes'], $l['units']);
                [$b, $u] = $converter->split($p, $base);
                \App\Models\ContainerMaterial::create(['container_id' => $c->id, 'product_id' => $p->id, 'product_sku' => $p->sku, 'product_name' => $p->name, 'boxes' => $b, 'units' => $u, 'quantity_base' => $base]);
            }
            \App\Services\Audit::log('container.created', "Furgón {$number} creado", $c);
            if ($close) {
                $m = $stock->consume([
                    'warehouse_id' => $wh->id, 'container_id' => $c->id, 'vehicle_id' => $c->vehicle_id, 'destination' => 'Furgón '.$number, 'destination_type' => 'container',
                    'employee_name' => $c->driver_name, 'document_no' => $conduce, 'reference' => 'Furgón '.$number, 'reason' => 'Consumo de furgón',
                    'occurred_at' => now()->subDays($daysAgo)->setTime(16, 0), 'source_type' => $c->getMorphClass(), 'source_id' => $c->id,
                ], $c->materials->map(fn ($m) => ['product_id' => $m->product_id, 'boxes' => $m->boxes, 'units' => $m->units])->all(), $actor);
                $c->forceFill(['status' => 'closed', 'closed_at' => now()->subDays($daysAgo)->setTime(16, 0), 'closed_by' => $actor->id, 'movement_id' => $m->id])->save();
                \App\Services\Audit::log('container.closed', "Furgón {$number} cerrado", $c);
            }

            return $c->fresh(['materials.product.unit', 'vehicle']);
        };

        $c101 = $make('0101', 20, 0, ['TAPE-TR' => [1, 0], 'PLAS-ST' => [0, 12], 'FLEJE' => [0, 2], 'FIL-ACEITE' => [0, 1]], 25, 'CD-5101', true);
        $c110 = $make('0110', 11, 1, ['TAPE-TR' => [1, 6], 'PLAS-ST' => [1, 0], 'FLEJE' => [0, 3]], 30, 'CD-5110', true);
        $c111 = $make('0111', 6, 2, ['TAPE-TR' => [1, 0], 'PLAS-ST' => [0, 6], 'FLEJE' => [0, 2], 'FIL-ACEITE' => [0, 1]], 25, 'CD-5111', true);
        $make('0112', 0, 0, ['TAPE-TR' => [0, 18], 'FLEJE' => [0, 2]], 20, 'CD-5112', false);

        // Tickets de ejemplo (emitidos con el servicio real de numeración)
        $numbering = app(\App\Services\DocumentNumbering::class);
        foreach ([$c101, $c111] as $c) {
            $t = \App\Models\Ticket::create([
                'container_id' => $c->id, 'vehicle_id' => $c->vehicle_id, 'type' => 'materials', 'number' => $numbering->next('ticket_materials'), 'status' => 'issued',
                'issued_at' => $c->date, 'container_number' => $c->number, 'driver_name' => $c->driver_name, 'conduce' => $c->conduce, 'ficha' => $c->vehicle->ficha,
                'phone' => $c->phone, 'dispatched_by' => $actor->name, 'received_by' => $c->driver_name, 'created_by' => $actor->id,
            ]);
            foreach ($c->materials as $m) {
                \App\Models\TicketItem::create(['ticket_id' => $t->id, 'product_id' => $m->product_id, 'description' => $m->product_name, 'quantity_label' => $converter->format($m->product, $m->quantity_base),
                    'total_units' => $m->quantity_base, 'unit_label' => $converter->unitLabel($m->product, $m->quantity_base)]);
            }
            $f = \App\Models\Ticket::create([
                'container_id' => $c->id, 'vehicle_id' => $c->vehicle_id, 'type' => 'fuel', 'number' => $numbering->next('ticket_fuel'), 'status' => 'issued',
                'issued_at' => $c->date, 'container_number' => $c->number, 'driver_name' => $c->driver_name, 'conduce' => $c->conduce, 'ficha' => $c->vehicle->ficha,
                'phone' => $c->phone, 'dispatched_by' => $actor->name, 'received_by' => $c->driver_name, 'created_by' => $actor->id,
            ]);
            \App\Models\TicketItem::create(['ticket_id' => $f->id, 'description' => 'Gasoil', 'ficha' => $c->vehicle->ficha, 'odometer' => $c->vehicle->odometer, 'gallons' => $c->fuel_gallons]);
        }
    }
}
