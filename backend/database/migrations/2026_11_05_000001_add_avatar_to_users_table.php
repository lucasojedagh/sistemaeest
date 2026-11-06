<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto de perfil. Guarda la ruta dentro del disco privado "local"
     * ("avatars/{id}/{version}.webp"), nunca en una carpeta web: la imagen se
     * sirve siempre por la API, con la sesion del usuario. Null = sin foto, las
     * pantallas muestran las iniciales de siempre.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'avatar')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar', 255)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'avatar')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
