<?php

namespace Tests\Feature;

use App\Dominios\Usuarios\User;
use App\Http\Middleware\EnsureRole;
use Illuminate\Http\Request;
use Tests\TestCase;

// El middleware es jerárquico: role:X admite a X y superiores.
// Usa modelos User SIN guardar (ningún test toca la BD).
class EnsureRoleTest extends TestCase
{
    private function runMiddleware(?string $role, array $roles): int
    {
        $req = Request::create('/x', 'GET');
        if ($role !== null) {
            $req->setUserResolver(fn () => new User(['role' => $role]));
        }
        $res = (new EnsureRole)->handle($req, fn () => response('ok'), ...$roles);

        return $res->getStatusCode();
    }

    public function test_directivo_pasa_donde_antes_quedaba_afuera(): void
    {
        // La ventana de nota final pide role:admin,jefe_preceptores (nivel 3):
        // directivo (4) debe pasar.
        $this->assertSame(200, $this->runMiddleware('directivo', ['admin', 'jefe_preceptores']));
        $this->assertSame(200, $this->runMiddleware('admin', ['admin', 'jefe_preceptores']));
        $this->assertSame(200, $this->runMiddleware('jefe_preceptores', ['admin', 'jefe_preceptores']));
        // La prosecretaria tiene el mismo nivel operativo que el jefe: pasa los mismos grupos.
        $this->assertSame(200, $this->runMiddleware('prosecretaria', ['admin', 'jefe_preceptores']));
        $this->assertSame(403, $this->runMiddleware('preceptor', ['admin', 'jefe_preceptores']));
        $this->assertSame(403, $this->runMiddleware('profesor', ['admin', 'jefe_preceptores']));
        $this->assertSame(403, $this->runMiddleware('sin_rol', ['admin', 'jefe_preceptores']));
    }

    public function test_listas_contiguas_igual_que_antes(): void
    {
        $this->assertSame(200, $this->runMiddleware('preceptor', ['admin', 'directivo', 'jefe_preceptores', 'preceptor']));
        $this->assertSame(200, $this->runMiddleware('prosecretaria', ['admin', 'directivo', 'jefe_preceptores', 'preceptor']));
        $this->assertSame(403, $this->runMiddleware('profesor', ['admin', 'directivo', 'jefe_preceptores', 'preceptor']));
        $this->assertSame(200, $this->runMiddleware('admin', ['admin']));
        $this->assertSame(403, $this->runMiddleware('directivo', ['admin']));
    }

    public function test_sin_usuario_o_rol_desconocido(): void
    {
        $this->assertSame(401, $this->runMiddleware(null, ['admin']));
        $this->assertSame(403, $this->runMiddleware('admin', ['rol_inexistente']));
    }

    public function test_el_rechazo_lo_lee_una_persona(): void
    {
        // La pantalla pone este texto en el toast tal cual llega: un "Forbidden"
        // no le dice al preceptor qué le faltó.
        $req = Request::create('/x', 'GET');
        $req->setUserResolver(fn () => new User(['role' => 'preceptor']));
        $cuerpo = (string) (new EnsureRole)->handle($req, fn () => response('ok'), 'admin')->getContent();

        $this->assertStringNotContainsString('Forbidden', $cuerpo);
        $this->assertStringContainsString('rango', $cuerpo);
    }
}
