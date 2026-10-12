<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->decimal('fuel_gallons', 12, 2)->default(0)->after('phone');       // gasoil previsto/entregado al furgón
            $table->foreignId('movement_id')->nullable()->after('responsible_id')->constrained('inventory_movements'); // consumo de inventario al cerrar
            $table->foreignId('void_movement_id')->nullable()->after('movement_id')->constrained('inventory_movements');
            $table->timestamp('started_at')->nullable()->after('closed_by');
        });

        Schema::table('container_materials', function (Blueprint $table) {
            $table->string('product_sku', 60)->nullable()->after('product_id');
            $table->string('product_name')->nullable()->after('product_sku');
        });

        Schema::table('ticket_items', function (Blueprint $table) {
            $table->string('unit_label', 40)->nullable()->after('total_units');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('container_id')->constrained();
            $table->string('print_layout', 2)->nullable()->after('print_count');   // última disposición usada: 1, 2, 4
            $table->timestamp('last_printed_at')->nullable()->after('print_layout');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_items', fn (Blueprint $t) => $t->dropColumn('unit_label'));
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vehicle_id');
            $t->dropColumn(['print_layout', 'last_printed_at']);
        });
        Schema::table('container_materials', fn (Blueprint $t) => $t->dropColumn(['product_sku', 'product_name']));
        Schema::table('containers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('movement_id');
            $t->dropConstrainedForeignId('void_movement_id');
            $t->dropColumn(['fuel_gallons', 'started_at']);
        });
    }
};
