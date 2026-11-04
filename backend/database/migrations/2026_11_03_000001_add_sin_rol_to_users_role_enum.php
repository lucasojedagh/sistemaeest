<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega 'sin_rol' al enum users.role. Es el rol que escribe el registro
     * público (AuthApiController@register) mientras la cuenta está 'pending':
     * sin este valor el INSERT fallaba con "Data truncated" y el alta devolvía 500.
     *
     * 'sin_rol' no se suma a RoleHierarchy::LEVELS, así que sigue teniendo nivel 0:
     * no puede leer ni escribir nada hasta que un admin apruebe la cuenta y le
     * asigne un rol real.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','directivo','jefe_preceptores','preceptor','prosecretaria','profesor','sin_rol') NULL DEFAULT 'profesor'");
    }

    public function down(): void
    {
        // Las cuentas pendientes se dejan sin rol (la columna es nullable) antes de
        // achicar el enum; si no, el ALTER truncaría esas filas en sql_mode estricto.
        DB::table('users')->where('role', 'sin_rol')->update(['role' => null]);
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','directivo','jefe_preceptores','preceptor','prosecretaria','profesor') NULL DEFAULT 'profesor'");
    }
};
