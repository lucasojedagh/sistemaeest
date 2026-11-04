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
        Schema::create('alumno_promedios', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('alumno_id')->index('ap_ix_al');
            $table->unsignedBigInteger('materia_id')->index('ap_ix_mat');
            $table->unsignedSmallInteger('anio');
            $table->string('division', 2)->nullable();
            $table->unsignedSmallInteger('ciclo_lectivo');
            $table->unsignedTinyInteger('periodo');
            $table->decimal('promedio', 5)->nullable();
            $table->unsignedInteger('notas_count')->default(0);
            $table->timestamps();

            $table->unique(['alumno_id', 'materia_id', 'anio', 'division', 'ciclo_lectivo', 'periodo'], 'ap_u_combo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alumno_promedios');
    }
};
