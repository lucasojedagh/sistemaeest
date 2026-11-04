<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el rol 'prosecretaria' (nivel 2, equivalente a preceptor)
     * al enum de users.role. Se hace con SQL crudo porque Laravel no
     * puede modificar un ENUM con el schema builder.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','directivo','jefe_preceptores','preceptor','prosecretaria','profesor') NULL DEFAULT 'profesor'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','directivo','jefe_preceptores','preceptor','profesor') NULL DEFAULT 'profesor'");
    }
};
