<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Candados de configuracion que ya se rompieron una vez:
// - El registro tenia throttle:2,60 (2 intentos por HORA por IP) y bloqueaba
//   a toda la escuela cuando varios se registraban el mismo dia.
// - Los errores con mas de un campo terminaban en ingles ("(and 1 more error)")
//   porque la clave no existia en ningun idioma.
class RouteConfigTest extends TestCase
{
    public function test_registro_permite_varios_intentos_por_ip(): void
    {
        $ruta = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/register' && in_array('POST', $r->methods(), true)
        );
        $this->assertNotNull($ruta, 'No se encontro POST api/register');

        $middlewares = $ruta->gatherMiddleware();
        $this->assertContains(
            'throttle:10,10',
            $middlewares,
            'El registro volvio al limite que bloquea escuelas: ' . json_encode($middlewares)
        );
        $this->assertNotContains('throttle:2,60', $middlewares);
    }

    public function test_errores_multiples_en_espanol(): void
    {
        $this->assertSame(
            '(y 1 error más)',
            __('(and :count more error)', ['count' => 1])
        );
        $this->assertSame(
            '(y 3 errores más)',
            __('(and :count more errors)', ['count' => 3])
        );
    }
}
