<?php

namespace App\Support;

use App\Dominios\Usuarios\RolConfig;

/**
 * Alcance de cada rol: hasta dónde llega a VER y a MODIFICAR, y qué módulos del
 * menú lateral se le muestran.
 *
 * El catálogo de Permisos responde "¿esta acción está habilitada?"; esta clase
 * responde "¿sobre qué datos?". Son dos filtros que se aplican en serie: primero
 * la acción (permiso) y después el alcance (todos los cursos, solo los
 * asignados, o ninguno). Un alcance nunca habilita una acción que el permiso
 * bloquea, solo la restringe.
 *
 * Los valores por defecto están tabulados por rol de sistema para que una
 * instalación sin filas en roles_config siga comportándose como siempre. Los
 * roles personalizados arrancan con el defecto de su rango y se ajustan desde
 * la pantalla "Roles y permisos".
 */
class Alcances
{
    public const TODO = 'todos';
    public const ASIGNADO = 'asignados';
    public const NADA = 'ninguno';

    /** De menos a más: sirve para comparar "ve" contra "modifica" al recortar el alcance. */
    private const JERARQUIA = [self::NADA => 0, self::ASIGNADO => 1, self::TODO => 2];

    /**
     * Áreas a las que se les puede acotar la lectura y la escritura.
     * `curso: true` significa que el valor "solo asignados" tiene sentido porque
     * el dato está atado a un año/división; en las demás solo hay Todo / Nada.
     */
    public const AREAS = [
        'alumnos' => [
            'nombre' => 'Alumnos',
            'curso'  => true,
            'ver'    => 'Ver el padrón y las fichas',
            'editar' => 'Altas, ediciones y bajas de alumnos',
        ],
        'cursos' => [
            'nombre' => 'Cursos, materias y horarios',
            'curso'  => true,
            'ver'    => 'Ver cursos, divisiones y grillas de horario',
            'editar' => 'Crear y modificar cursos, materias y horarios',
        ],
        'calificaciones' => [
            'nombre' => 'Calificaciones',
            'curso'  => true,
            'ver'    => 'Ver notas, promedios y planillas',
            'editar' => 'Cargar y corregir notas',
        ],
        'asistencia' => [
            'nombre' => 'Asistencia',
            'curso'  => true,
            'ver'    => 'Ver planillas, inasistencias y estadísticas',
            'editar' => 'Tomar y corregir asistencia',
        ],
        'profesores' => [
            'nombre' => 'Profesores',
            'curso'  => true,
            'ver'    => 'Ver fichas de profesores y suplencias',
            'editar' => 'Crear y editar fichas de profesores',
        ],
        'documentos' => [
            'nombre' => 'Documentos',
            'curso'  => false,
            'ver'    => 'Ver el archivo de documentos',
            'editar' => 'Subir, editar y eliminar documentos',
        ],
        'usuarios' => [
            'nombre' => 'Usuarios del sistema',
            'curso'  => true,
            'ver'    => 'Ver las cuentas y sus asignaciones',
            'editar' => 'Crear, editar y eliminar cuentas',
        ],
    ];

    /** Claves del menú lateral (coinciden con las ids que usa el Navbar). */
    public const MODULOS = [
        'dashboard'         => 'Inicio (panel)',
        'alumnos'           => 'Alumnos',
        'cursos'            => 'Cursos',
        'asistencia'        => 'Asistencia',
        'calificaciones'    => 'Calificaciones',
        'documentos'        => 'Documentos',
        'profesores'        => 'Profesores',
        'preceptores'       => 'Preceptores (cuentas docentes)',
        'jefe-preceptores'  => 'Jefe de Preceptores y Prosecretaría',
        'usuarios'          => 'Usuarios (administración)',
        'historial'         => 'Historial de actividad',
    ];

    /** Etiquetas de las tres opciones de alcance, para la pantalla. */
    public const ETIQUETAS = [
        self::TODO      => 'Todos',
        self::ASIGNADO  => 'Solo los asignados',
        self::NADA      => 'Nada',
    ];

    /** Rol -> área -> [ver, editar]. Es la foto de cómo funciona hoy el sistema. */
    private const DEFECTOS_ROL = [
        RoleHierarchy::ADMIN             => ['ver' => self::TODO, 'editar' => self::TODO],
        RoleHierarchy::DIRECTIVO         => ['ver' => self::TODO, 'editar' => self::TODO],
        RoleHierarchy::JEFE_PRECEPTORES  => ['ver' => self::TODO, 'editar' => self::TODO],
        RoleHierarchy::PROSECRETARIA     => ['ver' => self::TODO, 'editar' => self::TODO],
        // El preceptor ve todo el padrón pero solo modifica los cursos que tiene asignados.
        RoleHierarchy::PRECEPTOR         => [
            'ver' => self::TODO,
            'editar' => self::ASIGNADO,
            // Los documentos no cuelgan de un curso: el preceptor sube y edita los suyos.
            'excepciones' => ['documentos' => self::TODO],
        ],
        // El profesor trabaja dentro de sus cursos: no ve ni toca nada fuera de ellos.
        // La asistencia la ve (sus cursos) pero no la toma: el rango 1 nace con
        // 'editar' en 'ninguno' y dirección lo habilita desde Roles y permisos.
        RoleHierarchy::PROFESOR          => [
            'ver' => self::ASIGNADO,
            'editar' => self::NADA,
            'excepciones' => [
                'calificaciones' => self::ASIGNADO,
            ],
            'ver_excepciones' => ['documentos' => self::TODO],
        ],
        RoleHierarchy::SIN_ROL           => ['ver' => self::NADA, 'editar' => self::NADA],
    ];

    /** El mismo esquema, pero para un rol personalizado que solo tiene rango. */
    private const DEFECTOS_NIVEL = [
        5 => ['ver' => self::TODO, 'editar' => self::TODO],
        4 => ['ver' => self::TODO, 'editar' => self::TODO],
        3 => ['ver' => self::TODO, 'editar' => self::TODO],
        2 => ['ver' => self::TODO, 'editar' => self::ASIGNADO, 'excepciones' => ['documentos' => self::TODO]],
        // Rango 1 = lo mismo del profesor: ve sus cursos, no modifica nada salvo
        // las notas. La asistencia se ve pero no se toma (ver test_de_par).
        1 => ['ver' => self::ASIGNADO, 'editar' => self::NADA,
              'excepciones' => ['calificaciones' => self::ASIGNADO],
              'ver_excepciones' => ['documentos' => self::TODO]],
        0 => ['ver' => self::NADA, 'editar' => self::NADA],
    ];

    /** Módulos visibles por defecto según el rol de sistema. */
    private const MODULOS_ROL = [
        RoleHierarchy::ADMIN            => null, // null = todos
        RoleHierarchy::JEFE_PRECEPTORES => null,
        RoleHierarchy::PROSECRETARIA    => null,
        RoleHierarchy::DIRECTIVO        => ['dashboard', 'alumnos', 'cursos', 'asistencia', 'calificaciones',
                                            'documentos', 'profesores', 'preceptores', 'usuarios', 'historial'],
        RoleHierarchy::PRECEPTOR        => ['dashboard', 'alumnos', 'cursos', 'asistencia', 'calificaciones',
                                            'documentos', 'profesores', 'preceptores'],
        RoleHierarchy::PROFESOR         => ['dashboard', 'alumnos', 'cursos', 'asistencia', 'calificaciones',
                                            'documentos', 'profesores'],
        RoleHierarchy::SIN_ROL          => [],
    ];

    /** Mapa área => {ver, editar} para un rol sin configuración guardada. */
    public static function defecto(?string $rol): array
    {
        return self::armarDefecto(
            self::DEFECTOS_ROL[$rol] ?? self::DEFECTOS_NIVEL[RoleHierarchy::level($rol)] ?? self::DEFECTOS_NIVEL[1]
        );
    }

    /**
     * El plano de un rol personalizado que solo tiene rango. Está separado de
     * defecto() para poder comparar rango y rol de sistema en la guarda: un rol
     * creado "a partir de Profesor" tiene que quedar haciendo lo mismo que Profesor.
     */
    public static function defectoNivel(int $nivel): array
    {
        return self::armarDefecto(self::DEFECTOS_NIVEL[$nivel] ?? self::DEFECTOS_NIVEL[1]);
    }

    private static function armarDefecto(array $plan): array
    {
        $out = [];
        foreach (array_keys(self::AREAS) as $area) {
            $editar = $plan['excepciones'][$area] ?? $plan['editar'];
            $ver    = $plan['ver_excepciones'][$area] ?? $plan['ver'];
            // Donde no hay curso asignado, "solo asignados" no es una opción real.
            if (!self::AREAS[$area]['curso'] && $editar === self::ASIGNADO) $editar = self::NADA;
            if (!self::AREAS[$area]['curso'] && $ver === self::ASIGNADO) $ver = self::NADA;
            $out[$area] = ['ver' => $ver, 'editar' => $editar];
        }
        return $out;
    }

    /**
     * Alcance efectivo: el defecto del rol corregido por lo que se guardó en
     * roles_config. Acepta un mapa parcial (solo las áreas tocadas en la pantalla).
     */
    public static function de(?string $rol): array
    {
        $out = self::defecto($rol);
        $guardado = RolConfig::registry()[$rol]['alcance'] ?? [];
        foreach ($guardado as $area => $regla) {
            if (!isset(self::AREAS[$area])) continue;
            $ver    = $regla['ver'] ?? null;
            $editar = $regla['editar'] ?? null;
            if (in_array($ver, self::valores(), true)) $out[$area]['ver'] = $ver;
            if (in_array($editar, self::valores(), true)) $out[$area]['editar'] = $editar;
            $out[$area] = self::recortar($out[$area]);
        }
        return $out;
    }

    /**
     * "Ver" manda sobre "modificar": nadie puede editar filas que su propio alcance
     * no deja ver. Sin este recorte la pantalla permite guardar una combinación
     * (ve Asignados / modifica Todos) que el backend acepta pero nunca aplica.
     */
    public static function recortar(array $regla): array
    {
        $ver    = $regla['ver'] ?? self::NADA;
        $editar = $regla['editar'] ?? self::NADA;
        if ((self::JERARQUIA[$editar] ?? 0) > (self::JERARQUIA[$ver] ?? 0)) $regla['editar'] = $ver;
        return $regla;
    }

    /** Alcance de lectura de un área. */
    public static function ver(?string $rol, string $area): string
    {
        $mapa = self::de($rol);
        return $mapa[$area]['ver'] ?? self::NADA;
    }

    /** Alcance de escritura de un área. */
    public static function editar(?string $rol, string $area): string
    {
        $mapa = self::de($rol);
        return $mapa[$area]['editar'] ?? self::NADA;
    }

    /**
     * Módulos del menú visibles para el rol. Devuelve null cuando el rol no tiene
     * configuración guardada: la interfaz sigue usando el filtro histórico por
     * nombre de rol, así ninguna cuenta existente cambia de pantalla sin querer.
     */
    public static function modulos(?string $rol): ?array
    {
        $guardado = RolConfig::registry()[$rol]['modulos'] ?? null;
        if (is_array($guardado)) {
            return array_values(array_intersect(array_keys(self::MODULOS), $guardado));
        }
        return null;
    }

    /** Lista de módulos que corresponde a un rol sin configuración. */
    public static function modulosDefecto(?string $rol, ?int $nivel = null): array
    {
        $rol = (string) $rol;
        if (array_key_exists($rol, self::MODULOS_ROL)) {
            $lista = self::MODULOS_ROL[$rol];
            return $lista === null ? array_keys(self::MODULOS) : $lista;
        }
        // El rango viene pasado por quien está GUARDANDO el rol: si en el mismo
        // guardado sube de nivel, comparar contra el nivel viejo descartaría
        // módulos que el nuevo rango sí justifica.
        $nivel = $nivel ?? RoleHierarchy::level($rol);
        if ($nivel >= 3) return array_keys(self::MODULOS);
        if ($nivel === 2) return self::MODULOS_ROL[RoleHierarchy::PRECEPTOR];
        if ($nivel <= 0) return [];
        return self::MODULOS_ROL[RoleHierarchy::PROFESOR];
    }

    /** @return array<int,string> las tres opciones, o dos si el área no cuelga de un curso */
    public static function opciones(string $area): array
    {
        return self::AREAS[$area]['curso']
            ? [self::TODO, self::ASIGNADO, self::NADA]
            : [self::TODO, self::NADA];
    }

    /** Todas las palabras válidas de alcance, para validar lo que llega de la pantalla. */
    public static function valores(): array
    {
        return [self::TODO, self::ASIGNADO, self::NADA];
    }

    /** Catálogo de áreas listo para pintar la matriz (con sus opciones). */
    public static function catalogo(): array
    {
        $out = [];
        foreach (self::AREAS as $clave => $area) {
            $out[] = [
                'area'    => $clave,
                'nombre'  => $area['nombre'],
                'ver'     => $area['ver'],
                'editar'  => $area['editar'],
                'curso'   => $area['curso'],
                'opciones'=> self::opciones($clave),
            ];
        }
        return $out;
    }

    /**
     * Deja solo las reglas válidas y descarta lo que ya es el valor por defecto:
     * si el mapa guardado queda vacío, el rol vuelve a no pasar por el filtro.
     */
    public static function sanear(?array $input, ?string $rol, ?int $nivel = null): array
    {
        // Para un rol personalizado el plano base es el de su rango, y el guardado
        // que cambia el rango tiene que comparar contra el rango NUEVO: si no, la
        // excepción que se acaba de pedir se descarta por "ya era el defecto".
        $base = $nivel !== null && RoleHierarchy::nivelSistema($rol) <= 0
            ? self::defectoNivel($nivel)
            : self::defecto($rol);
        $out = [];
        foreach ((array) $input as $area => $regla) {
            if (!isset(self::AREAS[$area])) continue;
            $regla = (array) $regla;
            $valores = [];
            foreach (['ver', 'editar'] as $campo) {
                $v = $regla[$campo] ?? null;
                // Un mapa parcial heredó lo que no se tocó: sin esto, el campo
                // ausente se leería como "ninguno" y achataría al otro.
                $regla[$campo] = in_array($v, self::valores(), true) ? $v : $base[$area][$campo];
                // "Solo asignados" no existe en un área que no cuelga de un curso.
                if (!self::AREAS[$area]['curso'] && $regla[$campo] === self::ASIGNADO) $regla[$campo] = $base[$area][$campo];
            }
            // Se recorta antes de comparar: un "modifica Todos" sobre un "ve
            // Asignados" no se guarda; queda el par coherente que manda el backend.
            $regla = self::recortar($regla);
            foreach (['ver', 'editar'] as $campo) {
                if ($regla[$campo] === $base[$area][$campo]) continue;
                $valores[$campo] = $regla[$campo];
            }
            if ($valores) $out[$area] = $valores;
        }
        return $out;
    }

    /**
     * Igual que sanear() pero con los módulos: se guarda solo la lista cuando es
     * distinta de la que el rol muestra por defecto.
     */
    public static function sanearModulos(?array $input, ?string $rol, ?int $nivel = null): ?array
    {
        $validas = array_values(array_unique(array_intersect(array_keys(self::MODULOS), (array) $input)));
        sort($validas);
        $defecto = self::modulosDefecto($rol, $nivel);
        sort($defecto);
        return $validas === $defecto ? null : $validas;
    }
}
