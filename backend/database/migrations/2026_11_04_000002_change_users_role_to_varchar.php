<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// users.role pasa de ENUM a VARCHAR(60). El ENUM impedía asignar roles
// personalizados: cualquier clave nueva ("auxiliar", "secretaria") fallaba al
// guardar. Los valores actuales se conservan tal cual (la conversión de ENUM a
// VARCHAR no pierde datos).
//
// down() revierte a la lista de roles de sistema; antes de achicar, manda a
// 'profesor' las cuentas con un rol personalizado que ya no existe en el ENUM.
return new class extends Migration
{
    private const ENUM = "ENUM('admin','directivo','jefe_preceptores','preceptor','prosecretaria','profesor','sin_rol')";

    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY role VARCHAR(60) NULL DEFAULT \'profesor\'');
    }

    public function down(): void
    {
        $sistema = ['admin','directivo','jefe_preceptores','preceptor','prosecretaria','profesor','sin_rol'];
        DB::table('users')->whereNotIn('role', $sistema)->update(['role' => 'profesor']);
        DB::statement('ALTER TABLE users MODIFY ' . self::ENUM . " NULL DEFAULT 'profesor'");
    }
};
