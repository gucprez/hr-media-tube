<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            // Empleado que pidió el material (texto libre, como employee_name). Nulo en documentos anteriores y en salidas automáticas.
            $table->string('requested_by', 150)->nullable()->after('employee_name');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('requested_by');
        });
    }
};
