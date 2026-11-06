<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade "email" (correo electrónico del alumno) a la tabla alumnos.
     * Opcional: los alumnos ya cargados quedan en null y se completa desde la
     * ficha o desde la importación de Excel (columna E-MAIL).
     */
    public function up(): void
    {
        Schema::table('alumnos', function (Blueprint $table) {
            $table->string('email', 120)->nullable()->after('matricula');
            // Se busca por mail cuando una familia escribe desde esa casilla.
            $table->index('email', 'alumnos_email_idx');
        });
    }

    public function down(): void
    {
        Schema::table('alumnos', function (Blueprint $table) {
            $table->dropIndex('alumnos_email_idx');
            $table->dropColumn('email');
        });
    }
};
