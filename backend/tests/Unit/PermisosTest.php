<?php

namespace Tests\Unit;

use App\Support\Permisos;
use PHPUnit\Framework\TestCase;

// El catálogo de permisos y el mapa ruta->permiso, sin tocar la BD.
// Lo que se prueba acá es la base de los roles de sistema: mientras un rol no
// tenga fila en roles_config, Permisos::sinFiltro() debe dar true (cero regresión).
class PermisosTest extends TestCase
{
    public function test_catalogo_coherente(): void
    {
        $claves = Permisos::claves();
        $this->assertNotEmpty($claves);
        $this->assertSame(count($claves), count(array_unique($claves)), 'hay claves duplicadas');

        foreach (Permisos::catalogo() as $grupo) {
            foreach ($grupo['items'] as $item) {
                $this->assertContains($item['clave'], $claves);
                $this->assertGreaterThanOrEqual(1, $item['nivel']);
                $this->assertLessThanOrEqual(5, $item['nivel']);
                $this->assertStringStartsWith(explode('.', $item['clave'])[0] . '.', $item['clave']);
            }
        }
    }

    public function test_sanear_deja_solo_claves_conocidas(): void
    {
        $limpias = Permisos::sanear(['alumnos.ver', 'no.existe', 'alumnos.ver', '', null]);
        $this->assertSame(['alumnos.ver'], $limpias);
    }

    public function test_plantillas_usan_claves_validas(): void
    {
        $validas = Permisos::claves();
        foreach (Permisos::plantillas() as $p) {
            $this->assertNotEmpty($p['nombre']);
            foreach ($p['permitidos'] as $clave) {
                $this->assertContains($clave, $validas, "plantilla {$p['id']} usa una clave inexistente");
            }
        }
    }

    public function test_nivel_hasta_sube_con_el_rango(): void
    {
        $profesor = Permisos::conNivelHasta(1);
        $jefe     = Permisos::conNivelHasta(3);
        $admin    = Permisos::conNivelHasta(5);

        $this->assertContains('calificaciones.editar', $profesor);
        $this->assertNotContains('alumnos.crear', $profesor);
        $this->assertContains('alumnos.crear', $jefe);
        $this->assertContains('roles.configurar', $admin);
        $this->assertCount(count(Permisos::claves()), $admin);
    }

    public function test_rutas_normalizadas_y_de_siempre_permitidas(): void
    {
        $this->assertSame('calificaciones.ver', Permisos::rutaPermiso('GET', 'alumnos/63/calificaciones'));
        $this->assertSame('horario.editar', Permisos::rutaPermiso('PUT', 'horarios/2026/1A'));
        $this->assertSame('cursos.ver', Permisos::rutaPermiso('GET', 'cursos/2026/1A/horario'));
        $this->assertSame('usuarios.rol', Permisos::rutaPermiso('PUT', 'admin/users/41/role'));
        $this->assertSame('roles.configurar', Permisos::rutaPermiso('POST', 'admin/roles'));
        // Imprimir y exportar es una acción propia: sin ticket no hay impresión.
        $this->assertSame('impresion.usar', Permisos::rutaPermiso('POST', 'print/ticket'));
        $this->assertSame('documentos.subir', Permisos::rutaPermiso('POST', 'documentos'));
        $this->assertSame('documentos.descargar', Permisos::rutaPermiso('GET', 'documentos/9/download'));
        $this->assertSame('backup.restaurar', Permisos::rutaPermiso('POST', 'backups/sql/import'));
        $this->assertSame('manuales.programador', Permisos::rutaPermiso('GET', 'manuales/programador'));

        // Sesión, perfil y catálogo: nunca se filtran.
        foreach (['GET:me', 'GET:me/ambito', 'POST:logout', 'GET:roles/detalle'] as $p) {
            [$m, $camino] = explode(':', $p, 2);
            $this->assertNull(Permisos::rutaPermiso($m, $camino), $p);
        }
        $this->assertTrue(Permisos::rutaConocida('POST', 'logout'));
        $this->assertFalse(Permisos::rutaConocida('GET', 'ruta/que/no/existe'));
    }

    public function test_rol_desconocido_no_puede_nada(): void
    {
        // Sin BD la tabla roles_config no aporta filas: un rol que no existe
        // tampoco está filtrado, y el nivel 0 no trae ningún permiso.
        $this->assertTrue(Permisos::sinFiltro('rol_inventado'));
        $this->assertTrue(Permisos::sinFiltro(null));
        $this->assertFalse(Permisos::puede('rol_inventado', 'alumnos.ver'));
        $this->assertTrue(Permisos::puede('profesor', 'alumnos.ver'));
        $this->assertFalse(Permisos::puede('profesor', 'alumnos.crear'));
        $this->assertTrue(Permisos::puede('jefe_preceptores', 'alumnos.crear'));
    }
}
