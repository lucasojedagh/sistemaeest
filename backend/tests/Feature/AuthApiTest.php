<?php

namespace Tests\Feature;

use App\Dominios\Usuarios\ApiSesion;
use App\Dominios\Usuarios\User;
use Illuminate\Support\Facades\Cache;

// Entrar, salir y recuperar la cuenta: la puerta del sistema. Todo pasa por
// HTTP real, con el middleware de sesión y el limitador de intentos puestos.
class AuthApiTest extends ApiTestCase
{
    public function test_el_login_devuelve_token_y_los_datos_de_la_cuenta(): void
    {
        $r = $this->postJson('/api/login', [
            'email'    => 'preceptor@abc.gob.ar',
            'password' => self::CLAVE,
        ]);

        $r->assertOk()
            ->assertJsonPath('user.role', 'preceptor')
            ->assertJsonPath('user.email', 'preceptor@abc.gob.ar')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.debe_cambiar_password', false);

        $this->assertNotEmpty($r->json('token'));
        $this->assertDatabaseHas('api_sesiones', [
            'user_id' => $this->cuenta('preceptor')->id,
            'token'   => $r->json('token'),
        ]);
        // El ingreso queda anotado en el historial con nombre e id, no "desconocido".
        $this->assertDatabaseHas('activity_logs', [
            'action_type' => 'user.login',
            'action'      => 'Inició sesión',
            'user_id'     => $this->cuenta('preceptor')->id,
            'user_email'  => 'preceptor@abc.gob.ar',
        ]);
    }

    public function test_la_clave_incorrecta_no_entra(): void
    {
        $this->postJson('/api/login', [
            'email' => 'preceptor@abc.gob.ar', 'password' => 'otra-clave',
        ])->assertStatus(422)->assertJsonPath('message', 'Credenciales inválidas');

        $this->assertDatabaseCount('api_sesiones', 0);
    }

    public function test_un_email_que_no_existe_no_entra(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nadie@abc.gob.ar', 'password' => self::CLAVE,
        ])->assertStatus(422);
    }

    public function test_la_cuenta_pendiente_no_entra_hasta_que_la_verifiquen(): void
    {
        $this->postJson('/api/login', [
            'email' => 'pendiente@abc.gob.ar', 'password' => self::CLAVE,
        ])->assertStatus(403);

        $this->assertDatabaseCount('api_sesiones', 0);
    }

    public function test_entrar_desde_otro_equipo_no_tira_abajo_la_sesion_anterior(): void
    {
        $primero = $this->tokenDe('preceptor');

        // El mismo usuario entra de nuevo (otra pestaña, el celular).
        $segundo = $this->postJson('/api/login', [
            'email' => 'preceptor@abc.gob.ar', 'password' => self::CLAVE,
        ])->assertOk()->json('token');

        $this->assertNotSame($primero, $segundo);
        $this->sinSesion()->withToken($primero)->getJson('/api/me')->assertOk();
        $this->sinSesion()->withToken($segundo)->getJson('/api/me')->assertOk();
    }

    public function test_se_cierran_los_equipos_mas_viejos_al_pasar_de_diez(): void
    {
        $userId = $this->cuenta('preceptor')->id;

        // Once equipos ya abiertos, del más viejo al más nuevo. Se arman por
        // modelo y no entrando once veces: el limitador de login corta a los
        // cinco intentos por minuto y acá se prueba el límite de equipos.
        $tokens = [];
        for ($i = 0; $i < 11; $i++) {
            $tokens[$i] = 'token-equipo-' . $i;
            ApiSesion::create([
                'user_id'       => $userId,
                'token'         => $tokens[$i],
                'dispositivo'   => 'Equipo ' . $i,
                'ip_address'    => '127.0.0.1',
                'user_agent'    => 'Prueba',
                'ultimo_uso_at' => now()->subMinutes(11 - $i),
            ]);
        }

        $nuevo = $this->postJson('/api/login', [
            'email' => 'preceptor@abc.gob.ar', 'password' => self::CLAVE,
        ])->assertOk()->json('token');

        // Quedan los nueve más recientes más el que acaba de entrar.
        $this->assertSame(10, ApiSesion::where('user_id', $userId)->count());
        $this->assertDatabaseMissing('api_sesiones', ['token' => $tokens[0]]);
        $this->assertDatabaseMissing('api_sesiones', ['token' => $tokens[1]]);
        $this->assertDatabaseHas('api_sesiones', ['token' => $tokens[10]]);
        $this->assertDatabaseHas('api_sesiones', ['token' => $nuevo]);

        $this->sinSesion()->withToken($tokens[0])->getJson('/api/me')->assertUnauthorized();
        $this->sinSesion()->withToken($nuevo)->getJson('/api/me')->assertOk();
    }

    public function test_el_sexto_intento_seguido_queda_bloqueado(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'preceptor@abc.gob.ar', 'password' => 'mal',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'preceptor@abc.gob.ar', 'password' => 'mal',
        ])->assertStatus(429);

        // El bloqueo es por cuenta: otra persona de la misma escuela sigue entrando.
        $this->postJson('/api/login', [
            'email' => 'profesor@abc.gob.ar', 'password' => self::CLAVE,
        ])->assertOk();
    }

    public function test_sin_token_o_con_token_inventado_no_pasa(): void
    {
        $this->sinSesion()->getJson('/api/me')->assertUnauthorized();
        $this->sinSesion()->withToken('inventado')->getJson('/api/me')->assertUnauthorized();
        $this->sinSesion()->getJson('/api/alumnos')->assertUnauthorized();
    }

    public function test_me_devuelve_rol_y_alcance(): void
    {
        $r = $this->como('profesor')->getJson('/api/me')->assertOk();

        $r->assertJsonPath('role', 'profesor')
            ->assertJsonPath('nivel', 1)
            ->assertJsonPath('rol_nombre', 'Profesor');

        // El profesor nace con la escritura de asistencia en "ninguno".
        $this->assertSame('ninguno', $r->json('alcance.asistencia.editar'));
        $this->assertSame('asignados', $r->json('alcance.calificaciones.editar'));

        // Sin fila en roles_config el rol de sistema entra por su rango: null
        // significa "el front no le filtra nada", no "no tiene permisos".
        $this->assertNull($r->json('permisos'));
    }

    public function test_el_admin_no_trae_lista_de_permisos(): void
    {
        // null = entra por su rango, el front no le filtra nada.
        $this->como('admin')->getJson('/api/me')->assertOk()->assertJsonPath('permisos', null);
    }

    public function test_el_preceptor_ve_todo_el_padron(): void
    {
        $r = $this->como('preceptor')->getJson('/api/me/ambito')->assertOk();

        $r->assertJsonPath('role', 'preceptor');
        // null = sin restricción de lectura: ve todos los cursos aunque solo
        // tenga asignado 1° 1.ª para escribir.
        $this->assertNull($r->json('asignaciones'));
    }

    public function test_ambito_del_profesor_incluye_la_materia_que_dicta(): void
    {
        $r = $this->como('profesor')->getJson('/api/me/ambito')->assertOk();

        $this->assertSame([['anio' => 1, 'division' => '1']], $r->json('asignaciones'));
        $this->assertSame('Matemática', $r->json('materias.0.materia'));
        $this->assertSame($this->materia->id, $r->json('materias.0.materia_id'));
    }

    public function test_el_logout_cierra_este_equipo(): void
    {
        $token = $this->tokenDe('preceptor');

        $this->como('preceptor')->postJson('/api/logout')->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseCount('api_sesiones', 0);
        $this->sinSesion()->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_salir_de_todos_lados_cierra_tambien_los_otros_equipos(): void
    {
        $primero = $this->tokenDe('preceptor');
        $this->postJson('/api/login', [
            'email' => 'preceptor@abc.gob.ar', 'password' => self::CLAVE,
        ])->assertOk();

        $this->como('preceptor')->postJson('/api/logout?todos=1')->assertOk();

        $this->assertDatabaseCount('api_sesiones', 0);
        $this->sinSesion()->withToken($primero)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_sesiones_abiertas_marca_cual_es_la_de_este_equipo(): void
    {
        $this->como('preceptor')->getJson('/api/auth/sesiones')->assertOk()
            ->assertJsonPath('sesiones.0.es_esta', true)
            ->assertJsonCount(1, 'sesiones');
    }

    public function test_cerrar_sesiones_pide_ids_o_todos(): void
    {
        $this->como('preceptor')->postJson('/api/auth/sesiones/cerrar', [])
            ->assertStatus(422);
    }

    public function test_cambiar_la_clave_pide_la_actual(): void
    {
        $this->como('preceptor')->putJson('/api/profile', [
            'name' => 'Preceptor', 'email' => 'preceptor@abc.gob.ar', 'password' => 'ClaveNueva2026',
        ])->assertStatus(422)->assertJsonValidationErrors('password_actual');

        $this->assertDatabaseCount('api_sesiones', 1);
    }

    public function test_cambiar_la_clave_con_la_actual_cierra_los_otros_equipos(): void
    {
        $token = $this->tokenDe('preceptor');
        $otro = $this->postJson('/api/login', [
            'email' => 'preceptor@abc.gob.ar', 'password' => self::CLAVE,
        ])->assertOk()->json('token');

        $this->como('preceptor')->putJson('/api/profile', [
            'name'            => 'Preceptor',
            'email'           => 'preceptor@abc.gob.ar',
            'password'        => 'ClaveNueva2026',
            'password_actual' => self::CLAVE,
        ])->assertOk()->assertJsonPath('debe_cambiar_password', false);

        // La sesión que hizo el cambio sigue abierta; la del otro equipo, no.
        $this->sinSesion()->withToken($token)->getJson('/api/me')->assertOk();
        $this->sinSesion()->withToken($otro)->getJson('/api/me')->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => 'preceptor@abc.gob.ar', 'password' => 'ClaveNueva2026',
        ])->assertOk();
    }

    public function test_el_perfil_no_acepta_un_correo_que_no_es_del_colegio(): void
    {
        $this->como('preceptor')->putJson('/api/profile', [
            'name' => 'Preceptor', 'email' => 'preceptor@gmail.com',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_confirmar_la_clave_chequea_la_propia_clave_de_cualquier_cuenta(): void
    {
        $this->como('admin')->postJson('/api/auth/confirm-password', ['password' => 'incorrecta'])
            ->assertStatus(422);
        $this->assertFalse((bool) Cache::get('pwdconfirm_' . $this->cuenta('admin')->id));

        $this->como('admin')->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertTrue((bool) Cache::get('pwdconfirm_' . $this->cuenta('admin')->id));

        // El preceptor también tiene acciones que piden la clave (deshacer una
        // clase propia, borrar un documento). Si no pudiera confirmarla, esas
        // acciones quedaban bloqueadas para siempre con un 403.
        $this->como('preceptor')->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])
            ->assertOk();
        $this->assertTrue((bool) Cache::get('pwdconfirm_' . $this->cuenta('preceptor')->id));
    }

    public function test_la_prosecretaria_tambien_confirma_la_clave(): void
    {
        $this->como('prosecretaria')->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])
            ->assertOk();
    }

    public function test_el_registro_publico_queda_pendiente_y_sin_rol(): void
    {
        $this->postJson('/api/register', [
            'name'                  => 'Nueva',
            'apellido'              => 'Persona',
            'dni'                   => '41111111',
            'email'                 => 'nueva@abc.gob.ar',
            'password'              => self::CLAVE,
            'password_confirmation' => self::CLAVE,
            'rol_solicitado'        => 'preceptor',
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'nueva@abc.gob.ar', 'role' => 'sin_rol', 'status' => 'pending',
            'rol_solicitado' => 'preceptor',
        ]);

        // Todavía no puede entrar.
        $this->postJson('/api/login', [
            'email' => 'nueva@abc.gob.ar', 'password' => self::CLAVE,
        ])->assertForbidden();
    }

    public function test_nadie_se_autoregala_un_rol_de_gestion(): void
    {
        foreach (['admin', 'directivo', 'jefe_preceptores'] as $rol) {
            $this->postJson('/api/register', [
                'name' => 'Colado', 'email' => 'colado-' . $rol . '@abc.gob.ar',
                'password' => self::CLAVE, 'password_confirmation' => self::CLAVE,
                'rol_solicitado' => $rol,
            ])->assertStatus(422)->assertJsonValidationErrors('rol_solicitado');
        }

        $this->assertSame(0, User::where('email', 'like', 'colado-%')->count());
    }

    public function test_el_registro_rechaza_correo_ajeno_y_duplicado(): void
    {
        $base = [
            'name' => 'Persona', 'password' => self::CLAVE, 'password_confirmation' => self::CLAVE,
        ];

        $this->postJson('/api/register', $base + ['email' => 'persona@gmail.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        // El duplicado lo frena la validación, antes de tocar la base.
        $this->postJson('/api/register', $base + ['email' => 'preceptor@abc.gob.ar'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(8, User::count());
    }

    public function test_el_ticket_de_impresion_sirve_solo_para_las_vistas_imprimibles(): void
    {
        $ticket = $this->como('preceptor')->postJson('/api/print/ticket')->assertOk()->json('ticket');
        $this->assertNotEmpty($ticket);

        // Sin Authorization pero con ticket: la planilla imprimible abre.
        $this->sinSesion()->get('/calificaciones/planilla?ticket=' . $ticket
            . '&anio=1&division=1&materia_id=' . $this->materia->id)->assertOk();

        // El mismo ticket NO sirve para leer la API: si se filtra en un log,
        // no puede abrir el padrón entero.
        $this->sinSesion()->getJson('/api/alumnos?ticket=' . $ticket)->assertUnauthorized();
    }

    public function test_una_cuenta_deshabilitada_pierde_el_acceso(): void
    {
        $token = $this->tokenDe('profesor');
        $this->cuenta('profesor')->update(['status' => 'rejected']);
        Cache::forget('auth_token_' . $token);

        $this->sinSesion()->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    /**
     * El 401 no siempre pasa desapercibido: el iframe de la planilla pinta el
     * JSON en pantalla y un fetch lo muestra en el toast. Con "Unauthorized" el
     * usuario lee una palabra en inglés y ninguna explicación.
     */
    public function test_el_401_se_lee_en_espanol(): void
    {
        $iframe = $this->sinSesion()->get('/calificaciones/planilla?anio=1&division=1');
        $iframe->assertUnauthorized();
        $this->assertStringNotContainsString('Unauthorized', (string) $iframe->getContent());

        $api = $this->sinSesion()->withToken('token-inventado')->getJson('/api/me');
        $api->assertUnauthorized();
        $this->assertStringContainsString('sesión', (string) $api->json('message'));
    }
}