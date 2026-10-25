<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Inventory\StockService;
use App\Services\Operations\ContainerRemoval;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

class PurgeVoidedInventory extends Command
{
    protected $signature = 'inventory:purge-voided {--dry-run : Solo muestra lo que se borraría}';

    protected $description = 'Borra los documentos, furgones y tickets que ya estaban anulados (con su reverso) y recalcula los saldos; queda constancia en la auditoría';

    public function handle(StockService $stock, ContainerRemoval $removal): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = ['borrados' => 0, 'omitidos' => 0, 'furgones' => 0, 'tickets' => 0];
        foreach (Company::all() as $company) {
            app(CurrentCompany::class)->set($company);
            $r = $stock->purgeVoided($dry);
            foreach ($r['detalle'] as $line) {
                $this->line("{$company->name}: {$line}");
            }
            $total['borrados'] += $r['borrados'];
            $total['omitidos'] += $r['omitidos'];
            $c = $removal->purgeVoided($dry);
            foreach ($c['detalle'] as $line) {
                $this->line("{$company->name}: {$line}");
            }
            $total['furgones'] += $c['furgones'];
            $total['tickets'] += $c['tickets'];
            $total['omitidos'] += $c['omitidos'];
        }
        app(CurrentCompany::class)->clear();
        $this->info(($dry ? 'Simulación: ' : '')."{$total['borrados']} documento(s), {$total['furgones']} furgón(es) y {$total['tickets']} ticket(s) ".($dry ? 'se borrarían' : 'borrados').", {$total['omitidos']} omitido(s).");

        return self::SUCCESS;
    }
}
