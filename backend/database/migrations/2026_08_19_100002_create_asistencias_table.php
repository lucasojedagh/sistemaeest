<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registros de asistencia. Cada fila vincula un alumno con una clase.
     * No se repiten curso_id ni fecha: ambos se derivan de la clase.
     * La restriccion UNIQUE(clase_id, alumno_id) impide doble carga.
     */
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('clase_id');
            $table->unsignedBigInteger('alumno_id');
            $table->enum('estado', ['presente', 'ausente', 'justificado', 'tarde'])->default('presente');
            $table->text('anotacion')->nullable();
            $table->timestamps();

            $table->unique(['clase_id', 'alumno_id'], 'asistencias_clase_alumno_unique');
            $table->index(['clase_id', 'estado'], 'asistencias_clase_estado_idx');
            $table->index('alumno_id');
            $table->foreign('clase_id')->references('id')->on('clases')->onDelete('cascade');
            $table->foreign('alumno_id')->references('id')->on('alumnos')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};