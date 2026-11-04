<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('alumno_telefonos', function (Blueprint $table) {
            $table->foreign(['alumno_id'])->references(['id'])->on('alumnos')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alumno_telefonos', function (Blueprint $table) {
            $table->dropForeign('alumno_telefonos_alumno_id_foreign');
        });
    }
};
