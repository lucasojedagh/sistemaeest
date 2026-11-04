<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('alumno_calificaciones', function (Blueprint $table) {
            $table->foreign(['alumno_id'])->references(['id'])->on('alumnos')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['materia_id'])->references(['id'])->on('materias')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alumno_calificaciones', function (Blueprint $table) {
            $table->dropForeign('alumno_calificaciones_alumno_id_foreign');
            $table->dropForeign('alumno_calificaciones_materia_id_foreign');
        });
    }
};
