<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El histórico que el alumno generó en la escuela (notas, promedios, estado
     * por materia, asistencia y su horario) estaba enlazado con ON DELETE CASCADE:
     * si alguien borraba la ficha —o una fila suelta desde el administrador de
     * BD—, MySQL se llevaba esos datos por arrastre y no había forma de
     * recuperarlos. Con RESTRICT la base se niega y el borrado falla en vez de
     * vaciar historiales. Las fichas de contacto (direcciones, teléfonos,
     * responsables, recursando, intensificación) siguen en cascada porque viajan
     * con el alumno y el sistema las borra de forma explícita.
     *
     * [hijo, columna, padre, columna_padre, regla anterior]
     */
    private const RESTRICCIONES = [
        ['alumno_calificaciones',   'alumno_id', 'alumnos',  'id', 'cascade'],
        ['alumno_promedios',        'alumno_id', 'alumnos',  'id', 'cascade'],
        ['alumno_materias_estado',  'alumno_id', 'alumnos',  'id', 'cascade'],
        ['asistencias',             'alumno_id', 'alumnos',  'id', 'cascade'],
        ['alumno_horario_overrides','alumno_id', 'alumnos',  'id', 'cascade'],
        ['alumno_calificaciones',   'materia_id','materias', 'id', 'set null'],
        ['alumno_promedios',        'materia_id','materias', 'id', 'cascade'],
        ['alumno_materias_estado',  'materia_id','materias', 'id', 'cascade'],
        ['clases',                  'curso_id',  'cursos',   'id', 'cascade'],
        ['horario_celdas',          'curso_id',  'cursos',   'id', 'cascade'],
    ];

    private function clave(string $hijo, string $columna): string
    {
        return "{$hijo}_{$columna}_foreign";
    }

    private function reemplazar(string $hijo, string $columna, string $padre, string $colPadre, string $regla): void
    {
        $nombre = $this->clave($hijo, $columna);
        if (!Schema::hasTable($hijo) || !Schema::hasColumn($hijo, $columna)) return;

        $existe = DB::select(
            "select CONSTRAINT_NAME from information_schema.TABLE_CONSTRAINTS
             where CONSTRAINT_SCHEMA = database() and TABLE_NAME = ?
               and CONSTRAINT_NAME = ? and CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$hijo, $nombre]
        );
        if ($existe) {
            Schema::table($hijo, fn (Blueprint $t) => $t->dropForeign($nombre));
        }

        Schema::table($hijo, function (Blueprint $t) use ($columna, $padre, $colPadre, $regla) {
            $fk = $t->foreign($columna)->references($colPadre)->on($padre);
            if ($regla === 'cascade')   $fk->cascadeOnDelete();
            elseif ($regla === 'set null') $fk->nullOnDelete();
            else $fk->restrictOnDelete();
        });
    }

    public function up(): void
    {
        foreach (self::RESTRICCIONES as [$hijo, $columna, $padre, $colPadre]) {
            $this->reemplazar($hijo, $columna, $padre, $colPadre, 'restrict');
        }
    }

    public function down(): void
    {
        foreach (self::RESTRICCIONES as [$hijo, $columna, $padre, $colPadre, $regla]) {
            $this->reemplazar($hijo, $columna, $padre, $colPadre, $regla);
        }
    }
};
