<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2: el libro de movimientos conserva TODO el contexto histórico en cada línea
 * (tipo, almacén, sucursal, fecha, stock antes/después, snapshot de producto) para que los
 * reportes por rango de fechas nunca dependan del stock actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained();
            $table->string('reference', 80)->nullable()->after('number');
            $table->string('destination_type', 20)->nullable()->after('destination'); // container|vehicle|maintenance|employee|other
            $table->string('status', 10)->default('posted')->after('type'); // posted|voided
        });

        Schema::table('inventory_movement_items', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained();
            $table->foreignId('warehouse_id')->nullable()->after('branch_id')->constrained();
            // entry|exit|adjustment_in|adjustment_out|container|maintenance|return|void
            $table->string('type', 20)->default('entry')->after('movement_id');
            $table->timestamp('occurred_at')->nullable()->after('type');
            $table->decimal('stock_before_base', 14, 3)->default(0)->after('delta_base');
            $table->string('product_sku', 60)->nullable()->after('product_id');
            $table->string('product_name')->nullable()->after('product_sku');
            $table->index(['company_id', 'warehouse_id', 'product_id', 'occurred_at'], 'imi_wh_prod_date');
            $table->index(['company_id', 'type', 'occurred_at'], 'imi_type_date');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('default_warehouse_id')->nullable()->after('unit_id')->constrained('warehouses');
            $table->string('aisle', 40)->nullable()->after('location');
            $table->string('shelf', 40)->nullable()->after('aisle');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('path');
            $table->string('original_name')->nullable()->after('thumb_path');
            $table->unsignedBigInteger('size')->default(0)->after('original_name');
        });

        Schema::table('attachments', function (Blueprint $table) {
            // Los archivos de empresa viven en el disco privado y se sirven con autorización.
            $table->string('disk', 20)->default('local')->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', fn (Blueprint $t) => $t->dropColumn(['thumb_path', 'original_name', 'size']));
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('default_warehouse_id');
            $t->dropColumn(['aisle', 'shelf']);
        });
        Schema::table('inventory_movement_items', function (Blueprint $t) {
            $t->dropIndex('imi_wh_prod_date');
            $t->dropIndex('imi_type_date');
            $t->dropConstrainedForeignId('branch_id');
            $t->dropConstrainedForeignId('warehouse_id');
            $t->dropColumn(['type', 'occurred_at', 'stock_before_base', 'product_sku', 'product_name']);
        });
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('branch_id');
            $t->dropColumn(['reference', 'destination_type', 'status']);
        });
    }
};
