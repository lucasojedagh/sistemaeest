<?php

namespace App\Dominios\Sistema;

use App\Http\Controllers\Controller;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Materia;
use App\Dominios\Profesores\Profesor;
use App\Dominios\Sistema\ActivityLog;
use App\Dominios\Usuarios\User;
use App\Dominios\Usuarios\UserAnioDivision;
use App\Support\ActivityDiff;
use App\Support\ActivityLogger;
use App\Support\LikeSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

// Controlador que expone el historial de actividades del sistema (ActivityLog).
class HistorialController extends Controller
{
    // Solo se usa assertPasswordConfirmed(): vaciar la auditoría pide contraseña.
    use \App\Support\AuthorizesScope;

    // Tiempo de vida de la caché de historial (segundos).
    const CACHE_TTL = 300;

    // Cuántas filas mueve cada paso al vaciar el historial y cuántas lee por lote
    // al escribir la copia .sql.
    const LOTE_VACIADO = 1000;
    const LOTE_COPIA   = 500;

    // Filas por lote al armar la descarga del historial: cada lote se presenta,
    // se escribe en el CSV y se suelta, así el archivo no depende del
    // memory_limit de PHP por más grande que sea la auditoría.
    const LOTE_EXPORT = 500;

    // Columnas del archivo descargado, en el mismo orden y con los mismos títulos
    // que la pantalla. Un solo lugar para que CSV y JSON nunca se despeguen.
    const CABECERAS_EXPORT = ['Fecha', 'Área', 'Operación', 'Acción', 'Actor', 'Rol', 'Entidad', 'Cambios', 'Motivo'];

    // Techo de cursos por persona en la respuesta. Está por encima de lo que puede
    // dar esta escuela (7 años × 9 divisiones = 63), así que la ficha siempre recibe
    // la lista completa y el contador nunca miente; solo corta una anomalía de datos.
    const CURSOS_EN_FICHA = 100;

    // Nombres de materias resueltos en lote y recordados durante la request.
    private ?array $materiasPorId = null;

    // GET /api/historial
    // Lista las actividades recientes con filtros opcionales por categoria, tipo de
    // operacion (crear/editar/eliminar) y busqueda, con paginacion configurable.
    // Cada registro se devuelve ya "presentado" para que el frontend lo muestre
    // de forma intuitiva (icono, color, titulo legible, resumen de cambios, etc.).
    //
    // Cuando NO hay filtros activos (carga inicial), se sirve desde caché para
    // una respuesta rapida. Los filtros dinamicos (busqueda, fechas, etc.) siempre
    // consultan la base para mantener la precision.
    public function index(Request $request)
    {
        // Valida el rango de fechas SIEMPRE desde el backend (no confiar solo en la UI).
        // Formato válido, no futuras y Desde <= Hasta.
        $this->validateFecha('desde', $request->query('desde'));
        $this->validateFecha('hasta', $request->query('hasta'));
        $desdeQ = $request->query('desde');
        $hastaQ = $request->query('hasta');
        if ($desdeQ !== null && $desdeQ !== '' && $hastaQ !== null && $hastaQ !== '') {
            $d = Carbon::parse($desdeQ);
            $h = Carbon::parse($hastaQ);
            if ($d->gt($h)) {
                $this->fail('La fecha "Desde" debe ser menor o igual a "Hasta".');
            }
        }

        $hayFiltros = $request->query('categoria') || $request->query('operacion')
            || $request->query('search') || $request->query('desde') || $request->query('hasta');

        $perPage = \App\Support\Paginacion::porPagina($request, 50, 200);
        $page = (int) $request->query('page', 1);

        if (!$hayFiltros) {
            $cacheKey = 'historial_index_v4_' . $page . '_' . $perPage;
            $result = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($perPage) {
                return $this->buildResult(ActivityLog::recent(), $perPage);
            });
            // Registramos la clave para poder invalidarla luego. Se podan las que ya
            // vencieron (TTL 300s) y se limita la lista: la lista misma se guarda con
            // Cache::forever, así que sin poda crecería para siempre.
            $keys = array_values(array_filter(
                (array) Cache::get('historial_cache_keys', []),
                fn ($k) => $k === $cacheKey || Cache::has($k)
            ));
            if (!in_array($cacheKey, $keys, true)) {
                $keys[] = $cacheKey;
            }
            if (count($keys) > 50) {
                $keys = array_slice($keys, -50);
            }
            Cache::forever('historial_cache_keys', $keys);
            return $this->responder($result);
        }

        $query = $this->filtrar(ActivityLog::recent(), $request);

        $result = $this->buildResult($query, $perPage);

        return $this->responder($result);
    }

    // Aplica los filtros de la pantalla (área, operación, búsqueda y fechas) sobre
    // la consulta base. Lo usan index() y export() para que el archivo descargado
    // sea exactamente lo que se está viendo, no otra cosa.
    private function filtrar($query, Request $request)
    {
        if ($categoria = $request->query('categoria')) {
            $query->where('action_type', 'like', LikeSearch::escape($categoria) . '%');
        }

        if ($operacion = $request->query('operacion')) {
            $query->where('action_type', 'like', '%' . LikeSearch::escape($operacion));
        }

        if ($search = $request->query('search')) {
            $s = LikeSearch::contains($search);
            $query->where(function ($q) use ($s) {
                $q->where('action', 'like', $s)
                  ->orWhere('user_email', 'like', $s)
                  ->orWhere('detalles', 'like', $s)
                  // También busca en el detalle de los cambios: si no, buscar
                  // por el nombre de un alumno no encuentra su carga de notas.
                  ->orWhere('old_values', 'like', $s)
                  ->orWhere('new_values', 'like', $s);
            });
        }

        // Filtro por rango de fechas (created_at). Acepta fecha (Y-m-d) o datetime.
        if ($desde = $request->query('desde')) {
            $query->whereDate('created_at', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $query->whereDate('created_at', '<=', $hasta);
        }

        return $query;
    }

    // GET /api/historial/export
    // Descarga TODO el historial con los filtros de la pantalla. Antes el botón
    // "Exportar" armaba el archivo en el navegador con lo que ya estaba cargado,
    // o sea la página visible (50 filas), así que el CSV nunca coincidía con el
    // contador de la cabecera. Se escribe por lotes para que un historial grande
    // no choque contra el memory_limit de PHP.
    public function export(Request $request)
    {
        $this->validateFecha('desde', $request->query('desde'));
        $this->validateFecha('hasta', $request->query('hasta'));
        $desdeQ = $request->query('desde');
        $hastaQ = $request->query('hasta');
        if ($desdeQ && $hastaQ && Carbon::parse($desdeQ)->gt(Carbon::parse($hastaQ))) {
            $this->fail('La fecha "Desde" debe ser menor o igual a "Hasta".');
        }

        // Se fija el último id existente: si alguien trabaja mientras se descarga,
        // el archivo corta en lo que había al momento del click en vez de saltear
        // filas por meterse en el medio del recorrido.
        $topeId = (int) ActivityLog::query()->max('id');
        $query = $this->filtrar(ActivityLog::query(), $request)
            ->where('activity_logs.id', '<=', $topeId)
            ->reorder()
            ->orderByDesc('activity_logs.id');

        $esJson = strtolower((string) $request->query('format', 'csv')) === 'json';
        $nombre = 'historial_' . date('Ymd-His') . ($esJson ? '.json' : '.csv');

        return response()->streamDownload(function () use ($query, $esJson) {
            $h = fopen('php://output', 'w');
            if ($esJson) {
                fwrite($h, '[');
            } else {
                // BOM: sin él, Excel abre los acentos como caracteres raros.
                fwrite($h, "\xEF\xBB\xBF");
                fputcsv($h, array_values(self::CABECERAS_EXPORT));
            }
            $primera = true;
            $query->chunk(self::LOTE_EXPORT, function ($logs) use ($h, $esJson, &$primera) {
                [$rolesMap, $autoresMap, $cursosMap, $alumnosMap] = $this->mapasDe($logs);
                foreach ($logs as $log) {
                    $fila = $this->filaExport($this->present($log, $rolesMap, $autoresMap, $cursosMap, $alumnosMap));
                    if ($esJson) {
                        if (!$primera) fwrite($h, ',');
                        fwrite($h, json_encode($fila, JSON_UNESCAPED_UNICODE));
                    } else {
                        fputcsv($h, array_values($fila));
                    }
                    $primera = false;
                }
            });
            if ($esJson) fwrite($h, ']');
            fclose($h);
        }, $nombre, [
            'Content-Type' => $esJson ? 'application/json; charset=UTF-8' : 'text/csv; charset=UTF-8',
        ]);
    }

    // Una fila del historial ya plana y en español, igual que la tabla de la
    // pantalla: el resumen de cambios viaja como texto único para que el archivo
    // no termine lleno de "[object Object]".
    private function filaExport(array $p): array
    {
        return [
            self::CABECERAS_EXPORT[0] => $p['fecha_legible'] ?? '',
            self::CABECERAS_EXPORT[1] => $p['categoria'],
            self::CABECERAS_EXPORT[2] => $p['operacion'],
            self::CABECERAS_EXPORT[3] => $p['titulo'],
            self::CABECERAS_EXPORT[4] => $p['actor'],
            self::CABECERAS_EXPORT[5] => $p['actor_role_legible'],
            self::CABECERAS_EXPORT[6] => $p['entidad'] ?? '',
            self::CABECERAS_EXPORT[7] => implode(' | ', array_column($p['resumen'] ?? [], 'texto')),
            self::CABECERAS_EXPORT[8] => $p['detalles'] ?? '',
        ];
    }

    // Devuelve la pagina paginada agregando el conteo global por area, que la
    // pantalla usa en los chips de filtro. Va junto a la paginacion y no dentro:
    // los contadores tienen que reflejar TODO el historial, no la pagina visible.
    private function responder($result)
    {
        return response()->json(array_merge($result->toArray(), [
            'conteos' => $this->conteos(),
        ]));
    }

    // Cuantas entradas hay por area (el prefijo del action_type, ej: "alumno").
    // Una sola consulta agrupada y cacheada: recorrer la tabla en cada pedido
    // sobraria cuando el historial crece.
    private function conteos(): array
    {
        return Cache::remember('historial_conteos', self::CACHE_TTL, function () {
            return ActivityLog::query()
                ->selectRaw('SUBSTRING_INDEX(action_type, \'.\', 1) AS area, COUNT(*) AS cantidad')
                ->groupBy('area')
                ->pluck('cantidad', 'area')
                ->all();
        });
    }

    // GET /api/datos/sello
    // Numero por grupo de datos (alumnos, profesores, cursos, asistencia,
    // calificaciones, usuarios, documentos) con el ultimo cambio registrado.
    // El frontend lo guarda y, al volver a una pantalla, lo compara: si el
    // numero subio, es que alguien toco esos datos y se ofrece actualizar.
    // No trae datos, solo el numero: por eso es barato.
    public function sello()
    {
        return response()->json(SelloCambios::estado());
    }

    // Valida que el parámetro de fecha opcional tenga formato válido y no sea futura.
    private function validateFecha(string $campo, $valor): void
    {
        if ($valor === null || $valor === '') return;
        try {
            $c = Carbon::parse($valor);
        } catch (\Throwable $e) {
            $this->fail("La fecha \"$campo\" no tiene un formato válido.");
        }
        // Carbon arregla en silencio las fechas que no existen: "2026-02-30" se
        // convierte en 2 de marzo y el filtro se corre un mes entero sin avisar,
        // mostrando movimientos que el usuario nunca pidió. El calendario lo
        // decide antes de comparar.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string) $valor), $m)
            && !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $this->fail("La fecha \"$campo\" no existe en el calendario.");
        }
        if ($c->isAfter(now())) {
            $this->fail("La fecha \"$campo\" no puede ser futura.");
        }
    }

    // Aborta la petición con un error de validación (422).
    private function fail(string $message): void
    {
        throw ValidationException::withMessages(['fecha' => [$message]]);
    }

    // Construye la respuesta paginada transformando cada log al formato presentado.
    private function buildResult($query, int $perPage)
    {
        $result = $query->paginate($perPage);
        $collection = $result->getCollection();

        [$rolesMap, $autoresMap, $cursosMap, $alumnosMap] = $this->mapasDe($collection);

        $result->setCollection($collection->transform(
            fn (ActivityLog $log) => $this->present($log, $rolesMap, $autoresMap, $cursosMap, $alumnosMap)
        ));
        return $result;
    }

    // Mapas que necesita present() para un lote de logs: rol del usuario afectado,
    // datos de quien hizo el movimiento y sus cursos. Se resuelven en tres
    // consultas para todo el lote, no por tarjeta; los usa la página paginada y
    // también la descarga completa del historial.
    private function mapasDe($collection): array
    {
        // Resuelve el rol de los usuarios afectados (auditable_type = 'User') de forma
        // masiva para evitar una consulta por tarjeta.
        $userIds = $collection
            ->where('auditable_type', 'User')
            ->pluck('auditable_id')->filter()->unique()->values()->all();
        $rolesMap = $userIds ? User::whereIn('id', $userIds)->pluck('role', 'id')->all() : [];

        // Nombre real de quien hizo cada movimiento. Sin esto la tarjeta muestra
        // el arranque del email ("jefepreceptores"), que en la escuela no es
        // como se llama a nadie. La versión de la foto sale del mismo query: sirve
        // para que el nombre de la tarjeta sea clickeable y abra la ficha de esa
        // persona. El apellido viaja aparte para que la ficha saque las iniciales
        // con la misma regla que usan las demás pantallas, en vez de adivinar
        // cuál palabra lo es.
        $autores = $collection->pluck('user_id')->filter()->unique()->values()->all();
        $autoresMap = [];
        $usuarios = $autores
            ? User::whereIn('id', $autores)->get(['id', 'name', 'apellido', 'avatar', 'email', 'role'])
            : collect();
        foreach ($usuarios as $u) {
            $autoresMap[$u->id] = [
                'completo' => trim($u->name . ' ' . $u->apellido),
                'pila'     => trim((string) $u->name),
                'apellido' => trim((string) $u->apellido),
                'avatar'   => $u->avatar_version,
            ];
        }
        return [$rolesMap, $autoresMap, $this->cursosDeAutores($usuarios), $this->nombresAlumnos($collection)];
    }

    // Nombre de los alumnos que aparecen como marca de asistencia. La clave que
    // guarda esa acción es "alumno_<id>" para que el historial muestre solo al
    // alumno corregido y no la clase entera; acá se traduce a apellido y nombre
    // en una sola consulta por lote, sin recorrer el padrón tarjeta por tarjeta.
    private function nombresAlumnos($collection): array
    {
        $ids = [];
        foreach ($collection as $log) {
            foreach ([$log->old_values, $log->new_values] as $valores) {
                if (!is_array($valores)) continue;
                foreach (array_keys($valores) as $k) {
                    if (preg_match('/^alumno_(\d+)$/', (string) $k, $m)) $ids[(int) $m[1]] = true;
                }
            }
        }
        if (!$ids) return [];

        $nombres = [];
        foreach (Alumno::whereIn('id', array_keys($ids))->get(['id', 'nombre', 'apellido']) as $a) {
            $nombres[(int) $a->id] = trim($a->apellido . ', ' . $a->nombre);
        }
        return $nombres;
    }

    // Etiqueta de un campo del diff. Las marcas de asistencia viajan con el id
    // interno: se muestran con el nombre y, si el alumno ya no está en el padrón,
    // se dice en vez de dejar un número suelto que no significa nada.
    private function etiquetaCampo($campo, array $alumnos): string
    {
        $campo = (string) $campo;
        if (preg_match('/^alumno_(\d+)$/', $campo, $m)) {
            $id = (int) $m[1];
            return $alumnos[$id] ?? 'Alumno n.° ' . $id . ' (ya no está en el padrón)';
        }
        return ActivityDiff::etiqueta($campo);
    }

    // Cursos en los que trabaja cada autor de la página, para la ficha que se abre
    // al pulsar su nombre. Salen de dos lugares: el alcance de la cuenta
    // (user_anio_division) y, cuando el e-mail coincide con una ficha de profesor,
    // los cursos en los que esa ficha dicta materias. Se resuelven en tres
    // consultas para toda la página, no por tarjeta.
    private function cursosDeAutores($usuarios): array
    {
        if ($usuarios->isEmpty()) return [];

        $ids      = $usuarios->pluck('id')->all();
        $porEmail = [];
        foreach ($usuarios as $u) {
            if ($u->email) $porEmail[strtolower(trim((string) $u->email))] = (int) $u->id;
        }

        $cursos = [];
        $agrupar = function (int $uid, $anio, $division) use (&$cursos): void {
            $anio = (int) $anio;
            if ($anio <= 0) return;
            $division = $division === null ? null : strtoupper(trim((string) $division));
            if ($division === '') $division = null;
            $cursos[$uid][$anio . '|' . ($division ?? '')] = true;
        };

        foreach (UserAnioDivision::whereIn('user_id', $ids)->get(['user_id', 'anio', 'division']) as $a) {
            $agrupar((int) $a->user_id, $a->anio, $a->division);
        }

        if ($porEmail) {
            $uidPorFicha = [];
            foreach (Profesor::whereIn('email', array_keys($porEmail))->get(['id', 'email']) as $f) {
                $uid = $porEmail[strtolower(trim((string) $f->email))] ?? null;
                if ($uid) $uidPorFicha[(int) $f->id] = $uid;
            }
            if ($uidPorFicha) {
                $filas = DB::table('profesor_asignaciones')
                    ->select('profesor_id', 'anio', 'division')
                    ->distinct()
                    ->whereIn('profesor_id', array_keys($uidPorFicha))
                    ->whereNull('deleted_at')
                    ->get();
                foreach ($filas as $f) {
                    $agrupar($uidPorFicha[(int) $f->profesor_id], $f->anio, $f->division);
                }
            }
        }

        $mapa = [];
        foreach ($cursos as $uid => $lista) {
            // Cada curso viaja como "anio|division" y no como objeto. Una página llena
            // de movimientos del mismo docente repetía la lista entera tarjeta por
            // tarjeta: en formato corto pesan una fracción y la caché del navegador
            // sigue alcanzando para guardar la última carga.
            $claves = array_keys($lista);
            usort($claves, function (string $a, string $b): int {
                [$an, $da] = explode('|', $a, 2);
                [$bn, $db] = explode('|', $b, 2);
                return [(int) $an, $da] <=> [(int) $bn, $db];
            });
            $mapa[$uid] = [
                'total'  => count($claves),
                'cursos' => array_slice($claves, 0, self::CURSOS_EN_FICHA),
            ];
        }
        return $mapa;
    }

    // Invalida toda la caché de historial (se llama al registrar nueva actividad
    // o al forzar un refresco desde el frontend).
    public static function clearCache(): void
    {
        $keys = Cache::get('historial_cache_keys', []);
        foreach ($keys as $k) { Cache::forget($k); }
        Cache::forget('historial_cache_keys');
        Cache::forget('historial_conteos');
    }

    // POST /api/historial/refresh
    // Invalida la caché y devuelve los datos mas recientes forzando una
    // consulta a la base de datos (usado por el boton de refrescar del frontend).
    public function refresh(Request $request)
    {
        self::clearCache();
        return $this->index($request);
    }

    // DELETE /api/historial  (role:admin + permiso historial.limpiar + contraseña)
    // Vacía la auditoría completa. Pide confirmación de contraseña reciente, como
    // las otras acciones que tocan la base entera. Antes de borrar escribe una
    // copia .sql de todas las filas en storage/app/backups: si la copia no se
    // puede crear, no se borra nada. Del vaciamiento queda una sola tarjeta en el
    // historial nuevo (quién, cuántos registros, nombre de la copia).
    public function limpiar(Request $request)
    {
        $this->assertPasswordConfirmed($request);

        $total = ActivityLog::count();
        if ($total === 0) {
            return response()->json(['borrados' => 0, 'respaldo' => null]);
        }

        $copia = $this->escribirCopiaHistorial();
        if ($copia === null) {
            abort(500, 'No se pudo crear la copia del historial en el servidor. No se borró nada.');
        }

        // De a lotes, sin transacción larga: un DELETE de todo junto sobre un
        // historial de un año traba las escrituras de los demás usuarios mientras
        // dura. Si se corta a mitad, la copia ya tiene todas las filas.
        $borrados = 0;
        while ($ids = ActivityLog::query()->orderBy('id')->limit(self::LOTE_VACIADO)->pluck('id')->all()) {
            $borrados += ActivityLog::query()->whereIn('id', $ids)->delete();
        }

        self::clearCache();

        // El vaciamiento se anota EN el historial que acaba de limpiar: es la única
        // fila que queda, y dice quién, cuándo y dónde está la copia. Sin ella,
        // borrar la auditoría no deja ninguna auditoría. Es un balance (como el de
        // una promoción o una importación): se presenta como lista de datos, no
        // como antes/después.
        ActivityLogger::log(
            $request,
            'Vació el historial de actividad',
            'historial.vaciar',
            null,
            null,
            null,
            ['Registros borrados' => $total, 'Copia de respaldo' => basename($copia)]
        );

        Log::notice('Historial de actividad vaciado desde el sistema', [
            'cuenta'   => $request->user()->email,
            'borrados' => $borrados,
            'copia'    => basename($copia),
        ]);

        return response()->json([
            'borrados' => $borrados,
            'respaldo' => basename($copia),
        ]);
    }

    // Vuelca activity_logs a un .sql pegable (phpMyAdmin o `mysql < archivo`).
    // Lee de a lotes y escribe directo al disco para que el tamaño del historial
    // no dependa del memory_limit de PHP.
    private function escribirCopiaHistorial(): ?string
    {
        $dir = storage_path('app/backups');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $archivo = $dir . '/historial_' . now()->format('Y-m-d_H-i-s') . '.sql';
        $h = @fopen($archivo, 'w');
        if ($h === false) {
            return null;
        }

        $columnas = [
            'id', 'user_id', 'user_email', 'user_role', 'action', 'action_type',
            'auditable_type', 'auditable_id', 'old_values', 'new_values',
            'detalles', 'ip_address', 'user_agent', 'created_at',
        ];

        try {
            $pdo = DB::connection()->getPdo();
            fwrite($h, "-- Historial de actividad del Sistema ENRO\n");
            fwrite($h, "-- Copia tomada " . now()->format('Y-m-d H:i:s')
                . " antes de vaciar el historial desde la pantalla.\n");
            fwrite($h, "-- Tabla: activity_logs\n\n");
            fwrite($h, "SET NAMES utf8mb4;\n");

            $listaCols = '`' . implode('`, `', $columnas) . '`';
            ActivityLog::orderBy('id')->chunk(self::LOTE_COPIA, function ($lote) use ($h, $pdo, $columnas, $listaCols) {
                foreach ($lote as $fila) {
                    $valores = [];
                    foreach ($columnas as $col) {
                        $valores[] = $this->valorSql($pdo, $fila->getAttribute($col));
                    }
                    fwrite($h, "INSERT INTO `activity_logs` ({$listaCols}) VALUES ("
                        . implode(', ', $valores) . ");\n");
                }
            });
        } catch (\Throwable $e) {
            fclose($h);
            @unlink($archivo);
            return null;
        }

        if (fclose($h) === false) {
            @unlink($archivo);
            return null;
        }

        return $archivo;
    }

    // Un valor de la tabla ya en formato SQL. Los JSON se vuelven a codificar
    // (el modelo los entrega como arreglo) y PDO se encarga de las comillas.
    private function valorSql(\PDO $pdo, $valor): string
    {
        if ($valor === null) return 'NULL';
        if (is_bool($valor)) return $valor ? '1' : '0';
        if (is_array($valor)) $valor = json_encode($valor, JSON_UNESCAPED_UNICODE);

        return $pdo->quote((string) $valor);
    }

    // ========================= PRESENTACION =========================
    // Convierte un ActivityLog en un arreglo listo para la vista.
    private function present(ActivityLog $log, array $rolesMap = [], array $autoresMap = [], array $cursosMap = [], array $alumnosMap = []): array
    {
        $meta = $this->meta($log->action_type);
        $actor = $this->actorName($log->user_email, $log->user_id, $autoresMap);

        $entidad = $this->entidad($log->auditable_type, $log->auditable_id);
        // Solo se puede abrir la ficha de quien todavía tiene cuenta: si la cuenta
        // fue borrada, el nombre de la tarjeta queda como texto simple.
        $autor = $log->user_id !== null ? ($autoresMap[$log->user_id] ?? null) : null;
        $actorVive = $autor !== null;
        $categoria = $meta['categoria'];
        // Para usuarios afectados, la etiqueta de categoria refleja su rol.
        if ($log->auditable_type === 'User' && $log->auditable_id && isset($rolesMap[$log->auditable_id])) {
            $rol = $rolesMap[$log->auditable_id];
            $entidad = ($entidad ? $entidad . ' · ' : '') . $this->roleLabel($rol);
            if ($rol === 'preceptor') {
                $categoria = 'Preceptores';
            } elseif ($rol === 'profesor') {
                $categoria = 'Profesores';
            } elseif ($rol === 'directivo') {
                $categoria = 'Directivos';
            } elseif ($rol === 'jefe_preceptores') {
                $categoria = 'Jefes de preceptores';
            }
        }

        return [
            'id'            => $log->id,
            'icono'         => $meta['icono'],
            'color'         => $meta['color'],
            'categoria'     => $categoria,
            'categoria_key' => $meta['categoria_key'],
            'operacion'     => $meta['operacion'],
            'action_type'   => $log->action_type,
            'titulo'        => $log->action,
            'actor'         => $actor,
            'actor_email'   => $log->user_email,
            'actor_role'    => $log->user_role,
            // El rol interno (ej: "jefe_preceptores") nunca debe mostrarse tal cual.
            'actor_role_legible' => $this->roleLabel($log->user_role),
            'actor_user_id'        => $actorVive ? $log->user_id : null,
            'actor_avatar_version' => $actorVive ? $autor['avatar'] : null,
            // Nombre y apellido por separado: la ficha arma sus iniciales con la
            // misma regla que el resto del sistema. Cuando la cuenta ya no existe
            // quedan null y la ficha se las ingenia con el nombre completo.
            'actor_nombre'         => $autor['pila'] ?? null,
            'actor_apellido'       => $autor['apellido'] ?? null,
            // Para la ficha: en qué cursos trabaja quien hizo el movimiento.
            'actor_cursos'         => $actorVive ? ($cursosMap[$log->user_id]['cursos'] ?? []) : [],
            'actor_cursos_total'   => $actorVive ? ($cursosMap[$log->user_id]['total'] ?? 0) : 0,
            'entidad'       => $entidad,
            // "sin motivo" es lo que guarda el botón Omitir: repetirlo en la
            // tarjeta es ruido, así que se muestra el motivo solo si existe.
            'detalles'      => $this->motivo($log->detalles),
            'resumen'       => $this->resumen($log, $alumnosMap),
            'fecha'         => $log->created_at?->toIso8601String(),
            'fecha_legible' => $log->created_at?->format('d/m/Y H:i'),
        ];
    }

    // Etiqueta legible de un rol de usuario.
    private function roleLabel(?string $role): string
    {
        $map = [
            'admin'             => 'Admin',
            'directivo'         => 'Directivo',
            'jefe_preceptores'  => 'Jefe de preceptores',
            'preceptor'         => 'Preceptor',
            'prosecretaria'     => 'Prosecretaria',
            'profesor'          => 'Profesor',
        ];
        return $map[$role] ?? ($role ? ucfirst(str_replace('_', ' ', $role)) : '—');
    }

    // El ultimo tramo del action_type es la operacion ("alumno.store" -> "store").
    private function operacionDe(string $actionType): string
    {
        $partes = explode('.', $actionType);
        return (string) end($partes);
    }

    // Devuelve icono, color y etiquetas segun el action_type (ej: "alumno.update").
    private function meta(string $actionType): array
    {
        $partes = explode('.', $actionType);
        $base = $partes[0] ?? 'general';
        // La operacion es el ULTIMO tramo: hay tipos de tres niveles
        // ("profesor.suplencia.store", "asistencia.justificacion.aprobada") y leer el
        // segundo dejaba esas tarjetas con la etiqueta generica "Operacion".
        $operacion = $this->operacionDe($actionType);

        $categorias = [
            'user'           => ['categoria' => 'Usuarios',        'icono' => 'user'],
            'alumno'         => ['categoria' => 'Alumnos',         'icono' => 'alumno'],
            'profesor'       => ['categoria' => 'Profesores',      'icono' => 'profesor'],
            'profesor_baja'  => ['categoria' => 'Profesores',      'icono' => 'profesor'],
            'calificaciones' => ['categoria' => 'Calificaciones',  'icono' => 'nota'],
            'asistencia'     => ['categoria' => 'Asistencia',      'icono' => 'asistencia'],
            'horario'        => ['categoria' => 'Horarios',        'icono' => 'reloj'],
            'divisiones'     => ['categoria' => 'Divisiones',      'icono' => 'division'],
            'curso'          => ['categoria' => 'Cursos',          'icono' => 'division'],
            'cursos'         => ['categoria' => 'Cursos',          'icono' => 'division'],
            'materias'       => ['categoria' => 'Materias',        'icono' => 'materia'],
            'documento'      => ['categoria' => 'Documentos',      'icono' => 'doc'],
            'promocion'      => ['categoria' => 'Promociones',     'icono' => 'promocion'],
            'ventana'        => ['categoria' => 'Ventana de notas', 'icono' => 'ventana'],
            'backup'         => ['categoria' => 'Copias de seguridad', 'icono' => 'backup'],
            // Los roles del sistema se guardan con prefijo "role" (el catálogo de
            // acciones); "rol" queda por si alguna entrada vieja usa esa forma.
            'role'           => ['categoria' => 'Roles',           'icono' => 'user'],
            'rol'            => ['categoria' => 'Roles',           'icono' => 'user'],
            'ciclo'          => ['categoria' => 'Ciclo lectivo',   'icono' => 'promocion'],
            'historial'      => ['categoria' => 'Historial',       'icono' => 'engranaje'],
        ];

        $cat = $categorias[$base] ?? ['categoria' => 'Sistema', 'icono' => 'engranaje'];

        // Etiqueta y color por accion. Si aparece un action_type nuevo que no esta
        // aqui, se humaniza el tramo en lugar de mostrar "Operacion" a secas.
        $operaciones = [
            'store'            => ['Creación', 'green'],
            'create'           => ['Creación', 'green'],
            'register'         => ['Registro público', 'green'],
            'storeFromUsers'   => ['Creación desde usuarios', 'green'],
            'up'               => ['Promoción', 'green'],
            'aprobada'         => ['Justificación aprobada', 'green'],
            'aprobar'          => ['Aprobación de cuenta', 'green'],
            'update'           => ['Edición', 'blue'],
            'edit'             => ['Edición', 'blue'],
            'replace'          => ['Reemplazo', 'blue'],
            'sync'             => ['Sincronización', 'blue'],
            'assignments'      => ['Asignaciones', 'blue'],
            'avatar'           => ['Foto de perfil', 'blue'],
            'clave_temporal'   => ['Clave temporal', 'amber'],
            'horario'          => ['Ajuste de horario', 'blue'],
            'todas'            => ['Carga de materias', 'blue'],
            'oficiales'        => ['Materias oficiales', 'blue'],
            'destroy'          => ['Eliminación', 'red'],
            'delete'           => ['Eliminación', 'red'],
            'forceDelete'      => ['Eliminación definitiva', 'red'],
            'vaciar'           => ['Vaciado del historial', 'red'],
            'inactivar'        => ['Baja por abandono', 'amber'],
            'promote'          => ['Promoción del ciclo', 'amber'],
            'enable_years'     => ['Apertura de años', 'blue'],
            'sesiones'         => ['Cierre de sesiones', 'indigo'],
            'profile'          => ['Edición de Mi cuenta', 'blue'],
            'password_reset'   => ['Cambio de contraseña', 'amber'],
            'password_link'    => ['Solicitud de recuperación', 'amber'],
            'ticket'           => ['Impresión de constancia', 'violet'],
            'rechazar'         => ['Rechazo de cuenta', 'red'],
            'rechazada'        => ['Justificación rechazada', 'red'],
            'error'            => ['Error', 'red'],
            'email_error'      => ['Error de envío', 'red'],
            'login'            => ['Inicio de sesión', 'indigo'],
            'logout'           => ['Cierre de sesión', 'indigo'],
            'download'         => ['Descarga', 'violet'],
            'print'            => ['Impresión', 'violet'],
            'download_sql'     => ['Descarga de copia', 'violet'],
            'email_sql'        => ['Copia enviada por mail', 'violet'],
            'email_json'       => ['Informe enviado por mail', 'violet'],
            'restore'          => ['Restauración', 'amber'],
            'import'           => ['Importación', 'amber'],
            'import_sql'       => ['Importación de copia', 'amber'],
            'verify'           => ['Verificación', 'amber'],
            'role'             => ['Cambio de rol', 'amber'],
            'egresar'          => ['Egreso', 'amber'],
            'justificar'       => ['Justificación', 'amber'],
            'pendiente'        => ['Justificación pendiente', 'amber'],
            'cerrar'           => ['Cierre de la ventana', 'amber'],
            'down'             => ['Reversión', 'amber'],
        ];

        [$etiqueta, $color] = $operaciones[$operacion]
            ?? [ucfirst(str_replace('_', ' ', $operacion)), 'slate'];

        return [
            'icono'         => $cat['icono'],
            'color'         => $color,
            'categoria'     => $cat['categoria'],
            'categoria_key' => $base,
            'operacion'     => $etiqueta,
        ];
    }

    // Nombre legible del actor: el nombre y apellido de la cuenta. Si la cuenta
    // ya no existe (la borraron), se degrada al arreglo del email sin guiones ni
    // puntos; y si el email tampoco se conoce, se dice que fue el sistema.
    private function actorName(?string $email, $userId, array $autoresMap): string
    {
        $nombre = trim((string) ($autoresMap[(int) $userId]['completo'] ?? ''));
        if ($nombre !== '') return $nombre;

        if (!$email || $email === 'desconocido') return 'Usuario del sistema';
        $parte = str_replace('@', ' ', explode('@', $email)[0]);
        $parte = trim(preg_replace('/[._-]+/', ' ', $parte) ?? $parte);
        return $parte !== '' ? ucwords($parte) : 'Usuario del sistema';
    }

    // Etiqueta de la entidad afectada. Se muestra como distintivo al lado del
    // título, que ya trae el nombre del alumno o del usuario: el número interno
    // ("Alumno #2451", "RolConfig #4") no le sirve a nadie y el nombre de la clase
    // tampoco, así que va solo el nombre en español.
    private function entidad(?string $type, $id): ?string
    {
        if (!$type) return null;
        $map = [
            'Alumno'                  => 'Alumno',
            'Profesor'                => 'Profesor',
            'User'                    => 'Cuenta',
            'Documento'               => 'Documento',
            'Curso'                   => 'Curso',
            'Materia'                 => 'Materia',
            'Clase'                   => 'Clase',
            'ParametroDivision'       => 'Divisiones',
            'RolConfig'               => 'Rol y permisos',
            'AsistenciaJustificacion' => 'Justificación de inasistencia',
            'ProfesorSuplencia'       => 'Suplencia',
            'VentanaNotaFinal'        => 'Ventana de notas',
            'AlumnoRecursando'        => 'Materias a recursar',
            'AlumnoIntensificacion'   => 'Intensificación',
            'ApiSesion'               => 'Sesión',
        ];
        return $map[$type] ?? 'Sistema';
    }

    /**
     * Compara dos valores de forma tolerante para evitar "falsos cambios" por
     * diferencias de formato (ej: dni int vs string, 0 vs false, "7.50" vs "7.5",
     * acentos o mayúsculas). Delega en el diff centralizado.
     */
    private function sameVal($o, $n): bool
    {
        return ActivityDiff::igual($o, $n);
    }

    // Genera un resumen humano de los cambios (diff old/new) o usa detalles/accion.
    private function resumen(ActivityLog $log, array $alumnos = []): array
    {
        $old = $log->old_values;
        $new = $log->new_values;

        if (is_string($old)) { try { $old = json_decode($old, true); } catch (\Throwable $e) { $old = null; } }
        if (is_string($new)) { try { $new = json_decode($new, true); } catch (\Throwable $e) { $new = null; } }

        // Caso divisiones: old es lista, new es mapa por anio.
        if (is_array($old) && array_is_list($old) && isset($old[0]['anio'])) {
            return [['texto' => 'Se reconfiguraron las divisiones por año.', 'tipo' => 'info']];
        }

        // Caso asignaciones de usuario: old/new.asignaciones = [{anio, division}]
        if (is_array($new) && isset($new['asignaciones']) && is_array($new['asignaciones'])) {
            $fmtCursos = function ($arr) {
                return array_map(function ($a) {
                    $anio = $a['anio'] ?? '';
                    $div = $a['division'] ?? '';
                    return trim("{$anio}° {$div}");
                }, is_array($arr) ? $arr : []);
            };

            $viejas = (is_array($old) && isset($old['asignaciones']) && is_array($old['asignaciones'])) ? $old['asignaciones'] : null;

            // Con estado previo conocido: muestra altas y bajas.
            if (is_array($viejas)) {
                $setOld = $fmtCursos($viejas);
                $setNew = $fmtCursos($new['asignaciones']);
                sort($setOld);
                sort($setNew);
                $lineas = [];
                $agregados = array_values(array_diff($setNew, $setOld));
                $quitados = array_values(array_diff($setOld, $setNew));
                if ($agregados) $lineas[] = ['texto' => 'Agregó: ' . implode(', ', $agregados), 'tipo' => 'add'];
                if ($quitados) $lineas[] = ['texto' => 'Quitó: ' . implode(', ', $quitados), 'tipo' => 'remove'];
                if (!$lineas) $lineas[] = ['texto' => 'Reordenó las asignaciones.', 'tipo' => 'info'];
                return $lineas;
            }

            // Sin estado previo (ej: creación): lista final.
            return [['texto' => 'Cursos asignados: ' . implode(', ', $fmtCursos($new['asignaciones'])), 'tipo' => 'info']];
        }

        // Caso materias.replace: claves '#id', 'Nueva: X', 'Eliminada: X'.
        if ($this->looksLikeMateriasDiff(is_array($old) ? $old : [], is_array($new) ? $new : [])) {
            return $this->resumenMaterias(is_array($old) ? $old : [], is_array($new) ? $new : []);
        }

        // Caso planilla: old/new son mapas por alumno, cada valor es un mapa de campos.
        if (is_array($old) && is_array($new) && !empty($old) && !empty($new)
            && $this->isGradesByStudent($old) && $this->isGradesByStudent($new)) {
            $lineas = $this->resumenPlanilla($old, $new);
            if ($lineas !== []) return $lineas;
        }

        // Caso objeto: diff campo por campo.
        if ((is_array($old) && !array_is_list($old)) || (is_array($new) && !array_is_list($new))) {
            $oldObj = is_array($old) && !array_is_list($old) ? $old : [];
            $newObj = is_array($new) && !array_is_list($new) ? $new : [];
            $cambios = [];
            $claves = array_unique(array_merge(array_keys($oldObj), array_keys($newObj)));

            // Alta (no había nada antes): las cinco líneas "se estableció X" de un
            // alumno recién creado se leen de una. El título ya dice qué se creó.
            if ($oldObj === []) {
                $datos = [];
                foreach ($claves as $k) {
                    if (in_array($k, ActivityDiff::CAMPOS_OCULTOS, true)) continue;
                    $n = $newObj[$k] ?? null;
                    if ($n === null || $n === '' || $n === []) continue;
                    $datos[] = $this->etiquetaCampo($k, $alumnos) . ': ' . $this->valorLegible((string) $k, $n);
                }
                if ($datos === []) return [];

                // Un alta concentra sus datos en una línea; un balance (promoción,
                // importación, copias) no es el alta de nada y se lee campo por campo.
                $esAlta = in_array($this->operacionDe($log->action_type), ['store', 'create', 'register'], true);
                if (!$esAlta) {
                    return array_map(
                        fn ($d) => ['texto' => $d, 'tipo' => 'info'],
                        $datos
                    );
                }
                return [['texto' => 'Se dio de alta con: ' . implode('; ', $datos), 'tipo' => 'info']];
            }

            foreach ($claves as $k) {
                if (in_array($k, ActivityDiff::CAMPOS_OCULTOS, true)) continue;
                $o = $oldObj[$k] ?? null;
                $n = $newObj[$k] ?? null;
                if (ActivityDiff::igual($o, $n)) continue;
                $label = $this->etiquetaCampo($k, $alumnos);
                $oText = $this->valorLegible($k, $o);
                $nText = $this->valorLegible($k, $n);
                if ($o === null || $o === '') {
                    $cambios[] = ['texto' => "{$label}: se estableció «{$nText}»", 'tipo' => 'add'];
                } elseif ($n === null || $n === '') {
                    $cambios[] = ['texto' => "{$label}: se quitó (era «{$oText}»)", 'tipo' => 'remove'];
                } else {
                    $cambios[] = ['texto' => "{$label}: «{$oText}» → «{$nText}»", 'tipo' => 'change'];
                }
            }
            if (count($cambios) > 0) return $cambios;
        }

        // Sin cambios detectados: detalles se muestra solo en el footer del frontend.
        return [];
    }

    /**
     * Detecta el formato de diff usado por materias.replace.
     */
    private function looksLikeMateriasDiff($old, $new): bool
    {
        return ActivityDiff::esMaterias($old, $new);
    }

    /**
     * Resumen legible para cambios de materias: altas, bajas y renombres/modificaciones.
     */
    private function resumenMaterias(array $old, array $new): array
    {
        $cambios = [];

        // Quitadas
        foreach ($old as $k => $v) {
            if (array_key_exists($k, $new)) continue;
            $nom = is_array($v) && isset($v['nombre']) ? $v['nombre'] : $k;
            $cambios[] = ['texto' => "Se quitó «{$nom}»", 'tipo' => 'remove'];
        }

        // Modificadas
        foreach ($old as $k => $v) {
            if (!isset($new[$k]) || !is_array($v) || !is_array($new[$k])) continue;
            $subCambios = [];
            $claves = array_unique(array_merge(array_keys($v), array_keys($new[$k])));
            foreach ($claves as $ck) {
                $o = $v[$ck] ?? null;
                $n = $new[$k][$ck] ?? null;
                if ($this->sameVal($o, $n)) continue;
                $label = ActivityDiff::etiqueta((string) $ck);
                $subCambios[] = "{$label}: «" . $this->scalarOrJson($o) . "» → «" . $this->scalarOrJson($n) . "»";
            }
            if (!empty($subCambios)) {
                $titulo = $new[$k]['nombre'] ?? ($v['nombre'] ?? $k);
                $cambios[] = ['texto' => $titulo . ':', 'tipo' => 'student'];
                foreach ($subCambios as $sc) {
                    $cambios[] = ['texto' => $sc, 'tipo' => 'change'];
                }
            }
        }

        // Agregadas
        foreach ($new as $k => $v) {
            if (array_key_exists($k, $old)) continue;
            $nom = is_array($v) && isset($v['nombre']) ? $v['nombre'] : $k;
            $cambios[] = ['texto' => "Se agregó «{$nom}»", 'tipo' => 'add'];
        }

        return $cambios;
    }

    /**
     * Detecta si un arreglo es un mapa por alumno: todas sus valores son mapas asociativos.
     */
    private function isGradesByStudent($arr): bool
    {
        return ActivityDiff::esPlanilla($arr);
    }

    /**
     * Convierte un valor a texto para el resumen. Las listas se leen separadas por
     comas y un mapa (las notas por materia) como "Lengua 4, Matemática 7": volcar
     el JSON en la tarjeta era uno de los peores ruidos del historial.
     */
    private function scalarOrJson($v): string
    {
        if (is_array($v)) return $this->listaLegible($v);
        if (is_object($v)) return $this->listaLegible((array) $v);
        if (is_bool($v)) return $v ? 'sí' : 'no';
        if ($v === null) return '';
        return (string) $v;
    }

    /** Lista o mapa de valores, leído en español y sin corchetes ni comillas. */
    private function listaLegible(array $arr): string
    {
        if ($arr === []) return '(vacío)';
        $partes = [];
        foreach ($arr as $k => $v) {
            $txt = is_array($v) || is_object($v)
                ? $this->listaLegible((array) $v)
                : $this->scalarOrJson($v);
            // Un mapa de notas (clave = materia) se lee "Lengua 4"; una lista
            // simple (["1ra","2da"]) se lee tal cual, sin repetir la clave.
            $partes[] = array_is_list($arr) || $txt === '' ? $txt : trim($k . ' ' . $txt);
        }
        $partes = array_values(array_filter($partes, fn ($p) => $p !== ''));
        return $partes === [] ? '(vacío)' : implode(', ', $partes);
    }

    /**
     * Valor legible para el resumen: traduce lo que el sistema guarda en clave
     * interna (roles, estados de cuenta) y delega el resto.
     */
    private function valorLegible(string $k, $v): string
    {
        if ($v === null) return '';
        if ($k === 'recursando' || $k === 'intensificacion') {
            return $this->relacionLista($v);
        }
        if ($k === 'status') {
            return is_array($v) ? $this->listaLegible($v) : $this->estadoCuenta($v);
        }
        if (in_array($k, ['role', 'rol', 'role_old', 'role_new', 'rol_solicitado'], true)) {
            if (is_array($v)) {
                $etiquetas = array_map(fn ($r) => $this->roleLabel((string) $r), $v);
                return $etiquetas === [] ? '(ninguno)' : implode(', ', $etiquetas);
            }
            $s = trim((string) $v);
            return $s === '' ? '' : $this->roleLabel($s);
        }
        return $this->scalarOrJson($v);
    }

    /** El motivo «sin motivo» que guarda el botón Omitir no es un motivo. */
    private function motivo(?string $detalles): ?string
    {
        $t = trim((string) $detalles);
        return ($t === '' || strcasecmp($t, 'sin motivo') === 0) ? null : $t;
    }

    /** Estado de una cuenta de usuario, en palabras. */
    private function estadoCuenta($v): string
    {
        $map = [
            'pending'  => 'Pendiente',
            'active'   => 'Aprobada',
            'approved' => 'Aprobada',
            'rejected' => 'Rechazada',
            'blocked'  => 'Bloqueada',
        ];
        $s = strtolower(trim((string) $v));
        if ($s === '') return '';
        return $map[$s] ?? ucfirst($s);
    }

    /**
     * Convierte una lista de {anio, division, materia_id, materia_nombre} en un
     * texto legible, p. ej. "3.º 5 · Matemática; 4.º 5 · Lengua".
     */
    /**
     * Ids y nombres de todas las materias en un solo mapa.
     *
     * El curriculo es acotado (pocas centenas de materias) y se resuelve una unica
     * vez por request: antes cada tarjeta hacia un find() por materia y armar una
     * pagina de 200 registros disparaba cientos de consultas.
     */
    private function nombresMaterias(): array
    {
        return $this->materiasPorId ??= Materia::query()->pluck('nombre', 'id')->all();
    }

    private function relacionLista($v): string
    {
        $items = is_array($v) ? $v : [];
        if (!$items) return '(vacío)';
        $nombres = [];
        foreach ($items as $it) {
            if (!is_array($it)) { $nombres[] = (string) $it; continue; }
            $mid = $it['materia_id'] ?? null;
            $nombre = trim((string)($it['materia_nombre'] ?? $it['materia'] ?? ''));
            if ($nombre === '' && $mid) {
                $nombre = trim((string) ($this->nombresMaterias()[(int) $mid] ?? ''));
            }
            if ($nombre === '') $nombre = $mid ? 'Materia #' . $mid : 'Materia';
            $curso = \App\Support\CourseLabel::label($it['anio'] ?? null, $it['division'] ?? null);
            $nombres[] = $curso ? "{$curso} · {$nombre}" : $nombre;
        }
        return implode('; ', $nombres);
    }

    /**
     * Genera el resumen de cambios de una planilla: agrupa los cambios por alumno.
     */
    private function resumenPlanilla($old, $new): array
    {
        $cambios = [];
        $alumnos = array_unique(array_merge(array_keys($old), array_keys($new)));
        foreach ($alumnos as $nombre) {
            $oldAl = $old[$nombre] ?? [];
            $newAl = $new[$nombre] ?? [];
            $claves = array_unique(array_merge(array_keys($oldAl), array_keys($newAl)));
            $cambiosAl = [];
            foreach ($claves as $k) {
                if (in_array($k, ActivityDiff::CAMPOS_OCULTOS, true)) continue;
                $o = $oldAl[$k] ?? null;
                $n = $newAl[$k] ?? null;
                if (ActivityDiff::igual($o, $n)) continue;
                $label = ActivityDiff::etiqueta((string) $k);
                $oText = $this->scalarOrJson($o);
                $nText = $this->scalarOrJson($n);
                if ($o === null || $o === '') {
                    $cambiosAl[] = ['texto' => "{$label}: se estableció «{$nText}»", 'tipo' => 'add'];
                } elseif ($n === null || $n === '') {
                    $cambiosAl[] = ['texto' => "{$label}: se quitó (era «{$oText}»)", 'tipo' => 'remove'];
                } else {
                    $cambiosAl[] = ['texto' => "{$label}: «{$oText}» → «{$nText}»", 'tipo' => 'change'];
                }
            }
            if (!empty($cambiosAl)) {
                $cambios[] = ['texto' => $nombre, 'tipo' => 'student'];
                foreach ($cambiosAl as $c) {
                    $cambios[] = $c;
                }
            }
        }
        return $cambios;
    }
}

