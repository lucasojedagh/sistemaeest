<?php

namespace Tests\Unit;

use App\Support\ActivityDiff;
use Tests\TestCase;

// El diff centralizado del historial: solo muestra lo que cambio de verdad,
// y no inventa cambios por diferencias de formato ("Activo" vs "activo ").
class ActivityDiffTest extends TestCase
{
    public function test_solo_deja_lo_que_cambio(): void
    {
        [$old, $new] = ActivityDiff::cambios(
            ['nombre' => 'Ana', 'dni' => '123', 'anio' => 1],
            ['nombre' => 'Ana', 'dni' => '123', 'anio' => 1, 'observaciones' => 'llamo tarde'],
        );

        $this->assertSame(['observaciones' => null], $old);
        $this->assertSame(['observaciones' => 'llamo tarde'], $new);
    }

    public function test_nada_cambiado_da_vacio(): void
    {
        [$old, $new] = ActivityDiff::cambios(['a' => 1], ['a' => 1]);

        $this->assertSame([], $old);
        $this->assertSame([], $new);
    }

    public function test_tolera_diferencias_de_formato(): void
    {
        $this->assertTrue(ActivityDiff::igual('Activo', 'activo '));
        $this->assertTrue(ActivityDiff::igual(null, ''));
        $this->assertTrue(ActivityDiff::igual(0, false));
        $this->assertTrue(ActivityDiff::igual(7.50, 7.5));
        $this->assertFalse(ActivityDiff::igual('Ana', 'Maria'));
    }

    public function test_formatos_especializados_no_se_filtran(): void
    {
        // Materias y asignaciones necesitan la foto completa para detectar
        // altas y bajas: el diff las devuelve intactas.
        $materias = [['nombre' => 'Mat']];
        [$old, $new] = ActivityDiff::cambios(['#1' => $materias], ['#1' => $materias]);
        $this->assertSame(['#1' => $materias], $old);

        $asig = ['asignaciones' => [['anio' => 1, 'division' => '1']]];
        [$old, $new] = ActivityDiff::cambios($asig, $asig);
        $this->assertSame($asig, $old);
    }

    public function test_etiquetas_en_criollo(): void
    {
        $this->assertSame('Notas 1.° cuatrimestre', ActivityDiff::etiqueta('p1_notas'));
        $this->assertSame('Fecha de nacimiento', ActivityDiff::etiqueta('fecha_nacimiento'));
        $this->assertSame('Alumno 6', ActivityDiff::etiqueta('alumno_6'));
        // Lo que ya viene rotulado se respeta.
        $this->assertSame('Año 3', ActivityDiff::etiqueta('Año 3'));
    }
}
