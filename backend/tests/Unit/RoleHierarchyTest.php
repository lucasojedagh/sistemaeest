<?php

namespace Tests\Unit;

use App\Support\RoleHierarchy;
use PHPUnit\Framework\TestCase;

// Reglas de jerarquía sin tocar la BD: admin 5 > directivo 4 > jefe 3
// > preceptor/prosecretaria 2 > profesor 1 > sin_rol/desconocido 0.
class RoleHierarchyTest extends TestCase
{
    public function test_levels(): void
    {
        $this->assertSame(5, RoleHierarchy::level('admin'));
        $this->assertSame(4, RoleHierarchy::level('directivo'));
        $this->assertSame(3, RoleHierarchy::level('jefe_preceptores'));
        $this->assertSame(2, RoleHierarchy::level('preceptor'));
        $this->assertSame(2, RoleHierarchy::level('prosecretaria'));
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
        $this->assertFalse(RoleHierarchy::isAtLeast('preceptor', 'jefe_preceptores'));
        $this->assertFalse(RoleHierarchy::isAtLeast('prosecretaria', 'jefe_preceptores'));
        $this->assertFalse(RoleHierarchy::isAtLeast('sin_rol', 'profesor'));
    }

    public function test_can_manage(): void
    {
        // admin puede con cualquiera; directivo con nivel < 4 pero no pares ni admin.
        $this->assertTrue(RoleHierarchy::canManage('admin', 'directivo'));
        $this->assertTrue(RoleHierarchy::canManage('admin', 'admin'));
        $this->assertTrue(RoleHierarchy::canManage('directivo', 'preceptor'));
        $this->assertTrue(RoleHierarchy::canManage('directivo', 'prosecretaria'));
        $this->assertFalse(RoleHierarchy::canManage('directivo', 'directivo'));
        $this->assertFalse(RoleHierarchy::canManage('directivo', 'admin'));
        // jefe gestiona 2/1/0 pero no 3/4/5; el resto no gestiona usuarios.
        $this->assertTrue(RoleHierarchy::canManage('jefe_preceptores', 'profesor'));
        $this->assertTrue(RoleHierarchy::canManage('jefe_preceptores', 'prosecretaria'));
        $this->assertTrue(RoleHierarchy::canManage('jefe_preceptores', 'sin_rol'));
        $this->assertFalse(RoleHierarchy::canManage('jefe_preceptores', 'jefe_preceptores'));
        $this->assertFalse(RoleHierarchy::canManage('preceptor', 'profesor'));
        $this->assertFalse(RoleHierarchy::canManage('prosecretaria', 'profesor'));
        $this->assertFalse(RoleHierarchy::canManage('profesor', 'profesor'));
        $this->assertFalse(RoleHierarchy::canManage('sin_rol', 'sin_rol'));
    }
}
