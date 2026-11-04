<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega la visibilidad por rol a los documentos.
     * null = visible para todos los roles (documentos anteriores a esta funcion).
     */
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $table) {
            $table->json('roles')->nullable()->after('tamano');
        });
    }

    public function down(): void
    {
        Schema::table('documentos', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
