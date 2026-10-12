<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_tanks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('warehouse_id')->nullable()->constrained();
            $table->string('name');
            $table->decimal('capacity_gallons', 12, 2)->nullable();
            $table->decimal('current_gallons', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fuel_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('fuel_tank_id')->constrained();
            $table->string('receipt_number', 30);
            $table->date('received_at');
            $table->decimal('meter_start', 14, 2)->nullable();
            $table->decimal('meter_end', 14, 2)->nullable();
            $table->decimal('gallons_received', 12, 2);
            $table->decimal('reserve_before', 12, 2);
            $table->decimal('reserve_after', 12, 2);
            $table->string('supplier')->nullable();
            $table->string('truck_plate', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'receipt_number']);
            $table->index(['company_id', 'received_at']);
        });

        Schema::create('fuel_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('fuel_tank_id')->constrained();
            $table->foreignId('vehicle_id')->nullable()->constrained();
            $table->foreignId('container_id')->nullable()->constrained();
            $table->date('dispatched_at');
            $table->decimal('reading_start', 14, 2);
            $table->decimal('reading_end', 14, 2);
            $table->decimal('gallons', 12, 2);
            $table->decimal('odometer', 12, 1)->nullable(); // kilometraje
            $table->decimal('reserve_before', 12, 2);
            $table->decimal('reserve_after', 12, 2);
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'dispatched_at']);
            $table->index(['company_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        foreach (['fuel_dispatches', 'fuel_receipts', 'fuel_tanks'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
