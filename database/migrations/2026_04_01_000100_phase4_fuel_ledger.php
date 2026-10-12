<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Libro de la reserva de gasoil: cada cambio de reserva es una línea con antes/después (reportes históricos).
        Schema::create('fuel_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('fuel_tank_id')->constrained();
            $table->string('type', 20); // receipt|dispatch|void_receipt|void_dispatch|adjustment
            $table->timestamp('occurred_at');
            $table->decimal('delta_gallons', 12, 2);
            $table->decimal('reserve_before', 12, 2);
            $table->decimal('reserve_after', 12, 2);
            $table->nullableMorphs('source');
            $table->foreignId('vehicle_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'fuel_tank_id', 'occurred_at']);
            $table->index(['company_id', 'type', 'occurred_at']);
        });

        // Un despacho originado en un furgón no tiene lectura de contador (se registra por galones).
        Schema::table('fuel_dispatches', function (Blueprint $table) {
            $table->decimal('reading_start', 14, 2)->nullable()->change();
            $table->decimal('reading_end', 14, 2)->nullable()->change();
            $table->string('origin', 20)->default('manual')->after('notes'); // manual|container
        });

        Schema::table('fuel_tanks', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('fuel_tanks', fn (Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('fuel_dispatches', fn (Blueprint $t) => $t->dropColumn('origin'));
        Schema::dropIfExists('fuel_ledger');
    }
};
