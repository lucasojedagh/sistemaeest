<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('profesores', function (Blueprint $table) {
            $table->string('dni', 15)->nullable()->after('email');
        });

        // Migrar los DNI existentes desde users (por coincidencia de email).
        \Illuminate\Support\Facades\DB::statement(
            "UPDATE profesores p
               JOIN users u ON u.email = p.email
               SET p.dni = u.dni
             WHERE p.dni IS NULL AND u.dni IS NOT NULL"
        );
    }

    public function down()
    {
        Schema::table('profesores', function (Blueprint $table) {
            $table->dropColumn('dni');
        });
    }
};
