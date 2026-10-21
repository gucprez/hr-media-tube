<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            // «Editar» una entrada/salida = anular el documento original y crear uno nuevo corregido.
            // replaces_id (en el documento NUEVO) apunta al original que reemplaza. El libro sigue siendo inmutable.
            $table->unsignedBigInteger('replaces_id')->nullable()->after('reversal_of_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['replaces_id']);
            $table->dropColumn('replaces_id');
        });
    }
};
