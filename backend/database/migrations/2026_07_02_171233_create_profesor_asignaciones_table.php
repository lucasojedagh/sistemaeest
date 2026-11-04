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
        Schema::create('profesor_asignaciones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('profesor_id')->index('prof_asignaciones_profesor_id_idx');
            $table->unsignedTinyInteger('anio');
            $table->char('division', 1)->nullable();
            $table->char('grupo', 1)->nullable();
            $table->unsignedBigInteger('materia_id')->nullable()->index('profesor_asignaciones_materia_id_foreign');
            $table->timestamps();
            $table->softDeletes()->index();

            $table->unique(['profesor_id', 'anio', 'division', 'grupo', 'materia_id'], 'prof_asig_unique');
            $table->index(['anio', 'division', 'materia_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profesor_asignaciones');
    }
};
