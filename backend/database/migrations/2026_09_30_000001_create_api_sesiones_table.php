<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Una fila por ingreso al sistema (por equipo/navegador).
// Reemplaza el uso de users.api_token como único token: antes, al volver a
// iniciar sesion se invalidaba el token anterior y la persona se quedaba
// afuera de la pestana o del equipo donde todavia estaba trabajando.
// Con varias filas se puede cerrar la sesion de UN equipo sin tocar los demas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_sesiones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token', 80)->unique();
            // Nombre legible del equipo ("Chrome en Windows"), para que la
            // persona sepa cual cerrar.
            $table->string('dispositivo', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('ultimo_uso_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ultimo_uso_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_sesiones');
    }
};
