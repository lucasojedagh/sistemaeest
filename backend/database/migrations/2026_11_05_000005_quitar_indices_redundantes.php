<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La BD tenía índices duplicados: los que Laravel crea al declarar las claves
 * foráneas y los compuestos que agregó 2026_07_08_000001_add_performance_indexes
 * encima. Cada índice de esta lista es el prefijo exacto de otro que sigue
 * existiendo, así que el optimizador lo usa igual y las escrituras dejan de
 * mantener un árbol de más (horario_celdas tenía 688 KB de índices para 240 KB
 * de datos).
 *
 * Ningún índice UNIQUE se toca: esos son restricción de integridad, no
 * aceleradores de lectura. En `users` queda el único de api_token y se suelta
 * el compuesto (api_token, id), que en InnoDB no agrega nada porque todo
 * índice secundario ya lleva la clave primaria.
 */
return new class extends Migration
{
    /** [tabla, índice a eliminar, columnas originales (para el down)] */
    private const REDUNDANTES = [
        ['alumno_calificaciones', 'alumno_calificaciones_alumno_id_materia_id_index', '`alumno_id`, `materia_id`'],
        ['alumno_calificaciones', 'idx_calif_agregados', '`alumno_id`, `materia_id`, `anio`, `division`, `ciclo_lectivo`, `periodo`'],
        ['alumno_intensificaciones', 'alumno_intensificaciones_alumno_id_index', '`alumno_id`'],
        ['alumno_promedios', 'ap_ix_al', '`alumno_id`'],
        ['alumno_recursandos', 'alumno_recursandos_alumno_id_index', '`alumno_id`'],
        ['horario_celdas', 'horario_celdas_curso_id_index', '`curso_id`'],
        ['profesor_asignaciones', 'prof_asignaciones_profesor_id_idx', '`profesor_id`'],
        ['profesor_bajas', 'profesor_bajas_profesor_id_index', '`profesor_id`'],
        ['profesor_suplencias', 'profesor_suplencias_suplente_id_index', '`suplente_id`'],
        ['profesor_suplencias', 'profesor_suplencias_titular_id_index', '`titular_id`'],
        ['users', 'users_api_token_id_idx', '`api_token`, `id`'],
    ];

    public function up(): void
    {
        foreach (self::REDUNDANTES as [$tabla, $indice]) {
            if ($this->existe($tabla, $indice)) {
                DB::statement("ALTER TABLE `{$tabla}` DROP INDEX `{$indice}`");
            }
        }
        $this->actualizarEstadisticas();
    }

    public function down(): void
    {
        foreach (self::REDUNDANTES as [$tabla, $indice, $columnas]) {
            if (!$this->existe($tabla, $indice)) {
                DB::statement("ALTER TABLE `{$tabla}` ADD INDEX `{$indice}` ({$columnas})");
            }
        }
        $this->actualizarEstadisticas();
    }

    /**
     * Tras la importación masiva el optimizador seguía creyendo que alumnos
     * tenía 57 filas. ANALYZE le devuelve la cardinalidad real, que es lo que
     * usa para elegir índice en el padrón, las listas y el dashboard.
     */
    private function actualizarEstadisticas(): void
    {
        $tablas = array_map(
            fn ($r) => $r->TABLE_NAME,
            DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
        );
        foreach ($tablas as $t) {
            DB::statement("ANALYZE TABLE `{$t}`");
        }
    }

    private function existe(string $tabla, string $indice): bool
    {
        return DB::selectOne(
            'SELECT 1 x FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$tabla, $indice]
        ) !== null;
    }
};
