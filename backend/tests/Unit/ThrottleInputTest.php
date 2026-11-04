<?php

namespace Tests\Unit;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// Los limitadores de login y recupero armaban la clave con el email crudo:
// un array en el campo (bot o formulario roto) tiraba "Array to string
// conversion" con 500 ANTES de llegar a la validacion.
class ThrottleInputTest extends TestCase
{
    private function limiter(string $name): callable
    {
        $l = RateLimiter::limiter($name);
        $this->assertIsCallable($l, "No hay limitador registrado como '{$name}'");

        return $l;
    }

    private function reqConEmail($email): Request
    {
        return Request::create('/api/login', 'POST', ['email' => $email, 'password' => 'x']);
    }

    public function test_login_tolera_email_raro(): void
    {
        $limiter = $this->limiter('login');

        foreach (['profe@abc.gob.ar', '', ['x'], null, 123] as $email) {
            $limit = $limiter($this->reqConEmail($email));
            $this->assertInstanceOf(Limit::class, $limit, 'email: ' . json_encode($email));
        }
    }

    public function test_password_tolera_email_raro(): void
    {
        $limiter = $this->limiter('password');

        foreach (['profe@abc.gob.ar', ['x'], null] as $email) {
            $limit = $limiter($this->reqConEmail($email));
            $this->assertInstanceOf(Limit::class, $limit, 'email: ' . json_encode($email));
        }
    }
}
