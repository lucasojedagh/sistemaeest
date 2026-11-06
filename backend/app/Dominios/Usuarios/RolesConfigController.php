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

        // Roles de sistema: su base es el nivel; la fila (si existe) aporta las quitas.
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
                'negados'     => $negados,
                'efectivos'   => Permisos::efectivos($rol),
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
                'negados'     => $fila['negados'],
                'efectivos'   => Permisos::efectivos($rol),
                'alcance'     => Alcances::de($rol),
                'modulos'     => Alcances::modulos($rol),
                'modulos_defecto' => Alcances::modulosDefecto($rol),
                'usuarios'    => (int) ($usados[$rol] ?? 0),
                'editable'    => $this->puedeEditarRol($r, $rol),
            ];
        }

        return [
            'grupos'      => Permisos::catalogo(),
            'areas'       => Alcances::catalogo(),
            'modulos'     => Alcances::MODULOS,
            'etiquetas_alcance' => Alcances::ETIQUETAS,
            'roles'       => $roles,
            'plantillas'  => Permisos::plantillas(),
            'mi_nivel'    => RoleHierarchy::level($r->user()->role),
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

        $permitidos = $this->recortarPorNivel($permitidos, $nivelMax);

        // Alcance y módulos del rol nuevo: si no se copiaron de un rol existente se
        // toman del pedido; la fuente copiada ya trae su propio mapa guardado.
        $alcanceInput = $data['alcance'] ?? null;
        $modulosInput = array_key_exists('modulos', $data) ? $data['modulos'] : null;
        if (!$alcanceInput && !empty($data['desde_rol'])) {
            $fuente = RolConfig::registry()[(string) $data['desde_rol']] ?? null;
            $alcanceInput = $fuente['alcance'] ?? null;
            $modulosInput = $fuente['modulos'] ?? null;
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

        ActivityLogger::log($r, 'Creó el rol ' . $fila->nombre . " ({$rol})", 'role.store', 'RolConfig', $fila->id, null, ['rol' => $rol, 'nivel' => $fila->nivel, 'permitidos' => count($permitidos)]);

        return response()->json($this->show($fila), 201);
    }

    /**
     * Editar un rol.
     *  - Rol de sistema: solo se le QUIEREN permisos (negados) y se le cambia el nombre visible.
     *  - Rol personalizado: nivel, nombre y lista completa de permisos.
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
            $negados     = Permisos::sanear($data['negados'] ?? []);
            $nivel       = RoleHierarchy::nivelSistema($rolKey);
            $permitidos  = (array) ($fila->permitidos ?? []);
            $descripcion = $fila->descripcion ?? null;
        } else {
            if (array_key_exists('nivel', $data) && $data['nivel'] !== null) {
                if ((int) $data['nivel'] > $nivelMax) {
                    return response()->json(['message' => "No podés subir el rol a un rango superior al tuyo (máximo {$nivelMax})."], 403);
                }
                $nivel = (int) $data['nivel'];
            } else {
                $nivel = $fila->nivel;
            }
            $permitidos = $this->recortarPorNivel(Permisos::sanear($data['permitidos'] ?? $fila->permitidos ?? []), $nivelMax);
            $negados = Permisos::sanear($data['negados'] ?? $fila->negados ?? []);
            $descripcion = $data['descripcion'] ?? $fila->descripcion;
        }

        // Alcance y módulos: se guarda solo lo que se desvía del defecto del rol,
        // así un rol que vuelve a su configuración de fábrica deja de pasar por el filtro.
        $alcance = array_key_exists('alcance', $data) && is_array($data['alcance'])
            ? Alcances::sanear($data['alcance'], $rolKey)
            : (array) ($fila->alcance ?? []);
        $modulos = array_key_exists('modulos', $data)
            ? Alcances::sanearModulos($data['modulos'], $rolKey)
            : ($fila->modulos ?? null);

        // Si vuelve a quedar idéntico a un rol de sistema sin quitas, se borra la fila:
        // así el rol otra vez no pasa por el filtro de permisos.
        if ($esSistema && $negados === [] && $permitidos === [] && $alcance === [] && $modulos === null
            && !isset($data['nombre']) && !isset($data['descripcion'])) {
            if ($fila) {
                $fila->delete();
                RolConfig::olvidarRegistro();
                ActivityLogger::log($r, "Restauró los permisos del rol {$rolKey}", 'role.restore', 'RolConfig', $fila->id, null, ['rol' => $rolKey]);
            }
            return $this->show(RolConfig::where('rol', $rolKey)->first() ?: null, $rolKey);
        }

        $fila = $fila ?: new RolConfig(['rol' => $rolKey]);
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

        ActivityLogger::log($r, 'Actualizó el rol ' . $fila->nombre . " ({$rolKey})", 'role.update', 'RolConfig', $fila->id, null, ['rol' => $rolKey, 'nivel' => $nivel, 'negados' => count($negados), 'alcance' => array_keys($alcance)]);

        return $this->show($fila, $rolKey);
    }

    /** Eliminar un rol personalizado. No se borra si hay cuentas que lo usen. */
    public function destroy(Request $r, string $rolKey)
    {
        $this->assertAcceso($r);

        if (RoleHierarchy::nivelSistema($rolKey) > 0) {
            return response()->json(['message' => 'Los roles del sistema no se pueden eliminar, solo quitarles permisos.'], 422);
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

        $fila->delete();
        RolConfig::olvidarRegistro();

        ActivityLogger::log($r, 'Eliminó el rol ' . $fila->nombre . " ({$rolKey})", 'role.destroy', 'RolConfig', $fila->id, ['rol' => $rolKey], null);

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

    /**
     * Lista que puede tener un rol personalizado: solo permisos que el actor pueda
     * otorgar y sin los que son exclusivos de los roles de sistema (gestión de roles).
     */
    private function recortarPorNivel(array $claves, int $nivelMax): array
    {
        $exclusivos = array_flip(Permisos::SOLO_SISTEMA);
        $out = [];
        foreach (Permisos::sanear($claves) as $c) {
            if (isset($exclusivos[$c])) continue;
            if (Permisos::nivelBase($c) <= $nivelMax) $out[] = $c;
        }
        return $out;
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
