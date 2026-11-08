<?php

namespace Tests\Feature;

use App\Dominios\Asistencia\Clase;
use App\Dominios\Cursos\HorarioCelda;
use App\Dominios\Profesores\Profesor;
use App\Dominios\Profesores\ProfesorAsignacion;
use App\Dominios\Usuarios\ApiSesion;
use App\Dominios\Usuarios\RolConfig;
use App\Dominios\Usuarios\User;
use App\Dominios\Usuarios\UserAnioDivision;
use App\Support\Alcances;
use App\Support\Permisos;
use App\Support\RoleHierarchy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Red de pruebas del módulo Usuarios: administración de cuentas, asignaciones de
 * cursos, clave temporal y la pestaña "Roles y permisos".
 *
 * Entra por POST /api/login con el token real, así se prueba el middleware
 * completo (auth.token + permiso + role) y no una versión simulada.
 *
 * El eje de este módulo es la jerarquía: quién puede tocar a quién, y que el
 * rango Admin siga siendo único e inalcanzable desde cualquier pantalla.
 */
class UsuariosApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El throttle de login y de clave temporal no tiene que frenar la suite.
        Cache::flush();
    }

    // ========================= HELPERS =========================

    private function confirmar(User|string $cuenta = 'admin'): void
    {
        $pedido = $cuenta instanceof User ? $this->comoCuenta($cuenta) : $this->como($cuenta);
        $pedido->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])
            ->assertOk();
    }

    private function nuevaCuenta(string $rol, array $datos = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name'     => 'Nombre',
            'apellido' => 'Apellido' . $n,
            'email'    => 'cuenta' . $n . '@abc.gob.ar',
            'password' => self::CLAVE,
            'role'     => $rol,
            'status'   => 'active',
        ], $datos));
    }

    private function payloadAlta(array $cambios = []): array
    {
        return array_merge([
            'name'     => 'Marta',
            'apellido' => 'Gómez',
            'email'    => 'marta.gomez@abc.gob.ar',
            'password' => self::CLAVE,
            'role'     => 'preceptor',
        ], $cambios);
    }

    private function guardarPermisos(string $rol, array $permitidos, array $negados = [], string $tipo = 'sistema'): RolConfig
    {
        $fila = RolConfig::updateOrCreate(
            ['rol' => $rol],
            [
                'nombre'     => ucfirst(str_replace('_', ' ', $rol)),
                'nivel'      => RoleHierarchy::level($rol),
                'tipo'       => $tipo,
                'permitidos' => $permitidos,
                'negados'    => $negados,
            ]
        );
        RolConfig::olvidarRegistro();

        return $fila;
    }

    private function quitarPermiso(string $rol, string $clave): void
    {
        $this->guardarPermisos($rol, array_values(array_filter(
            Permisos::base($rol), fn ($c) => $c !== $clave
        )), [$clave]);
    }

    /** Entra con una cuenta suelta (rol personalizado o recién creada) y devuelve su token. */
    private function tokenDeCuenta(User $u, string $clave = self::CLAVE): string
    {
        $r = $this->sinSesion()->postJson('/api/login', ['email' => $u->email, 'password' => $clave]);
        $r->assertOk();

        return $r->json('token');
    }

    private function comoCuenta(User $u, string $clave = self::CLAVE): static
    {
        return $this->sinSesion()->withToken($this->tokenDeCuenta($u, $clave));
    }

    // ========================= CATÁLOGO DE ROLES =========================

    public function test_el_catalogo_de_roles_no_ofrece_el_rango_admin(): void
    {
        // El admin es una sola cuenta: no puede aparecer como opción para nadie,
        // ni siquiera para el propio admin.
        $r = $this->como('admin')->getJson('/api/roles')->assertOk();

        $this->assertNotContains('admin', $r->json());
        foreach (['directivo', 'jefe_preceptores', 'prosecretaria', 'preceptor', 'profesor'] as $rol) {
            $this->assertContains($rol, $r->json());
        }
        // sin_rol no es un rango que se asigna: es la cuenta pendiente de aprobar.
        $this->assertNotContains('sin_rol', $r->json());
    }

    public function test_el_catalogo_de_roles_suma_los_personalizados(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 2);

        $r = $this->como('preceptor')->getJson('/api/roles')->assertOk();

        $this->assertContains('biblioteca', $r->json());
        $this->assertNotContains('admin', $r->json());
    }

    public function test_roles_detalle_lo_puede_leer_cualquier_cuenta_autenticada(): void
    {
        // Lo usa el front para rotular el rol en el menú y en el historial: sin
        // esto una cuenta sin rol no puede ni dibujar la pantalla.
        $this->crearRolPersonalizado('Biblioteca', 2);

        foreach (self::ROLES as $rol) {
            $r = $this->como($rol)->getJson('/api/roles/detalle')->assertOk();
            $this->assertSame('Biblioteca', $r->json('biblioteca.nombre'));
            $this->assertSame('personalizado', $r->json('biblioteca.tipo'));
            $this->assertSame(2, $r->json('biblioteca.nivel'));
            $this->assertSame('sistema', $r->json('preceptor.tipo'));
        }
    }

    public function test_roles_detalle_no_filtra_la_configuracion_de_permisos(): void
    {
        $r = $this->como('profesor')->getJson('/api/roles/detalle')->assertOk();

        $this->assertSame(['nombre', 'nivel', 'tipo'], array_keys($r->json('admin')));
    }

    // ========================= LISTADO DE CUENTAS =========================

    public function test_el_listado_de_cuentas_exige_el_permiso_usuarios_ver(): void
    {
        $this->como('admin')->getJson('/api/admin/users')->assertOk();
        $this->como('directivo')->getJson('/api/admin/users')->assertOk();
        $this->como('jefe_preceptores')->getJson('/api/admin/users')->assertOk();
        $this->como('preceptor')->getJson('/api/admin/users')->assertOk();

        // profesor y sin_rol no tienen la casilla (nivel 2 del catálogo).
        $this->como('profesor')->getJson('/api/admin/users')->assertForbidden();
        $this->como('sin_rol')->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_quitar_la_casilla_ver_usuarios_deja_afuera_al_preceptor(): void
    {
        $this->quitarPermiso('preceptor', 'usuarios.ver');

        $this->como('preceptor')->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_el_listado_filtra_por_un_rol_y_por_varios(): void
    {
        $this->nuevaCuenta('preceptor');
        $this->nuevaCuenta('profesor');

        $uno = $this->como('admin')->getJson('/api/admin/users?role=preceptor')->assertOk();
        $this->assertSame(
            ['preceptor'],
            array_values(array_unique(array_column($uno->json('data'), 'role')))
        );
        $this->assertCount(2, $uno->json('data'));

        $varios = $this->como('admin')
            ->getJson('/api/admin/users?role=preceptor,profesor')->assertOk();
        $roles = array_values(array_unique(array_column($varios->json('data'), 'role')));
        sort($roles);
        $this->assertSame(['preceptor', 'profesor'], $roles);
    }

    public function test_el_listado_trae_las_asignaciones_cuando_se_piden(): void
    {
        $r = $this->como('admin')
            ->getJson('/api/admin/users?role=preceptor&with_assignments=1')->assertOk();

        $fila = $r->json('data.0');
        $this->assertArrayHasKey('asignaciones', $fila);
        $this->assertArrayNotHasKey('anio_divisiones', $fila);
        $this->assertSame(1, $fila['asignaciones'][0]['anio']);
        $this->assertSame('1', (string) $fila['asignaciones'][0]['division']);
    }

    public function test_el_listado_pagina_y_respeta_el_tope(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->nuevaCuenta('profesor');
        }

        $r = $this->como('admin')->getJson('/api/admin/users?per_page=2')->assertOk();
        $this->assertCount(2, $r->json('data'));
        $this->assertSame(13, $r->json('total'));

        // El tope del sistema evita que un per_page gigante cargue toda la tabla.
        $grande = $this->como('admin')->getJson('/api/admin/users?per_page=99999')->assertOk();
        $this->assertLessThanOrEqual(200, $grande->json('per_page'));
    }

    public function test_el_listado_esconde_el_dni_sin_la_casilla_de_datos_personales(): void
    {
        $this->cuenta('profesor')->update(['dni' => '30000001']);
        $this->quitarPermiso('preceptor', 'ficha.datos_personales');

        $r = $this->como('preceptor')->getJson('/api/admin/users?role=profesor')->assertOk();

        $this->assertArrayNotHasKey('dni', $r->json('data.0'));
        $this->assertArrayHasKey('email', $r->json('data.0'));
    }

    public function test_el_listado_muestra_el_dni_con_la_casilla(): void
    {
        $this->cuenta('profesor')->update(['dni' => '30000001']);

        $r = $this->como('admin')->getJson('/api/admin/users?role=profesor')->assertOk();

        $this->assertSame('30000001', $r->json('data.0.dni'));
    }

    public function test_el_listado_nunca_devuelve_la_clave_ni_el_token(): void
    {
        $r = $this->como('admin')->getJson('/api/admin/users')->assertOk();

        foreach ($r->json('data') as $fila) {
            $this->assertArrayNotHasKey('password', $fila);
            $this->assertArrayNotHasKey('api_token', $fila);
            $this->assertArrayNotHasKey('remember_token', $fila);
        }
    }

    // ========================= CUENTAS PENDIENTES =========================

    public function test_las_pendientes_traen_solo_las_que_esperan_verificacion(): void
    {
        $this->nuevaCuenta('sin_rol', ['status' => 'pending', 'rol_solicitado' => 'profesor']);

        $r = $this->como('admin')->getJson('/api/admin/users/pending')->assertOk();

        $this->assertSame(2, $r->json('total'));
        foreach ($r->json('data') as $fila) {
            $this->assertSame('pending', $fila['status']);
        }
        // La más vieja primero: es el orden de la cola de aprobación.
        $this->assertSame('pendiente@abc.gob.ar', $r->json('data.0.email'));
    }

    public function test_las_pendientes_exigen_la_casilla_aprobar(): void
    {
        $this->como('admin')->getJson('/api/admin/users/pending')->assertOk();
        $this->como('jefe_preceptores')->getJson('/api/admin/users/pending')->assertOk();

        // El preceptor lee cuentas pero no aprueba registros.
        $this->como('preceptor')->getJson('/api/admin/users/pending')->assertForbidden();
        $this->como('profesor')->getJson('/api/admin/users/pending')->assertForbidden();
    }

    public function test_las_pendientes_tambien_esconden_el_dni_sin_la_casilla(): void
    {
        // Misma regla que el listado: el DNI es un dato personal y la casilla
        // ficha.datos_personales decide en las dos pantallas, no en una sola.
        $this->cuenta('pendiente')->update(['dni' => '30000009']);

        $conCasilla = $this->como('admin')->getJson('/api/admin/users/pending')->assertOk();
        $this->assertSame('30000009', $conCasilla->json('data.0.dni'));

        $this->crearRolPersonalizado('Aprobador', 3, ['usuarios.aprobar', 'usuarios.ver']);
        $aprobador = $this->nuevaCuenta('aprobador');

        $sinCasilla = $this->comoCuenta($aprobador)->getJson('/api/admin/users/pending')->assertOk();
        $this->assertArrayNotHasKey('dni', $sinCasilla->json('data.0'));
    }

    // ========================= VERIFICAR CUENTAS =========================

    public function test_aprobar_una_cuenta_le_da_el_rol_real(): void
    {
        $pendiente = $this->cuenta('pendiente');

        $r = $this->como('admin')->putJson('/api/admin/users/' . $pendiente->id . '/verify', [
            'status' => 'active', 'role' => 'preceptor',
        ])->assertOk();

        $this->assertSame('active', $r->json('status'));
        $this->assertSame('preceptor', $r->json('role'));
        $this->assertSame('preceptor', $pendiente->fresh()->role);

        // Ya aprobada, la cuenta puede entrar.
        $this->comoCuenta($pendiente->fresh())->getJson('/api/me')->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action_type' => 'user.aprobar', 'auditable_id' => $pendiente->id,
        ]);
    }

    public function test_aprobar_sin_rol_deja_el_que_trae_la_cuenta(): void
    {
        $pendiente = $this->nuevaCuenta('profesor', ['status' => 'pending']);

        $this->como('admin')->putJson('/api/admin/users/' . $pendiente->id . '/verify', [
            'status' => 'active',
        ])->assertOk();

        $this->assertSame('profesor', $pendiente->fresh()->role);
        $this->assertSame('active', $pendiente->fresh()->status);
    }

    public function test_rechazar_una_cuenta_le_cierra_todos_los_equipos(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $token = $this->tokenDeCuenta($u);
        $this->assertGreaterThan(0, ApiSesion::where('user_id', $u->id)->count());

        $this->como('admin')->putJson('/api/admin/users/' . $u->id . '/verify', [
            'status' => 'rejected',
        ])->assertOk();

        $this->assertSame('rejected', $u->fresh()->status);
        $this->assertNull($u->fresh()->api_token);
        $this->assertSame(0, ApiSesion::where('user_id', $u->id)->count());

        // El token que estaba en uso ya no sirve.
        $this->sinSesion()->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'user.rechazar']);
    }

    public function test_verificar_no_regala_el_rango_admin(): void
    {
        $pendiente = $this->cuenta('pendiente');

        $this->como('admin')->putJson('/api/admin/users/' . $pendiente->id . '/verify', [
            'status' => 'active', 'role' => 'admin',
        ])->assertStatus(422)->assertJsonPath('message', RoleHierarchy::mensajeRolAdminUnico());

        $this->assertSame('sin_rol', $pendiente->fresh()->role);
        $this->assertSame('pending', $pendiente->fresh()->status);
    }

    public function test_verificar_no_asigna_un_rol_superior_al_propio(): void
    {
        $pendiente = $this->cuenta('pendiente');

        $this->como('jefe_preceptores')->putJson('/api/admin/users/' . $pendiente->id . '/verify', [
            'status' => 'active', 'role' => 'directivo',
        ])->assertForbidden();

        $this->assertSame('pending', $pendiente->fresh()->status);
    }

    public function test_verificar_valida_el_estado_y_el_rol(): void
    {
        $id = $this->cuenta('pendiente')->id;

        $this->como('admin')->putJson('/api/admin/users/' . $id . '/verify', [])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->como('admin')->putJson('/api/admin/users/' . $id . '/verify', ['status' => 'cualquiera'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->como('admin')->putJson('/api/admin/users/' . $id . '/verify', ['status' => 'active', 'role' => 'inventado'])
            ->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    public function test_verificar_una_cuenta_inexistente_da_404(): void
    {
        $this->como('admin')->putJson('/api/admin/users/999999/verify', ['status' => 'active'])
            ->assertNotFound();
    }

    public function test_el_jefe_no_verifica_cuentas_de_gestion(): void
    {
        $directivo = $this->cuenta('directivo');

        $this->como('jefe_preceptores')->putJson('/api/admin/users/' . $directivo->id . '/verify', [
            'status' => 'rejected',
        ])->assertForbidden();

        $this->assertSame('active', $directivo->fresh()->status);
    }

    // ========================= ASIGNACIONES DE CURSOS =========================

    public function test_leer_las_asignaciones_de_una_cuenta(): void
    {
        $preceptor = $this->cuenta('preceptor');
        UserAnioDivision::create(['user_id' => $preceptor->id, 'anio' => 2, 'division' => '2']);

        $r = $this->como('admin')->getJson('/api/admin/users/' . $preceptor->id . '/assignments')->assertOk();

        $this->assertCount(2, $r->json());
        $this->assertSame(1, $r->json('0.anio'));
        $this->assertSame(2, $r->json('1.anio'));
    }

    public function test_guardar_asignaciones_reemplaza_la_lista_entera(): void
    {
        $preceptor = $this->cuenta('preceptor');

        $r = $this->como('admin')->putJson('/api/admin/users/' . $preceptor->id . '/assignments', [
            'asignaciones' => [
                ['anio' => 2, 'division' => '2'],
                ['anio' => 1, 'division' => '1'],
            ],
        ])->assertOk();

        $this->assertCount(2, $r->json());
        // Ordenadas por año y división, sin importar el orden en que llegaron.
        $this->assertSame(1, $r->json('0.anio'));
        $this->assertSame(2, $r->json('1.anio'));
        $this->assertDatabaseMissing('user_anio_division', [
            'user_id' => $preceptor->id, 'anio' => 3,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'user.assignments']);
    }

    public function test_guardar_asignaciones_acepta_la_lista_vacia(): void
    {
        // Sacarle todos los cursos a una cuenta es una operación válida: la
        // cuenta queda sin alcance y hay que poder hacerlo desde la pantalla.
        $preceptor = $this->cuenta('preceptor');

        $r = $this->como('admin')->putJson('/api/admin/users/' . $preceptor->id . '/assignments', [
            'asignaciones' => [],
        ])->assertOk();

        $this->assertSame([], $r->json());
        $this->assertSame(0, UserAnioDivision::where('user_id', $preceptor->id)->count());
    }

    public function test_guardar_asignaciones_sin_la_clave_asignaciones_da_422(): void
    {
        $this->como('admin')->putJson('/api/admin/users/' . $this->cuenta('preceptor')->id . '/assignments', [])
            ->assertStatus(422)->assertJsonValidationErrors(['asignaciones']);
    }

    public function test_guardar_asignaciones_rechaza_duplicados(): void
    {
        $this->como('admin')->putJson('/api/admin/users/' . $this->cuenta('preceptor')->id . '/assignments', [
            'asignaciones' => [
                ['anio' => 1, 'division' => '1'],
                ['anio' => 1, 'division' => '1'],
            ],
        ])->assertStatus(422);

        // No dejó la lista a medias.
        $this->assertSame(1, UserAnioDivision::where('user_id', $this->cuenta('preceptor')->id)->count());
    }

    public function test_guardar_asignaciones_normaliza_la_division_vacia_a_null(): void
    {
        // División null = todo el año. La cadena vacía no puede quedar guardada
        // como un valor distinto de null porque el alcance compara contra null.
        $r = $this->como('admin')->putJson('/api/admin/users/' . $this->cuenta('preceptor')->id . '/assignments', [
            'asignaciones' => [['anio' => 1, 'division' => '']],
        ])->assertOk();

        $this->assertNull($r->json('0.division'));
        $this->assertDatabaseHas('user_anio_division', [
            'user_id' => $this->cuenta('preceptor')->id, 'anio' => 1, 'division' => null,
        ]);
    }

    public function test_guardar_asignaciones_valida_el_anio_contra_el_ciclo(): void
    {
        $max = \App\Support\SchoolYear::maxYear();

        $this->como('admin')->putJson('/api/admin/users/' . $this->cuenta('preceptor')->id . '/assignments', [
            'asignaciones' => [['anio' => $max + 1, 'division' => '1']],
        ])->assertStatus(422)->assertJsonValidationErrors(['asignaciones.0.anio']);

        $this->como('admin')->putJson('/api/admin/users/' . $this->cuenta('preceptor')->id . '/assignments', [
            'asignaciones' => [['anio' => 0, 'division' => '1']],
        ])->assertStatus(422)->assertJsonValidationErrors(['asignaciones.0.anio']);
    }

    public function test_guardar_asignaciones_no_toca_nada_si_no_cambio(): void
    {
        $preceptor = $this->cuenta('preceptor');
        $this->como('admin'); // el login deja su propia entrada en el historial
        $antes = DB::table('activity_logs')->count();

        $this->como('admin')->putJson('/api/admin/users/' . $preceptor->id . '/assignments', [
            'asignaciones' => [['anio' => 1, 'division' => '1']],
        ])->assertOk();

        $this->assertSame($antes, DB::table('activity_logs')->count());
    }

    public function test_guardar_asignaciones_de_un_profesor_sincroniza_sus_materias(): void
    {
        $profesor = $this->cuenta('profesor');

        $this->como('admin')->putJson('/api/admin/users/' . $profesor->id . '/assignments', [
            'asignaciones' => [
                ['anio' => 1, 'division' => '1', 'materias' => [$this->materia->id, $this->otraMateria->id]],
            ],
        ])->assertOk();

        $this->assertSame(2, ProfesorAsignacion::where('profesor_id', $this->profesor->id)->count());
        $this->assertDatabaseHas('profesor_asignaciones', [
            'profesor_id' => $this->profesor->id, 'materia_id' => $this->otraMateria->id,
            'anio' => 1, 'division' => '1',
        ]);
    }

    public function test_desmarcar_una_materia_la_saca_de_la_ficha_del_profesor(): void
    {
        $profesor = $this->cuenta('profesor');
        ProfesorAsignacion::create([
            'profesor_id' => $this->profesor->id, 'materia_id' => $this->otraMateria->id,
            'anio' => 1, 'division' => '1',
        ]);

        $this->como('admin')->putJson('/api/admin/users/' . $profesor->id . '/assignments', [
            'asignaciones' => [
                ['anio' => 1, 'division' => '1', 'materias' => [$this->materia->id]],
            ],
        ])->assertOk();

        $this->assertSame(1, ProfesorAsignacion::where('profesor_id', $this->profesor->id)->count());
        $this->assertDatabaseMissing('profesor_asignaciones', [
            'profesor_id' => $this->profesor->id, 'materia_id' => $this->otraMateria->id,
        ]);
    }

    public function test_las_materias_de_un_curso_no_incluido_quedan_intactas(): void
    {
        $profesor = $this->cuenta('profesor');
        ProfesorAsignacion::create([
            'profesor_id' => $this->profesor->id, 'materia_id' => $this->materia->id,
            'anio' => 2, 'division' => '2',
        ]);

        $this->como('admin')->putJson('/api/admin/users/' . $profesor->id . '/assignments', [
            'asignaciones' => [
                ['anio' => 1, 'division' => '1', 'materias' => [$this->otraMateria->id]],
                ['anio' => 2, 'division' => '2'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('profesor_asignaciones', [
            'profesor_id' => $this->profesor->id, 'materia_id' => $this->materia->id,
            'anio' => 2, 'division' => '2',
        ]);
    }

    public function test_una_materia_que_no_existe_no_puede_guardarse_en_la_ficha(): void
    {
        // profesor_asignaciones.materia_id tiene clave foránea: sin validar el
        // "exists", un id inventado llegaba hasta MySQL y devolvía 500.
        $profesor = $this->cuenta('profesor');

        $this->como('admin')->putJson('/api/admin/users/' . $profesor->id . '/assignments', [
            'asignaciones' => [
                ['anio' => 1, 'division' => '1', 'materias' => [999999]],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['asignaciones.0.materias.0']);

        $this->assertSame(1, ProfesorAsignacion::where('profesor_id', $this->profesor->id)->count());
    }

    public function test_guardar_materias_le_crea_la_ficha_al_profesor_que_no_tiene(): void
    {
        $nuevo = $this->nuevaCuenta('profesor', ['name' => 'Sin', 'apellido' => 'Ficha']);
        $this->assertNull(Profesor::where('email', $nuevo->email)->first());

        $this->como('admin')->putJson('/api/admin/users/' . $nuevo->id . '/assignments', [
            'asignaciones' => [
                ['anio' => 1, 'division' => '1', 'materias' => [$this->materia->id]],
            ],
        ])->assertOk();

        $ficha = Profesor::where('email', $nuevo->email)->first();
        $this->assertNotNull($ficha);
        $this->assertSame('Sin', $ficha->nombre);
        $this->assertSame('Ficha', $ficha->apellido);
        $this->assertSame(1, ProfesorAsignacion::where('profesor_id', $ficha->id)->count());
    }

    public function test_las_materias_se_ignoran_en_una_cuenta_que_no_es_profesor(): void
    {
        $preceptor = $this->cuenta('preceptor');

        $this->como('admin')->putJson('/api/admin/users/' . $preceptor->id . '/assignments', [
            'asignaciones' => [
                ['anio' => 1, 'division' => '1', 'materias' => [$this->materia->id]],
            ],
        ])->assertOk();

        $this->assertNull(Profesor::where('email', $preceptor->email)->first());
    }

    public function test_guardar_asignaciones_exige_el_permiso_usuarios_editar(): void
    {
        $this->quitarPermiso('jefe_preceptores', 'usuarios.editar');

        $this->como('jefe_preceptores')->putJson('/api/admin/users/' . $this->cuenta('preceptor')->id . '/assignments', [
            'asignaciones' => [],
        ])->assertForbidden();
    }

    public function test_el_preceptor_no_guarda_asignaciones_de_cuentas(): void
    {
        $this->como('preceptor')->putJson('/api/admin/users/' . $this->cuenta('profesor')->id . '/assignments', [
            'asignaciones' => [],
        ])->assertForbidden();
    }

    public function test_guardar_asignaciones_respeta_la_jerarquia_de_cuentas(): void
    {
        // En todas las demás escrituras el jefe no toca a un directivo ni al
        // admin. Las asignaciones de cursos son datos de la cuenta: la misma
        // regla tiene que valer acá.
        $directivo = $this->cuenta('directivo');
        UserAnioDivision::create(['user_id' => $directivo->id, 'anio' => 1, 'division' => '1']);

        $this->como('jefe_preceptores')->putJson('/api/admin/users/' . $directivo->id . '/assignments', [
            'asignaciones' => [],
        ])->assertForbidden();

        $this->assertSame(1, UserAnioDivision::where('user_id', $directivo->id)->count());
    }

    // ========================= ALTA DE CUENTAS =========================

    public function test_crear_una_cuenta_devuelve_201_y_la_deja_activa(): void
    {
        $r = $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta())->assertCreated();

        $this->assertSame('preceptor', $r->json('role'));
        $this->assertSame('active', $r->json('status'));
        $this->assertArrayNotHasKey('password', $r->json());
        $this->assertDatabaseHas('users', ['email' => 'marta.gomez@abc.gob.ar', 'role' => 'preceptor']);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'user.store']);

        // La cuenta entra con la clave que se le cargó.
        $this->comoCuenta(User::where('email', 'marta.gomez@abc.gob.ar')->first())
            ->getJson('/api/me')->assertOk();
    }

    public function test_crear_un_profesor_le_crea_la_ficha_en_el_modulo(): void
    {
        $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta([
            'role' => 'profesor', 'dni' => '30123456',
        ]))->assertCreated();

        $ficha = Profesor::where('email', 'marta.gomez@abc.gob.ar')->first();
        $this->assertNotNull($ficha);
        $this->assertSame('Marta', $ficha->nombre);
        $this->assertSame('Gómez', $ficha->apellido);
        $this->assertSame('30123456', $ficha->dni);
        $this->assertSame('Activo', $ficha->estado);
    }

    public function test_crear_un_preceptor_no_le_crea_ficha_de_profesor(): void
    {
        $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta())->assertCreated();

        $this->assertNull(Profesor::where('email', 'marta.gomez@abc.gob.ar')->first());
    }

    public function test_nadie_crea_una_cuenta_con_el_rango_admin(): void
    {
        // Ni el propio admin: el rango es de una sola cuenta y no se reparte.
        $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta(['role' => 'admin']))
            ->assertStatus(422)->assertJsonPath('message', RoleHierarchy::mensajeRolAdminUnico());

        $this->assertDatabaseMissing('users', ['email' => 'marta.gomez@abc.gob.ar']);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_no_se_crea_una_cuenta_con_un_rol_superior_al_propio(): void
    {
        $this->como('jefe_preceptores')->postJson('/api/admin/users', $this->payloadAlta(['role' => 'directivo']))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'marta.gomez@abc.gob.ar']);
    }

    public function test_el_alta_valida_los_campos_obligatorios(): void
    {
        $this->como('admin')->postJson('/api/admin/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'apellido', 'email', 'password', 'role']);
    }

    public function test_el_alta_exige_correo_institucional(): void
    {
        $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta(['email' => 'marta@gmail.com']))
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_el_alta_rechaza_un_correo_repetido(): void
    {
        $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta())->assertCreated();

        $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta())
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_el_alta_valida_el_formato_del_dni(): void
    {
        foreach (['12345', '123456789', '30A12345', ''] as $dni) {
            $r = $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta([
                'dni' => $dni, 'email' => 'dni' . md5($dni) . '@abc.gob.ar',
            ]));
            if ($dni === '') {
                // El DNI es opcional: vacío se guarda como null.
                $r->assertCreated();
            } else {
                $r->assertStatus(422)->assertJsonValidationErrors(['dni']);
            }
        }
    }

    public function test_el_alta_rechaza_un_dni_repetido(): void
    {
        $this->cuenta('profesor')->update(['dni' => '30123456']);

        $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta(['dni' => '30123456']))
            ->assertStatus(422)->assertJsonValidationErrors(['dni']);
    }

    public function test_el_alta_exige_una_clave_de_ocho_caracteres(): void
    {
        $this->como('admin')->postJson('/api/admin/users', $this->payloadAlta(['password' => 'corta']))
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_el_alta_exige_el_permiso_usuarios_crear(): void
    {
        $this->quitarPermiso('jefe_preceptores', 'usuarios.crear');

        $this->como('jefe_preceptores')->postJson('/api/admin/users', $this->payloadAlta())
            ->assertForbidden();
        $this->como('preceptor')->postJson('/api/admin/users', $this->payloadAlta())
            ->assertForbidden();
    }

    public function test_un_rol_personalizado_no_crea_cuentas_aunque_tenga_el_rango(): void
    {
        // roles.configurar y las acciones de usuarios no se reparten por rango:
        // la lista guardada del rol personalizado es la que manda.
        $this->crearRolPersonalizado('Gestion', 5, ['alumnos.ver']);
        $cuenta = $this->nuevaCuenta('gestion');

        $this->comoCuenta($cuenta)->postJson('/api/admin/users', $this->payloadAlta())
            ->assertForbidden();
    }

    // ========================= EDICIÓN DE DATOS =========================

    public function test_editar_los_datos_de_una_cuenta(): void
    {
        $u = $this->nuevaCuenta('profesor', ['dni' => '30123456']);

        $r = $this->como('admin')->putJson('/api/admin/users/' . $u->id, [
            'name' => 'Marta', 'apellido' => 'Gómez',
            'email' => 'marta.editada@abc.gob.ar', 'dni' => '30654321',
        ])->assertOk();

        $this->assertSame('Marta', $r->json('name'));
        $this->assertSame('marta.editada@abc.gob.ar', $r->json('email'));
        $this->assertSame('30654321', $u->fresh()->dni);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'user.update']);
    }

    public function test_editar_sin_cambios_no_ensucia_el_historial(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $this->como('admin'); // el login deja su propia entrada en el historial
        $antes = DB::table('activity_logs')->count();

        $this->como('admin')->putJson('/api/admin/users/' . $u->id, [
            'name' => $u->name, 'apellido' => $u->apellido, 'email' => $u->email,
        ])->assertOk();

        $this->assertSame($antes, DB::table('activity_logs')->count());
    }

    public function test_editar_el_correo_mueve_la_ficha_del_profesor(): void
    {
        // La ficha se enlaza por e-mail: cambiar el correo de la cuenta sin mover
        // la ficha deja al profesor sin sus cursos en el módulo Profesores.
        $u = $this->cuenta('profesor');

        $this->como('admin')->putJson('/api/admin/users/' . $u->id, [
            'name' => $u->name, 'apellido' => $u->apellido, 'email' => 'profesor.nuevo@abc.gob.ar',
        ])->assertOk();

        $this->assertSame('profesor.nuevo@abc.gob.ar', $this->profesor->fresh()->email);
        $this->assertSame(1, ProfesorAsignacion::where('profesor_id', $this->profesor->id)->count());
    }

    public function test_editar_el_correo_no_mezcla_dos_fichas(): void
    {
        // Si ya hay otra ficha con el correo nuevo, no se toca ninguna: mezclar
        // los datos de dos personas es peor que dejar el enlace roto.
        $otra = Profesor::create([
            'nombre' => 'Otra', 'apellido' => 'Persona',
            'email' => 'ocupado@abc.gob.ar', 'estado' => 'Activo',
        ]);
        $u = $this->cuenta('profesor');
        $emailViejo = $u->email;

        $this->como('admin')->putJson('/api/admin/users/' . $u->id, [
            'name' => $u->name, 'apellido' => $u->apellido, 'email' => 'ocupado@abc.gob.ar',
        ])->assertOk();

        $this->assertSame($emailViejo, $this->profesor->fresh()->email);
        $this->assertSame('ocupado@abc.gob.ar', $otra->fresh()->email);
        $this->assertSame(2, Profesor::count());
    }

    public function test_editar_la_clave_de_otra_cuenta_le_cierra_los_equipos(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $token = $this->tokenDeCuenta($u);

        $this->como('admin')->putJson('/api/admin/users/' . $u->id, [
            'name' => $u->name, 'apellido' => $u->apellido, 'email' => $u->email,
            'password' => 'ClaveNueva2026',
        ])->assertOk();

        $this->assertNull($u->fresh()->api_token);
        $this->assertSame(0, ApiSesion::where('user_id', $u->id)->count());
        $this->sinSesion()->withToken($token)->getJson('/api/me')->assertUnauthorized();

        // El historial marca el cambio pero nunca guarda la clave ni el hash.
        $fila = DB::table('activity_logs')->where('action_type', 'user.update')->first();
        $this->assertNotNull($fila, 'Cambiar la clave de otra cuenta tiene que dejar huella en el historial');
        $this->assertStringNotContainsString('ClaveNueva2026', json_encode($fila));
        $this->assertStringNotContainsString('$2y$', json_encode($fila));
        $this->assertSame('actualizada', json_decode($fila->new_values, true)['Contraseña']);
    }

    public function test_editar_valida_el_correo_y_el_dni(): void
    {
        $u = $this->nuevaCuenta('profesor');

        $this->como('admin')->putJson('/api/admin/users/' . $u->id, [
            'name' => 'X', 'apellido' => 'Y', 'email' => 'ajeno@gmail.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->como('admin')->putJson('/api/admin/users/' . $u->id, [
            'name' => 'X', 'apellido' => 'Y', 'email' => $u->email, 'dni' => str_repeat('9', 16),
        ])->assertStatus(422)->assertJsonValidationErrors(['dni']);
    }

    public function test_editar_el_dni_tiene_el_mismo_formato_que_el_alta(): void
    {
        // El alta exige de 6 a 8 dígitos; la edición no puede aceptar letras ni
        // un número de otro largo, porque después el padrón no lo puede leer.
        $u = $this->nuevaCuenta('profesor');

        foreach (['12345', '123456789', '30A12345'] as $dni) {
            $this->como('admin')->putJson('/api/admin/users/' . $u->id, [
                'name' => $u->name, 'apellido' => $u->apellido, 'email' => $u->email, 'dni' => $dni,
            ])->assertStatus(422)->assertJsonValidationErrors(['dni']);
        }

        $this->como('admin')->putJson('/api/admin/users/' . $u->id, [
            'name' => $u->name, 'apellido' => $u->apellido, 'email' => $u->email, 'dni' => '30123456',
        ])->assertOk();
    }

    public function test_nadie_edita_una_cuenta_de_rango_superior(): void
    {
        $admin = $this->cuenta('admin');
        $directivo = $this->cuenta('directivo');

        $this->como('jefe_preceptores')->putJson('/api/admin/users/' . $directivo->id, [
            'name' => 'X', 'apellido' => 'Y', 'email' => $directivo->email,
        ])->assertForbidden();

        $this->como('directivo')->putJson('/api/admin/users/' . $admin->id, [
            'name' => 'X', 'apellido' => 'Y', 'email' => $admin->email,
        ])->assertForbidden();
    }

    public function test_el_preceptor_solo_edita_datos_de_profesores_de_sus_cursos(): void
    {
        $profesor = $this->cuenta('profesor');

        // Su profesor (1° 1.ª): puede editar el nombre.
        $this->como('preceptor')->putJson('/api/admin/users/' . $profesor->id, [
            'name' => 'NombreEditado',
        ])->assertOk();
        $this->assertSame('NombreEditado', $profesor->fresh()->name);
    }

    public function test_el_preceptor_no_le_cambia_el_correo_ni_el_dni_a_un_profesor(): void
    {
        // El correo es el enlace con la ficha: si el preceptor lo cambia, el
        // profesor pierde sus cursos. La pantalla ni siquiera ofrece el campo.
        $profesor = $this->cuenta('profesor');
        $email = $profesor->email;
        $profesor->update(['dni' => '30123456']);

        $this->como('preceptor')->putJson('/api/admin/users/' . $profesor->id, [
            'name' => 'Otro', 'apellido' => 'Apellido',
            'email' => 'cambiado@abc.gob.ar', 'dni' => '30999999',
        ])->assertOk();

        $this->assertSame($email, $profesor->fresh()->email);
        $this->assertSame('30123456', $profesor->fresh()->dni);
        // El apellido tampoco: la pantalla del preceptor solo ofrece el nombre.
        $this->assertSame('DePrueba', $profesor->fresh()->apellido);
        $this->assertSame($email, $this->profesor->fresh()->email);
    }

    public function test_el_preceptor_no_edita_a_un_profesor_fuera_de_sus_cursos(): void
    {
        $ajeno = $this->nuevaCuenta('profesor');
        Profesor::create([
            'nombre' => $ajeno->name, 'apellido' => $ajeno->apellido,
            'email' => $ajeno->email, 'estado' => 'Activo',
        ]);
        ProfesorAsignacion::create([
            'profesor_id' => Profesor::where('email', $ajeno->email)->first()->id,
            'materia_id' => $this->materia->id, 'anio' => 2, 'division' => '2',
        ]);

        $this->como('preceptor')->putJson('/api/admin/users/' . $ajeno->id, ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_el_preceptor_no_edita_cuentas_que_no_son_profesores(): void
    {
        $otroPreceptor = $this->nuevaCuenta('preceptor');

        $this->como('preceptor')->putJson('/api/admin/users/' . $otroPreceptor->id, ['name' => 'X'])
            ->assertForbidden();
        $this->como('preceptor')->putJson('/api/admin/users/' . $this->cuenta('admin')->id, ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_editar_exige_el_permiso_usuarios_editar(): void
    {
        $this->quitarPermiso('preceptor', 'usuarios.editar');

        $this->como('preceptor')->putJson('/api/admin/users/' . $this->cuenta('profesor')->id, ['name' => 'X'])
            ->assertForbidden();
    }

    // ========================= CLAVE TEMPORAL =========================

    public function test_la_clave_temporal_se_entra_con_el_formato_para_dictar(): void
    {
        $u = $this->nuevaCuenta('profesor');

        $r = $this->como('admin')->postJson('/api/admin/users/' . $u->id . '/clave-temporal')->assertOk();

        $this->assertSame($u->id, $r->json('id'));
        $this->assertSame($u->email, $r->json('email'));
        $this->assertMatchesRegularExpression('/^[a-z]{4}-[a-z]{4}-\d{4}$/', $r->json('clave'));
        $this->assertTrue($u->fresh()->debe_cambiar_password);
    }

    public function test_la_clave_temporal_vieja_deja_de_servir(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $token = $this->tokenDeCuenta($u);
        $clave = $this->como('admin')->postJson('/api/admin/users/' . $u->id . '/clave-temporal')
            ->assertOk()->json('clave');

        // La sesión abierta se cierra: la clave que la sostenía ya no vale.
        $this->sinSesion()->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->assertSame(0, ApiSesion::where('user_id', $u->id)->count());

        // Con la clave vieja no entra; con la nueva sí, y queda marcada para cambiarla.
        $this->sinSesion()->postJson('/api/login', ['email' => $u->email, 'password' => self::CLAVE])
            ->assertStatus(422);
        $login = $this->sinSesion()->postJson('/api/login', ['email' => $u->email, 'password' => $clave])
            ->assertOk();
        $this->assertTrue((bool) $login->json('user.debe_cambiar_password') || (bool) $login->json('debe_cambiar_password'));
    }

    public function test_la_clave_temporal_no_queda_en_el_historial(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $clave = $this->como('admin')->postJson('/api/admin/users/' . $u->id . '/clave-temporal')
            ->assertOk()->json('clave');

        $fila = DB::table('activity_logs')->where('action_type', 'user.clave_temporal')->first();
        $this->assertNotNull($fila);
        $this->assertStringNotContainsString($clave, json_encode($fila));
        $this->assertNull($fila->old_values);
        $this->assertNull($fila->new_values);
    }

    public function test_nadie_se_genera_una_clave_temporal_a_si_mismo(): void
    {
        // Una tecla mal dada dejaría afuera al propio operador, y con la cuenta
        // administrativa no hay quien la recupere.
        $admin = $this->cuenta('admin');

        $this->como('admin')->postJson('/api/admin/users/' . $admin->id . '/clave-temporal')
            ->assertStatus(422);
        $this->assertFalse((bool) $admin->fresh()->debe_cambiar_password);
    }

    public function test_no_se_genera_clave_temporal_para_una_cuenta_sin_aprobar(): void
    {
        $pendiente = $this->cuenta('pendiente');

        $this->como('admin')->postJson('/api/admin/users/' . $pendiente->id . '/clave-temporal')
            ->assertStatus(422);
    }

    public function test_la_clave_temporal_respeta_la_jerarquia(): void
    {
        $this->como('jefe_preceptores')->postJson('/api/admin/users/' . $this->cuenta('directivo')->id . '/clave-temporal')
            ->assertForbidden();
        $this->como('directivo')->postJson('/api/admin/users/' . $this->cuenta('admin')->id . '/clave-temporal')
            ->assertForbidden();
    }

    public function test_la_clave_temporal_exige_su_casilla(): void
    {
        $this->quitarPermiso('jefe_preceptores', 'usuarios.clave_temporal');

        $this->como('jefe_preceptores')->postJson('/api/admin/users/' . $this->cuenta('profesor')->id . '/clave-temporal')
            ->assertForbidden();
    }

    public function test_el_admin_conserva_la_clave_temporal_aunque_se_desmarque_todo(): void
    {
        // PISO_ADMIN: la única cuenta que puede corregir el sistema no se puede
        // quedar afuera de su propia pantalla de roles.
        $this->guardarPermisos('admin', []);

        $this->assertTrue(Permisos::puede('admin', 'usuarios.clave_temporal'));
        $this->assertTrue(Permisos::puede('admin', 'roles.configurar'));
        $this->como('admin')->getJson('/api/admin/roles')->assertOk();
    }

    // ========================= CAMBIO DE ROL =========================

    public function test_cambiar_el_rol_de_una_cuenta(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $this->confirmar();

        $r = $this->como('admin')->putJson('/api/admin/users/' . $u->id . '/role', ['role' => 'preceptor'])
            ->assertOk();

        $this->assertSame('preceptor', $r->json('role'));
        $this->assertSame('preceptor', $u->fresh()->role);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'user.role']);
    }

    public function test_cambiar_el_rol_pide_confirmar_la_clave(): void
    {
        $u = $this->nuevaCuenta('profesor');

        $this->como('admin')->putJson('/api/admin/users/' . $u->id . '/role', ['role' => 'preceptor'])
            ->assertStatus(422);
        $this->assertSame('profesor', $u->fresh()->role);
    }

    public function test_el_cambio_de_rol_se_aplica_en_los_equipos_ya_abiertos(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $token = $this->tokenDeCuenta($u);
        $this->confirmar();

        $this->como('admin')->putJson('/api/admin/users/' . $u->id . '/role', ['role' => 'preceptor'])
            ->assertOk();

        // El token cacheado traía el rol viejo: sin invalidarlo, la cuenta
        // seguiría viendo el menú de profesor hasta que expire la caché.
        $me = $this->sinSesion()->withToken($token)->getJson('/api/me')->assertOk();
        $this->assertSame('preceptor', $me->json('user.role') ?? $me->json('role'));
    }

    public function test_nadie_le_da_el_rango_admin_a_otra_cuenta(): void
    {
        $u = $this->nuevaCuenta('directivo');
        $this->confirmar();

        $this->como('admin')->putJson('/api/admin/users/' . $u->id . '/role', ['role' => 'admin'])
            ->assertStatus(422)->assertJsonPath('message', RoleHierarchy::mensajeRolAdminUnico());

        $this->assertSame('directivo', $u->fresh()->role);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_al_admin_no_se_le_quita_su_rango(): void
    {
        // Si se reasignara la cuenta admin, el sistema se queda sin la única
        // cuenta que puede crear otra.
        $admin = $this->cuenta('admin');
        $this->confirmar();

        $this->como('admin')->putJson('/api/admin/users/' . $admin->id . '/role', ['role' => 'directivo'])
            ->assertStatus(422);

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_no_se_asigna_un_rol_superior_al_propio(): void
    {
        $u = $this->nuevaCuenta('preceptor');
        $this->confirmar('jefe_preceptores');

        $this->como('jefe_preceptores')->putJson('/api/admin/users/' . $u->id . '/role', ['role' => 'directivo'])
            ->assertForbidden();
        $this->assertSame('preceptor', $u->fresh()->role);
    }

    public function test_nadie_se_eleva_el_rol_a_si_mismo(): void
    {
        $jefe = $this->cuenta('jefe_preceptores');
        $this->confirmar('jefe_preceptores');

        $this->como('jefe_preceptores')->putJson('/api/admin/users/' . $jefe->id . '/role', ['role' => 'directivo'])
            ->assertForbidden();
        $this->assertSame('jefe_preceptores', $jefe->fresh()->role);
    }

    public function test_el_jefe_no_cambia_el_rol_de_una_cuenta_de_gestion(): void
    {
        $directivo = $this->cuenta('directivo');
        $this->confirmar('jefe_preceptores');

        $this->como('jefe_preceptores')->putJson('/api/admin/users/' . $directivo->id . '/role', ['role' => 'preceptor'])
            ->assertForbidden();
    }

    public function test_cambiar_el_rol_valida_el_valor(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $this->confirmar();

        $this->como('admin')->putJson('/api/admin/users/' . $u->id . '/role', [])
            ->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->como('admin')->putJson('/api/admin/users/' . $u->id . '/role', ['role' => 'inventado'])
            ->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    public function test_cambiar_el_rol_exige_su_casilla(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $this->confirmar('jefe_preceptores');
        $this->quitarPermiso('jefe_preceptores', 'usuarios.rol');

        $this->como('jefe_preceptores')->putJson('/api/admin/users/' . $u->id . '/role', ['role' => 'preceptor'])
            ->assertForbidden();
    }

    public function test_se_puede_ascender_a_un_rol_personalizado(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 2);
        $u = $this->nuevaCuenta('profesor');
        $this->confirmar();

        $this->como('admin')->putJson('/api/admin/users/' . $u->id . '/role', ['role' => 'biblioteca'])
            ->assertOk();

        $this->assertSame('biblioteca', $u->fresh()->role);
    }

    // ========================= BAJA DE CUENTAS =========================

    public function test_eliminar_una_cuenta_limpia_sus_datos_vinculados(): void
    {
        $u = $this->nuevaCuenta('preceptor');
        UserAnioDivision::create(['user_id' => $u->id, 'anio' => 1, 'division' => '1']);
        $clase = Clase::create([
            'curso_id' => $this->curso->id, 'fecha' => '2026-03-10', 'numero_clase' => 1,
            'responsable_id' => $u->id, 'tipo_responsable' => 'preceptor', 'estado' => 'dictada',
        ]);
        DB::table('activity_logs')->insert([
            'user_id' => $u->id, 'user_email' => $u->email, 'user_role' => 'preceptor',
            'action' => 'Hizo algo', 'action_type' => 'prueba', 'created_at' => now(),
        ]);
        $this->confirmar();

        $this->como('admin')->deleteJson('/api/admin/users/' . $u->id)->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('users', ['id' => $u->id]);
        $this->assertSame(0, UserAnioDivision::where('user_id', $u->id)->count());
        // El historial no se borra: queda el registro, pero sin el id de una
        // cuenta que ya no existe.
        $this->assertNull($clase->fresh()->responsable_id);
        $this->assertSame(0, DB::table('activity_logs')->where('user_id', $u->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'user.destroy']);
    }

    public function test_eliminar_un_profesor_libera_su_horario_y_borra_la_ficha(): void
    {
        $u = $this->cuenta('profesor');
        $celda = HorarioCelda::create([
            'curso_id' => $this->curso->id, 'dia' => 1, 'slot_key' => 'h1',
            'hora_inicio' => '07:30', 'hora_fin' => '08:30',
            'materia_id' => $this->materia->id, 'profesor_id' => $this->profesor->id,
        ]);
        $this->confirmar();

        $this->como('admin')->deleteJson('/api/admin/users/' . $u->id)->assertOk();

        $this->assertDatabaseMissing('profesores', ['id' => $this->profesor->id]);
        $this->assertSame(0, ProfesorAsignacion::where('profesor_id', $this->profesor->id)->count());
        // La celda queda: el horario del curso no se puede borrar por dar de baja
        // una cuenta. Solo se le quita el profesor.
        $this->assertNotNull($celda->fresh());
        $this->assertNull($celda->fresh()->profesor_id);
    }

    public function test_eliminar_una_cuenta_le_cierra_los_equipos(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $token = $this->tokenDeCuenta($u);
        $this->confirmar();

        $this->como('admin')->deleteJson('/api/admin/users/' . $u->id)->assertOk();

        $this->sinSesion()->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->assertSame(0, ApiSesion::where('user_id', $u->id)->count());
    }

    public function test_nadie_elimina_su_propia_cuenta(): void
    {
        $admin = $this->cuenta('admin');
        $this->confirmar();

        $this->como('admin')->deleteJson('/api/admin/users/' . $admin->id)->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_eliminar_pide_confirmar_la_clave(): void
    {
        $u = $this->nuevaCuenta('profesor');

        $this->como('admin')->deleteJson('/api/admin/users/' . $u->id)->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $u->id]);
    }

    public function test_eliminar_respeta_la_jerarquia(): void
    {
        $this->confirmar('jefe_preceptores');

        $this->como('jefe_preceptores')->deleteJson('/api/admin/users/' . $this->cuenta('directivo')->id)
            ->assertForbidden();
        $this->como('jefe_preceptores')->deleteJson('/api/admin/users/' . $this->cuenta('admin')->id)
            ->assertForbidden();

        $this->confirmar('directivo');
        $this->como('directivo')->deleteJson('/api/admin/users/' . $this->cuenta('admin')->id)
            ->assertForbidden();
    }

    public function test_eliminar_exige_su_casilla(): void
    {
        $u = $this->nuevaCuenta('profesor');
        $this->confirmar('jefe_preceptores');
        $this->quitarPermiso('jefe_preceptores', 'usuarios.eliminar');

        $this->como('jefe_preceptores')->deleteJson('/api/admin/users/' . $u->id)->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $u->id]);
    }

    public function test_eliminar_una_cuenta_inexistente_da_404(): void
    {
        $this->confirmar();

        $this->como('admin')->deleteJson('/api/admin/users/999999')->assertNotFound();
    }

    // ========================= ROLES Y PERMISOS: ACCESO =========================

    public function test_la_pestaña_de_roles_es_solo_del_admin_y_del_jefe(): void
    {
        $this->como('admin')->getJson('/api/admin/roles')->assertOk();
        $this->como('jefe_preceptores')->getJson('/api/admin/roles')->assertOk();

        // El middleware role: es jerárquico y dejaría pasar a estos dos rangos:
        // el filtro por nombre exacto lo hace el controlador.
        foreach (['directivo', 'prosecretaria', 'preceptor', 'profesor', 'sin_rol'] as $rol) {
            $this->como($rol)->getJson('/api/admin/roles')->assertForbidden();
        }
    }

    public function test_la_pestaña_de_roles_trae_el_catalogo_y_los_roles(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 2);

        $r = $this->como('admin')->getJson('/api/admin/roles')->assertOk();

        $this->assertNotEmpty($r->json('bloques'));
        $this->assertNotEmpty($r->json('plantillas'));
        $this->assertSame(Permisos::PISO_ADMIN, $r->json('piso_admin'));
        $this->assertSame(Permisos::SOLO_ADMIN, $r->json('solo_admin'));
        $this->assertSame(5, $r->json('mi_nivel'));
        $this->assertSame(User::count(), $r->json('total_usuarios'));

        $roles = array_column($r->json('roles'), null, 'rol');
        $this->assertArrayHasKey('admin', $roles);
        $this->assertArrayHasKey('biblioteca', $roles);
        $this->assertSame('sistema', $roles['admin']['tipo']);
        $this->assertSame('personalizado', $roles['biblioteca']['tipo']);
        $this->assertSame(1, $roles['admin']['usuarios']);
        $this->assertSame(0, $roles['biblioteca']['usuarios']);
        // El jefe no puede tocar al admin ni a otro jefe.
        $this->assertTrue($roles['admin']['editable']);
    }

    public function test_el_jefe_ve_que_no_puede_editar_los_roles_de_gestion(): void
    {
        $r = $this->como('jefe_preceptores')->getJson('/api/admin/roles')->assertOk();
        $roles = array_column($r->json('roles'), null, 'rol');

        $this->assertFalse($roles['admin']['editable']);
        $this->assertFalse($roles['directivo']['editable']);
        $this->assertTrue($roles['jefe_preceptores']['editable']);
        $this->assertTrue($roles['preceptor']['editable']);
    }

    public function test_los_respaldos_solo_aparecen_en_el_rol_admin(): void
    {
        $r = $this->como('admin')->getJson('/api/admin/roles')->assertOk();
        $roles = array_column($r->json('roles'), null, 'rol');

        foreach (Permisos::SOLO_ADMIN as $clave) {
            $this->assertContains($clave, $roles['admin']['permitidos']);
            foreach (['directivo', 'jefe_preceptores', 'prosecretaria', 'preceptor', 'profesor'] as $rol) {
                $this->assertNotContains($clave, $roles[$rol]['permitidos'], "{$rol} no puede tener {$clave}");
            }
        }
    }

    // ========================= ROLES Y PERMISOS: ALTA =========================

    public function test_crear_un_rol_personalizado_devuelve_201(): void
    {
        $r = $this->como('admin')->postJson('/api/admin/roles', [
            'nombre' => 'Biblioteca', 'nivel' => 2,
            'descripcion' => 'Atiende la biblioteca',
            'permitidos' => ['alumnos.ver', 'documentos.ver'],
        ])->assertCreated();

        $this->assertSame('biblioteca', $r->json('rol'));
        $this->assertSame('personalizado', $r->json('tipo'));
        $this->assertSame(2, $r->json('nivel'));
        $this->assertSame(['alumnos.ver', 'documentos.ver'], $r->json('permitidos'));
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'role.store']);
    }

    public function test_el_nombre_del_rol_se_convierte_en_clave_sin_acentos(): void
    {
        $r = $this->como('admin')->postJson('/api/admin/roles', [
            'nombre' => 'Asistente Social', 'nivel' => 2,
        ])->assertCreated();

        $this->assertSame('asistente_social', $r->json('rol'));

        $otro = $this->como('admin')->postJson('/api/admin/roles', [
            'nombre' => 'Coordinación de Matemática', 'nivel' => 2,
        ])->assertCreated();
        $this->assertSame('coordinacion_de_matematica', $otro->json('rol'));
    }

    public function test_no_se_crea_un_rol_con_un_rango_superior_al_propio(): void
    {
        $this->como('jefe_preceptores')->postJson('/api/admin/roles', [
            'nombre' => 'Directivo trucho', 'nivel' => 4,
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles_config', ['rol' => 'directivo_trucho']);
    }

    public function test_no_se_crea_un_rol_con_el_nombre_de_uno_del_sistema(): void
    {
        $this->como('admin')->postJson('/api/admin/roles', ['nombre' => 'Preceptor', 'nivel' => 2])
            ->assertStatus(422);
        $this->como('admin')->postJson('/api/admin/roles', ['nombre' => 'Admin', 'nivel' => 5])
            ->assertStatus(422);

        $this->assertSame(0, RolConfig::count());
    }

    public function test_no_se_crea_un_rol_con_un_nombre_repetido(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 2);

        $this->como('admin')->postJson('/api/admin/roles', ['nombre' => 'Biblioteca', 'nivel' => 2])
            ->assertStatus(422);
        $this->assertSame(1, RolConfig::where('rol', 'biblioteca')->count());
    }

    public function test_un_nombre_sin_letras_no_da_una_clave_vacia(): void
    {
        $this->como('admin')->postJson('/api/admin/roles', ['nombre' => '!!!', 'nivel' => 2])
            ->assertStatus(422);
    }

    public function test_el_alta_de_rol_valida_nombre_y_nivel(): void
    {
        $this->como('admin')->postJson('/api/admin/roles', [])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre', 'nivel']);
        $this->como('admin')->postJson('/api/admin/roles', ['nombre' => 'X', 'nivel' => 6])
            ->assertStatus(422)->assertJsonValidationErrors(['nivel']);
        $this->como('admin')->postJson('/api/admin/roles', ['nombre' => 'X', 'nivel' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['nivel']);
    }

    public function test_crear_un_rol_desde_una_plantilla(): void
    {
        $r = $this->como('admin')->postJson('/api/admin/roles', [
            'nombre' => 'Profesor invitado', 'nivel' => 1, 'plantilla' => 'profesor',
        ])->assertCreated();

        $esperado = Permisos::normalizarLista('profesor_invitado', collect(Permisos::plantillas())
            ->firstWhere('id', 'profesor')['permitidos'], 5);
        $this->assertSame($esperado, $r->json('permitidos'));
        $this->assertContains('calificaciones.editar', $r->json('permitidos'));
        $this->assertNotEmpty($r->json('descripcion'));
    }

    public function test_una_plantilla_desconocida_da_422(): void
    {
        $this->como('admin')->postJson('/api/admin/roles', [
            'nombre' => 'Raro', 'nivel' => 2, 'plantilla' => 'inventada',
        ])->assertStatus(422);
    }

    public function test_crear_un_rol_copiando_otro_trae_sus_permisos_y_su_alcance(): void
    {
        $r = $this->como('admin')->postJson('/api/admin/roles', [
            'nombre' => 'Preceptor suplente', 'nivel' => 2, 'desde_rol' => 'preceptor',
        ])->assertCreated();

        $this->assertSame(Permisos::base('preceptor'), $r->json('permitidos'));
        // El preceptor ve todo el padrón pero solo modifica sus cursos: la copia
        // tiene que traer ese mapa, no el defecto del rango. Se compara el alcance
        // efectivo porque la columna guarda solo los desvíos del defecto.
        $this->assertSame(Alcances::TODO, Alcances::ver('preceptor_suplente', 'alumnos'));
        $this->assertSame(Alcances::ASIGNADO, Alcances::editar('preceptor_suplente', 'alumnos'));
    }

    public function test_copiar_un_rol_que_no_existe_da_422(): void
    {
        $this->como('admin')->postJson('/api/admin/roles', [
            'nombre' => 'Copia', 'nivel' => 2, 'desde_rol' => 'inventado',
        ])->assertStatus(422);
    }

    public function test_copiar_al_admin_no_regala_los_respaldos(): void
    {
        $r = $this->como('admin')->postJson('/api/admin/roles', [
            'nombre' => 'Casi admin', 'nivel' => 5, 'desde_rol' => 'admin',
        ])->assertCreated();

        foreach (Permisos::SOLO_ADMIN as $clave) {
            $this->assertNotContains($clave, $r->json('permitidos'));
        }
        $this->assertNotContains('roles.configurar', $r->json('permitidos'));
    }

    public function test_el_alta_no_regala_acciones_de_un_rango_mayor_al_que_edita(): void
    {
        // El jefe (3) no puede meter en un rol nuevo una acción de rango 4.
        $r = $this->como('jefe_preceptores')->postJson('/api/admin/roles', [
            'nombre' => 'Rol del jefe', 'nivel' => 3,
            'permitidos' => ['alumnos.ver', 'manuales.programador', 'backup.usar', 'inventada'],
        ])->assertCreated();

        $this->assertSame(['alumnos.ver'], $r->json('permitidos'));
    }

    public function test_un_rol_personalizado_nunca_recibe_la_gestion_de_roles(): void
    {
        $r = $this->como('admin')->postJson('/api/admin/roles', [
            'nombre' => 'Gestor', 'nivel' => 5,
            'permitidos' => ['roles.configurar', 'alumnos.ver'],
        ])->assertCreated();

        $this->assertNotContains('roles.configurar', $r->json('permitidos'));
        $this->assertFalse(Permisos::puede('gestor', 'roles.configurar'));
    }

    public function test_el_alta_de_rol_exige_la_casilla_roles_configurar(): void
    {
        // Solo admin y jefe la tienen; el resto ni llega al controlador.
        $this->como('directivo')->postJson('/api/admin/roles', ['nombre' => 'X', 'nivel' => 2])
            ->assertForbidden();
        $this->como('preceptor')->postJson('/api/admin/roles', ['nombre' => 'X', 'nivel' => 2])
            ->assertForbidden();
    }

    // ========================= ROLES Y PERMISOS: EDICIÓN =========================

    public function test_editar_un_rol_del_sistema_le_crea_su_fila(): void
    {
        $this->assertDatabaseMissing('roles_config', ['rol' => 'preceptor']);
        $lista = array_values(array_diff(Permisos::base('preceptor'), ['documentos.eliminar']));

        $r = $this->como('admin')->putJson('/api/admin/roles/preceptor', ['permitidos' => $lista])
            ->assertOk();

        $this->assertSame($lista, $r->json('permitidos'));
        $this->assertDatabaseHas('roles_config', ['rol' => 'preceptor', 'tipo' => 'sistema']);
        $this->assertFalse(Permisos::puede('preceptor', 'documentos.eliminar'));
        $this->assertTrue(Permisos::puede('preceptor', 'documentos.ver'));
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'role.update']);
    }

    public function test_la_quit_de_una_accion_se_respeta_en_la_ruta_real(): void
    {
        $this->quitarPermiso('preceptor', 'documentos.eliminar');
        $doc = DB::table('documentos')->insertGetId([
            'nombre' => 'Nota', 'nombre_archivo' => 'nota.pdf', 'ruta' => 'documentos/nota.pdf',
            'mime' => 'application/pdf', 'tamano' => 1234,
            'roles' => json_encode(['admin']), 'user_id' => $this->cuenta('admin')->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->como('preceptor')->deleteJson('/api/documentos/' . $doc)->assertForbidden();
        $this->assertDatabaseHas('documentos', ['id' => $doc]);
    }

    public function test_volver_a_la_lista_del_rango_borra_la_fila_del_rol(): void
    {
        // Así el rol hereda solo lo del rango y los permisos que se agreguen al
        // catálogo en el futuro, en vez de quedar congelado en una lista vieja.
        $this->guardarPermisos('preceptor', array_values(array_diff(Permisos::base('preceptor'), ['alumnos.padron'])));
        $this->assertDatabaseHas('roles_config', ['rol' => 'preceptor']);

        $this->como('admin')->putJson('/api/admin/roles/preceptor', ['permitidos' => Permisos::base('preceptor')])
            ->assertOk();

        $this->assertDatabaseMissing('roles_config', ['rol' => 'preceptor']);
        $this->assertTrue(Permisos::puede('preceptor', 'alumnos.padron'));
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'role.restore']);
    }

    public function test_editar_solo_el_alcance_no_pisa_las_acciones(): void
    {
        $this->guardarPermisos('preceptor', ['alumnos.ver', 'documentos.ver']);

        $r = $this->como('admin')->putJson('/api/admin/roles/preceptor', [
            'alcance' => ['alumnos' => ['ver' => 'todos', 'editar' => 'ninguno']],
        ])->assertOk();

        $this->assertSame(['alumnos.ver', 'documentos.ver'], $r->json('permitidos'));
        $this->assertSame('ninguno', $r->json('alcance.alumnos.editar'));
    }

    public function test_editar_un_rol_personalizado(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 2, ['alumnos.ver']);

        $r = $this->como('admin')->putJson('/api/admin/roles/biblioteca', [
            'nombre' => 'Biblioteca y archivo',
            'permitidos' => ['alumnos.ver', 'documentos.ver', 'documentos.subir'],
        ])->assertOk();

        $this->assertSame('Biblioteca y archivo', $r->json('nombre'));
        $this->assertContains('documentos.subir', $r->json('permitidos'));
        $this->assertSame('biblioteca', $r->json('rol'));
    }

    public function test_editar_un_rol_que_no_existe_da_404(): void
    {
        $this->como('admin')->putJson('/api/admin/roles/inventado', ['permitidos' => []])
            ->assertNotFound();
    }

    public function test_no_se_sube_un_rol_a_un_rango_superior_al_propio(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 2);

        $this->como('jefe_preceptores')->putJson('/api/admin/roles/biblioteca', ['nivel' => 4])
            ->assertForbidden();

        $this->assertSame(2, RolConfig::where('rol', 'biblioteca')->first()->nivel);
    }

    public function test_el_jefe_no_edita_el_rol_admin_ni_el_directivo(): void
    {
        $this->como('jefe_preceptores')->putJson('/api/admin/roles/admin', ['permitidos' => []])
            ->assertForbidden();
        $this->como('jefe_preceptores')->putJson('/api/admin/roles/directivo', ['permitidos' => []])
            ->assertForbidden();

        $this->assertDatabaseMissing('roles_config', ['rol' => 'admin']);
        $this->assertDatabaseMissing('roles_config', ['rol' => 'directivo']);
    }

    public function test_el_admin_no_se_puede_quedar_sin_la_puerta_de_roles(): void
    {
        $r = $this->como('admin')->putJson('/api/admin/roles/admin', ['permitidos' => ['alumnos.ver']])
            ->assertOk();

        foreach (Permisos::PISO_ADMIN as $clave) {
            $this->assertContains($clave, $r->json('permitidos'));
        }
        $this->assertContains('roles.configurar', $r->json('permitidos'));
        $this->como('admin')->getJson('/api/admin/roles')->assertOk();
    }

    public function test_el_jefe_conserva_la_gestion_de_roles(): void
    {
        $r = $this->como('admin')->putJson('/api/admin/roles/jefe_preceptores', ['permitidos' => ['alumnos.ver']])
            ->assertOk();

        $this->assertContains('roles.configurar', $r->json('permitidos'));
        $this->como('jefe_preceptores')->getJson('/api/admin/roles')->assertOk();
    }

    public function test_la_edicion_de_roles_no_regala_acciones_sobre_el_rango_del_actor(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 2);

        $r = $this->como('jefe_preceptores')->putJson('/api/admin/roles/biblioteca', [
            'permitidos' => ['alumnos.ver', 'manuales.programador'],
        ])->assertOk();

        $this->assertSame(['alumnos.ver'], $r->json('permitidos'));
    }

    public function test_los_modulos_del_menu_se_guardan_en_el_rol(): void
    {
        $r = $this->como('admin')->putJson('/api/admin/roles/preceptor', [
            'modulos' => ['alumnos', 'asistencia', 'dashboard'],
        ])->assertOk();

        $this->assertSame(['alumnos', 'asistencia', 'dashboard'], $r->json('modulos'));
        // Alcances::modulos() devuelve las ids en el orden del menú, no en el que
        // se mandaron: lo que importa es el conjunto.
        $guardados = Alcances::modulos('preceptor');
        sort($guardados);
        $this->assertSame(['alumnos', 'asistencia', 'dashboard'], $guardados);
    }

    public function test_un_modulo_inventado_no_queda_guardado(): void
    {
        $r = $this->como('admin')->putJson('/api/admin/roles/preceptor', [
            'modulos' => ['alumnos', 'modulo_inventado'],
        ])->assertOk();

        $this->assertSame(['alumnos'], $r->json('modulos'));
    }

    public function test_la_casilla_de_roles_se_aplica_tambien_al_editar_y_al_borrar(): void
    {
        // GET y POST del catálogo están cubiertos; PUT y DELETE llevan la clave
        // del rol en la URL (no un número) y también tienen que pasar por el
        // permiso, si no la casilla solo frena la mitad de la pantalla.
        $this->assertSame('roles.configurar', Permisos::rutaPermiso('PUT', 'admin/roles/preceptor'));
        $this->assertSame('roles.configurar', Permisos::rutaPermiso('DELETE', 'admin/roles/biblioteca'));
        $this->assertTrue(Permisos::rutaConocidaRequest(
            \Illuminate\Http\Request::create('/api/admin/roles/preceptor', 'PUT')
        ));

        // Por la vía real (solo quitas, sin mandar la lista) el jefe pierde la
        // casilla: tienen que cerrarse las cuatro puertas, no nada más el listado.
        $this->como('admin')->putJson('/api/admin/roles/jefe_preceptores', [
            'negados' => ['roles.configurar'],
        ])->assertOk();
        $this->assertFalse(Permisos::puede('jefe_preceptores', 'roles.configurar'));

        $this->crearRolPersonalizado('Biblioteca', 2);
        $this->como('jefe_preceptores')->getJson('/api/admin/roles')->assertForbidden();
        $this->como('jefe_preceptores')->postJson('/api/admin/roles', ['nombre' => 'Otro', 'nivel' => 2])
            ->assertForbidden();
        $this->como('jefe_preceptores')->putJson('/api/admin/roles/preceptor', ['permitidos' => Permisos::base('preceptor')])
            ->assertForbidden();
        $this->como('jefe_preceptores')->deleteJson('/api/admin/roles/biblioteca')->assertForbidden();
        $this->assertDatabaseHas('roles_config', ['rol' => 'biblioteca']);
    }

    // ========================= ROLES Y PERMISOS: BAJA =========================

    public function test_eliminar_un_rol_personalizado(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 2);

        $this->como('admin')->deleteJson('/api/admin/roles/biblioteca')->assertOk()
            ->assertJsonPath('rol', 'biblioteca');

        $this->assertDatabaseMissing('roles_config', ['rol' => 'biblioteca']);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'role.destroy']);
    }

    public function test_los_roles_del_sistema_no_se_eliminan(): void
    {
        foreach (['admin', 'directivo', 'jefe_preceptores', 'preceptor', 'profesor'] as $rol) {
            $this->como('admin')->deleteJson('/api/admin/roles/' . $rol)->assertStatus(422);
        }
    }

    public function test_no_se_elimina_un_rol_con_cuentas_adentro(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 2);
        $this->nuevaCuenta('biblioteca');

        $this->como('admin')->deleteJson('/api/admin/roles/biblioteca')->assertStatus(422);
        $this->assertDatabaseHas('roles_config', ['rol' => 'biblioteca']);
    }

    public function test_eliminar_un_rol_inexistente_da_404(): void
    {
        $this->como('admin')->deleteJson('/api/admin/roles/inventado')->assertNotFound();
    }

    public function test_el_jefe_no_elimina_un_rol_de_rango_superior(): void
    {
        $this->crearRolPersonalizado('Direccion auxiliar', 4);

        $this->como('jefe_preceptores')->deleteJson('/api/admin/roles/direccion_auxiliar')
            ->assertForbidden();
        $this->assertDatabaseHas('roles_config', ['rol' => 'direccion_auxiliar']);
    }

    // ========================= EFECTO EN LA CUENTA =========================

    public function test_un_rol_personalizado_usa_solo_su_lista_guardada(): void
    {
        $this->crearRolPersonalizado('Biblioteca', 3, ['alumnos.ver', 'documentos.ver']);
        $u = $this->nuevaCuenta('biblioteca');

        $this->comoCuenta($u)->getJson('/api/alumnos')->assertOk();
        $this->comoCuenta($u)->getJson('/api/documentos')->assertOk();
        // Tiene rango 3 pero no la casilla: el rango no le regala nada.
        $this->comoCuenta($u)->getJson('/api/admin/users')->assertForbidden();
        $this->comoCuenta($u)->postJson('/api/admin/users', $this->payloadAlta())->assertForbidden();
        $this->comoCuenta($u)->getJson('/api/admin/roles')->assertForbidden();
    }

    public function test_un_rol_personalizado_de_rango_cinco_no_toca_los_respaldos(): void
    {
        // Las tres acciones que meten mano a la base entera son de la cuenta
        // Admin y de nadie más, ni por rango ni con un rol a medida.
        $this->crearRolPersonalizado('Casi admin', 5, Permisos::base('admin'));
        $u = $this->nuevaCuenta('casi_admin');

        $this->comoCuenta($u)->getJson('/api/backups/sql/check')->assertForbidden();
        $this->comoCuenta($u)->getJson('/api/backups/sql/download')->assertForbidden();
        $this->comoCuenta($u)->getJson('/api/historial/export')->assertForbidden();
        $this->confirmar($u);
        $this->comoCuenta($u)->deleteJson('/api/historial')->assertForbidden();
    }

    public function test_una_accion_agregada_a_un_rol_de_sistema_se_aplica_en_la_ruta(): void
    {
        // El profesor nace sin tomar asistencia. Dirección le puede dar la
        // casilla desde Roles y permisos y tiene que funcionar de verdad.
        $this->assertFalse(Permisos::puede('profesor', 'asistencia.justificar'));
        $lista = Permisos::base('profesor');
        $lista[] = 'asistencia.justificar';
        $this->guardarPermisos('profesor', array_values(array_unique($lista)));

        $this->assertTrue(Permisos::puede('profesor', 'asistencia.justificar'));
        $this->como('profesor')->postJson('/api/asistencia/justificaciones', [])
            ->assertStatus(422); // pasa el permiso y cae en la validación, no en 403
    }

    public function test_el_catalogo_de_la_pantalla_coincide_con_lo_que_el_backend_acepta(): void
    {
        $r = $this->como('admin')->getJson('/api/admin/roles')->assertOk();
        $claves = [];
        foreach ($r->json('bloques') as $bloque) {
            foreach ($bloque['items'] as $item) {
                $claves[] = $item['clave'];
            }
        }

        $this->assertSame([], array_diff($claves, Permisos::claves()));
        $this->assertSame([], array_diff(Permisos::claves(), $claves));
    }

    // ========================= FIXTURE DE ROLES =========================

    private function crearRolPersonalizado(string $nombre, int $nivel, array $permitidos = []): RolConfig
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(
            strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'])
        )), '_'));

        $fila = RolConfig::create([
            'rol'         => $slug,
            'nombre'      => $nombre,
            'nivel'       => $nivel,
            'tipo'        => 'personalizado',
            'permitidos'  => Permisos::normalizarLista($slug, $permitidos, 5),
            'negados'     => [],
            'alcance'     => [],
            'modulos'     => null,
        ]);
        RolConfig::olvidarRegistro();

        return $fila;
    }
}