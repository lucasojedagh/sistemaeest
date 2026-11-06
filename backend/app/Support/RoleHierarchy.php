<?php

namespace App\Support;

use App\Dominios\Usuarios\RolConfig;

/**
 * Jerarquia de roles del sistema.
 *
 * Niveles (mayor = mas privilegios):
 *   admin            = 5  control total
 *   directivo        = 4  control total
 *   jefe_preceptores = 3  casi todo, no puede tocar a admin/directivo
 *   prosecretaria    = 3  mismos permisos operativos que el jefe, pero un rango abajo:
 *                         no crea ni edita cuentas de prosecretaria ni de jefe
 *   preceptor        = 2  lectura global; escritura en su ambito; edita datos de profesores asignados
 *   profesor         = 1  solo calificaciones de sus alumnos asignados
 *
 * Los roles personalizados (tabla roles_config, pantalla "Roles y permisos")
 * usan el nivel que se les asignó al crearlos: se comparan con esta misma escala.
 */
class RoleHierarchy
{
    public const ADMIN = 'admin';
    public const DIRECTIVO = 'directivo';
    public const JEFE_PRECEPTORES = 'jefe_preceptores';
    public const PRECEPTOR = 'preceptor';
    public const PROSECRETARIA = 'prosecretaria';
    public const PROFESOR = 'profesor';
    public const SIN_ROL = 'sin_rol';

    /** Rol por defecto para nuevos usuarios. */
    public const DEFAULT = self::PROFESOR;

    private const LEVELS = [
        self::PROFESOR         => 1,
        self::PRECEPTOR        => 2,
        self::PROSECRETARIA    => 3,
        self::JEFE_PRECEPTORES => 3,
        self::DIRECTIVO        => 4,
        self::ADMIN            => 5,
    ];

    /**
     * Todos los roles validos para asignar (sin 'director', unificado a
     * 'directivo'), incluyendo los roles personalizados creados en
     * "Roles y permisos".
     */
    public static function all(): array
    {
        $roles = array_keys(self::LEVELS);
        foreach (RolConfig::registry() as $rol => $fila) {
            if ($fila['tipo'] === 'personalizado') {
                $roles[] = $rol;
            }
        }
        return $roles;
    }

    /** Nivel del rol dentro de los roles de sistema (0 si es personalizado o desconocido). */
    public static function nivelSistema(?string $role): int
    {
        if ($role === null) return 0;
        return self::LEVELS[$role] ?? 0;
    }

    /** Nombre visible de los roles de sistema. */
    private const NOMBRES = [
        self::PROFESOR         => 'Profesor',
        self::PRECEPTOR        => 'Preceptor',
        self::PROSECRETARIA    => 'Prosecretaria',
        self::JEFE_PRECEPTORES => 'Jefe de Preceptores',
        self::DIRECTIVO        => 'Directivo',
        self::ADMIN            => 'Admin',
    ];

    /** Todos los roles de sistema como mapa rol => nivel, del de menor rango al de mayor. */
    public static function allSistema(): array
    {
        $niveles = self::LEVELS;
        asort($niveles);
        return $niveles;
    }

    /** true si el rol es un rol personalizado (tabla roles_config). */
    public static function esPersonalizado(?string $role): bool
    {
        if ($role === null || self::nivelSistema($role) > 0) return false;
        return isset(RolConfig::registry()[$role]);
    }

    /** Nombre de sistema, sin mirar la tabla (sirve para crear la fila de un rol de sistema). */
    public static function nombreBase(?string $role): string
    {
        if ($role === null || $role === '') return '';
        return self::NOMBRES[$role] ?? str_replace('_', ' ', $role);
    }

    /** Nombre visible del rol: el editado en la tabla, o el de sistema. */
    public static function nombre(?string $role): string
    {
        if ($role === null || $role === '') return '';
        $fila = RolConfig::registry()[$role] ?? null;
        return $fila['nombre'] ?? self::nombreBase($role);
    }

    /** Nivel numerico del rol (0 si desconocido). Los personalizados usan su nivel guardado. */
    public static function level(?string $role): int
    {
        $sistema = self::nivelSistema($role);
        if ($sistema > 0) return $sistema;
        if ($role === null || $role === '') return 0;
        return RolConfig::registry()[$role]['nivel'] ?? 0;
    }

    /**
     * Devuelve true si $role tiene al menos el nivel de $minRole.
     * Ej: isAtLeast('admin', 'directivo') => true.
     */
    public static function isAtLeast(?string $role, string $minRole): bool
    {
        return self::level($role) >= self::level($minRole);
    }

    /**
     * Devuelve true si $actor puede gestionar (editar/eliminar/cambiar rol de) a $target.
     * Reglas:
     *  - Los roles del MISMO nivel se gestionan entre si (dos jefes, dos directivos,
     *    dos preceptores...). Lo que no se admite es gestionar a un rango superior.
     *  - El administrador es la cima: solo otro admin toca una cuenta admin.
     *  - Nadie de nivel < 3 gestiona usuarios (profesor/sin_rol: false; el preceptor tiene
     *    su propio atajo en AdminUsersController: solo edita datos de profesores asignados).
     *  - jefe_preceptores (3) gestiona a prosecretaria(3), preceptor(2), profesor(1) y sin_rol(0);
     *    prosecretaria (3) hace lo mismo con sus pares y hacia abajo.
     *  - directivo (4) gestiona su nivel y todo lo de abajo, nunca a un admin(5).
     *  - un rol personalizado gestiona roles personalizados del mismo nivel o de abajo,
     *    y nunca roles de sistema.
     */
    public static function canManage(?string $actorRole, ?string $targetRole): bool
    {
        $actor = self::level($actorRole);
        if ($actor <= 0) return false;
        $target = self::level($targetRole);
        // Sin rol (pendiente de asignar): lo puede gestionar cualquiera con permisos
        // de aprobacion (jefe_preceptores, prosecretaria, directivo o admin). Los preceptores no aprueban.
        if ($target <= 0) return $actor >= 3;

        // La cima: otra cuenta admin es intocable para quien no sea admin.
        if ($targetRole === self::ADMIN) return $actorRole === self::ADMIN;

        $actorEsSistema = self::nivelSistema($actorRole) > 0;
        if (!$actorEsSistema) {
            // Los personalizados se gestionan por nivel, sin la escala de sistema.
            return self::nivelSistema($targetRole) === 0 && $target <= $actor;
        }

        // Hacia abajo de la escala: 3, 4 y 5 gestionan su propio nivel y todo lo inferior.
        if ($actor < 3) return false;

        return $actor >= $target;
    }
}
