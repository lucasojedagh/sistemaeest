<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade "nacionalidad" y "matricula" (Nro. de matrícula) a la tabla alumnos.
     * Ambas son opcionales: los 1200 alumnos ya cargados quedan en null y se
     * completan de a poco desde la ficha.
     */
    public function up(): void
    {
        Schema::table('alumnos', function (Blueprint $table) {
            $table->string('nacionalidad', 60)->nullable()->after('genero');
            $table->string('matricula', 30)->nullable()->after('dni');
            // Se busca y se ordena por matrícula (padrón, actas).
            $table->index('matricula', 'alumnos_matricula_idx');
        });
    }

    public function down(): void
    {
        Schema::table('alumnos', function (Blueprint $table) {
            $table->dropIndex('alumnos_matricula_idx');
            $table->dropColumn(['nacionalidad', 'matricula']);
        });
    }
};
