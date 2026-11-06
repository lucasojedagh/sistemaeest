<?php

namespace App\Dominios\Sistema;

use App\Http\Controllers\Controller;

use App\Mail\BackupReady;
use App\Support\ActivityLogger;
use App\Support\RoleHierarchy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

// Controlador para la generacion y envio de backups de la base de datos.
// Intenta realizar un dump SQL via mysqldump; si falla, genera un archivo JSON
// comprimido en ZIP como plan de contingencia.
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

    // Genera un backup de la base de datos y lo envia por email.
    // Intenta primero un dump SQL con mysqldump; si falla, genera un archivo JSON comprimido en ZIP.
    /** Endpoint del botón: intenta SQL; si falla, envía JSON(.zip). */
    public function emailBackup(Request $request)
    {
        // Exfiltración de la base completa: exige confirmación de contraseña reciente.
        $this->assertPasswordConfirmed($request);

        $request->validate([
            'email' => ['required','email'],
        ]);
        $email = $request->input('email');

        // Carpeta destino
        $dir = storage_path('app/backups');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        // Intenta generar un backup SQL con mysqldump como metodo principal.
        // 1) INTENTO .SQL
        $sql = $this->trySqlDump($dir);
        if ($sql['ok']) {
            Mail::to($email)->send(new BackupReady('sql', $sql['filename']));
            ActivityLogger::log($request, 'Envió backup SQL a ' . $email, 'backup.email_sql');
            return response()->json([
                'status' => 'ok',
                'mode'   => 'sql',
                'file'   => $sql['filename'],
                'size'   => filesize($dir . DIRECTORY_SEPARATOR . $sql['filename']),
            ]);
        }

        // Si mysqldump fallo, utiliza el plan de contingencia: exporta JSON comprimido en ZIP.
        // 2) FALLBACK: JSON .zip (seguro en cualquier entorno)
        $json = $this->makeJsonZip($dir);
        if ($json['ok']) {
            Mail::to($email)->send(new BackupReady('json', $json['filename']));
            ActivityLogger::log($request, 'Envió backup JSON a ' . $email, 'backup.email_json');
            return response()->json([
                'status' => 'ok',
                'mode'   => 'json',
                'file'   => $json['filename'],
                'size'   => filesize($dir . DIRECTORY_SEPARATOR . $json['filename']),
                'sql_error' => $sql['error'] ?? null,
            ]);
        }

        ActivityLogger::log($request, 'Fallo envío de backup a ' . $email, 'backup.email_error');
        return response()->json([
            'message'   => 'No se pudo generar el backup',
            'sql_error' => $sql['error'] ?? null,
            'json_error'=> $json['error'] ?? null,
        ], 500);
    }

    // Intenta generar un dump SQL de la base de datos usando mysqldump.
    // Retorna un array con 'ok' true/false y el nombre del archivo o el error.
    /** Genera .sql con mysqldump (true si salió bien). */
    private function trySqlDump(string $dir): array
    {
        try {
            $db = config('database.connections.mysql');
            $host = in_array($db['host'] ?? '', ['localhost','','::1'], true) ? '127.0.0.1' : ($db['host'] ?? '127.0.0.1');
            $port = (string)($db['port'] ?? 3306);
            $user = (string)($db['username'] ?? 'root');
            $pass = (string)($db['password'] ?? '');
            $name = (string)($db['database'] ?? '');

            if ($name === '') {
                return ['ok'=>false, 'error'=>'DB no configurada'];
            }

            $file = sprintf(
                '%s_db_%s.sql',
                Str::slug(config('app.name','app'), '_'),
                now()->format('Y-m-d_H-i-s')
            );
            $full = $dir . DIRECTORY_SEPARATOR . $file;

            $dump = $this->resolveDumpExe();
            if ($dump['type'] !== 'PATH' && !$dump['exists']) {
                return ['ok'=>false, 'error'=>"mysqldump no encontrado en {$dump['exe']}"];
            }

            // Args (sin usar '>' redirection; escribimos con --result-file)
            $args = [
                $dump['exe'],
                '--protocol=tcp',
                '--host='.$host,
                '--port='.$port,
                '--user='.$user,
                '--single-transaction',
                '--skip-lock-tables',
                '--routines',
                '--events',
                '--set-gtid-purged=OFF',
                '--default-character-set=utf8mb4',
                '--result-file='.$full,
                $name,
            ];

            // Heredamos el entorno + agregamos MYSQL_PWD por seguridad
            $env = ['MYSQL_PWD' => $pass];
            $proc = new Process($args, null, $pass!=='' ? $env : null);
            $proc->setTimeout(300);
            $proc->run();

            if ($proc->isSuccessful() && is_file($full) && filesize($full) > 0) {
                return ['ok'=>true, 'filename'=>$file];
            }

            // Devuelvo detalle de error para depurar
            return [
                'ok'=>false,
                'error'=> $proc->getErrorOutput() ?: $proc->getOutput() ?: 'mysqldump desconocido',
            ];
        } catch (\Throwable $e) {
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    // Plan de contingencia: exporta todas las tablas de la base de datos a archivos JSON
    // y los comprime en un archivo ZIP.
    /** Plan B: exporta todas las tablas a JSON y las comprime en .zip */
    private function makeJsonZip(string $dir): array
    {
        $db = config('database.connections.mysql');
        $name = (string)($db['database'] ?? 'db');

        $tmpDir = storage_path('app/tmp_json_backup_'.Str::random(6));
        @mkdir($tmpDir, 0755, true);

        $zipName = sprintf(
            '%s_json_%s.zip',
            Str::slug(config('app.name','app'), '_'),
            now()->format('Y-m-d_H-i-s')
        );
        $zipFull = $dir . DIRECTORY_SEPARATOR . $zipName;

        try {
            // Listado de tablas
            $tables = collect(DB::select('SHOW TABLES'));
            if ($tables->isEmpty()) {
                return ['ok'=>false, 'error'=>'La base no tiene tablas (SHOW TABLES vacío)'];
            }

            $col = collect((array)$tables->first())->keys()->first(); // nombre de la columna devuelta por SHOW TABLES
            foreach ($tables as $t) {
                $table = $t->$col;
                $rows  = DB::table($table)->get();
                file_put_contents(
                    $tmpDir.DIRECTORY_SEPARATOR.$table.'.json',
                    $rows->toJson(JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                );
            }

            // Armar ZIP
            $zip = new ZipArchive();
            if ($zip->open($zipFull, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return ['ok'=>false, 'error'=>'No pude crear ZIP'];
            }
            foreach (scandir($tmpDir) as $f) {
                if ($f === '.' || $f === '..') continue;
                $zip->addFile($tmpDir.DIRECTORY_SEPARATOR.$f, $name.'/'.$f);
            }
            $zip->close();

            // limpiar temp
            foreach (scandir($tmpDir) as $f) {
                if ($f === '.' || $f === '..') continue;
                @unlink($tmpDir.DIRECTORY_SEPARATOR.$f);
            }
            @rmdir($tmpDir);

            return ['ok'=>true, 'filename'=>$zipName];
        } catch (\Throwable $e) {
            // limpiar si quedó algo
            if (is_dir($tmpDir)) {
                foreach (scandir($tmpDir) as $f) {
                    if ($f === '.' || $f === '..') continue;
                    @unlink($tmpDir.DIRECTORY_SEPARATOR.$f);
                }
                @rmdir($tmpDir);
            }
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    // Resuelve la ruta al ejecutable de mysqldump, ya sea desde la variable de entorno DB_BACKUP_MYSQLDUMP
    // o desde el PATH del sistema.
    /** Resuelve el ejecutable mysqldump desde .env (directorio o archivo) */
    private function resolveDumpExe(): array
    {
        $hint = trim((string) env('DB_BACKUP_MYSQLDUMP', ''));
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

    // Resuelve la ruta al ejecutable mysql CLI para importar dumps SQL.
    private function resolveMysqlExe(): array
    {
        $hint = trim((string) env('DB_BACKUP_MYSQL', ''));
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $exeName = $isWin ? 'mysql.exe' : 'mysql';

        if ($hint === '') {
            return ['exe'=>$exeName, 'type'=>'PATH', 'exists'=>true];
        }
        $norm = $isWin ? str_replace('/', DIRECTORY_SEPARATOR, $hint) : $hint;

        if (preg_match('/mysql(\.exe)?$/i', $norm)) {
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
            try {
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

