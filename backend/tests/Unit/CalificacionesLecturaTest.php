<?php

namespace Tests\Unit;

use App\Dominios\Calificaciones\AlumnoCalificacionesController;
use Tests\TestCase;

// TEA/TEP/TED no son numeros. Tres reglas que no deben romperse:
// 1) Al leer se conserva el texto (un (float) lo volvia 0 y el 0 despues
//    arruinaba promedios y estados).
// 2) Los promedios solo cuentan numeros (8 + TEA promedia 8, no 4).
// 3) La preliminar se muestra venga de donde venga: filas tipo=preliminar
//    (formulario) o detalle.nota_texto (planilla). Cada pantalla leia solo
//    la suya y al reabrir parecia que no se habia guardado.
class CalificacionesLecturaTest extends TestCase
{
    private function metodo(string $nombre): \ReflectionMethod
    {
        $m = new \ReflectionMethod(AlumnoCalificacionesController::class, $nombre);
        $m->setAccessible(true);

        return $m;
    }

    private function controlador(): AlumnoCalificacionesController
    {
        return (new \ReflectionClass(AlumnoCalificacionesController::class))->newInstanceWithoutConstructor();
    }

    public function test_valor_leido_conserva_texto(): void
    {
        $m = $this->metodo('valorLeido');
        $c = $this->controlador();

        $this->assertSame('TEA', $m->invoke($c, 'TEA'));
        $this->assertSame('TEA', $m->invoke($c, 'tea'));
        $this->assertSame(8.0, $m->invoke($c, '8'));
        $this->assertSame(7.5, $m->invoke($c, '7.5'));
        $this->assertNull($m->invoke($c, ''));
        $this->assertNull($m->invoke($c, null));
        // El 0 era basura del bug viejo (TEA guardado como 0): no se muestra.
        $this->assertNull($m->invoke($c, '0'));
        $this->assertNull($m->invoke($c, 0));
    }

    public function test_promedio_ignora_textos(): void
    {
        $m = $this->metodo('promedioDe');
        $c = $this->controlador();

        $this->assertSame(8.0, $m->invoke($c, [8, 'TEA']));
        $this->assertSame(7.5, $m->invoke($c, ['7.5', 'TEP', null, '']));
        $this->assertNull($m->invoke($c, ['TEA', 'TED']));
        $this->assertNull($m->invoke($c, []));
    }

    public function test_preliminar_visible_desde_cualquiera_de_las_dos_convenciones(): void
    {
        $m = $this->metodo('preliminarVisible');
        $c = $this->controlador();

        // Solo filas (formulario): se muestran tal cual.
        $this->assertSame(
            [['valor' => 'TED', 'descripcion' => 'TED']],
            $m->invoke($c, [['valor' => 'TED', 'descripcion' => 'TED']], null)
        );

        // Solo nota_texto (planilla): se sintetiza para el formulario.
        $this->assertSame(
            [['valor' => 'TEA', 'descripcion' => 'TEA']],
            $m->invoke($c, [], 'tea')
        );

        // Si hay de las dos, mandan las filas (convencion mas especifica).
        $this->assertSame(
            [['valor' => 'TED', 'descripcion' => 'TED']],
            $m->invoke($c, [['valor' => 'TED', 'descripcion' => 'TED']], 'TEA')
        );

        // Nada de nada: vacio, no inventa.
        $this->assertSame([], $m->invoke($c, [], null));
        $this->assertSame([], $m->invoke($c, [], ''));
    }
}
