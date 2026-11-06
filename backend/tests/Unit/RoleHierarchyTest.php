<?php

namespace Tests\Unit;

use App\Support\RoleHierarchy;
use PHPUnit\Framework\TestCase;

// Reglas de jerarquía sin tocar la BD: admin 5 > directivo 4 > jefe/prosecretaria 3
// > preceptor 2 > profesor 1 > sin_rol/desconocido 0.
// Jefe y prosecretaria comparten nivel operativo; la escala de cuentas los separa
// (canManage), así una prosecretaria no crea ni edita jefes ni otras prosecretarias.
class RoleHierarchyTest extends TestCase
{
    public function test_levels(): void
    {
        $this->assertSame(5, RoleHierarchy::level('admin'));
        $this->assertSame(4, RoleHierarchy::level('directivo'));
        $this->assertSame(3, RoleHierarchy::level('jefe_preceptores'));
        $this->assertSame(3, RoleHierarchy::level('prosecretaria'));
        $this->assertSame(2, RoleHierarchy::level('preceptor'));
        $this->assertSame(1, RoleHierarchy::level('profesor'));
        $this->assertSame(0, RoleHierarchy::level('sin_rol'));
        $this->assertSame(0, RoleHierarchy::level('director')); // rol viejo, unificado
        $this->assertSame(0, RoleHierarchy::level(null));
    }

    public function test_is_at_least(): void
    {
        $this->assertTrue(RoleHierarchy::isAtLeast('admin', 'directivo'));
        $this->assertTrue(RoleHierarchy::isAtLeast('directivo', 'directivo'));
        $this->assertTrue(RoleHierarchy::isAtLeast('prosecretaria', 'preceptor'));
        $this->assertTrue(RoleHierarchy::isAtLeast('prosecretaria', 'profesor'));
        // Mismos permisos operativos que el jefe de preceptores.
        $this->assertTrue(RoleHierarchy::isAtLeast('prosecretaria', 'jefe_preceptores'));
        $this->assertFalse(RoleHierarchy::isAtLeast('prosecretaria', 'directivo'));
        $this->assertFalse(RoleHierarchy::isAtLeast('preceptor', 'jefe_preceptores'));
        $this->assertFalse(RoleHierarchy::isAtLeast('sin_rol', 'profesor'));
    }

    public function test_can_manage(): void
    {
        // Regla nueva: los pares de nivel se gestionan entre si; hacia arriba nadie.
        // admin es la cima: solo otro admin toca una cuenta admin.
        $this->assertTrue(RoleHierarchy::canManage('admin', 'directivo'));
        $this->assertTrue(RoleHierarchy::canManage('admin', 'admin'));
        $this->assertTrue(RoleHierarchy::canManage('directivo', 'preceptor'));
        $this->assertTrue(RoleHierarchy::canManage('directivo', 'prosecretaria'));
        $this->assertTrue(RoleHierarchy::canManage('directivo', 'directivo'));
        $this->assertFalse(RoleHierarchy::canManage('directivo', 'admin'));
        // jefe gestiona su nivel, prosecretaria, preceptor, profesor y sin_rol; no a un directivo.
        $this->assertTrue(RoleHierarchy::canManage('jefe_preceptores', 'profesor'));
        $this->assertTrue(RoleHierarchy::canManage('jefe_preceptores', 'prosecretaria'));
        $this->assertTrue(RoleHierarchy::canManage('jefe_preceptores', 'sin_rol'));
        $this->assertTrue(RoleHierarchy::canManage('jefe_preceptores', 'jefe_preceptores'));
        $this->assertFalse(RoleHierarchy::canManage('jefe_preceptores', 'directivo'));
        // prosecretaria: mismo nivel que el jefe, se gestionan entre si.
        $this->assertTrue(RoleHierarchy::canManage('prosecretaria', 'profesor'));
        $this->assertTrue(RoleHierarchy::canManage('prosecretaria', 'preceptor'));
        $this->assertTrue(RoleHierarchy::canManage('prosecretaria', 'sin_rol'));
        $this->assertTrue(RoleHierarchy::canManage('prosecretaria', 'prosecretaria'));
        $this->assertTrue(RoleHierarchy::canManage('prosecretaria', 'jefe_preceptores'));
        $this->assertFalse(RoleHierarchy::canManage('prosecretaria', 'directivo'));
        $this->assertFalse(RoleHierarchy::canManage('prosecretaria', 'admin'));
        // Los rangos bajos no gestionan usuarios.
        $this->assertFalse(RoleHierarchy::canManage('preceptor', 'profesor'));
        $this->assertFalse(RoleHierarchy::canManage('preceptor', 'preceptor'));
        $this->assertFalse(RoleHierarchy::canManage('profesor', 'profesor'));
        $this->assertFalse(RoleHierarchy::canManage('sin_rol', 'sin_rol'));
    }
}
