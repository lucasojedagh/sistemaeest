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
        Schema::create('alumno_calificaciones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('alumno_id');
            $table->unsignedBigInteger('materia_id')->nullable()->index('alumno_calificaciones_materia_id_foreign');
            $table->unsignedTinyInteger('anio')->nullable();
            $table->char('division', 1)->nullable();
            $table->tinyInteger('periodo')->nullable()->comment('1..3=cuatrimestres');
            $table->string('tipo_calificacion', 40)->default('regular')->index();
            $table->json('detalle')->nullable();
            $table->unsignedSmallInteger('ciclo_lectivo')->nullable();
            $table->string('valor', 10);
            $table->date('fecha')->nullable();
            $table->text('descripcion')->nullable();
            $table->timestamps();

            $table->unique(['alumno_id', 'materia_id', 'anio', 'division', 'ciclo_lectivo', 'periodo', 'tipo_calificacion'], 'ac_unique');
            $table->index(['alumno_id', 'anio', 'division']);
            $table->index(['alumno_id', 'materia_id']);
            $table->index(['alumno_id', 'materia_id', 'anio', 'division', 'ciclo_lectivo', 'periodo'], 'idx_calif_agregados');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alumno_calificaciones');
    }
};
