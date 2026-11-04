<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ventana_nota_final', function (Blueprint $table) {
            // Qué nota final de cuatrimestre habilita la ventana: p1, p2 o ambos.
            $table->string('alcance', 10)->default('ambos')->after('ciclo_lectivo');
        });
    }

    public function down()
    {
        Schema::table('ventana_nota_final', function (Blueprint $table) {
            $table->dropColumn('alcance');
        });
    }
};
