<?php

namespace App\Dominios\Sistema;

use App\Http\Controllers\Controller;

use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

// Diagnóstico, descarga y restauración de la base de datos. Lo puede usar solo el
// rol Admin: las rutas lo filtran con role:admin y el catálogo reserva estas
// acciones en Permisos::SOLO_ADMIN. El envío del backup por mail se sacó del
// sistema: mandaba la base completa a un correo y no se usaba.
class BackupController extends Controller
{
    use \App\Support\AuthorizesScope;

    // Verifica el entorno de backup: detecta mysqldump, muestra configuracion de BD y carpeta de backups.
    /** Diagnóstico rápido del entorno. */
    public function check(Request $request)
    {
        // Expone credenciales de BD y rutas de storage: requiere el permiso de respaldos.
        if (!\App\Support\Permisos::puede($request->user()->role, 'backup.usar')) {
            abort(403, 'No tenés permiso para ver el diagnóstico de backups.');
        }

        $dumpInfo = $this->resolveDumpExe();
        $version = null;
        try {
            $p = new Process([$dumpInfo['exe'], '--version']);
            $p->setTimeout(5)->run();
            $version = trim($p->getOutput() ?: $p->getErrorOutput());
        } catch (\Throwable $e) {
            $version = 'unknown';
        }

        $db = config('database.connections.mysql');
        return response()->json([
            'mysqldump' => $dumpInfo,
            'mysqldump_version' => mb_substr($version, 0, 200),
            'db' => [
                'host' => $db['host'] ?? '',
                'port' => $db['port'] ?? '',
                'database' => $db['database'] ?? '',
                'configured' => !empty($db['database'] ?? ''),
            ],
            'storage_backups' => storage_path('app/backups'),
        ]);
    }

    // Resuelve la ruta al ejecutable de mysqldump: DB_BACKUP_MYSQLDUMP (carpeta
    // o archivo) o el PATH del sistema. Sólo lo usa el diagnóstico: el respaldo
    // y la restauración se hacen en PHP puro y no necesitan ningún ejecutable.
    private function resolveDumpExe(): array
    {
        $hint = trim((string) config('enro.backup_mysqldump', ''));
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $exeName = $isWin ? 'mysqldump.exe' : 'mysqldump';

        if ($hint === '') {
            return ['exe'=>$exeName, 'type'=>'PATH', 'exists'=>true];
        }
        $norm = $isWin ? str_replace('/', DIRECTORY_SEPARATOR, $hint) : $hint;

        if (preg_match('/mysqldump(\.exe)?$/i', $norm)) {
            return ['exe'=>$norm, 'type'=>'FILE', 'exists'=>is_file($norm)];
        }
        $full = rtrim($norm, "\\/").DIRECTORY_SEPARATOR.$exeName;
        return ['exe'=>$full, 'type'=>'DIR', 'exists'=>is_file($full)];
    }

    // Genera un dump SQL puro PHP (sin mysqldump).
    private function generatePhpSqlDump(): string
    {
        $pdo = DB::connection()->getPdo();
        $dbName = DB::getDatabaseName();
        $tables = collect(DB::select('SHOW TABLES'))->map(fn($r) => reset($r));

        // Nombre saneado para usarlo como identificador (solo letras, números y _).
        $dbSafe = preg_replace('/[^A-Za-z0-9_]/', '', (string) $dbName) ?: 'sistema_escuela';

        $out = "-- Sistema ENRO - Backup SQL completo generado por PHP\n";
        $out .= "-- Fecha: " . now()->toDateTimeString() . "\n";
        $out .= "-- Incluye: CREATE DATABASE + estructura (DROP/CREATE TABLE) + datos (INSERT)\n";
        $out .= "-- Para restaurar en cero: ejecutar este archivo en un servidor MySQL vacío.\n\n";
        $out .= "CREATE DATABASE IF NOT EXISTS `{$dbSafe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        $out .= "USE `{$dbSafe}`;\n\n";
        $out .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $out .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
        $out .= "SET NAMES utf8mb4;\n\n";

        foreach ($tables as $table) {
            $table = (string) $table;

            // CREATE TABLE
            $createRow = DB::select("SHOW CREATE TABLE `{$table}`");
            if (!empty($createRow)) {
                $createSql = $createRow[0]->{'Create Table'} ?? $createRow[0]->{"Create Table"} ?? '';
                $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $out .= $createSql . ";\n\n";
            }

            // INSERTS por lotes con lectura en streaming (cursor): nunca se carga
            // la tabla entera en memoria (con miles de filas agotaría los 128M
            // de PHP). Sin orderBy para no depender de que exista columna `id`.
            $colList = null;
            $lote = [];
            $volcarLote = function () use (&$lote, &$out, &$colList, $table, $pdo) {
                if (empty($lote)) return;
                if ($colList === null) {
                    $columns = array_keys((array) $lote[0]);
                    $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
                }
                $values = [];
                foreach ($lote as $rowArr) {
                    $escaped = array_map(function ($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote((string) $v);
                    }, $rowArr);
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $out .= "INSERT INTO `{$table}` ({$colList}) VALUES\n";
                $out .= implode(",\n", $values) . ";\n\n";
                $lote = [];
            };
            foreach (DB::table($table)->cursor() as $row) {
                $lote[] = (array) $row;
                if (count($lote) >= 500) $volcarLote();
            }
            $volcarLote();
        }

        $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $out;
    }

    // Descarga un backup SQL de la base de datos (pure PHP, sin mysqldump).
    /** GET /api/backups/sql/download */
    public function download(Request $request)
    {
        // Descarga de la base completa: exige confirmación de contraseña reciente.
        $this->assertPasswordConfirmed($request);

        $filename = sprintf(
            '%s_db_%s.sql',
            Str::slug(config('app.name','app'), '_'),
            now()->format('Y-m-d_H-i-s')
        );

        $sql = $this->generatePhpSqlDump();

        ActivityLogger::log($request, 'Descargó backup SQL', 'backup.download_sql');

        return response($sql, 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // Parte el SQL en sentencias respetando strings ('..', "..", `..`) y
    // comentarios (--, #, /* */). Un ';' dentro de un texto (ej: una
    // observación "hola; chau") NO corta la sentencia, a diferencia de un
    // explode(';') que rompería el INSERT y dejaría un restore parcial.
    private function splitSqlStatements(string $sql): array
    {
        $stmts = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        $quote = null;
        while ($i < $len) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($quote !== null) {
                $buf .= $c;
                if ($c === $quote) {
                    if ($next === $quote) {
                        $buf .= $next;
                        $i += 2;
                        continue;
                    }
                    $quote = null;
                } elseif ($c === '\\') {
                    $buf .= $next;
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }
            // Comentarios de línea se omiten (un ';' dentro de un comentario
            // no debe arrastrar a la sentencia que sigue en la línea próxima).
            if ($c === '-' && $next === '-' && isset($sql[$i + 2]) && ctype_space($sql[$i + 2])) {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $buf .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                $i++;
                continue;
            }
            if ($c === ';') {
                $stmts[] = $buf;
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        if (trim($buf) !== '') {
            $stmts[] = $buf;
        }
        return $stmts;
    }

    // Importa un archivo SQL en la base de datos (pure PHP, sin mysql CLI).
    /** POST /api/backups/sql/import */
    public function import(Request $request)
    {
        // Reemplaza TODA la base: exige confirmación de contraseña reciente.
        $this->assertPasswordConfirmed($request);

        $request->validate([
            'file' => 'required|file|mimes:sql,txt|max:51200',
        ], [
            // Los kilobytes de la regla no se leen en pantalla: se dice en MB.
            'file.required' => 'Elegí el archivo .sql que querés restaurar.',
            'file.mimes' => 'Solo se restauran archivos .sql generados por este sistema (o su copia .txt).',
            'file.max' => 'El respaldo no puede pesar más de 50 MB. Si tu base es más grande, pedile al administrador que suba ese límite.',
        ]);

        $file = $request->file('file');
        $tmpPath = $file->getRealPath();

        if (!$tmpPath || !is_file($tmpPath)) {
            return response()->json(['message' => 'No se pudo leer el archivo subido.'], 400);
        }

        $sqlContent = file_get_contents($tmpPath);

        if ($sqlContent === false || trim($sqlContent) === '') {
            return response()->json(['message' => 'El archivo está vacío o no se pudo leer.'], 400);
        }

        // Red de seguridad best-effort: guarda un dump del estado previo por si
        // el import deja la base a medias. Si falla, no bloquea el restore.
        $preBackupFile = null;
        try {
            $preDir = storage_path('app/backups');
            if (!is_dir($preDir)) @mkdir($preDir, 0755, true);
            $preBackupFile = 'pre-import_' . now()->format('Y-m-d_H-i-s') . '.sql';
            @file_put_contents($preDir . DIRECTORY_SEPARATOR . $preBackupFile, $this->generatePhpSqlDump());
        } catch (\Throwable $e) {
            $preBackupFile = null;
        }

        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        // Un solo commit al final. Con autocommit por sentencia, cada INSERT es
        // una transacción que va a disco: el restore del .sql de la base entera
        // (2 MB, 8021 sentencias) tardaba 75 s y se cortaba por timeout del
        // servidor, dejando la base a mitad de camino. Así tarda 5 s.
        $pdo->exec('SET UNIQUE_CHECKS=0');
        $pdo->exec('SET autocommit=0');

        $statements = array_filter(
            array_map('trim', $this->splitSqlStatements($sqlContent)),
            fn($s) => $s !== '' && !str_starts_with($s, '--')
        );

        $executed = 0;
        $failed = 0;
        $firstError = null;
        try {
            foreach ($statements as $stmt) {
                if (preg_match('/^SET\b/i', $stmt) && preg_match('/FOREIGN_KEY_CHECKS/i', $stmt)) continue;
                if (preg_match('/^--/i', $stmt)) continue;
                try {
                    if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $stmt)) {
                        // Las consultas que devuelven filas no van por exec():
                        // hay que drenar el resultado o la conexión queda
                        // envenenada y todo lo siguiente falla (error 2014).
                        $pdo->query($stmt)->fetchAll(\PDO::FETCH_NUM);
                    } else {
                        $pdo->exec($stmt);
                    }
                    $executed++;
                } catch (\Throwable $e) {
                    $failed++;
                    if ($firstError === null) $firstError = mb_substr($e->getMessage(), 0, 300);
                }
            }
        } finally {
            // Se confirma lo que anduvo: si el import fue parcial, el reporte de
            // abajo dice cuántas sentencias se aplicaron y esas quedan escritas.
            // Los DROP/CREATE TABLE del archivo ya confirmaron solos (MySQL no
            // puede deshacer DDL), así que revertir dejaría las tablas vacías.
            try {
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                // Sin commit no hay nada que informar: se sigue con el reporte.
            }
            try {
                $pdo->exec('SET autocommit=1');
                $pdo->exec('SET UNIQUE_CHECKS=1');
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (\Throwable $e) {
                // No enmascara el resultado del import si el restore de FK falla.
            }
        }

        ActivityLogger::log($request, 'Importó backup SQL (' . $file->getClientOriginalName() . ', ' . $executed . ' ok, ' . $failed . ' fallidas)', 'backup.import_sql');

        // Reporte honesto: no se responde ok:true si hubo fallos (el frontend muestra el message).
        if ($failed > 0 && $executed === 0) {
            return response()->json([
                'ok' => false,
                'message' => "No se pudo restaurar: las {$failed} sentencias fallaron y ninguna se aplicó" . ($firstError ? " ({$firstError})" : "") . ".",
                'executed' => $executed,
                'failed' => $failed,
            ], 422);
        }
        if ($failed > 0) {
            return response()->json([
                'ok' => false,
                'message' => "Restauración parcial: {$executed} sentencias aplicadas y {$failed} fallaron. Revisá los datos" . ($preBackupFile ? " (respaldo previo: {$preBackupFile})" : "") . ($firstError ? " Primer error: {$firstError}." : ""),
                'executed' => $executed,
                'failed' => $failed,
                'pre_backup' => $preBackupFile,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => "Base de datos restaurada correctamente ({$executed} sentencias ejecutadas).",
            'executed' => $executed,
            'failed' => $failed,
        ]);
    }
}

