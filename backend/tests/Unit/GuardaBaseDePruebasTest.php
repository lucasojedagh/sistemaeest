<?php

namespace Tests\Unit;

use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

// migrate:fresh borra todas las tablas. Esta guarda es lo único que separa a
// la red de pruebas de la base del colegio: si se rompe, el siguiente
// RefreshDatabase se lleva puestos los datos reales.
class GuardaBaseDePruebasTest extends TestCase
{
    private function proteger(): void
    {
        $m = new ReflectionMethod($this, 'protegerBaseReal');
        $m->invoke($this, $this->app);
    }

    public function test_la_configuracion_de_phpunit_apunta_a_la_base_de_pruebas(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('sistema_escuela_tests', config('database.connections.mysql.database'));
        $this->assertSame('array', config('cache.default'));
    }

    public function test_aborta_si_la_base_no_termina_en_tests(): void
    {
        config(['database.connections.mysql.database' => 'sistema_escuela']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("_tests");
        $this->proteger();
    }

    public function test_aborta_si_no_esta_en_ambiente_de_pruebas(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=testing');
        $this->proteger();
    }

    public function test_acepta_la_base_de_pruebas(): void
    {
        config(['database.connections.mysql.database' => 'sistema_escuela_tests']);
        $this->proteger();
        $this->assertTrue(true);
    }

    public function test_acepta_sqlite_en_memoria(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        $this->proteger();
        $this->assertTrue(true);
    }

    public function test_rechaza_sqlite_en_archivo(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => database_path('database.sqlite'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->proteger();
    }
}