<?php

namespace Tests\Unit;

use App\Support\Paginacion;
use Illuminate\Http\Request;
use Tests\TestCase;

// ?per_page= llegaba crudo al paginate() y un valor negativo o texto
// rompia el SQL con un 500. El helper debe devolver siempre [1, max].
class PaginacionTest extends TestCase
{
    private function req($perPage): Request
    {
        return is_null($perPage)
            ? Request::create('/x', 'GET')
            : Request::create('/x?' . http_build_query(['per_page' => $perPage]), 'GET');
    }

    public function test_valores_normales_pasan_igual(): void
    {
        $this->assertSame(5, Paginacion::porPagina($this->req(5), 100, 2000));
        $this->assertSame(10, Paginacion::porPagina($this->req('10'), 100, 2000));
    }

    public function test_respeta_el_maximo(): void
    {
        $this->assertSame(200, Paginacion::porPagina($this->req(9999), 100, 200));
        $this->assertSame(2000, Paginacion::porPagina($this->req(5000), 100, 2000));
    }

    public function test_valores_rotos_caen_al_defecto(): void
    {
        // Negativo y cero: era el 500 (SQL sin LIMIT).
        $this->assertSame(100, Paginacion::porPagina($this->req(-5), 100, 2000));
        $this->assertSame(100, Paginacion::porPagina($this->req(0), 100, 2000));
        // Texto y arreglos: antes (int)'abc' daba 0 y rompia igual.
        $this->assertSame(100, Paginacion::porPagina($this->req('abc'), 100, 2000));
        $this->assertSame(100, Paginacion::porPagina($this->req(['5']), 100, 2000));
        // Ausente: el defecto que pida cada listado.
        $this->assertSame(50, Paginacion::porPagina($this->req(null), 50, 200));
    }
}
