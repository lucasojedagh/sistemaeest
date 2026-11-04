<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // clases.responsable_id: RESTRICT → SET NULL
        Schema::table('clases', function (Blueprint $table) {
            $table->unsignedBigInteger('responsable_id')->nullable()->change();
            $table->dropForeign(['responsable_id']);
            $table->foreign('responsable_id')->references('id')->on('users')->nullOnDelete();
        });

        // asistencia_justificaciones.registrado_por: RESTRICT → SET NULL
        Schema::table('asistencia_justificaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('registrado_por')->nullable()->change();
            $table->dropForeign(['registrado_por']);
            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });

        // asistencia_justificaciones.aprobado_por: ya es nullable, solo cambiar FK
        Schema::table('asistencia_justificaciones', function (Blueprint $table) {
            $table->dropForeign(['aprobado_por']);
            $table->foreign('aprobado_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clases', function (Blueprint $table) {
            $table->dropForeign(['responsable_id']);
            $table->foreign('responsable_id')->references('id')->on('users');
            $table->unsignedBigInteger('responsable_id')->nullable(false)->change();
        });

        Schema::table('asistencia_justificaciones', function (Blueprint $table) {
            $table->dropForeign(['registrado_por']);
            $table->foreign('registrado_por')->references('id')->on('users');
            $table->unsignedBigInteger('registrado_por')->nullable(false)->change();

            $table->dropForeign(['aprobado_por']);
            $table->foreign('aprobado_por')->references('id')->on('users');
        });
    }
};
