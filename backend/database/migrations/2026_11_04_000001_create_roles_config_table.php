<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabla de configuración de roles. Guarda los roles personalizados que se crean
// desde el apartado "Roles y permisos" y, para los roles de sistema, la lista de
// permisos quitados (negados).
//
//  - rol:      clave tecnica (la misma que usa users.role).
//  - nivel:    rango numerico 0..5; se compara con RoleHierarchy::LEVELS y define
//              hasta donde llega el rol frente a los roles de sistema.
//  - tipo:     'sistema' (fila creada al quitarle permisos a un rol existente) o
//              'personalizado' (rol nuevo).
//  - permitidos / negados: arrays JSON con claves del catalogo de Permisos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles_config', function (Blueprint $t) {
            $t->id();
            $t->string('rol', 60)->unique();
            $t->string('nombre', 120);
            $t->tinyInteger('nivel')->default(1);
            $t->string('tipo', 20)->default('personalizado');
            $t->json('permitidos')->nullable();
            $t->json('negados')->nullable();
            $t->string('descripcion', 255)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles_config');
    }
};
