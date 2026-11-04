<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unifica el rol 'director' en 'directivo' (eran duplicados).
     * Corrige datos existentes y el enum de la columna role.
     */
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            DB::table('users')
                ->where('role', 'director')
                ->update(['role' => 'directivo']);

            // Actualiza el enum de la columna para eliminar 'director'.
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','directivo','jefe_preceptores','preceptor','profesor') NULL DEFAULT 'profesor'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','director','directivo','jefe_preceptores','preceptor','profesor') NULL DEFAULT 'profesor'");
        }
    }
};
