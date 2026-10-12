<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name']);
        });

        // Unidad de medida base (Rollo, Unidad, Galón, Litro...)
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 40);
            $table->string('plural', 40)->nullable();
            $table->string('abbreviation', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('category_id')->nullable()->constrained();
            $table->foreignId('unit_id')->nullable()->constrained();
            $table->string('sku', 60);
            $table->string('barcode', 80)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            // boxes = solo cajas, units = solo unidades, both = cajas + unidades
            $table->string('handling_mode', 10)->default('units');
            $table->string('box_label', 40)->default('caja');
            $table->string('presentation')->nullable();
            $table->unsignedInteger('units_per_box')->default(1);
            // Alerta: umbral expresado en cajas o unidades; se guarda también en unidad base.
            $table->string('alert_unit', 10)->default('units'); // boxes|units
            $table->decimal('min_quantity', 14, 3)->default(0);
            $table->decimal('min_stock_base', 14, 3)->default(0);
            $table->decimal('critical_stock_base', 14, 3)->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_returnable')->default(false);
            $table->string('returnable_kind', 20)->nullable(); // tire|toner|other
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'barcode']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['product_images', 'products', 'units', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
