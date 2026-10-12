<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('container_id')->nullable()->constrained();
            $table->string('type', 20); // materials|fuel
            $table->string('number', 30);
            $table->string('status', 20)->default('issued'); // issued|voided
            $table->date('issued_at');
            $table->string('container_number', 30)->nullable();
            $table->string('driver_name')->nullable();
            $table->string('conduce', 60)->nullable();
            $table->string('ficha', 30)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('dispatched_by')->nullable();
            $table->string('received_by')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'type', 'number']);
            $table->index(['company_id', 'issued_at']);
        });

        Schema::create('ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained();
            $table->string('description');
            $table->string('quantity_label')->nullable(); // "5 cajas + 8 unidades"
            $table->decimal('total_units', 14, 3)->nullable();
            $table->string('ficha', 30)->nullable();      // ticket de gasoil
            $table->decimal('odometer', 12, 1)->nullable();
            $table->decimal('gallons', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->morphs('attachable');
            $table->string('collection', 40)->default('default'); // photo, invoice, meter...
            $table->string('disk', 20)->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 80)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();
            $table->index('company_id');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->nullable()->constrained();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('user_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('action', 60);
            $table->string('description')->nullable();
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'action']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'notifications', 'attachments', 'ticket_items', 'tickets'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
