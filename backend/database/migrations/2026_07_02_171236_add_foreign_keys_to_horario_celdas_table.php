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
        Schema::table('horario_celdas', function (Blueprint $table) {
            $table->foreign(['curso_id'])->references(['id'])->on('cursos')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['materia_id'])->references(['id'])->on('materias')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('horario_celdas', function (Blueprint $table) {
            $table->dropForeign('horario_celdas_curso_id_foreign');
            $table->dropForeign('horario_celdas_materia_id_foreign');
        });
    }
};
