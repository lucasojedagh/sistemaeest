<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comentario general de la clase (ej: acuerdos, parciales pactados,
     * novedades). Es opcional y aplica a toda la clase, no a un alumno.
     */
    public function up(): void
    {
        Schema::table('clases', function (Blueprint $table) {
            $table->text('comentario')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('clases', function (Blueprint $table) {
            $table->dropColumn('comentario');
        });
    }
};