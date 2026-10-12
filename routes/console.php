<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Integridad de inventario: el stock de cada producto debe coincidir con la suma de su libro de movimientos.
Schedule::command('inventory:verify')->dailyAt('02:30')->appendOutputTo(storage_path('logs/inventory-verify.log'));
