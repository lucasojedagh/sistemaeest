<?php

namespace App\Dominios\Sistema;

use App\Http\Controllers\Controller;

use App\Dominios\Cursos\Materia;
use App\Dominios\Sistema\ActivityLog;
use App\Dominios\Usuarios\User;
use App\Support\ActivityDiff;
use App\Support\LikeSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

// Controlador que expone el historial de actividades del sistema (ActivityLog).
class HistorialController extends Controller
{
    // Tiempo de vida de la caché de historial (segundos).
    const CACHE_TTL = 300;

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
            $cacheKey = 'historial_index_' . $page . '_' . $perPage;
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

        $query = ActivityLog::recent();

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

        $perPage = \App\Support\Paginacion::porPagina($request, 50, 200);

        $result = $this->buildResult($query, $perPage);

        return $this->responder($result);
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

        // Resuelve el rol de los usuarios afectados (auditable_type = 'User') de forma
        // masiva para evitar una consulta por tarjeta.
        $userIds = $collection
            ->where('auditable_type', 'User')
            ->pluck('auditable_id')->filter()->unique()->values()->all();
        $rolesMap = $userIds ? User::whereIn('id', $userIds)->pluck('role', 'id')->all() : [];

        $result->setCollection($collection->transform(
            fn (ActivityLog $log) => $this->present($log, $rolesMap)
        ));
        return $result;
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

    // ========================= PRESENTACION =========================
    // Convierte un ActivityLog en un arreglo listo para la vista.
    private function present(ActivityLog $log, array $rolesMap = []): array
    {
        $meta = $this->meta($log->action_type);
        $actor = $this->actorName($log->user_email, $log->user_role);

        $entidad = $this->entidad($log->auditable_type, $log->auditable_id);
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
            'entidad'       => $entidad,
            'detalles'      => $log->detalles,
            'resumen'       => $this->resumen($log),
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

    // Devuelve icono, color y etiquetas segun el action_type (ej: "alumno.update").
    private function meta(string $actionType): array
    {
        $partes = explode('.', $actionType);
        $base = $partes[0] ?? 'general';
        // La operacion es el ULTIMO tramo: hay tipos de tres niveles
        // ("profesor.suplencia.store", "asistencia.justificacion.aprobada") y leer el
        // segundo dejaba esas tarjetas con la etiqueta generica "Operacion".
        $operacion = end($partes);

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
            'rol'            => ['categoria' => 'Roles',           'icono' => 'user'],
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
            'update'           => ['Edición', 'blue'],
            'edit'             => ['Edición', 'blue'],
            'replace'          => ['Reemplazo', 'blue'],
            'sync'             => ['Sincronización', 'blue'],
            'assignments'      => ['Asignaciones', 'blue'],
            'avatar'           => ['Foto de perfil', 'blue'],
            'clave_temporal'   => ['Clave temporal', 'amber'],
            'horario'          => ['Horario', 'blue'],
            'todas'            => ['Carga de materias', 'blue'],
            'oficiales'        => ['Materias oficiales', 'blue'],
            'destroy'          => ['Eliminación', 'red'],
            'delete'           => ['Eliminación', 'red'],
            'rechazar'         => ['Rechazo', 'red'],
            'rechazada'        => ['Rechazo', 'red'],
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
            'cerrar'           => ['Cierre', 'amber'],
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

    // Nombre legible del actor (si el email es desconocido, lo indica).
    private function actorName(?string $email, ?string $role): string
    {
        if (!$email || $email === 'desconocido') return 'Usuario del sistema';
        $nombre = str_replace('@', ' ', explode('@', $email)[0]);
        $nombre = ucwords(str_replace(['.', '_'], ' ', $nombre));
        return $nombre;
    }

    // Etiqueta de la entidad afectada (ej: "Alumno #4").
    private function entidad(?string $type, $id): ?string
    {
        if (!$type) return null;
        $map = [
            'Alumno' => 'Alumno',
            'Profesor' => 'Profesor',
            'User' => 'Usuario',
            'Documento' => 'Documento',
            'ParametroDivision' => 'Divisiones',
        ];
        $label = $map[$type] ?? $type;
        return $id ? "{$label} #{$id}" : $label;
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
    private function resumen(ActivityLog $log): array
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
            return $this->resumenPlanilla($old, $new);
        }

        // Caso objeto: diff campo por campo.
        if ((is_array($old) && !array_is_list($old)) || (is_array($new) && !array_is_list($new))) {
            $oldObj = is_array($old) && !array_is_list($old) ? $old : [];
            $newObj = is_array($new) && !array_is_list($new) ? $new : [];
            $cambios = [];
            $claves = array_unique(array_merge(array_keys($oldObj), array_keys($newObj)));
            foreach ($claves as $k) {
                if (in_array($k, ActivityDiff::CAMPOS_OCULTOS, true)) continue;
                $o = $oldObj[$k] ?? null;
                $n = $newObj[$k] ?? null;
                if (ActivityDiff::igual($o, $n)) continue;
                $label = ActivityDiff::etiqueta((string) $k);
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
     * Convierte un valor a texto para el resumen. Si es escalar lo devuelve tal cual;
     * si es array/objeto lo serializa en JSON para evitar "Array to string conversion".
     */
    private function scalarOrJson($v): string
    {
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_object($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_bool($v)) return $v ? 'sí' : 'no';
        if ($v === null) return '';
        return (string) $v;
    }

    /**
     * Valor legible para el resumen: para las relaciones de materias
     * (recursando/intensificacion) muestra una lista humana de "curso · materia";
     * para el resto delega en scalarOrJson().
     */
    private function valorLegible(string $k, $v): string
    {
        if ($k === 'recursando' || $k === 'intensificacion') {
            return $this->relacionLista($v);
        }
        return $this->scalarOrJson($v);
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

