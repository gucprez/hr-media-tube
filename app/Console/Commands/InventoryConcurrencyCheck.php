<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CompanyProvisioner;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\StockService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Prueba de estrés real (procesos paralelos) del bloqueo de inventario. Requiere MySQL/MariaDB (InnoDB):
 * crea una empresa temporal, deja 10 unidades y lanza N procesos que despachan 3 unidades a la vez.
 * Deben tener éxito exactamente 3 (9 unidades); el resto debe ser rechazado y el stock jamás puede ser negativo.
 */
class InventoryConcurrencyCheck extends Command
{
    protected $signature = 'inventory:concurrency-check {--workers=8} {--keep : No borrar la empresa temporal}';

    protected $description = 'Verifica con procesos paralelos que dos despachos simultáneos no pueden dejar stock negativo';

    public function handle(): int
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->error('Esta prueba requiere MySQL/MariaDB (SQLite serializa todo el archivo y no representa el bloqueo real).');

            return self::FAILURE;
        }

        $workers = max(2, (int) $this->option('workers'));
        $slug = 'concurrency-'.Str::lower(Str::random(6));
        $company = app(CompanyProvisioner::class)->create(['name' => 'CHECK '.$slug, 'slug' => $slug], [
            'first_name' => 'Check', 'last_name' => 'Bot', 'username' => 'chk'.Str::lower(Str::random(8)), 'email' => $slug.'@check.local', 'password' => Str::password(16),
        ]);
        app(CurrentCompany::class)->set($company);
        app(CurrentCompany::class)->enforce();

        $user = User::where('company_id', $company->id)->first();
        $wh = Warehouse::where('is_default', true)->first();
        $product = Product::create([
            'sku' => 'CONC', 'name' => 'Producto de prueba', 'unit_id' => Unit::first()->id, 'handling_mode' => 'units', 'units_per_box' => 1,
            'alert_unit' => 'units', 'min_quantity' => 0, 'is_active' => true,
        ]);
        app(StockService::class)->entry(['warehouse_id' => $wh->id], [['product_id' => $product->id, 'units' => 10]], $user);

        $startAt = microtime(true) + 3; // barrera: todos arrancan en el mismo instante
        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $p = new Process([PHP_BINARY, base_path('artisan'), 'inventory:concurrency-worker', $company->id, $wh->id, $product->id, $user->id, (string) $startAt]);
            $p->start();
            $procs[] = $p;
        }
        $ok = 0;
        $rejected = 0;
        foreach ($procs as $p) {
            $p->wait();
            str_contains($p->getOutput(), 'OK') ? $ok++ : $rejected++;
        }

        $stock = $product->stockInWarehouse($wh->id);
        $consistent = app(InventoryLedger::class)->discrepancies()->isEmpty();
        $this->line("Procesos: {$workers} · despachos aceptados: {$ok} · rechazados: {$rejected} · stock final: {$stock}");

        $pass = $ok === 3 && $rejected === $workers - 3 && $stock == 1.0 && $consistent;
        $pass ? $this->info('✔ Sin sobreventa: el bloqueo funciona y el libro coincide con el stock.') : $this->error('✘ FALLÓ la verificación de concurrencia.');

        if (! $this->option('keep')) {
            $this->cleanup($company);
        }
        app(CurrentCompany::class)->clear();

        return $pass ? self::SUCCESS : self::FAILURE;
    }

    protected function cleanup(Company $company): void
    {
        \App\Support\PurgeCompany::run($company->id);
    }
}
