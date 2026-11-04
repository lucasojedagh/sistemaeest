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
        Schema::create('profesores', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('nombre');
            $table->string('apellido');
            $table->string('email')->nullable()->unique();
            $table->enum('estado', ['Activo', 'Licencia'])->default('Activo')->index();
            $table->boolean('suplente')->default(false);
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
            $table->text('deleted_reason')->nullable();
            $table->string('deleted_by', 120)->nullable();

            $table->index(['apellido', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profesores');
    }
};
