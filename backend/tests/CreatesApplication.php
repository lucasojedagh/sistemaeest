<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->protegerBaseReal($app);

        return $app;
    }

    // RefreshDatabase corre migrate:fresh, que BORRA todas las tablas. Si algún
    // día se pierde el APP_ENV o el DB_DATABASE de phpunit.xml (un .env distinto,
    // un `phpunit` corrido a mano), esto frena antes de tocar la base del colegio.
    private function protegerBaseReal(Application $app): void
    {
        if (!$app->environment('testing')) {
            throw new RuntimeException(
                'Las pruebas solo corren con APP_ENV=testing (llegó "'.$app->environment().'").'
            );
        }

        $conexion = (string) config('database.default');
        $base = (string) config("database.connections.{$conexion}.database");

        $sqliteEnMemoria = $conexion === 'sqlite' && $base === ':memory:';

        if (!$sqliteEnMemoria && !str_ends_with($base, '_tests')) {
            throw new RuntimeException(
                "La base de pruebas tiene que terminar en '_tests' (llegó '{$conexion}.{$base}'). ".
                'Crearla con: CREATE DATABASE sistema_escuela_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
            );
        }
    }
}