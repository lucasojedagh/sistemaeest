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
        Schema::table('alumno_horario_overrides', function (Blueprint $table) {
            $table->unsignedBigInteger('profesor_id')->nullable()->index()->after('materia_id');
            $table->string('profesor_nombre')->nullable()->after('materia_nombre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alumno_horario_overrides', function (Blueprint $table) {
            $table->dropColumn(['profesor_id', 'profesor_nombre']);
        });
    }
};
