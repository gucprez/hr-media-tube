<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyProvisioner;
use App\Services\Fuel\FuelException;
use App\Services\Fuel\FuelService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Prueba de estrés (procesos paralelos, MySQL/MariaDB): 10 galones de reserva y N despachos simultáneos de 3 galones. Deben pasar exactamente 3. */
class FuelConcurrencyCheck extends Command
{
    protected $signature = 'fuel:concurrency-check {--workers=8} {--keep}';

    protected $description = 'Verifica con procesos paralelos que dos despachos simultáneos no pueden dejar la reserva en negativo';

    public function handle(): int
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->error('Esta prueba requiere MySQL/MariaDB.');

            return self::FAILURE;
        }
        $workers = max(2, (int) $this->option('workers'));
        $slug = 'fuelcheck-'.Str::lower(Str::random(6));
        $company = app(CompanyProvisioner::class)->create(['name' => 'CHECK '.$slug, 'slug' => $slug], [
            'first_name' => 'Check', 'last_name' => 'Bot', 'username' => 'chk'.Str::lower(Str::random(8)), 'email' => $slug.'@check.local', 'password' => Str::password(16),
        ]);
        app(CurrentCompany::class)->set($company);
        app(CurrentCompany::class)->enforce();
        $user = User::where('company_id', $company->id)->first();
        $vehicle = Vehicle::create(['ficha' => 'T1', 'odometer' => 0, 'status' => 'operational']);
        $fuel = app(FuelService::class);
        $fuel->adjust(10.0, 'Reserva de prueba de concurrencia', $user);

        $startAt = microtime(true) + 3;
        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $p = new Process([PHP_BINARY, base_path('artisan'), 'fuel:concurrency-worker', $company->id, $vehicle->id, $user->id, (string) $startAt]);
            $p->start();
            $procs[] = $p;
        }
        $ok = $rej = 0;
        foreach ($procs as $p) {
            $p->wait();
            str_contains($p->getOutput(), 'OK') ? $ok++ : $rej++;
        }
        $reserve = $fuel->tank()->current_gallons;
        $this->line("Procesos: {$workers} · despachos aceptados: {$ok} · rechazados: {$rej} · reserva final: {$reserve}");
        $pass = $ok === 3 && $reserve == 1.0 && $fuel->discrepancies() === [];
        $pass ? $this->info('✔ Sin reserva negativa: el bloqueo funciona y el libro coincide.') : $this->error('✘ FALLÓ la verificación de concurrencia.');

        if (! $this->option('keep')) {
            \App\Support\PurgeCompany::run($company->id);
        }
        app(CurrentCompany::class)->clear();

        return $pass ? self::SUCCESS : self::FAILURE;
    }
}
