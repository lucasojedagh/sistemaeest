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
        Schema::create('alumno_responsables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('alumno_id')->index();
            $table->string('nombre', 120)->nullable();
            $table->string('apellido', 120)->nullable();
            $table->string('parentesco', 40)->nullable();
            $table->boolean('convive')->default(false);
            $table->boolean('tutor_legal')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alumno_responsables');
    }
};
