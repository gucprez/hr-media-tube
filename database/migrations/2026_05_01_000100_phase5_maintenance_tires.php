<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historial de estados de cada avería (quién, cuándo, de qué estado a cuál)
        Schema::create('incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('incident_id')->constrained('vehicle_incidents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'incident_id']);
        });

        Schema::table('vehicle_inspections', function (Blueprint $table) {
            $table->date('next_due_at')->nullable()->after('inspected_at');
        });

        Schema::table('maintenance_records', function (Blueprint $table) {
            $table->string('type', 20)->default('corrective')->after('incident_id'); // corrective|preventive
            $table->decimal('odometer', 12, 1)->nullable()->after('performed_at');
            $table->foreignId('movement_id')->nullable()->after('user_id')->constrained('inventory_movements');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason')->nullable();
        });

        Schema::table('maintenance_parts', function (Blueprint $table) {
            $table->string('product_sku', 60)->nullable()->after('product_id');
            $table->string('product_name')->nullable()->after('product_sku');
        });

        Schema::table('tire_returns', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('product_id');
            $table->string('waived_reason')->nullable()->after('notes');
        });

        Schema::table('returnable_movements', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('product_id');
            $table->string('holder')->nullable()->after('vehicle_id');          // empleado / área que lo tiene
            $table->foreignId('movement_id')->nullable()->after('holder')->constrained('inventory_movements');
        });
    }

    public function down(): void
    {
        Schema::table('returnable_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('movement_id');
            $t->dropColumn(['product_name', 'holder']);
        });
        Schema::table('tire_returns', fn (Blueprint $t) => $t->dropColumn(['product_name', 'waived_reason']));
        Schema::table('maintenance_parts', fn (Blueprint $t) => $t->dropColumn(['product_sku', 'product_name']));
        Schema::table('maintenance_records', function (Blueprint $t) {
            $t->dropConstrainedForeignId('movement_id');
            $t->dropConstrainedForeignId('voided_by');
            $t->dropColumn(['type', 'odometer', 'voided_at', 'void_reason']);
        });
        Schema::table('vehicle_inspections', fn (Blueprint $t) => $t->dropColumn('next_due_at'));
        Schema::dropIfExists('incident_events');
    }
};
