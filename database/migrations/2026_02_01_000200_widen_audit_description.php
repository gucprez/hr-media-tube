<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Los documentos con varios productos generan descripciones largas: varchar(255) no alcanza en MySQL. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', fn (Blueprint $t) => $t->text('description')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $t) => $t->string('description')->nullable()->change());
    }
};
