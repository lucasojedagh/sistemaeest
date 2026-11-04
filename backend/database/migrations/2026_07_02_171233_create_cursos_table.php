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
        Schema::create('cursos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedTinyInteger('anio');
            $table->char('division', 1);
            $table->enum('turno', ['manana', 'tarde', 'noche'])->nullable();
            $table->unsignedSmallInteger('cupo')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['anio', 'division']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cursos');
    }
};
