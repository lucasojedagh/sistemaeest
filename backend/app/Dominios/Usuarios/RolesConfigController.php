<?php

namespace App\Dominios\Usuarios;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\Alcances;
use App\Support\Permisos;
use App\Support\RoleHierarchy;
use Illuminate\Http\Request;

// Controlador de la pestaña "Roles y permisos" del módulo Usuarios.
// Expone el catálogo de acciones del sistema, la lista de roles (los de siempre
// más los personalizados) y permite crear, editar y eliminar roles nuevos.
//
// Acceso: SOLO admin y jefe_preceptores. No se usa el middleware role: porque es
// jerárquico (role:admin,jefe_preceptores también admite a directivo y
// prosecretaria); el filtro por nombre exacto de rol va acá adentro.
class RolesConfigController extends Controller
{
    private const ROLES_PERMITIDOS = [RoleHierarchy::ADMIN, RoleHierarchy::JEFE_PRECEPTORES];

    // Hasta cuántas acciones del rol se escriben una por una en el historial; de
    // ahí, la tarjeta muestra el total y sólo nombra las que cambiaron.
    private const ACCIONES_LISTADAS = 8;

    /** Aborta si quien llama no es admin ni jefe de preceptores. */
    private function assertAcceso(Request $r): void
    {
        $rol = $r->user()->role ?? null;
        if (!in_array($rol, self::ROLES_PERMITIDOS, true)) {
            abort(403, 'Solo el administrador y el jefe de preceptores pueden gestionar roles.');
        }
    }

    /**
     * Qué rol puede modificar quien llama.
     *  - Roles del sistema: rige la misma jerarquía que para las cuentas
     *    (canManage), así un jefe no le quita permisos al admin ni a otro jefe.
     *  - Roles personalizados: los crea el rango, así que se editan hasta el
     *    propio nivel (si no, el jefe no podría ajustar los que él mismo creó).
     */
    private function puedeEditarRol(Request $r, string $rolKey): bool
    {
        $actor = $r->user()->role ?? null;
        if (RoleHierarchy::nivelSistema($rolKey) > 0) {
            return RoleHierarchy::canManage($actor, $rolKey);
        }
        return RoleHierarchy::level($actor) >= RoleHierarchy::level($rolKey);
    }

    /** Aborta si el rango de quien llama no llega a ese rol. */
    private function assertPuedeEditarRol(Request $r, string $rolKey): void
    {
        if (!$this->puedeEditarRol($r, $rolKey)) {
            $nombre = RoleHierarchy::nombre($rolKey);
            abort(403, "Tu rango no alcanza para modificar el rol {$nombre}.");
        }
    }

    /** Catálogo completo + estado de todos los roles. Para pintar la pestaña. */
    public function index(Request $r)
    {
        $this->assertAcceso($r);

        $registros = RolConfig::registry();
        $usados = User::query()
            ->selectRaw('role, COUNT(*) AS cantidad')
            ->groupBy('role')
            ->pluck('cantidad', 'role')
            ->all();

        $roles = [];

        // Roles de sistema: nacen con la lista de su rango, pero una vez guardada
        // la propia, esa lista es la que manda (se editan igual que un personalizado).
        foreach (RoleHierarchy::allSistema() as $rol => $nivel) {
            $fila = $registros[$rol] ?? null;
            $negados = $fila['negados'] ?? [];
            $roles[] = [
                'rol'         => $rol,
                'nombre'      => RoleHierarchy::nombre($rol),
                'nivel'       => $nivel,
                'tipo'        => 'sistema',
                'descripcion' => $fila['descripcion'] ?? null,
                'permitidos'  => Permisos::base($rol),
                'base_rango'  => Permisos::base($rol),
                'negados'     => $negados,
                'efectivos'   => Permisos::efectivos($rol),
                'a_medida'    => $fila !== null,
                'alcance'     => Alcances::de($rol),
                'modulos'     => Alcances::modulos($rol),
                'modulos_defecto' => Alcances::modulosDefecto($rol),
                'usuarios'    => (int) ($usados[$rol] ?? 0),
                'editable'    => $this->puedeEditarRol($r, $rol),
            ];
        }

        // Roles personalizados.
        foreach ($registros as $rol => $fila) {
            if ($fila['tipo'] !== 'personalizado') continue;
            $roles[] = [
                'rol'         => $rol,
                'nombre'      => $fila['nombre'],
                'nivel'       => $fila['nivel'],
                'tipo'        => 'personalizado',
                'descripcion' => $fila['descripcion'] ?? null,
                'permitidos'  => $fila['permitidos'],
                'base_rango'  => Permisos::base($rol),
                'negados'     => $fila['negados'],
                'efectivos'   => Permisos::efectivos($rol),
                'a_medida'    => true,
                'alcance'     => Alcances::de($rol),
                'modulos'     => Alcances::modulos($rol),
                'modulos_defecto' => Alcances::modulosDefecto($rol),
                'usuarios'    => (int) ($usados[$rol] ?? 0),
                'editable'    => $this->puedeEditarRol($r, $rol),
            ];
        }

        // Cada bloque de la pantalla es un módulo: sus acciones, el área de datos
        // que lo acota y las entradas del menú que lo muestran u ocultan.
        $areas = [];
        foreach (Alcances::catalogo() as $a) {
            $areas[$a['area']] = $a;
        }
        $bloques = [];
        foreach (Permisos::bloques() as $b) {
            $area = $b['area'] === null ? null : ($areas[$b['area']] ?? null);
            // array_merge y no el operador +: el bloque ya trae sus claves y el
            // operador + conservaría siempre las de la izquierda.
            $bloques[] = array_merge($b, [
                'area_nombre'  => $area['nombre'] ?? null,
                'area_ver'     => $area['ver'] ?? null,
                'area_editar'  => $area['editar'] ?? null,
                'opciones'     => $area['opciones'] ?? [],
            ]);
        }

        return [
            'bloques'       => $bloques,
            'etiquetas_alcance' => Alcances::ETIQUETAS,
            'piso_admin'    => Permisos::PISO_ADMIN,
            // Las acciones de respaldos solo viven en el rol Admin: la pantalla
            // esconde estas casillas en cualquier otro rol para no ofrecer lo que
            // el backend nunca va a aceptar.
            'solo_admin'    => Permisos::SOLO_ADMIN,
            'roles'         => $roles,
            'plantillas'    => Permisos::plantillas(),
            'mi_nivel'      => RoleHierarchy::level($r->user()->role),
            'total_usuarios' => User::count(),
        ];
    }

    /** Mapa rol -> {nombre, nivel, tipo}. Lo usa el front para etiquetar roles personalizados. */
    public function nombres()
    {
        $out = [];
        foreach (RoleHierarchy::allSistema() as $rol => $nivel) {
            $out[$rol] = ['nombre' => RoleHierarchy::nombre($rol), 'nivel' => $nivel, 'tipo' => 'sistema'];
        }
        foreach (RolConfig::registry() as $rol => $fila) {
            if ($fila['tipo'] !== 'personalizado') continue;
            $out[$rol] = ['nombre' => $fila['nombre'], 'nivel' => $fila['nivel'], 'tipo' => 'personalizado'];
        }
        return $out;
    }

    /** Crear un rol personalizado, vacío o a partir de una plantilla. */
    public function store(Request $r)
    {
        $this->assertAcceso($r);

        $data = $r->validate([
            'nombre'      => ['required', 'string', 'max:120'],
            'nivel'       => ['required', 'integer', 'between:1,5'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'plantilla'   => ['nullable', 'string'],
            'desde_rol'   => ['nullable', 'string'],
            'permitidos'  => ['nullable', 'array'],
            'permitidos.*'=> ['string'],
            'alcance'     => ['nullable', 'array'],
            'modulos'     => ['nullable', 'array'],
            'modulos.*'   => ['string'],
        ], [], ['nombre' => 'nombre del rol']);

        $actor = $r->user()->role;
        $nivelMax = RoleHierarchy::level($actor);
        if ($data['nivel'] > $nivelMax) {
            return response()->json(['message' => "No podés crear un rol con un rango superior al tuyo (máximo {$nivelMax})."], 403);
        }

        $rol = $this->slug($data['nombre']);
        if ($rol === '') {
            return response()->json(['message' => 'El nombre del rol tiene que incluir letras.'], 422);
        }
        if (RolConfig::where('rol', $rol)->exists() || RoleHierarchy::nivelSistema($rol) > 0) {
            return response()->json(['message' => 'Ya existe un rol con ese nombre.'], 422);
        }

        if (!empty($data['plantilla'])) {
            $plantilla = $this->plantilla($data['plantilla']);
            if (!$plantilla) {
                return response()->json(['message' => 'Plantilla desconocida.'], 422);
            }
            $permitidos  = $plantilla['permitidos'];
            $descripcion = $data['descripcion'] ?? $plantilla['descripcion'];
        } elseif (!empty($data['desde_rol'])) {
            // Copiar la configuración de un rol existente (permisos, alcance y módulos).
            $fuente = (string) $data['desde_rol'];
            if (RoleHierarchy::nivelSistema($fuente) <= 0 && !isset(RolConfig::registry()[$fuente])) {
                return response()->json(['message' => 'El rol que querés copiar no existe.'], 422);
            }
            $permitidos  = Permisos::efectivos($fuente) ?? Permisos::base($fuente);
            $descripcion = $data['descripcion'] ?? null;
        } else {
            $permitidos  = Permisos::sanear($data['permitidos'] ?? []);
            $descripcion = $data['descripcion'] ?? null;
        }

        $permitidos = Permisos::normalizarLista($rol, $permitidos, $nivelMax);

        // Alcance y módulos del rol nuevo: si no se copiaron de un rol existente se
        // toman del pedido; la fuente copiada ya trae su propio mapa guardado.
        $alcanceInput = $data['alcance'] ?? null;
        $modulosInput = array_key_exists('modulos', $data) ? $data['modulos'] : null;
        if (!$alcanceInput && !empty($data['desde_rol'])) {
            // Se copia el mapa EFECTIVO del rol fuente, no su fila guardada: un rol
            // de sistema sin fila también tiene alcance y módulos, y copiar la fila
            // vacía (o inexistente) dejaba al rol nuevo con otra configuración.
            $origen       = (string) $data['desde_rol'];
            $alcanceInput = Alcances::de($origen);
            $modulosInput = Alcances::modulos($origen) ?? Alcances::modulosDefecto($origen);
        }

        $fila = RolConfig::create([
            'rol'         => $rol,
            'nombre'      => $data['nombre'],
            'nivel'       => $data['nivel'],
            'tipo'        => 'personalizado',
            'permitidos'  => $permitidos,
            'negados'     => [],
            'alcance'     => [],
            'modulos'     => null,
            'descripcion' => $descripcion,
        ]);
        RolConfig::olvidarRegistro();

        // El saneamiento compara contra el defecto del nivel del rol, así que se hace
        // con la fila ya creada; si no, un 'ninguno' copiado se descartaría por error.
        $fila->update([
            'alcance' => is_array($alcanceInput) ? Alcances::sanear($alcanceInput, $rol) : [],
            'modulos' => is_array($modulosInput) ? Alcances::sanearModulos($modulosInput, $rol) : null,
        ]);
        RolConfig::olvidarRegistro();

        ActivityLogger::log(
            $r,
            'Creó el rol ' . $fila->nombre,
            'role.store',
            'RolConfig', $fila->id,
            null,
            $this->estadoRol([
                'Rango'    => $fila->nivel,
                'Acciones' => (array) ($fila->permitidos ?? []),
                'Alcance'  => array_keys((array) ($fila->alcance ?? [])),
                'Módulos'  => (array) ($fila->modulos ?? []),
            ])
        );

        return response()->json($this->show($fila), 201);
    }

    /**
     * Editar un rol. Un rol del sistema y uno personalizado se tratan igual: la
     * pantalla manda la lista completa de acciones y esa lista es la que queda,
     * sin tope de rango. El rango sigue sirviendo para los permisos con los que
     * nace el rol, para el alcance por defecto y para quién puede editar a quién.
     */
    public function update(Request $r, string $rolKey)
    {
        $this->assertAcceso($r);

        $esSistema = RoleHierarchy::nivelSistema($rolKey) > 0;
        $fila = RolConfig::where('rol', $rolKey)->first();

        if (!$esSistema && !$fila) {
            return response()->json(['message' => 'Ese rol no existe.'], 404);
        }
        if (!$esSistema && $fila->tipo !== 'personalizado') {
            return response()->json(['message' => 'Ese rol no se puede editar.'], 422);
        }

        $data = $r->validate([
            'nombre'      => ['nullable', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'nivel'       => ['nullable', 'integer', 'between:1,5'],
            'permitidos'  => ['nullable', 'array'],
            'permitidos.*'=> ['string'],
            'negados'     => ['nullable', 'array'],
            'negados.*'   => ['string'],
            'alcance'     => ['nullable', 'array'],
            'modulos'     => ['nullable', 'array'],
            'modulos.*'   => ['string'],
        ]);

        $actor = $r->user()->role;
        $nivelMax = RoleHierarchy::level($actor);

        $this->assertPuedeEditarRol($r, $rolKey);

        if ($esSistema) {
            $nivel = RoleHierarchy::nivelSistema($rolKey);
        } elseif (array_key_exists('nivel', $data) && $data['nivel'] !== null) {
            if ((int) $data['nivel'] > $nivelMax) {
                return response()->json(['message' => "No podés subir el rol a un rango superior al tuyo (máximo {$nivelMax})."], 403);
            }
            $nivel = (int) $data['nivel'];
        } else {
            $nivel = $fila->nivel;
        }

        if (array_key_exists('permitidos', $data)) {
            // La lista manda: es todo lo que el rol puede hacer, venga del rango que venga.
            $permitidos = Permisos::normalizarLista($rolKey, (array) $data['permitidos'], $nivelMax);
            $negados    = [];
        } else {
            // Pedido sin lista (solo alcance, módulos o nombre): no se tocan las acciones.
            $permitidos = (array) ($fila->permitidos ?? []);
            $negados    = Permisos::sanear($data['negados'] ?? $fila->negados ?? []);
        }

        $descripcion = $data['descripcion'] ?? ($fila->descripcion ?? null);

        // Las acciones que el rol puede hacer de verdad, no las que están escritas
        // en la columna. Una fila de sistema con la lista vacía no es un rol sin
        // permisos: Permisos::efectivos() le devuelve los de su rango. Comparar
        // contra la columna escribía en el historial «Acciones: (ninguna)» al
        // editar solo el alcance de un rol que aún no tenía fila, o al tocar un
        // rol que tenía guardadas únicamente sus quitas.
        $efectivas = $this->accionesEfectivas($esSistema, $rolKey, $permitidos, $negados);

        // Alcance y módulos: se guarda solo lo que se desvía del defecto del rol,
        // así un rol que vuelve a su configuración de fábrica deja de pasar por el filtro.
        $alcance = array_key_exists('alcance', $data) && is_array($data['alcance'])
            ? Alcances::sanear($data['alcance'], $rolKey, $nivel)
            : (array) ($fila->alcance ?? []);
        $modulos = array_key_exists('modulos', $data)
            ? Alcances::sanearModulos($data['modulos'], $rolKey, $nivel)
            : ($fila->modulos ?? null);

        // Si un rol del sistema vuelve a tener exactamente la lista de su rango y
        // nada más tocado, se borra la fila: así herede solo lo del rango y los
        // permisos que se agreguen al catálogo en el futuro.
        if ($esSistema && $negados === [] && $alcance === [] && $modulos === null
            && !isset($data['nombre']) && !isset($data['descripcion'])
            && $this->mismaLista($efectivas, Permisos::base($rolKey))) {
            if ($fila) {
                $fila->delete();
                RolConfig::olvidarRegistro();
                ActivityLogger::log($r, 'Restauró los permisos del rol ' . RoleHierarchy::nombre($rolKey), 'role.restore', 'RolConfig', $fila->id);
            }
            return $this->show(RolConfig::where('rol', $rolKey)->first() ?: null, $rolKey);
        }

        // Un rol del sistema sin fila tampoco estaba "sin nada": funcionaba con lo
        // que dicta su rango. Ese es su antes, y comparar ahí deja ver la acción
        // que se sumó en vez de enumerar las 24 con las que el rol ya nació.
        $teniaFila = $fila !== null;
        $recien    = !$teniaFila && $esSistema;
        $fila = $fila ?: new RolConfig(['rol' => $rolKey]);

        // Foto de lo que había antes de tocar nada, para que el historial muestre
        // el cambio real y no un inventario del estado final.
        $antes = $teniaFila
            ? [
                'Nombre'   => $fila->nombre,
                'Rango'    => $fila->nivel,
                'Acciones' => $this->accionesEfectivas(
                    $esSistema, $rolKey,
                    (array) ($fila->permitidos ?? []),
                    (array) ($fila->negados ?? [])
                ),
                'Alcance'  => array_keys((array) ($fila->alcance ?? [])),
                'Módulos'  => array_values((array) ($fila->modulos ?? [])),
            ]
            : [
                'Nombre'   => $recien ? RoleHierarchy::nombreBase($rolKey) : null,
                'Rango'    => $recien ? RoleHierarchy::nivelSistema($rolKey) : null,
                'Acciones' => $recien ? array_values(Permisos::base($rolKey)) : [],
                'Alcance'  => [],
                'Módulos'  => [],
            ];

        $fila->fill([
            'rol'         => $rolKey,
            'nombre'      => $data['nombre'] ?? ($esSistema ? RoleHierarchy::nombreBase($rolKey) : $fila->nombre),
            'nivel'       => $nivel,
            'tipo'        => $esSistema ? 'sistema' : 'personalizado',
            'permitidos'  => $permitidos,
            'negados'     => $negados,
            'alcance'     => $alcance,
            'modulos'     => $modulos,
            'descripcion' => $descripcion,
        ]);
        $fila->save();
        RolConfig::olvidarRegistro();

        $despues = [
            'Nombre'   => $fila->nombre,
            'Rango'    => $fila->nivel,
            'Acciones' => $efectivas,
            'Alcance'  => array_keys($alcance),
            'Módulos'  => array_values((array) ($modulos ?? [])),
        ];

        [$cambiaAntes, $cambiaDespues] = $this->historialRol($antes, $despues);

        ActivityLogger::log(
            $r,
            'Actualizó el rol ' . $fila->nombre,
            'role.update',
            'RolConfig', $fila->id,
            $cambiaAntes,
            $cambiaDespues
        );

        return $this->show($fila, $rolKey);
    }

    /** Eliminar un rol personalizado. No se borra si hay cuentas que lo usen. */
    public function destroy(Request $r, string $rolKey)
    {
        $this->assertAcceso($r);

        if (RoleHierarchy::nivelSistema($rolKey) > 0) {
            return response()->json(['message' => 'Los roles del sistema no se pueden eliminar, solo se editan sus permisos.'], 422);
        }

        $fila = RolConfig::where('rol', $rolKey)->first();
        if (!$fila || $fila->tipo !== 'personalizado') {
            return response()->json(['message' => 'Ese rol no existe.'], 404);
        }

        $this->assertPuedeEditarRol($r, $rolKey);

        $enUso = User::where('role', $rolKey)->count();
        if ($enUso > 0) {
            return response()->json(['message' => "Hay {$enUso} cuenta(s) con ese rol. Cambiales el rol antes de eliminarlo."], 422);
        }

        $borrado = $this->estadoRol([
            'Rango'    => $fila->nivel,
            'Acciones' => (array) ($fila->permitidos ?? []),
            'Alcance'  => array_keys((array) ($fila->alcance ?? [])),
            'Módulos'  => (array) ($fila->modulos ?? []),
        ]);

        $fila->delete();
        RolConfig::olvidarRegistro();

        ActivityLogger::log($r, 'Eliminó el rol ' . $fila->nombre, 'role.destroy', 'RolConfig', $fila->id, $borrado);

        return ['ok' => true, 'rol' => $rolKey];
    }

    private function show(?RolConfig $fila, ?string $rolKey = null)
    {
        if (!$fila) {
            return ['ok' => true, 'rol' => $rolKey, 'negados' => [], 'alcance' => [], 'modulos' => null];
        }
        return [
            'rol'        => $fila->rol,
            'nombre'     => $fila->nombre,
            'nivel'      => $fila->nivel,
            'tipo'       => $fila->tipo,
            'descripcion'=> $fila->descripcion,
            'permitidos' => $fila->permitidos ?? [],
            'negados'    => $fila->negados ?? [],
            'alcance'    => $fila->alcance ?? [],
            'modulos'    => $fila->modulos ?? null,
        ];
    }

    /** Datos de una plantilla, o null si no existe. */
    private function plantilla(string $id): ?array
    {
        foreach (Permisos::plantillas() as $p) {
            if ($p['id'] === $id) return $p;
        }
        return null;
    }

    /** true si las dos listas tienen las mismas claves, sin importar el orden. */
    private function mismaLista(array $a, array $b): bool
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        sort($a);
        sort($b);
        return $a === $b;
    }

    /**
     * Acciones que el rol puede hacer con estos datos, igual que Permisos::efectivos():
     * un rol del sistema con la lista guardada vacía sigue teniendo las de su rango.
     */
    private function accionesEfectivas(bool $esSistema, string $rolKey, array $permitidos, array $negados): array
    {
        $lista = $permitidos !== [] ? $permitidos : ($esSistema ? Permisos::base($rolKey) : []);
        return array_values(array_diff($lista, $negados));
    }

    /**
     * Las dos fotos del cambio de rol, escritas como se leen en el historial.
     *
     * Con pocas acciones se listan todas; con muchas, la tarjeta dice cuántas son
     * y sólo nombra las que entraron y las que salieron, porque 50 etiquetas
     * seguidas no se pueden leer.
     */
    private function historialRol(array $antes, array $despues): array
    {
        $n = $this->etiquetasAcciones((array) $antes['Acciones']);
        $d = $this->etiquetasAcciones((array) $despues['Acciones']);

        $entraron = array_values(array_diff($d, $n));
        $salieron = array_values(array_diff($n, $d));

        $a = $this->estadoRol($antes);
        $b = $this->estadoRol($despues, $entraron, $salieron);

        // El nombre del rol ya está en el título de la tarjeta; sólo vale la pena
        // mostrarlo cuando el rol cambió de nombre.
        if (($antes['Nombre'] ?? null) !== ($despues['Nombre'] ?? null)) {
            $a = ['Nombre' => $antes['Nombre'] ?? null] + $a;
            $b = ['Nombre' => $despues['Nombre'] ?? null] + $b;
        }

        return [$a, $b];
    }

    /**
     * El estado de un rol con sus claves traducidas al nombre que muestra la
     * pantalla: acciones del catálogo, áreas de alcance y módulos del menú.
     * El nombre del rol no va: siempre está en el título de la tarjeta.
     */
    private function estadoRol(array $datos, array $entraron = [], array $salieron = []): array
    {
        static $areas = null;
        if ($areas === null) {
            $areas = [];
            foreach (Alcances::catalogo() as $a) {
                $areas[$a['area']] = $a['nombre'];
            }
        }

        $acciones = $this->etiquetasAcciones((array) ($datos['Acciones'] ?? []));
        $rango    = $datos['Rango'] ?? null;

        return [
            'Rango'    => ($rango === null || $rango === '') ? $rango : RoleHierarchy::nivelLabel($rango),
            'Acciones' => $this->textoAcciones($acciones, $entraron, $salieron),
            'Alcance'  => array_values(array_map(fn ($a) => $areas[$a] ?? $a, (array) ($datos['Alcance'] ?? []))),
            'Módulos'  => array_values(array_map(fn ($m) => Alcances::MODULOS[$m] ?? $m, (array) ($datos['Módulos'] ?? []))),
        ];
    }

    /** Claves de acciones -> etiquetas del catálogo de permisos. */
    private function etiquetasAcciones(array $claves): array
    {
        static $etiquetas = null;
        if ($etiquetas === null) {
            $etiquetas = [];
            foreach (Permisos::catalogo() as $grupo) {
                foreach ($grupo['items'] as $item) {
                    $etiquetas[$item['clave']] = $item['label'];
                }
            }
        }
        return array_values(array_map(fn ($c) => $etiquetas[$c] ?? $c, $claves));
    }

    /** La lista de acciones de un rol, escrita en una línea legible. */
    private function textoAcciones(array $lista, array $entraron, array $salieron): string
    {
        if (count($lista) <= self::ACCIONES_LISTADAS) {
            return $lista === [] ? '(ninguna)' : implode(', ', $lista);
        }
        $movidas = array_merge(
            array_map(fn ($e) => "entró {$e}", $entraron),
            array_map(fn ($e) => "salió {$e}", $salieron)
        );
        return count($lista) . ' acciones' . ($movidas === [] ? '' : ' (' . implode(', ', $movidas) . ')');
    }

    /** "Asistente Social" -> "asistente_social" (clave del rol, sin acentos). */
    private function slug(string $nombre): string
    {
        $s = strtolower(trim($nombre));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim((string) $s, '_');
        return substr($s, 0, 60);
    }
}
