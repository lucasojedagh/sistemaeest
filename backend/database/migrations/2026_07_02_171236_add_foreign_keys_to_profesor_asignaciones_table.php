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
        Schema::table('profesor_asignaciones', function (Blueprint $table) {
            $table->foreign(['materia_id'])->references(['id'])->on('materias')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['profesor_id'])->references(['id'])->on('profesores')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profesor_asignaciones', function (Blueprint $table) {
            $table->dropForeign('profesor_asignaciones_materia_id_foreign');
            $table->dropForeign('profesor_asignaciones_profesor_id_foreign');
        });
    }
};
