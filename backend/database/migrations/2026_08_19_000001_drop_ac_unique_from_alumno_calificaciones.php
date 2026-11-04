<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reemplaza el índice único ac_unique por un índice común.
     *
     * El índice único impedía guardar más de una valoración parcial por
     * (alumno, materia, anio, division, ciclo_lectivo, periodo, tipo):
     * con dos VPs del mismo alumno/materia/periodo la clave chocaba y la
     * grilla de calificaciones fallaba con 500. Las filas representan notas
     * individuales, por lo que el índice único no corresponde.
     */
    public function up(): void
    {
        Schema::table('alumno_calificaciones', function (Blueprint $table) {
            $table->dropUnique('ac_unique');
        });

        Schema::table('alumno_calificaciones', function (Blueprint $table) {
            $table->index(
                ['alumno_id', 'materia_id', 'anio', 'division', 'ciclo_lectivo', 'periodo', 'tipo_calificacion'],
                'ac_combo_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('alumno_calificaciones', function (Blueprint $table) {
            $table->dropIndex('ac_combo_idx');
        });

        Schema::table('alumno_calificaciones', function (Blueprint $table) {
            $table->unique(
                ['alumno_id', 'materia_id', 'anio', 'division', 'ciclo_lectivo', 'periodo', 'tipo_calificacion'],
                'ac_unique'
            );
        });
    }
};