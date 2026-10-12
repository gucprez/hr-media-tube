<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Fuel\FuelService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

class VerifyFuel extends Command
{
    protected $signature = 'fuel:verify';

    protected $description = 'Verifica que la reserva de gasoil coincida con la suma de su libro';

    public function handle(FuelService $fuel): int
    {
        $bad = 0;
        foreach (Company::all() as $company) {
            app(CurrentCompany::class)->set($company);
            foreach ($fuel->discrepancies() as $d) {
                $bad++;
                $this->error("{$company->name}: tanque «{$d['tank']}» reserva {$d['reserve']} ≠ libro {$d['ledger']}");
            }
        }
        app(CurrentCompany::class)->clear();
        $bad ? $this->error("{$bad} discrepancia(s).") : $this->info('Combustible íntegro: la reserva coincide con el libro.');

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
