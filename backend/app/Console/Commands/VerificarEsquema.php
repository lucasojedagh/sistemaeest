<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Compara la estructura que crean las migraciones con la base de trabajo real.
 *
 * Existe porque dos cambios se aplicaron a mano sobre la BD de la escuela y
 * nunca quedaron en una migración: funcionaban en la máquina de desarrollo y
 * se perdían al levantar un servidor nuevo. Esta comando crea una base vacía
 * junto a la de trabajo (`<base>_esquema_check`), le corre todas las
 * migraciones, compara columna por columna e índice por índice, y la borra.
 *
 * Nunca escribe en la base de trabajo: solo CREATE DATABASE / DROP DATABASE
 * sobre la auxiliar. Sale con código 1 si hay diferencias, así sirve de filtro
 * antes de publicar.
 */
class VerificarEsquema extends Command
{
    protected $signature = 'esquema:verificar {--dejar : deja la base auxiliar creada para revisarla a mano}';

    protected $description = 'Migra una base vacía y la compara con la estructura de la base de trabajo';

    private const SQL_COLUMNAS = <<<'SQL'
        select concat(table_name, '.', column_name, ' ', data_type, '(',
                      coalesce(character_maximum_length, numeric_precision, '-'), ') ',
                      is_nullable, ' ', coalesce(column_default, 'null')) x
          from information_schema.columns
         where table_schema = database()
         order by x
        SQL;

    private const SQL_INDICES = <<<'SQL'
        select concat(table_name, '|', index_name, '|',
                      group_concat(replace(replace(replace(column_name, ' ', ''), 'utf8mb4', ''), '`', '')
                                   order by seq_in_index)) x
          from information_schema.statistics
         where table_schema = database()
         group by table_name, index_name
         order by x
        SQL;

    public function handle(): int
    {
        $original = (string) config('database.default');
        $config   = (array) config("database.connections.{$original}");

        if (($config['driver'] ?? '') !== 'mysql') {
            $this->error("Este chequeo necesita MySQL/MariaDB (conexión actual: {$original}).");
            return self::FAILURE;
        }

        $referencia = (string) ($config['database'] ?? '');
        $auxiliar   = $referencia . '_esquema_check';
        if (!preg_match('/^[A-Za-z0-9_]+$/', $referencia) || $referencia === $auxiliar) {
            $this->error("El nombre de la base de trabajo no se puede usar para el chequeo: '{$referencia}'.");
            return self::FAILURE;
        }

        $this->line("Base de trabajo: <info>{$referencia}</info>   Base auxiliar: <comment>{$auxiliar}</comment>");

        DB::connection($original)->getPdo()
            ->exec("CREATE DATABASE IF NOT EXISTS `{$auxiliar}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $salioBien = false;
        try {
            config(["database.connections.esquema_check" => array_merge($config, ['database' => $auxiliar])]);
            DB::purge('esquema_check');
            config(['database.default' => 'esquema_check']);

            $this->line('Migrando la base auxiliar...');
            if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
                $this->error('Las migraciones fallaron en una base vacía. Salida:');
                $this->line(trim(Artisan::output()));
                return self::FAILURE;
            }

            $delTrabajo  = $this->huella(DB::connection($original));
            $deMigraciones = $this->huella(DB::connection('esquema_check'));
            $salioBien = $this->informar($deMigraciones, $delTrabajo);
        } finally {
            config(['database.default' => $original]);
            DB::purge('esquema_check');
            DB::purge($original);
            if (!$this->option('dejar')) {
                DB::connection($original)->getPdo()->exec("DROP DATABASE IF EXISTS `{$auxiliar}`");
                $this->line('Base auxiliar eliminada (usa --dejar para conservarla).');
            } else {
                $this->line("Base auxiliar dejada creada: <comment>{$auxiliar}</comment>");
            }
        }

        return $salioBien ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string> huella de columnas e índices, ordenada */
    private function huella(\Illuminate\Database\Connection $conexion): array
    {
        $fuera = array_map(fn ($r) => 'columna ' . $r->x, $conexion->select(self::SQL_COLUMNAS));
        $indices = array_map(fn ($r) => 'indice  ' . $r->x, $conexion->select(self::SQL_INDICES));
        $todo = array_merge($fuera, $indices);
        sort($todo);
        return $todo;
    }

    /**
     * @param array<string> $deMigraciones lo que las migraciones dejan en una base nueva
     * @param array<string> $delTrabajo    lo que tiene la base que hoy funciona
     */
    private function informar(array $deMigraciones, array $delTrabajo): bool
    {
        $faltan  = array_diff($delTrabajo, $deMigraciones);
        $sobran  = array_diff($deMigraciones, $delTrabajo);

        foreach ($faltan as $f) {
            $this->error("FALTA  {$f}  -- esta en la base de trabajo y ninguna migracion lo crea");
        }
        foreach ($sobran as $s) {
            $this->warn("SOBRA  {$s}  -- lo crean las migraciones y la base de trabajo no lo tiene");
        }

        if (!$faltan && !$sobran) {
            $this->info(sprintf('Esquema identico: %d columnas y %d indices.',
                count(array_filter($deMigraciones, fn ($x) => str_starts_with($x, 'columna'))),
                count(array_filter($deMigraciones, fn ($x) => str_starts_with($x, 'indice')))
            ));
            return true;
        }

        $this->line(sprintf('Diferencias: %d que faltan, %d que sobran. Las que faltan van en una migracion nueva.',
            count($faltan), count($sobran)));
        return false;
    }
}
