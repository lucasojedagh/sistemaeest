<?php

namespace App\Support;

/**
 * Jerarquia de roles del sistema.
 *
 * Niveles (mayor = mas privilegios):
 *   admin            = 5  control total
 *   directivo        = 4  control total
 *   jefe_preceptores = 3  casi todo, no puede tocar a admin/directivo
 *   preceptor        = 2  lectura global; escritura en su ambito; edita datos de profesores asignados
 *   prosecretaria    = 2  mismo nivel que preceptor (ámbito y permisos equivalentes)
 *   profesor         = 1  solo calificaciones de sus alumnos asignados
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
        self::PROSECRETARIA    => 2,
        self::JEFE_PRECEPTORES => 3,
        self::DIRECTIVO        => 4,
        self::ADMIN            => 5,
    ];

    /** Todos los roles validos (sin 'director', unificado a 'directivo'). */
    public static function all(): array
    {
        return array_keys(self::LEVELS);
    }

    /** Nivel numerico del rol (0 si desconocido). */
    public static function level(?string $role): int
    {
        return self::LEVELS[$role] ?? 0;
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
     *  - Nadie de nivel < 4 gestiona usuarios (preceptor/profesor/sin_rol: false).
     *  - jefe_preceptores (3) gestiona a preceptor(2), profesor(1) y sin_rol(0).
     *  - directivo (4) gestiona a todos los de nivel < 4, pero NO a directivo(4) ni admin(5).
     *  - admin (5) gestiona a cualquiera (único que puede tocar a directivo/admin).
     */
    public static function canManage(?string $actorRole, ?string $targetRole): bool
    {
        $actor = self::level($actorRole);
        if ($actor <= 0) return false;
        $target = self::level($targetRole);
        // Sin rol (pendiente de asignar): lo puede gestionar cualquiera con permisos
        // de aprobacion (jefe_preceptores, directivo o admin). Los preceptores no aprueban.
        if ($target <= 0) return $actor >= 3;
        // No puede gestionar a alguien de rol igual o superior, salvo que el actor sea
        // admin/directivo (que si pueden gestionar a sus pares jerarquicos inferiores o iguales).
        // Regla simple: el actor debe ser estrictamente superior en nivel para gestionar,
        // O bien ser admin/directivo (nivel >=4) que gestionan a todos los de nivel < 4.
        if ($actor >= 4) {
            return $target < 4 || $actorRole === self::ADMIN;
        }
        // jefe_preceptores (3) gestiona a preceptor(2) y profesor(1) pero no a 3/4/5.
        if ($actor === 3) {
            return $target < 3;
        }
        return false;
    }
}
