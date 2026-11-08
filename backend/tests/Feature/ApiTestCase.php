<?php

namespace Tests\Feature;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\Materia;
use App\Dominios\Profesores\Profesor;
use App\Dominios\Profesores\ProfesorAsignacion;
use App\Dominios\Usuarios\User;
use App\Dominios\Usuarios\UserAnioDivision;
use Database\Seeders\ParametroDivisionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de las pruebas de HTTP: entra por POST /api/login como una persona real
 * y pide los endpoints con ese token, así se prueba el middleware completo
 * (auth.token, permiso, role, throttle) y no una versión simulada.
 *
 * El fixture es el mismo para todos los módulos: una escuela chica con dos
 * cursos, donde el preceptor y el profesor tienen asignado 1° 1.ª y nada más.
 * Todo lo que esté en 2° 2.ª sirve para comprobar que el alcance se respeta.
 * Las divisiones son números ('1'…'9'), como en el catálogo real de la escuela.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const CLAVE = 'ClaveDePrueba2026';

    public const ROLES = [
        'admin', 'directivo', 'jefe_preceptores', 'prosecretaria', 'preceptor', 'profesor', 'sin_rol',
    ];

    /** @var array<string, User> */
    protected array $cuentas = [];

    /** @var array<string, string> */
    private array $tokens = [];

    protected Curso $curso;
    protected Curso $otroCurso;
    protected Materia $materia;
    protected Materia $otraMateria;
    protected Alumno $alumno;
    protected Profesor $profesor;

    protected function setUp(): void
    {
        parent::setUp();

        // Las subidas (documentos, fotos) y los respaldos no tienen que dejar
        // archivos en el storage real del proyecto.
        Storage::fake('local');

        // Roles y permisos se leen una sola vez por proceso: sin esto, la
        // configuración de una prueba quedaría vigente en la siguiente.
        \App\Dominios\Usuarios\RolConfig::olvidarRegistro();

        $this->seed(ParametroDivisionesSeeder::class);
        $this->sembrarCuentas();
        $this->sembrarCursos();
        $this->sembrarAlumnos();
    }

    private function sembrarCuentas(): void
    {
        foreach (self::ROLES as $rol) {
            $this->cuentas[$rol] = User::create([
                'name'     => ucfirst(str_replace('_', ' ', $rol)),
                'apellido' => 'DePrueba',
                'email'    => $rol . '@abc.gob.ar',
                'password' => self::CLAVE,
                'role'     => $rol,
                'status'   => 'active',
            ]);
        }

        // Cuenta recién registrada: sin rol y pendiente de que dirección la verifique.
        $this->cuentas['pendiente'] = User::create([
            'name'           => 'Pendiente',
            'apellido'       => 'DePrueba',
            'email'          => 'pendiente@abc.gob.ar',
            'password'       => self::CLAVE,
            'role'           => 'sin_rol',
            'rol_solicitado' => 'preceptor',
            'status'         => 'pending',
        ]);
    }

    private function sembrarCursos(): void
    {
        $this->curso = Curso::create([
            'anio' => 1, 'division' => '1', 'turno' => 'manana', 'cupo' => 30, 'activo' => true,
        ]);
        $this->otroCurso = Curso::create([
            'anio' => 2, 'division' => '2', 'turno' => 'manana', 'cupo' => 30, 'activo' => true,
        ]);

        $this->materia = Materia::create(['anio' => 1, 'nombre' => 'Matemática', 'tipo' => 'aula']);
        $this->otraMateria = Materia::create(['anio' => 1, 'nombre' => 'Lengua y Literatura', 'tipo' => 'aula']);
        Materia::create(['anio' => 2, 'nombre' => 'Historia', 'tipo' => 'aula']);

        // El preceptor y el profesor trabajan en 1° 1.ª: 2° 2.ª les tiene que quedar fuera.
        foreach (['preceptor', 'profesor'] as $rol) {
            UserAnioDivision::create([
                'user_id' => $this->cuentas[$rol]->id, 'anio' => 1, 'division' => '1',
            ]);
        }

        // La ficha de profesor se enlaza con la cuenta por e-mail (así lo hace el
        // sistema real) y dicta Matemática en 1° 1.ª.
        $this->profesor = Profesor::create([
            'nombre'   => 'Profesor',
            'apellido' => 'DePrueba',
            'email'    => $this->cuentas['profesor']->email,
            'estado'   => 'Activo',
        ]);
        ProfesorAsignacion::create([
            'profesor_id' => $this->profesor->id,
            'materia_id'  => $this->materia->id,
            'anio'        => 1,
            'division'    => '1',
        ]);
    }

    private function sembrarAlumnos(): void
    {
        $this->alumno = $this->nuevoAlumno(['apellido' => 'Álvarez', 'nombre' => 'Ana', 'dni' => '40000001']);
        $this->nuevoAlumno(['apellido' => 'Benítez', 'nombre' => 'Bruno', 'dni' => '40000002', 'genero' => 'M']);
        $this->nuevoAlumno(['apellido' => 'Cabrera', 'nombre' => 'Carla', 'dni' => '40000003']);

        $this->nuevoAlumno([
            'apellido' => 'Domínguez', 'nombre' => 'Diego', 'dni' => '40000011', 'genero' => 'M',
            'anio' => 2, 'division' => '2',
        ]);
        $this->nuevoAlumno([
            'apellido' => 'Escobar', 'nombre' => 'Elena', 'dni' => '40000012',
            'anio' => 2, 'division' => '2',
        ]);
    }

    protected function nuevoAlumno(array $datos = []): Alumno
    {
        static $dni = 40000100;

        return Alumno::create(array_merge([
            'apellido'         => 'Apellido',
            'nombre'           => 'Nombre',
            'genero'           => 'F',
            'dni'              => (string) $dni++,
            'anio'             => $this->curso->anio,
            'division'         => $this->curso->division,
            'estado'           => 'Activo',
            'fecha_nacimiento' => '2010-05-14',
        ], $datos));
    }

    protected function cuenta(string $rol): User
    {
        $this->assertArrayHasKey($rol, $this->cuentas, "No hay cuenta de prueba para el rol '{$rol}'");

        return $this->cuentas[$rol]->fresh();
    }

    /** Entra por la puerta real (POST /api/login) y devuelve el token Bearer. */
    protected function tokenDe(string $rol): string
    {
        if (isset($this->tokens[$rol])) {
            return $this->tokens[$rol];
        }

        $respuesta = $this->postJson('/api/login', [
            'email'    => $this->cuenta($rol)->email,
            'password' => self::CLAVE,
        ]);
        $respuesta->assertOk();

        $token = $respuesta->json('token');
        $this->assertIsString($token, 'El login no devolvió token');

        return $this->tokens[$rol] = $token;
    }

    /** Deja el token de ese rol puesto para los próximos pedidos. */
    protected function como(string $rol): static
    {
        return $this->withToken($this->tokenDe($rol));
    }

    /** Vuelve a pedir sin sesión, como un navegador que todavía no entró. */
    protected function sinSesion(): static
    {
        return $this->flushHeaders();
    }
}