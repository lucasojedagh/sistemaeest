<?php

namespace Tests\Unit;

use App\Support\Alcances;
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

    /**
     * La pantalla "Roles y permisos" muestra un bloque por módulo: cada grupo del
     * catálogo tiene que tener el suyo, con un área de datos que exista (o ninguna)
     * y solo ids de módulo del menú, sin que uno aparezca en dos bloques.
     */
    public function test_bloques_cubren_todos_los_grupos_del_catalogo(): void
    {
        $bloques = Permisos::bloques();
        $this->assertCount(count(Permisos::catalogo()), $bloques);

        $areas = array_keys(Alcances::AREAS);
        $modulos = array_keys(Alcances::MODULOS);
        $vistas = [];
        $acciones = [];
        foreach ($bloques as $b) {
            $this->assertNotEmpty($b['id']);
            $this->assertNotEmpty($b['nombre']);
            $this->assertNotEmpty($b['items'], "el bloque {$b['id']} quedó sin acciones");
            if ($b['area'] !== null) {
                $this->assertContains($b['area'], $areas, "el bloque {$b['id']} apunta a un área inexistente");
            }
            foreach ($b['modulos'] as $m) {
                // La pantalla pinta una chapita por módulo con su nombre: si el
                // mapa llega con los ids sueltos, el chip sale vacío.
                $this->assertArrayHasKey('id', $m, "el bloque {$b['id']} tiene un módulo sin id");
                $this->assertArrayHasKey('nombre', $m, "el bloque {$b['id']} tiene un módulo sin nombre");
                $this->assertNotEmpty($m['nombre'], "el módulo {$m['id']} del bloque {$b['id']} quedó sin nombre");
                $this->assertContains($m['id'], $modulos, "el bloque {$b['id']} apunta a un módulo inexistente");
                $this->assertNotContains($m['id'], $vistas, "el módulo {$m['id']} se marca en dos bloques");
                $vistas[] = $m['id'];
            }
            foreach ($b['items'] as $i) {
                $acciones[] = $i['clave'];
            }
        }

        sort($vistas);
        sort($modulos);
        $this->assertSame($modulos, $vistas, 'hay módulos del menú sin bloque donde marcarlos');
        sort($acciones);
        $esperadas = Permisos::claves();
        sort($esperadas);
        $this->assertSame($esperadas, $acciones, 'las acciones de los bloques no coinciden con el catálogo');
    }

    public function test_nivel_hasta_sube_con_el_rango(): void
    {
        $profesor = Permisos::conNivelHasta(1);
        $jefe     = Permisos::conNivelHasta(3);
        $admin    = Permisos::conNivelHasta(5);

        $this->assertContains('calificaciones.editar', $profesor);
        $this->assertNotContains('alumnos.crear', $profesor);
        // Al rango Profesor la acción le entra por el rango 1; lo que lo frena es
        // su alcance de escritura en 'ninguno' (AlcancesTest lo comprueba). Así la
        // dirección la reactiva desde Roles y permisos sin cambiar de rango.
        $this->assertContains('asistencia.ver', $profesor);
        $this->assertContains('asistencia.editar', $profesor);
        $molde = collect(Permisos::plantillas())->firstWhere('id', 'profesor');
        $this->assertNotContains('asistencia.editar', $molde['permitidos']);
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

    /**
     * La lista guardada de un rol es SU lista, no un extra sobre el rango:
     * un Profesor puede quedar habilitado para crear alumnos si la dirección lo
     * marca, y el tope que queda es el rango de quien edita (no se escala solo).
     */
    public function test_normalizar_lista_se_toma_de_cualquier_rango_pero_no_escala_al_que_edita(): void
    {
        $lealtad = Permisos::normalizarLista('profesor', ['alumnos.ver', 'alumnos.crear', 'documentos.eliminar'], 5);
        $this->assertContains('alumnos.crear', $lealtad, 'un rango bajo puede recibir una acción de rango alto');
        $this->assertContains('documentos.eliminar', $lealtad);

        $jefe = Permisos::normalizarLista('profesor', ['alumnos.ver', 'manuales.programador'], 3);
        $this->assertSame(['alumnos.ver'], $jefe, 'nadie puede regalar un permiso por encima de su propio rango');
    }

    public function test_normalizar_lista_cierra_la_gestion_de_roles_y_el_piso_del_admin(): void
    {
        // La gestión de roles no se cuela en un rol que no la tiene, ni sale de
        // los dos que la tienen.
        $this->assertNotContains('roles.configurar', Permisos::normalizarLista('preceptor', ['roles.configurar', 'alumnos.ver'], 5));
        $this->assertNotContains('roles.configurar', Permisos::normalizarLista('auxiliar', ['roles.configurar'], 5));
        $this->assertContains('roles.configurar', Permisos::normalizarLista('jefe_preceptores', [], 5));
        $this->assertContains('roles.configurar', Permisos::normalizarLista('admin', [], 5));

        // El Admin conserva el piso: es la única cuenta y no puede cerrarse la
        // puerta de Usuarios desde la propia pantalla de roles.
        $piso = Permisos::normalizarLista('admin', [], 5);
        foreach (Permisos::PISO_ADMIN as $clave) {
            $this->assertContains($clave, $piso, "el Admin perdió {$clave}");
        }
        $this->assertNotContains('usuarios.editar', Permisos::normalizarLista('jefe_preceptores', [], 5), 'el piso es solo del Admin');
    }

    /**
     * Los respaldos son del Admin y de nadie más: ni la base del rango, ni la
     * lista guardada de un rol, ni un director con rango alto los pueden habilitar.
     */
    public function test_los_respaldos_solo_del_admin(): void
    {
        foreach (Permisos::SOLO_ADMIN as $clave) {
            $this->assertContains($clave, Permisos::base('admin'), "el Admin perdió {$clave}");

            foreach (['jefe_preceptores', 'prosecretaria', 'directivo', 'preceptor', 'profesor', 'rol_inventado'] as $rol) {
                $this->assertNotContains($clave, Permisos::base($rol), "{$rol} no puede tener {$clave}");
                $this->assertFalse(Permisos::puede($rol, $clave), "{$rol} no puede usar {$clave}");
            }

            $this->assertNotContains($clave, Permisos::normalizarLista('directivo', [$clave], 5), 'nadie puede marcar un respaldo en otro rol');
        }

        // El plantillado histórico tampoco los regala.
        foreach (Permisos::plantillas() as $p) {
            if ($p['id'] === 'admin') continue;
            foreach (Permisos::SOLO_ADMIN as $clave) {
                $this->assertNotContains($clave, $p['permitidos'], "plantilla {$p['id']} ofrece {$clave}");
            }
        }
    }

    /**
     * Vaciar la auditoría entró en el mismo candado: la ruta DELETE existe y el
     * rango más alto después del Admin ve el historial pero no lo borra. Exportar
     * corrió al mismo lado: la casilla tampoco aparece para otro rol.
     */
    public function test_limpiar_el_historial_es_solo_del_admin(): void
    {
        $this->assertSame('historial.limpiar', Permisos::rutaPermiso('DELETE', 'historial'));
        $this->assertTrue(Permisos::puede('jefe_preceptores', 'historial.ver'));
        $this->assertFalse(Permisos::puede('jefe_preceptores', 'historial.limpiar'));
        $this->assertFalse(Permisos::puede('jefe_preceptores', 'historial.exportar'));
    }
}
