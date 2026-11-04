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
        Schema::create('alumno_materias_estado', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('alumno_id');
            $table->unsignedBigInteger('materia_id')->index();
            $table->integer('ciclo_lectivo')->index();
            $table->tinyInteger('anio');
            $table->char('division', 10)->nullable();
            $table->string('p1_estado', 20)->nullable();
            $table->string('p2_estado', 20)->nullable();
            $table->decimal('nota_final', 4)->nullable();
            $table->string('estado_final', 40)->nullable()->index();
            $table->decimal('nota_intensificacion_febrero', 4)->nullable();
            $table->decimal('nota_intensificacion_julio', 4)->nullable();
            $table->decimal('nota_intensificacion_diciembre_pre', 4)->nullable();
            $table->decimal('nota_intensificacion_diciembre_post', 4)->nullable();
            $table->timestamps();

            $table->unique(['alumno_id', 'materia_id', 'ciclo_lectivo'], 'ame_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alumno_materias_estado');
    }
};
