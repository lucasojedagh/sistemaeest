<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cadena de suplencias entre profesores: quién reemplaza a quién, en qué
// materia de qué curso, y por qué motivo.
//
// Cada fila es UN eslabón: titular_id es reemplazado por suplente_id en
// (anio, division, materia_id). La cadena se arma sola: si B cubre a A y
// después C cubre a B, queda A → B → C. Profundidad máxima: 4 eslabones
// desde el titular original (o sea, hasta 4 suplentes en cadena).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profesor_suplencias', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('titular_id')->index();
            $table->unsignedBigInteger('suplente_id')->index();
            $table->unsignedTinyInteger('anio');
            $table->char('division', 1)->nullable();
            $table->unsignedBigInteger('materia_id')->index();
            // Motivo en criollo ("licencia", "accidente"...). Opcional.
            $table->string('motivo', 250)->nullable();
            $table->timestamps();

            $table->unique(
                ['titular_id', 'suplente_id', 'anio', 'division', 'materia_id'],
                'prof_suplencias_unique'
            );
            $table->index(['suplente_id', 'anio', 'division', 'materia_id'], 'prof_suplencias_supl_idx');

            $table->foreign('titular_id')->references('id')->on('profesores')->onDelete('cascade');
            $table->foreign('suplente_id')->references('id')->on('profesores')->onDelete('cascade');
            $table->foreign('materia_id')->references('id')->on('materias')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profesor_suplencias');
    }
};
