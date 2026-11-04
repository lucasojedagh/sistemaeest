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
        Schema::create('horario_celdas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('curso_id')->index();
            $table->unsignedTinyInteger('dia');
            $table->string('slot_key', 4);
            $table->char('grupo', 1)->nullable();
            $table->unsignedBigInteger('materia_id')->nullable()->index();
            $table->unsignedBigInteger('profesor_id')->nullable()->index();
            $table->string('aula')->nullable();
            $table->timestamps();

            $table->unique(['curso_id', 'dia', 'slot_key', 'grupo'], 'horario_celdas_curso_dia_slot_grupo_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('horario_celdas');
    }
};
