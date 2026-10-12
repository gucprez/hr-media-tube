<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Inventory\InventoryLedger;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

class VerifyInventory extends Command
{
    protected $signature = 'inventory:verify';

    protected $description = 'Verifica que el stock de cada producto coincida con la suma de su libro de movimientos';

    public function handle(InventoryLedger $ledger): int
    {
        $bad = 0;
        foreach (Company::all() as $company) {
            app(CurrentCompany::class)->set($company);
            foreach ($ledger->discrepancies() as $d) {
                $bad++;
                $this->error("{$company->name}: almacén {$d['warehouse_id']} producto {$d['product_id']} → stock {$d['stock']} ≠ libro {$d['ledger']}");
            }
        }
        app(CurrentCompany::class)->clear();
        $bad ? $this->error("{$bad} discrepancia(s).") : $this->info('Inventario íntegro: todos los stocks coinciden con el libro de movimientos.');

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
