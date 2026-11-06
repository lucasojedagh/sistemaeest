<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La recuperación por email no llega a funcionar en el colegio (el correo
     * del sistema no está configurado), así que dirección genera una clave
     * temporal en la ventanilla. Con esta bandera la app sabe que quien entró
     * con esa clave tiene que ponér una propia antes de usar el sistema.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'debe_cambiar_password')) return;
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('debe_cambiar_password')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'debe_cambiar_password')) return;
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('debe_cambiar_password');
        });
    }
};
