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
        Schema::create('alumno_intensificaciones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('alumno_id')->index();
            $table->unsignedTinyInteger('anio')->nullable();
            $table->char('division', 1)->nullable();
            $table->unsignedBigInteger('materia_id')->nullable()->index();
            $table->string('materia_nombre', 120);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alumno_intensificaciones');
    }
};
