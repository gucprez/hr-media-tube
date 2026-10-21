<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            // true  = al cerrar el furgón se descuentan sus materiales (menos lo que ya descontaron salidas ligadas al furgón)
            // false = la lista de materiales es solo un registro: el cierre NO toca el inventario
            $table->boolean('deduct_on_close')->default(true)->after('fuel_gallons');
        });
    }

    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->dropColumn('deduct_on_close');
        });
    }
};
