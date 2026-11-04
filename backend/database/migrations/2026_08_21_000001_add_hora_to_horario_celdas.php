<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega las columnas de hora (inicio/fin) por fila de horario,
     * para permitir editar manualmente el rango horario de cada modulo.
     */
    public function up(): void
    {
        Schema::table('horario_celdas', function (Blueprint $table) {
            $table->time('hora_inicio')->nullable()->after('aula');
            $table->time('hora_fin')->nullable()->after('hora_inicio');
        });
    }

    public function down(): void
    {
        Schema::table('horario_celdas', function (Blueprint $table) {
            $table->dropColumn(['hora_inicio', 'hora_fin']);
        });
    }
};
