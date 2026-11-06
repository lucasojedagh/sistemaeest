<?php

namespace Tests\Unit;

use App\Support\Permisos;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Inventario del catálogo contra las rutas reales. Se rompió dos veces en la
// práctica: una acción marcada en la pantalla que no frenaba nada, y una ruta
// que ningún permiso cubría, así que un rol personalizado la perdía o la usaba
// de más. Estas pruebas mantienen las dos listas cerradas.
class InventarioPermisosTest extends TestCase
{
    /**
     * Formas normalizadas que puede tomar una ruta declarada.
     *
     * Permisos::normalizar() decide el token mirando el valor real del segment
     * (número -> {n}, "1A" -> {d}), y desde el registro de rutas solo se ve el
     * placeholder. Por eso se generan las variantes plausibles según el `where`
     * y se exige que al menos una esté cubierta: ninguna ruta puede quedar
     * invisible para el catálogo, pero no se castiga qué forma eligió el mapa.
     *
     * @return array<int, string>
     */
    private function variantesDe(\Illuminate\Routing\Route $ruta): array
    {
        $camino = preg_replace('#^api/#', '', $ruta->uri());
        $partes = explode('/', trim($camino, '/'));
        $wheres = $ruta->wheres;

        $opciones = [];
        foreach ($partes as $i => $p) {
            if (!str_starts_with($p, '{')) {
                $opciones[$i] = [strtolower($p)];
                continue;
            }
            $nombre = trim($p, '{}');
            $where  = $wheres[$nombre] ?? null;

            if (is_string($where) && preg_match('/^[a-zA-Z_]+(\|[a-zA-Z_]+)*$/', $where)) {
                // Alternativa de literales ("usuario|programador"): valores reales.
                $opciones[$i] = explode('|', $where);
            } elseif (is_string($where) && str_contains($where, '[a-zA-Z]')) {
                $opciones[$i] = ['{d}', '{n}'];
            } else {
                $opciones[$i] = ['{n}', '{d}'];
            }
        }

        $combinadas = [[]];
        foreach ($opciones as $posibles) {
            $nuevo = [];
            foreach ($combinadas as $previa) {
                foreach ($posibles as $v) $nuevo[] = array_merge($previa, [$v]);
            }
            $combinadas = $nuevo;
        }

        $out = [];
        foreach ($ruta->methods() as $metodo) {
            if ($metodo === 'HEAD' || $metodo === 'OPTIONS') continue;
            foreach ($combinadas as $c) {
                $out[] = strtoupper($metodo) . ':' . implode('/', $c);
            }
        }
        return $out;
    }

    public function test_cada_ruta_de_la_api_tiene_permiso_o_esta_declara(): void
    {
        $sinCubrir = [];
        foreach (Route::getRoutes() as $ruta) {
            if (!str_starts_with($ruta->uri(), 'api/')) continue;

            $variantes = $this->variantesDe($ruta);
            $cubierta = false;
            foreach ($variantes as $clave) {
                [$metodo, $camino] = explode(':', $clave, 2);
                if (Permisos::rutaConocida($metodo, $camino)) { $cubierta = true; break; }
            }
            if (!$cubierta) {
                $sinCubrir[] = $ruta->uri() . '  (' . implode(', ', array_slice($variantes, 0, 3)) . ')';
            }
        }

        $this->assertSame(
            [],
            $sinCubrir,
            "Rutas sin permiso asignado, sin ser públicas y sin estar en SIEMPRE:\n  "
                . implode("\n  ", $sinCubrir)
        );
    }

    public function test_cada_permiso_del_catalogo_se_aplica_en_algo(): void
    {
        $ref = new \ReflectionClass(Permisos::class);
        $usadas = array_unique(array_values($ref->getConstant('RUTAS')));

        $decorativas = array_values(array_diff(
            Permisos::claves(),
            $usadas,
            array_keys(Permisos::SIN_RUTA)
        ));

        $this->assertSame(
            [],
            $decorativas,
            'Casillas marcables en Roles y permisos que no frenan ninguna ruta '
                . 'ni están declaradas en Permisos::SIN_RUTA: ' . implode(', ', $decorativas)
        );
    }

    public function test_el_mapa_no_inventa_claves_fuera_del_catalogo(): void
    {
        $ref = new \ReflectionClass(Permisos::class);
        $validas = Permisos::claves();

        foreach ($ref->getConstant('RUTAS') as $ruta => $clave) {
            $this->assertContains($clave, $validas, "la ruta $ruta apunta a una clave inexistente");
        }
    }

    public function test_escribir_notas_desde_la_ficha_del_alumno_tiene_permiso(): void
    {
        // Cayó del mapa y EnsurePermiso lo trató como ruta desconocida: un rol
        // personalizado con "Cargar y editar notas" recibía 403 al guardar.
        $this->assertSame(
            'calificaciones.editar',
            Permisos::rutaPermiso('PUT', 'alumnos/63/calificaciones')
        );
        $this->assertTrue(Permisos::puede('profesor', 'calificaciones.editar'));
    }

    public function test_abrir_un_manual_desde_el_modal_tiene_permiso(): void
    {
        // ManualModal pide /manuales/{clave}; solo estaba la lista, así que el
        // rol personalizado quedaba afuera aunque tuviera "Abrir los manuales".
        $this->assertSame('manuales.ver', Permisos::rutaPermiso('GET', 'manuales/usuario'));
        $this->assertSame('manuales.programador', Permisos::rutaPermiso('GET', 'manuales/programador'));
    }
}
