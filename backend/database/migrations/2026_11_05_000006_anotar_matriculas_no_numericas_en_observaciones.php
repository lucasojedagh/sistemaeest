<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La columna "Nº DE MATRICULA" de REGISTRO ALUMNADO 2026 no trae números: trae
 * la división del año anterior ("3º Año 6º" lo comparten 31 alumnos) o la
 * escuela de origen ("Otra Escuela", 11). Copiada tal cual a `alumnos.matricula`,
 * 263 personas comparten 13 textos y el campo no identifica a nadie — y dos
 * alumnos distintos pueden terminar con la misma "matrícula".
 *
 * No se borra nada: cada anotación se pasa a `alumnos.observaciones`, que ya es
 * el lugar donde el sistema guarda pases, bajas y procedencia (igual que hace el
 * importador con los textos que no son mail). El valor original y las
 * Observaciones de antes quedan en `alumnos_matricula_rescate`, así que down()
 * devuelve fila por fila exactamente lo que estaba.
 *
 * El criterio es el mismo de AlumnosExcelImport::esMatriculaNumero, repetido acá
 * a propósito: una migración no tiene que depender de una clase de la app, que
 * con el tiempo puede renombrarse y dejaría el rollback roto.
 */
return new class extends Migration
{
    private const RESCATE = 'alumnos_matricula_rescate';

    public function up(): void
    {
        if (!Schema::hasTable(self::RESCATE)) {
            Schema::create(self::RESCATE, function (Blueprint $t) {
                $t->unsignedBigInteger('alumno_id')->primary();
                $t->string('matricula', 30)->nullable();
                $t->text('observaciones_antes')->nullable();
                $t->timestamp('movida_el')->useCurrent();
            });
        }

        $filas = DB::table('alumnos')
            ->whereNotNull('matricula')
            ->where('matricula', '!=', '')
            ->get(['id', 'matricula', 'observaciones']);

        $movidas = 0;
        DB::transaction(function () use ($filas, &$movidas) {
            foreach ($filas as $fila) {
                if ($this->esNumero($fila->matricula)) continue;

                $anotacion = trim($fila->matricula);
                $antes = trim((string) ($fila->observaciones ?? ''));
                // Si la anotación ya está escrita en Observaciones no se duplica.
                $despues = match (true) {
                    $antes === '' => $anotacion,
                    mb_stripos($antes, $anotacion) !== false => $antes,
                    default => $antes . ' — ' . $anotacion,
                };

                DB::table(self::RESCATE)->updateOrInsert(
                    ['alumno_id' => $fila->id],
                    [
                        'matricula' => $fila->matricula,
                        'observaciones_antes' => $antes === '' ? null : $antes,
                        'movida_el' => now(),
                    ]
                );

                DB::table('alumnos')
                    ->where('id', $fila->id)
                    ->update(['matricula' => null, 'observaciones' => $despues]);

                $movidas++;
            }
        });

        // updated_at se deja intacto a propósito: esto es una reubicación de un
        // dato mal leído, no una edición del alumno, y el Historial muestra esa
        // marca como "última vez que alguien lo cambió".
        if ($movidas > 0) {
            DB::statement('ANALYZE TABLE alumnos');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::RESCATE)) return;

        DB::transaction(function () {
            foreach (DB::table(self::RESCATE)->get(['alumno_id', 'matricula', 'observaciones_antes']) as $r) {
                DB::table('alumnos')->where('id', $r->alumno_id)->update([
                    'matricula' => $r->matricula,
                    'observaciones' => $r->observaciones_antes,
                ]);
            }
            DB::table(self::RESCATE)->delete();
        });

        Schema::drop(self::RESCATE);
    }

    /** Un número de legajo: empieza en dígito y solo tiene dígitos y separadores. */
    private function esNumero(string $v): bool
    {
        $v = trim($v);
        return $v !== '' && preg_match('#^\d[\d\s./-]*\z#', $v) === 1;
    }
};
