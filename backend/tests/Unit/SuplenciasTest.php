<?php

namespace Tests\Unit;

use App\Support\Suplencias;
use Tests\TestCase;

// Reglas de la cadena de suplencias: sin auto-reemplazo, sin duplicados,
// sin ciclos y con un maximo de 4 eslabones.
class SuplenciasTest extends TestCase
{
    public function test_rechaza_reemplazarse_a_si_mismo(): void
    {
        $this->assertSame(
            'El suplente tiene que ser otra persona.',
            Suplencias::validarEslabon([], 5, 5)
        );
    }

    public function test_permite_primer_eslabon(): void
    {
        $this->assertNull(Suplencias::validarEslabon([], 1, 2));
    }

    public function test_rechaza_duplicado(): void
    {
        $filas = [['titular_id' => 1, 'suplente_id' => 2]];

        $this->assertSame(
            'Esa suplencia ya está cargada.',
            Suplencias::validarEslabon($filas, 1, 2)
        );
    }

    public function test_rechaza_ciclo_directo(): void
    {
        // A → B existe; B → A cerraria el circulo.
        $filas = [['titular_id' => 1, 'suplente_id' => 2]];

        $this->assertSame(
            'Esa persona ya está en la cadena: se formaría un círculo.',
            Suplencias::validarEslabon($filas, 2, 1)
        );
    }

    public function test_rechaza_ciclo_largo(): void
    {
        // A → B → C existe; C → A cerraria el circulo.
        $filas = [
            ['titular_id' => 1, 'suplente_id' => 2],
            ['titular_id' => 2, 'suplente_id' => 3],
        ];

        $this->assertSame(
            'Esa persona ya está en la cadena: se formaría un círculo.',
            Suplencias::validarEslabon($filas, 3, 1)
        );
    }

    public function test_permite_rama_sin_ciclo(): void
    {
        // A → B y A → D existen; D → C no forma circulo (C no llega a D).
        $filas = [
            ['titular_id' => 1, 'suplente_id' => 2],
            ['titular_id' => 1, 'suplente_id' => 4],
        ];

        $this->assertNull(Suplencias::validarEslabon($filas, 4, 3));
    }

    public function test_corta_en_cuatro_eslabones(): void
    {
        // A → B → C → D → E: el cuarto eslabon pasa, el quinto no.
        $filas = [
            ['titular_id' => 1, 'suplente_id' => 2],
            ['titular_id' => 2, 'suplente_id' => 3],
            ['titular_id' => 3, 'suplente_id' => 4],
        ];

        $this->assertNull(Suplencias::validarEslabon($filas, 4, 5));

        $filas[] = ['titular_id' => 4, 'suplente_id' => 5];
        $this->assertSame(
            'La cadena ya tiene 4 suplentes, que es el máximo.',
            Suplencias::validarEslabon($filas, 5, 6)
        );
    }

    public function test_niveles_para_mostrar_la_cadena(): void
    {
        $filas = [
            ['titular_id' => 1, 'suplente_id' => 2],
            ['titular_id' => 2, 'suplente_id' => 3],
            ['titular_id' => 1, 'suplente_id' => 4],
        ];

        $this->assertSame([[2, 4], [3]], Suplencias::nivelesDesde($filas, 1));
        $this->assertSame([[3]], Suplencias::nivelesDesde($filas, 2));
        $this->assertSame([], Suplencias::nivelesDesde($filas, 3));
    }
}
