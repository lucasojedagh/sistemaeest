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
        Schema::create('alumnos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('nombre', 120);
            $table->string('apellido', 120);
            $table->string('genero', 1);
            $table->string('dni', 20)->nullable()->unique();
            $table->date('fecha_nacimiento')->nullable();
            $table->unsignedTinyInteger('anio')->nullable();
            $table->char('division', 1)->nullable();
            $table->char('grupo', 1)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->smallInteger('egreso_year')->nullable();
            $table->tinyInteger('egreso_anio')->nullable();
            $table->char('egreso_division', 2)->nullable();
            $table->decimal('promedio_general', 5)->nullable()->index();
            $table->string('estado')->default('Activo')->index();
            $table->boolean('tiene_ppi')->default(false);
            $table->boolean('tiene_ae')->default(false);

            $table->index(['anio', 'division'], 'alumnos_anio_div_idx');
            $table->index(['apellido', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alumnos');
    }
};
