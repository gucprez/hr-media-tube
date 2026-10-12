<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Fuel\FuelException;
use App\Services\Fuel\FuelService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

class FuelConcurrencyWorker extends Command
{
    protected $signature = 'fuel:concurrency-worker {company} {vehicle} {user} {startAt}';

    protected $description = 'Interno: despacha 3 galones en el instante indicado';

    protected $hidden = true;

    public function handle(FuelService $fuel): int
    {
        app(CurrentCompany::class)->set(Company::find($this->argument('company')));
        app(CurrentCompany::class)->enforce();
        while (microtime(true) < (float) $this->argument('startAt')) {
            usleep(500);
        }
        try {
            $fuel->dispatch(['dispatched_at' => now()->toDateString(), 'vehicle_id' => (int) $this->argument('vehicle'), 'gallons' => 3, 'origin' => 'container'], User::find($this->argument('user')));
            $this->line('OK');
        } catch (FuelException $e) {
            $this->line('RECHAZADO: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
