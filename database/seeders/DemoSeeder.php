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

        auth()->logout();
        app(CurrentCompany::class)->clear();
    }
}
