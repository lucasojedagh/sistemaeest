<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clases dictadas por curso. Una clase = una toma de asistencia de un curso
     * en una fecha. El numero_clase es un contador por curso (independiente de
     * la cantidad de alumnos). Responsable = usuario autenticado que registro.
     */
    public function up(): void
    {
        Schema::create('clases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('curso_id');
            $table->date('fecha');
            $table->unsignedInteger('numero_clase');
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            $table->unsignedBigInteger('responsable_id'); // users.id: quien tomo la asistencia
            $table->string('tipo_responsable', 40);       // rol del responsable al momento
            $table->enum('estado', ['dictada', 'suspendida'])->default('dictada');
            $table->timestamps();

            // Contador por curso y anti-duplicado de toma diaria.
            $table->unique(['curso_id', 'numero_clase'], 'clases_curso_numero_unique');
            $table->unique(['curso_id', 'fecha'], 'clases_curso_fecha_unique');

            $table->index('fecha');
            $table->foreign('curso_id')->references('id')->on('cursos')->onDelete('cascade');
            $table->foreign('responsable_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clases');
    }
};