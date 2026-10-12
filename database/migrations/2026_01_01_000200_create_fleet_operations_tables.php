<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('license_no', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 60);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vehicle_type_id')->nullable()->constrained();
            $table->foreignId('usual_driver_id')->nullable()->constrained('drivers');
            $table->string('ficha', 30); // F1, F2, F3...
            $table->string('plate', 20)->nullable();
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('photo_path')->nullable();
            $table->decimal('odometer', 12, 1)->default(0);
            // operational|breakdown|maintenance|inactive
            $table->string('status', 20)->default('operational');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'ficha']);
        });

        // Furgón = operación
        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('warehouse_id')->nullable()->constrained();
            $table->string('number', 30);
            $table->date('date');
            $table->foreignId('driver_id')->nullable()->constrained();
            $table->foreignId('vehicle_id')->nullable()->constrained();
            $table->string('driver_name')->nullable();
            $table->string('conduce', 60)->nullable();
            $table->string('phone', 40)->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users');
            // open|in_progress|closed|voided
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'date']);
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity_base', 14, 3)->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id']);
            $table->index('company_id');
        });

        // Libro de movimientos: única vía para cambiar existencias.
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('number', 30)->nullable();
            // entry|exit|adjustment|container|maintenance|return|receipt|void
            $table->string('type', 20);
            $table->timestamp('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('supplier')->nullable();
            $table->string('document_no', 60)->nullable();
            $table->string('destination')->nullable();
            $table->foreignId('container_id')->nullable()->constrained();
            $table->foreignId('vehicle_id')->nullable()->constrained();
            $table->string('employee_name')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->nullableMorphs('source'); // mantenimiento, devolución, etc.
            $table->boolean('negative_override')->default(false);
            $table->foreignId('authorized_by')->nullable()->constrained('users');
            $table->foreignId('reversal_of_id')->nullable()->constrained('inventory_movements');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'type', 'occurred_at']);
            $table->index(['company_id', 'occurred_at']);
        });

        Schema::create('inventory_movement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('movement_id')->constrained('inventory_movements')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('units_per_box')->default(1); // snapshot
            $table->decimal('boxes', 14, 3)->default(0);
            $table->decimal('units', 14, 3)->default(0);
            $table->decimal('delta_base', 14, 3); // con signo: + entra, - sale
            $table->decimal('balance_after_base', 14, 3)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'product_id']);
        });

        Schema::create('container_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('container_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('boxes', 14, 3)->default(0);
            $table->decimal('units', 14, 3)->default(0);
            $table->decimal('quantity_base', 14, 3)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['container_materials', 'inventory_movement_items', 'inventory_movements', 'inventory_stocks', 'containers', 'vehicles', 'vehicle_types', 'drivers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
