<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade la columna "especialidad" a la tabla alumnos.
     * Solo aplica para 4to año en adelante (Informática, MMO, Electromecánica, Química).
     */
    public function up(): void
    {
        Schema::table('alumnos', function (Blueprint $table) {
            $table->string('especialidad', 20)->nullable()->after('grupo');
            $table->index('especialidad', 'alumnos_especialidad_idx');
        });
    }

    public function down(): void
    {
        Schema::table('alumnos', function (Blueprint $table) {
            $table->dropIndex('alumnos_especialidad_idx');
            $table->dropColumn('especialidad');
        });
    }
};
