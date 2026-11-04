<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Justificaciones de inasistencias. Una justificacion se relaciona con un
     * registro de asistencia (estado ausente). Al aprobarse, la asistencia pasa
     * a 'justificado'. El documento/comprobante opcional reutiliza la tabla
     * documentos existente.
     */
    public function up(): void
    {
        Schema::create('asistencia_justificaciones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asistencia_id');
            $table->string('motivo');
            $table->text('observacion')->nullable();
            $table->unsignedBigInteger('documento_id')->nullable();
            $table->enum('estado', ['pendiente', 'aprobada', 'rechazada'])->default('pendiente');
            $table->unsignedBigInteger('registrado_por'); // users.id
            $table->unsignedBigInteger('aprobado_por')->nullable(); // users.id
            $table->timestamps();

            // Una sola justificacion por registro de asistencia.
            $table->unique('asistencia_id', 'ajust_asistencia_unique');
            $table->index('estado');
            $table->foreign('asistencia_id')->references('id')->on('asistencias')->onDelete('cascade');
            $table->foreign('documento_id')->references('id')->on('documentos')->nullOnDelete();
            $table->foreign('registrado_por')->references('id')->on('users');
            $table->foreign('aprobado_por')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_justificaciones');
    }
};