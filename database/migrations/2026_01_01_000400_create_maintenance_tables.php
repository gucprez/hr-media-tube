<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicle_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('inspector_id')->nullable()->constrained('users');
            $table->date('inspected_at');
            $table->decimal('odometer', 12, 1)->nullable();
            $table->string('overall_status', 20)->default('good'); // good|attention|damaged
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'vehicle_id', 'inspected_at']);
        });

        Schema::create('inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vehicle_inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained('inspection_checklist_items')->nullOnDelete();
            $table->string('label'); // snapshot del punto
            $table->string('status', 20); // good|attention|damaged|na
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('reported_by')->nullable()->constrained('users');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('odometer', 12, 1)->nullable();
            $table->string('priority', 10)->default('medium'); // low|medium|high|critical
            // reported|pending|in_repair|repaired|closed
            $table->string('status', 20)->default('reported');
            $table->timestamp('reported_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'status']);
        });

        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('incident_id')->nullable()->constrained('vehicle_incidents');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->date('performed_at');
            $table->text('description');
            $table->string('responsible')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'vehicle_id']);
        });

        Schema::create('maintenance_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('maintenance_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements');
            $table->decimal('quantity_base', 14, 3);
            $table->timestamps();
        });

        Schema::create('returnable_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('vehicle_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            // new|delivered|used|returned|refillable|discarded
            $table->string('state', 20);
            $table->decimal('quantity', 12, 2)->default(1);
            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'product_id', 'state']);
        });

        Schema::create('tire_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('product_id')->constrained(); // goma nueva entregada
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('odometer', 12, 1)->nullable();
            $table->date('delivered_at');
            $table->foreignId('delivered_by')->nullable()->constrained('users');
            $table->string('reason')->nullable();
            $table->boolean('used_tire_received')->default(false);
            // pending|received|waived
            $table->string('status', 20)->default('pending');
            // worn|damaged|repairable|reusable|scrap
            $table->string('condition', 20)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['tire_returns', 'returnable_movements', 'maintenance_parts', 'maintenance_records', 'vehicle_incidents', 'inspection_items', 'vehicle_inspections', 'inspection_checklist_items'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
