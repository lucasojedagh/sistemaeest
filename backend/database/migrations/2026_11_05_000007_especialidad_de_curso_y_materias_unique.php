<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pone al día las migraciones con la estructura que la base de trabajo ya tiene.
 *
 * Dos cambios se aplicaron a mano sobre la BD de la escuela (y quedaron solo en
 * `base-de-datos/schema.sql`), sin migración:
 *
 *  - `cursos.especialidad`: la pantalla de horarios ordena las materias del curso
 *    poniendo primero las de su propia especialidad. Sin la columna, en una base
 *    recién creada esa lista sale en otro orden y un restore sobre esa base falla.
 *  - El único de `materias` incluye la especialidad. Con el viejo
 *    (anio, nombre, tipo) no se pueden cargar las materias que el colegio tiene
 *    repetidas adrede: "DIBUJO TECNOLÓGICO" de 4º en electromecánica y en MMO.
 *
 * Es idempotente: sobre la base de hoy no toca nada, y sobre una base recién
 * migrada agrega lo que falta.
 */
return new class extends Migration
{
    private const MATERIAS_VIEJO = 'materias_anio_nombre_tipo_unique';
    private const MATERIAS_NUEVO = 'materias_anio_nombre_tipo_especialidad_unique';

    public function up(): void
    {
        if (!$this->tieneColumna('cursos', 'especialidad')) {
            DB::statement('ALTER TABLE `cursos` ADD COLUMN `especialidad` varchar(32) NULL AFTER `cupo`');
        }

        $tieneNuevo = $this->tieneIndice('materias', self::MATERIAS_NUEVO);
        $tieneViejo = $this->tieneIndice('materias', self::MATERIAS_VIEJO);

        if (!$tieneNuevo) {
            if ($tieneViejo) {
                DB::statement('ALTER TABLE `materias` DROP INDEX `' . self::MATERIAS_VIEJO . '`');
            }
            // Columna calculada: NULL y '' cuentan igual, así el alta por planilla
            // de una materia sin especialidad no choca contra sí misma.
            DB::statement("ALTER TABLE `materias` ADD UNIQUE KEY `" . self::MATERIAS_NUEVO . "` (`anio`, `nombre`, `tipo`, (coalesce(`especialidad`, '')))");
        }

        $this->actualizarEstadisticas();
    }

    public function down(): void
    {
        if ($this->tieneIndice('materias', self::MATERIAS_NUEVO)) {
            DB::statement('ALTER TABLE `materias` DROP INDEX `' . self::MATERIAS_NUEVO . '`');
        }
        if (!$this->tieneIndice('materias', self::MATERIAS_VIEJO)) {
            DB::statement('ALTER TABLE `materias` ADD UNIQUE KEY `' . self::MATERIAS_VIEJO . '` (`anio`, `nombre`, `tipo`)');
        }
        if ($this->tieneColumna('cursos', 'especialidad')) {
            DB::statement('ALTER TABLE `cursos` DROP COLUMN `especialidad`');
        }
    }

    private function tieneColumna(string $tabla, string $columna): bool
    {
        return DB::selectOne(
            'SELECT 1 x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$tabla, $columna]
        ) !== null;
    }

    private function tieneIndice(string $tabla, string $indice): bool
    {
        return DB::selectOne(
            'SELECT 1 x FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$tabla, $indice]
        ) !== null;
    }

    private function actualizarEstadisticas(): void
    {
        foreach (['cursos', 'materias'] as $t) {
            DB::statement("ANALYZE TABLE `{$t}`");
        }
    }
};
