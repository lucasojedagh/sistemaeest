<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las notas de intensificación se nombran igual que la planilla oficial
 * (PLANILLAS DE CALIFICACIONES): 1° cuatrimestre, diciembre, febrero y
 * marzo-abril. Antes se llamaban febrero, julio, diciembre (pre) y
 * diciembre (post).
 *
 * Los datos se copian de una columna a la otra para no perder nada. Si más
 * adelante se agrega una de las columnas nuevas, la copia no pisa lo que ya
 * esté cargado (solo rellena las vacías).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabla = 'alumno_materias_estado';
        if (!Schema::hasTable($tabla)) return;

        $copiar = [
            'nota_intensificacion_diciembre' => 'nota_intensificacion_diciembre_pre',
            'nota_intensificacion_marzo_abril' => 'nota_intensificacion_diciembre_post',
            'nota_intensificacion_1cuatri' => 'nota_intensificacion_julio',
        ];

        foreach ($copiar as $nueva => $vieja) {
            if (Schema::hasColumn($tabla, $nueva)) continue;
            if (!Schema::hasColumn($tabla, $vieja)) continue;
            DB::statement("ALTER TABLE `$tabla` CHANGE `$vieja` `$nueva` decimal(4,2) DEFAULT NULL");
        }
    }

    public function down(): void
    {
        $tabla = 'alumno_materias_estado';
        if (!Schema::hasTable($tabla)) return;

        $restaurar = [
            'nota_intensificacion_diciembre' => 'nota_intensificacion_diciembre_pre',
            'nota_intensificacion_marzo_abril' => 'nota_intensificacion_diciembre_post',
            'nota_intensificacion_1cuatri' => 'nota_intensificacion_julio',
        ];

        foreach ($restaurar as $columna => $nombreViejo) {
            if (!Schema::hasColumn($tabla, $columna)) continue;
            if (Schema::hasColumn($tabla, $nombreViejo)) continue;
            DB::statement("ALTER TABLE `$tabla` CHANGE `$columna` `$nombreViejo` decimal(4,2) DEFAULT NULL");
        }
    }
};