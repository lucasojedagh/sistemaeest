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
        Schema::create('alumno_horario_overrides', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('alumno_id');
            $table->unsignedTinyInteger('dia');
            $table->unsignedTinyInteger('bloque');
            $table->unsignedBigInteger('materia_id')->nullable()->index();
            $table->string('materia_nombre')->nullable();
            $table->enum('marcado', ['normal', 'recursando', 'intensificacion'])->default('normal');
            $table->timestamps();

            $table->unique(['alumno_id', 'dia', 'bloque']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alumno_horario_overrides');
    }
};
