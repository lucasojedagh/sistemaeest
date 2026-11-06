<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Columnas de alcance por rol (pantalla "Roles y permisos").
//
//  - alcance: mapa JSON área => {ver, editar} con valores 'todos' | 'asignados' |
//             'ninguno'. Solo se guardan las áreas que se apartan del valor por
//             defecto del rol, así una fila vacía significa "sin restricciones".
//  - modulos: lista JSON de claves del menú lateral que el rol ve. Null = que el
//             frontend siga con el filtro de siempre por nombre de rol.
//
// No toca ninguna fila existente: roles_config está sin configuración guardada,
// y mientras ambas columnas estén vacías el sistema se comporta igual que antes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles_config', function (Blueprint $t) {
            if (!Schema::hasColumn('roles_config', 'alcance')) {
                $t->json('alcance')->nullable();
            }
            if (!Schema::hasColumn('roles_config', 'modulos')) {
                $t->json('modulos')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles_config', function (Blueprint $t) {
            $t->dropColumn(['alcance', 'modulos']);
        });
    }
};
