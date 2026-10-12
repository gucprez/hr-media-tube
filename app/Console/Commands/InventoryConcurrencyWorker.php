<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/** Uso interno de inventory:concurrency-check. */
class InventoryConcurrencyWorker extends Command
{
    protected $signature = 'inventory:concurrency-worker {company} {warehouse} {product} {user} {startAt}';

    protected $description = 'Interno: despacha 3 unidades en el instante indicado';

    protected $hidden = true;

    public function handle(StockService $stock): int
    {
        app(CurrentCompany::class)->set(Company::find($this->argument('company')));
        app(CurrentCompany::class)->enforce();
        $user = User::find($this->argument('user'));

        while (microtime(true) < (float) $this->argument('startAt')) {
            usleep(500);
        }
        try {
            $stock->exit(['warehouse_id' => (int) $this->argument('warehouse'), 'destination' => 'Concurrencia', 'reason' => 'Prueba'], [['product_id' => (int) $this->argument('product'), 'units' => 3]], $user);
            $this->line('OK');
        } catch (InventoryException $e) {
            $this->line('RECHAZADO: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
