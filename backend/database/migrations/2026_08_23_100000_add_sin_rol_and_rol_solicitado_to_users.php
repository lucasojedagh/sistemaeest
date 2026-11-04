<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Agrega el rol "sin_rol" (sin permisos) al enum de la columna role y lo deja
// como valor por defecto para los nuevos registros, mas la columna rol_solicitado
// donde el usuario indica (texto libre) el rol que desea al registrarse.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','directivo','jefe_preceptores','preceptor','profesor','sin_rol') NULL DEFAULT 'sin_rol'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('rol_solicitado', 255)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('rol_solicitado');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','directivo','jefe_preceptores','preceptor','profesor') NULL DEFAULT 'profesor'");
        }
    }
};
