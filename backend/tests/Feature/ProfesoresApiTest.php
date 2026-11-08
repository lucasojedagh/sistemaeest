<?php

namespace Tests\Feature;

use App\Dominios\Cursos\HorarioCelda;
use App\Dominios\Cursos\Materia;
use App\Dominios\Profesores\Profesor;
use App\Dominios\Profesores\ProfesorAsignacion;
use App\Dominios\Profesores\ProfesorSuplencia;
use App\Dominios\Sistema\ActivityLog;
use App\Dominios\Usuarios\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Profesores y cadena de suplencias recorridos por HTTP con login real.
 *
 * El fixture deja un profesor (la cuenta 'profesor' enlazada por e-mail) que
 * dicta Matemática en 1.º 1.ª. Todo lo que se pida de 2.º 2.ª sirve para medir
 * el alcance, y los profesores extra se crean acá para armar las cadenas.
 */
class ProfesoresApiTest extends ApiTestCase
{
    /** Deja la contraseña confirmada, como hace el modal antes de una baja. */
    private function confirmar(string $rol = 'admin'): void
    {
        $this->como($rol)->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])
            ->assertOk();
    }

    /** Ficha nueva, con la materia del fixture asignada en 1.º 1.ª. */
    private function nuevoProfesor(string $apellido, array $datos = [], bool $conAsignacion = true): Profesor
    {
        $prof = Profesor::create(array_merge([
            'nombre'   => 'Nombre',
            'apellido' => $apellido,
            'estado'   => 'Activo',
        ], $datos));

        if ($conAsignacion) {
            ProfesorAsignacion::create([
                'profesor_id' => $prof->id,
                'materia_id'  => $this->materia->id,
                'anio'        => 1,
                'division'    => '1',
            ]);
        }

        return $prof;
    }

    private function payload(array $cambios = []): array
    {
        return array_merge([
            'nombre'       => 'Marta',
            'apellido'     => 'Gómez',
            'email'        => 'marta.gomez@abc.gob.ar',
            'estado'       => 'Activo',
            'asignaciones' => [['anio' => 1, 'division' => '1', 'materia' => 'Matemática']],
        ], $cambios);
    }

    private function crearProfesor(array $cambios = [], string $rol = 'admin'): TestResponse
    {
        return $this->como($rol)->postJson('/api/profesores', $this->payload($cambios));
    }

    private function agregarEslabon(Profesor $titular, Profesor $suplente, array $cambios = [], string $rol = 'admin'): TestResponse
    {
        return $this->como($rol)->postJson('/api/profesores/' . $titular->id . '/suplencias', array_merge([
            'suplente_id' => $suplente->id,
            'anio'        => 1,
            'division'    => '1',
            'materia_id'  => $this->materia->id,
        ], $cambios));
    }

    /** Deja al rol sin una casilla puntual, como desde la pantalla de Roles. */
    private function quitarPermiso(string $rol, string $clave): void
    {
        $this->guardarPermisos($rol, array_values(array_filter(
            \App\Support\Permisos::base($rol), fn ($c) => $c !== $clave
        )), [$clave]);
    }

    /** Le suma una casilla que su rango no trae de fábrica. */
    private function darPermiso(string $rol, string $clave): void
    {
        $base = \App\Support\Permisos::base($rol);
        $base[] = $clave;
        $this->guardarPermisos($rol, array_values(array_unique($base)), []);
    }

    private function guardarPermisos(string $rol, array $permitidos, array $negados): void
    {
        \App\Dominios\Usuarios\RolConfig::updateOrCreate(
            ['rol' => $rol],
            [
                'nombre'     => ucfirst(str_replace('_', ' ', $rol)),
                'nivel'      => \App\Support\RoleHierarchy::level($rol),
                'tipo'       => 'sistema',
                'permitidos' => $permitidos,
                'negados'    => $negados,
            ]
        );
        \App\Dominios\Usuarios\RolConfig::olvidarRegistro();
    }

    // ===== Sesión y gating =====

    public function test_el_listado_de_profesores_pide_sesion(): void
    {
        $this->sinSesion()->getJson('/api/profesores')->assertUnauthorized();
    }

    public function test_una_cuenta_sin_rol_no_ve_los_profesores(): void
    {
        $this->como('sin_rol')->getJson('/api/profesores')->assertForbidden();
    }

    public function test_una_cuenta_sin_rol_no_entra_a_las_lecturas_sin_candado_de_rango(): void
    {
        // Estas rutas no llevan middleware 'role:': el catálogo es el único
        // candado. Una cuenta sin rol tiene la base vacía, así que tiene que
        // quedar afuera de todas (antes pasaba porque no tenía fila en roles_config).
        $this->como('sin_rol')->postJson('/api/print/ticket', [])->assertForbidden();

        foreach ([
            '/api/profesores',
            '/api/profesores/mapping?anio=1',
            '/api/documentos',
            '/api/dashboard/resumen',
            '/api/materias/todas',
            '/api/divisiones',
            '/api/asistencia/cursos',
            '/api/manuales',
        ] as $url) {
            $this->como('sin_rol')->getJson($url)->assertForbidden();
        }
    }

    public function test_todos_los_rangos_con_la_casilla_pueden_ver_el_listado(): void
    {
        foreach (['admin', 'directivo', 'jefe_preceptores', 'prosecretaria', 'preceptor', 'profesor'] as $rol) {
            $this->como($rol)->getJson('/api/profesores')->assertOk();
        }
    }

    public function test_la_casilla_de_ver_profesores_manda_de_verdad(): void
    {
        $this->quitarPermiso('preceptor', 'profesores.ver');

        $this->como('preceptor')->getJson('/api/profesores')->assertForbidden();
        $this->como('preceptor')->getJson('/api/profesores/' . $this->profesor->id)->assertForbidden();
        $this->como('preceptor')->getJson('/api/profesores/mapping?anio=1')->assertForbidden();
        $this->como('preceptor')->getJson('/api/profesores/en-horarios?q=DePrueba')->assertForbidden();
        $this->como('preceptor')->getJson('/api/profesores/' . $this->profesor->id . '/suplencias')->assertForbidden();
    }

    public function test_la_casilla_de_editar_profesores_manda_de_verdad(): void
    {
        $this->quitarPermiso('jefe_preceptores', 'profesores.editar');

        $this->crearProfesor([], 'jefe_preceptores')->assertForbidden();
        $this->como('jefe_preceptores')->postJson('/api/profesores/from-users', ['ids' => []])->assertForbidden();
        $this->como('jefe_preceptores')->putJson('/api/profesores/' . $this->profesor->id, $this->payload())
            ->assertForbidden();

        // Lo que no se tocó sigue andando: suplencias y baja tienen casilla propia.
        $suplente = $this->nuevoProfesor('Suplente');
        $this->agregarEslabon($this->profesor, $suplente, [], 'jefe_preceptores')->assertCreated();
    }

    public function test_la_casilla_de_suplencias_manda_de_verdad(): void
    {
        $suplente = $this->nuevoProfesor('Suplente');
        $this->agregarEslabon($this->profesor, $suplente)->assertCreated();
        $id = ProfesorSuplencia::first()->id;

        $this->quitarPermiso('jefe_preceptores', 'profesores.suplencias');

        $this->agregarEslabon($this->profesor, $this->nuevoProfesor('Otro'), [], 'jefe_preceptores')
            ->assertForbidden();
        $this->como('jefe_preceptores')->deleteJson('/api/profesores/suplencias/' . $id)->assertForbidden();

        // La lectura de la cadena no depende de la casilla de gestión.
        $this->como('jefe_preceptores')->getJson('/api/profesores/' . $this->profesor->id . '/suplencias')
            ->assertOk();
    }

    public function test_la_casilla_de_baja_definitiva_manda_de_verdad(): void
    {
        $this->confirmar('jefe_preceptores');
        $this->quitarPermiso('jefe_preceptores', 'profesores.eliminar');

        $this->como('jefe_preceptores')->deleteJson('/api/profesores/' . $this->profesor->id . '/force')
            ->assertForbidden();
        $this->assertDatabaseHas('profesores', ['id' => $this->profesor->id]);
    }

    public function test_el_preceptor_y_el_profesor_no_dan_de_alta_profesores(): void
    {
        foreach (['preceptor', 'profesor', 'sin_rol'] as $rol) {
            $this->crearProfesor(['email' => $rol . '.nuevo@abc.gob.ar'], $rol)->assertForbidden();
        }
        $this->assertSame(1, Profesor::count());
    }

    // ===== Listado =====

    public function test_el_listado_trae_las_asignaciones_con_el_curso_legible(): void
    {
        $r = $this->como('admin')->getJson('/api/profesores')->assertOk();

        $fila = collect($r->json('data'))->firstWhere('id', $this->profesor->id);
        $this->assertNotNull($fila);
        $this->assertSame('DePrueba', $fila['apellido']);
        $this->assertCount(1, $fila['asignaciones']);
        $this->assertSame('1° 1', $fila['asignaciones'][0]['curso']);
        $this->assertSame('Matemática', $fila['asignaciones'][0]['materia']);
        $this->assertSame($this->materia->id, $fila['asignaciones'][0]['materia_id']);
    }

    public function test_el_listado_resuelve_la_cuenta_por_email(): void
    {
        $r = $this->como('admin')->getJson('/api/profesores')->assertOk();
        $fila = collect($r->json('data'))->firstWhere('id', $this->profesor->id);

        $this->assertSame($this->cuentas['profesor']->id, $fila['user_id']);
        $this->assertArrayHasKey('avatar_version', $fila);
    }

    public function test_el_listado_busca_por_apellido_nombre_y_email(): void
    {
        $this->nuevoProfesor('Zapata', ['nombre' => 'Ricardo', 'email' => 'ricardo.zapata@abc.gob.ar']);

        foreach (['zapata', 'Ricardo', 'ricardo.zapata@'] as $q) {
            $r = $this->como('admin')->getJson('/api/profesores?q=' . urlencode($q))->assertOk();
            $this->assertCount(1, $r->json('data'), "La búsqueda '{$q}' no trajo exactamente uno.");
            $this->assertSame('Zapata', $r->json('data.0.apellido'));
        }
    }

    public function test_el_listado_filtra_por_estado_y_por_rol(): void
    {
        $this->nuevoProfesor('Licencia', ['estado' => 'Licencia']);
        $this->nuevoProfesor('Suplente', ['suplente' => true]);

        $licencia = $this->como('admin')->getJson('/api/profesores?estado=Licencia')->assertOk();
        $this->assertCount(1, $licencia->json('data'));
        $this->assertSame('Licencia', $licencia->json('data.0.apellido'));

        $suplentes = $this->como('admin')->getJson('/api/profesores?rol=suplente')->assertOk();
        $this->assertCount(1, $suplentes->json('data'));
        $this->assertSame('Suplente', $suplentes->json('data.0.apellido'));

        $titulares = $this->como('admin')->getJson('/api/profesores?rol=titular')->assertOk();
        $this->assertCount(2, $titulares->json('data'));

        $todos = $this->como('admin')->getJson('/api/profesores?estado=todos&rol=todos')->assertOk();
        $this->assertCount(3, $todos->json('data'));
    }

    public function test_el_listado_filtra_por_curso_y_materia(): void
    {
        $otro = $this->nuevoProfesor('DeOtroCurso', [], false);
        $historia = Materia::where('anio', 2)->where('nombre', 'Historia')->first();
        ProfesorAsignacion::create([
            'profesor_id' => $otro->id, 'materia_id' => $historia->id, 'anio' => 2, 'division' => '2',
        ]);

        $porAnio = $this->como('admin')->getJson('/api/profesores?anio=2')->assertOk();
        $this->assertCount(1, $porAnio->json('data'));
        $this->assertSame('DeOtroCurso', $porAnio->json('data.0.apellido'));

        $porDivision = $this->como('admin')->getJson('/api/profesores?anio=1&division=1')->assertOk();
        $this->assertCount(1, $porDivision->json('data'));

        $porMateria = $this->como('admin')->getJson('/api/profesores?materia=Historia')->assertOk();
        $this->assertCount(1, $porMateria->json('data'));
        $this->assertSame('DeOtroCurso', $porMateria->json('data.0.apellido'));
    }

    public function test_el_listado_se_pagina(): void
    {
        foreach (range(1, 5) as $i) {
            $this->nuevoProfesor('Apellido' . $i);
        }

        $r = $this->como('admin')->getJson('/api/profesores?per_page=2')->assertOk();
        $this->assertCount(2, $r->json('data'));
        $this->assertSame(6, $r->json('total'));
        $this->assertSame(3, $r->json('last_page'));

        $pagina3 = $this->como('admin')->getJson('/api/profesores?per_page=2&page=3')->assertOk();
        $this->assertCount(2, $pagina3->json('data'));
    }

    public function test_el_dni_y_las_observaciones_son_solo_para_gestion(): void
    {
        $this->profesor->update(['dni' => '30111222', 'observaciones' => 'Llega tarde los lunes']);

        $gestion = $this->como('admin')->getJson('/api/profesores')->assertOk();
        $fila = collect($gestion->json('data'))->firstWhere('id', $this->profesor->id);
        $this->assertSame('30111222', $fila['dni']);
        $this->assertSame('Llega tarde los lunes', $fila['observaciones']);
        $this->assertNotNull($fila['creado_en']);

        foreach (['preceptor', 'profesor'] as $rol) {
            $r = $this->como($rol)->getJson('/api/profesores')->assertOk();
            $fila = collect($r->json('data'))->firstWhere('id', $this->profesor->id);
            $this->assertNull($fila['dni'], "El rol {$rol} no tiene que ver el DNI.");
            $this->assertNull($fila['observaciones']);
            $this->assertNull($fila['creado_en']);
            // El contacto y los cursos sí: es lo que necesita para trabajar.
            $this->assertSame($this->profesor->email, $fila['email']);
            $this->assertCount(1, $fila['asignaciones']);
        }
    }

    public function test_el_dni_de_la_cuenta_sirve_de_respaldo(): void
    {
        // La ficha no tiene DNI cargado, pero la cuenta sí: se muestra el de la cuenta.
        $this->cuentas['profesor']->update(['dni' => '28999888']);

        $r = $this->como('admin')->getJson('/api/profesores')->assertOk();
        $fila = collect($r->json('data'))->firstWhere('id', $this->profesor->id);
        $this->assertSame('28999888', $fila['dni']);
    }

    public function test_un_profesor_con_datos_personales_ve_sus_observaciones_pero_no_las_de_sus_pares(): void
    {
        // Sin la casilla, la ficha del profesor es sólo contacto y cursos.
        $this->profesor->update(['observaciones' => 'Nota propia']);
        $par = $this->nuevoProfesor('Par', ['observaciones' => 'Nota del par']);

        $sinCasilla = $this->como('profesor')->getJson('/api/profesores/' . $this->profesor->id)->assertOk();
        $this->assertArrayNotHasKey('observaciones', $sinCasilla->json());

        // Con la casilla entra a la ficha completa, y ahí las observaciones
        // siguen siendo sólo las propias: las de un par no se muestran.
        $this->darPermiso('profesor', 'ficha.datos_personales');

        $propias = $this->como('profesor')->getJson('/api/profesores/' . $this->profesor->id)->assertOk();
        $this->assertSame('Nota propia', $propias->json('observaciones'));

        $delPar = $this->como('profesor')->getJson('/api/profesores/' . $par->id)->assertOk();
        $this->assertNull($delPar->json('observaciones'));

        $listado = $this->como('profesor')->getJson('/api/profesores')->assertOk();
        $filaPar = collect($listado->json('data'))->firstWhere('id', $par->id);
        $this->assertNull($filaPar['observaciones']);
    }

    // ===== Ficha =====

    public function test_la_ficha_completa_trae_datos_y_asignaciones(): void
    {
        $this->profesor->update(['dni' => '30111222', 'estado' => 'Licencia']);

        $r = $this->como('admin')->getJson('/api/profesores/' . $this->profesor->id)->assertOk();
        $this->assertSame('30111222', $r->json('dni'));
        $this->assertSame('Licencia', $r->json('estado'));
        $this->assertFalse($r->json('suplente'));
        $this->assertSame('Matemática', $r->json('asignaciones.0.materia'));
    }

    public function test_la_ficha_recortada_no_pierde_la_foto_ni_los_cursos(): void
    {
        $r = $this->como('profesor')->getJson('/api/profesores/' . $this->profesor->id)->assertOk();

        $this->assertArrayNotHasKey('dni', $r->json());
        $this->assertArrayNotHasKey('estado', $r->json());
        $this->assertSame($this->cuentas['profesor']->id, $r->json('user_id'));
        $this->assertSame('Matemática', $r->json('asignaciones.0.materia'));
    }

    public function test_la_ficha_de_un_profesor_inexistente_da_404(): void
    {
        $this->como('admin')->getJson('/api/profesores/999999')->assertNotFound();
    }

    // ===== Alta =====

    public function test_crear_un_profesor_lo_deja_con_sus_asignaciones_y_en_el_historial(): void
    {
        $r = $this->crearProfesor(['dni' => '30111222', 'observaciones' => 'Recién incorporada'])
            ->assertCreated();

        $this->assertSame('Marta', $r->json('nombre'));
        $this->assertSame('Matemática', $r->json('asignaciones.0.materia'));
        $this->assertDatabaseHas('profesores', ['email' => 'marta.gomez@abc.gob.ar', 'dni' => '30111222']);
        $this->assertSame(1, ActivityLog::where('action_type', 'profesor.store')->count());
    }

    public function test_el_alta_normaliza_la_division_y_el_grupo(): void
    {
        $this->crearProfesor([
            'asignaciones' => [['anio' => 1, 'division' => '1', 'grupo' => 'a', 'materia' => 'Matemática']],
        ])->assertCreated();

        $this->assertDatabaseHas('profesor_asignaciones', [
            'anio' => 1, 'division' => '1', 'grupo' => 'A', 'materia_id' => $this->materia->id,
        ]);
    }

    public function test_no_se_puede_repetir_el_email_de_otro_profesor(): void
    {
        $this->crearProfesor(['email' => $this->profesor->email])->assertStatus(422);
        $this->assertSame(1, Profesor::count());
    }

    public function test_el_alta_valida_los_campos_obligatorios(): void
    {
        $this->crearProfesor(['nombre' => ''])->assertStatus(422);
        $this->crearProfesor(['apellido' => ''])->assertStatus(422);
        $this->crearProfesor(['email' => 'no-es-un-email'])->assertStatus(422);
        $this->crearProfesor(['estado' => 'Jubilado'])->assertStatus(422);
        // El formulario pide al menos una asignación: un profesor sin materias
        // no se puede cargar a mano (desde cuentas sí, porque ahí viene el alta).
        $this->crearProfesor(['asignaciones' => []])->assertStatus(422);
        $this->como('admin')->postJson('/api/profesores', ['nombre' => 'Sin', 'apellido' => 'Asignaciones'])
            ->assertStatus(422);
    }

    public function test_el_alta_valida_el_anio_y_el_grupo_de_las_asignaciones(): void
    {
        $this->crearProfesor(['asignaciones' => [['anio' => 99, 'materia' => 'Matemática']]])->assertStatus(422);
        $this->crearProfesor(['asignaciones' => [['anio' => 0, 'materia' => 'Matemática']]])->assertStatus(422);
        $this->crearProfesor(['asignaciones' => [['anio' => 1, 'grupo' => 'C', 'materia' => 'Matemática']]])
            ->assertStatus(422);
        $this->crearProfesor(['asignaciones' => [['anio' => 1, 'division' => 'AB', 'materia' => 'Matemática']]])
            ->assertStatus(422);
    }

    public function test_una_materia_que_no_existe_no_deja_la_ficha_creada_a_medias(): void
    {
        $this->crearProfesor(['asignaciones' => [['anio' => 1, 'division' => '1', 'materia' => 'Astronomía']]])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "La materia 'Astronomía' no existe para 1° (o no está activa)."]);

        // La transacción tiene que volver atrás: ni ficha ni asignaciones huérfanas.
        $this->assertSame(1, Profesor::count());
        $this->assertSame(1, ProfesorAsignacion::count());
    }

    public function test_una_materia_inactiva_no_se_puede_asignar(): void
    {
        $this->otraMateria->update(['activo' => false]);

        $this->crearProfesor(['asignaciones' => [['anio' => 1, 'materia' => 'Lengua y Literatura']]])
            ->assertStatus(422);
        $this->assertSame(1, Profesor::count());
    }

    public function test_la_materia_tiene_que_ser_del_anio_que_se_declara(): void
    {
        // Matemática es de 1.º: pedirla para 2.º no puede pasar.
        $this->crearProfesor(['asignaciones' => [['anio' => 2, 'division' => '2', 'materia' => 'Matemática']]])
            ->assertStatus(422);
    }

    public function test_el_dni_no_puede_ser_mas_largo_que_la_columna(): void
    {
        // La columna es varchar(15): un DNI de 16 caracteres tiene que rechazarse
        // con un mensaje de la pantalla, no reventar contra MySQL.
        $this->crearProfesor(['dni' => str_repeat('9', 16)])->assertStatus(422);
        $this->assertSame(1, Profesor::count());
    }

    // ===== Alta desde cuentas =====

    public function test_se_pueden_dar_de_alta_profesores_desde_las_cuentas(): void
    {
        $cuenta = User::create([
            'name' => 'Silvia', 'apellido' => 'Pérez', 'email' => 'silvia.perez@abc.gob.ar',
            'password' => self::CLAVE, 'role' => 'profesor', 'status' => 'active', 'dni' => '27555444',
        ]);

        $r = $this->como('admin')->postJson('/api/profesores/from-users', ['ids' => [$cuenta->id]])
            ->assertOk();
        $this->assertSame(1, $r->json('created'));
        $this->assertSame(0, $r->json('skipped'));

        $this->assertDatabaseHas('profesores', [
            'email' => 'silvia.perez@abc.gob.ar', 'nombre' => 'Silvia', 'apellido' => 'Pérez', 'dni' => '27555444',
        ]);
    }

    public function test_dar_de_alta_desde_cuentas_no_duplica_las_que_ya_son_profesores(): void
    {
        $r = $this->como('admin')->postJson('/api/profesores/from-users', [
            'ids' => [$this->cuentas['profesor']->id],
        ])->assertOk();

        $this->assertSame(0, $r->json('created'));
        $this->assertSame(1, $r->json('skipped'));
        $this->assertSame(1, Profesor::count());
    }

    public function test_dar_de_alta_desde_cuentas_acepta_un_id_suelto_y_descarta_los_inventados(): void
    {
        $r = $this->como('admin')->postJson('/api/profesores/from-users', [
            'ids' => [$this->cuentas['preceptor']->id, 999999, 0, -3],
        ])->assertOk();

        $this->assertSame(1, $r->json('created'));
        $this->assertDatabaseHas('profesores', ['email' => $this->cuentas['preceptor']->email]);
    }

    // ===== Edición =====

    public function test_editar_un_profesor_reemplaza_todas_sus_asignaciones(): void
    {
        $r = $this->como('admin')->putJson('/api/profesores/' . $this->profesor->id, $this->payload([
            'nombre'   => 'Otro',
            'apellido' => 'Apellido',
            'email'    => $this->profesor->email,
            'asignaciones' => [
                ['anio' => 1, 'division' => '1', 'materia' => 'Lengua y Literatura'],
                ['anio' => 2, 'division' => '2', 'materia' => 'Historia'],
            ],
        ]))->assertOk();

        $this->assertSame('Apellido', $r->json('apellido'));
        $this->assertCount(2, $r->json('asignaciones'));
        $this->assertDatabaseMissing('profesor_asignaciones', [
            'profesor_id' => $this->profesor->id, 'materia_id' => $this->materia->id,
        ]);
        $this->assertSame(2, ProfesorAsignacion::where('profesor_id', $this->profesor->id)->count());
        $this->assertSame(1, ActivityLog::where('action_type', 'profesor.update')->count());
    }

    public function test_editar_deja_en_el_historial_solo_lo_que_cambio(): void
    {
        $this->como('admin')->putJson('/api/profesores/' . $this->profesor->id, $this->payload([
            'nombre'   => $this->profesor->nombre,
            'apellido' => 'Cambiado',
            'email'    => $this->profesor->email,
        ]))->assertOk();

        $entrada = ActivityLog::where('action_type', 'profesor.update')->first();
        $this->assertNotNull($entrada);
        $this->assertStringContainsString('Cambiado', json_encode($entrada->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_un_profesor_puede_conservar_su_propio_email_al_editar(): void
    {
        $this->como('admin')->putJson('/api/profesores/' . $this->profesor->id, $this->payload([
            'email' => $this->profesor->email,
        ]))->assertOk();
    }

    public function test_una_materia_mala_al_editar_no_borra_las_asignaciones_viejas(): void
    {
        $this->como('admin')->putJson('/api/profesores/' . $this->profesor->id, $this->payload([
            'email'        => $this->profesor->email,
            'asignaciones' => [['anio' => 1, 'division' => '1', 'materia' => 'Astronomía']],
        ]))->assertStatus(422);

        // Rollback: la ficha y su asignación original siguen intactas.
        $this->assertSame(1, ProfesorAsignacion::where('profesor_id', $this->profesor->id)->count());
        $this->assertDatabaseHas('profesor_asignaciones', [
            'profesor_id' => $this->profesor->id, 'materia_id' => $this->materia->id,
        ]);
        $this->assertSame($this->profesor->apellido, $this->profesor->fresh()->apellido);
    }

    public function test_editar_un_profesor_inexistente_da_404(): void
    {
        $this->como('admin')->putJson('/api/profesores/999999', $this->payload())->assertNotFound();
    }

    // ===== Baja definitiva =====

    public function test_la_baja_definitiva_pide_la_clave(): void
    {
        $this->como('admin')->deleteJson('/api/profesores/' . $this->profesor->id . '/force')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Confirmá tu contraseña para realizar esta acción.']);

        $this->assertDatabaseHas('profesores', ['id' => $this->profesor->id]);
    }

    public function test_la_baja_definitiva_borra_la_ficha_y_libera_el_horario(): void
    {
        $suplente = $this->nuevoProfesor('Suplente');
        $this->agregarEslabon($this->profesor, $suplente)->assertCreated();

        HorarioCelda::create([
            'curso_id' => $this->curso->id, 'dia' => 1, 'slot_key' => 'h1',
            'materia_id' => $this->materia->id, 'profesor_id' => $this->profesor->id,
        ]);
        DB::table('alumno_horario_overrides')->insert([
            'alumno_id' => $this->alumno->id, 'dia' => 1, 'bloque' => 1,
            'materia_id' => $this->materia->id, 'profesor_id' => $this->profesor->id,
            'materia_nombre' => 'Matemática', 'profesor_nombre' => 'DePrueba',
        ]);
        DB::table('profesor_bajas')->insert(['profesor_id' => $this->profesor->id, 'motivo' => 'Renuncia']);

        $this->confirmar();
        $this->como('admin')->deleteJson('/api/profesores/' . $this->profesor->id . '/force')->assertOk();

        $this->assertDatabaseMissing('profesores', ['id' => $this->profesor->id]);
        $this->assertSame(0, ProfesorAsignacion::where('profesor_id', $this->profesor->id)->count());
        $this->assertSame(0, ProfesorSuplencia::where('titular_id', $this->profesor->id)->count());
        $this->assertSame(0, DB::table('profesor_bajas')->where('profesor_id', $this->profesor->id)->count());

        // La celda se conserva (materia y grilla siguen) pero queda sin docente.
        $this->assertDatabaseHas('horario_celdas', [
            'curso_id' => $this->curso->id, 'slot_key' => 'h1', 'profesor_id' => null,
            'materia_id' => $this->materia->id,
        ]);
        $this->assertSame(0, DB::table('alumno_horario_overrides')->where('profesor_id', $this->profesor->id)->count());
        $this->assertSame(1, ActivityLog::where('action_type', 'profesor.forceDelete')->count());
    }

    public function test_la_baja_de_un_profesor_inexistente_da_404(): void
    {
        $this->confirmar();
        $this->como('admin')->deleteJson('/api/profesores/999999/force')->assertNotFound();
    }

    // ===== Mapping =====

    public function test_el_mapping_agrupa_los_profesores_por_materia(): void
    {
        $otro = $this->nuevoProfesor('Álvarez');

        $r = $this->como('admin')->getJson('/api/profesores/mapping?anio=1&division=1')->assertOk();
        $mapa = $r->json();

        $this->assertArrayHasKey('Matemática', $mapa);
        $this->assertCount(2, $mapa['Matemática']);
        // Orden alfabético español: Álvarez antes que DePrueba.
        $this->assertSame('Nombre Álvarez', $mapa['Matemática'][0]['nombre']);
        $this->assertSame($otro->id, $mapa['Matemática'][0]['id']);
    }

    public function test_el_mapping_exige_un_anio_valido(): void
    {
        $this->como('admin')->getJson('/api/profesores/mapping')->assertStatus(422);
        $this->como('admin')->getJson('/api/profesores/mapping?anio=0')->assertStatus(422);
        $this->como('admin')->getJson('/api/profesores/mapping?anio=99')->assertStatus(422);
    }

    public function test_el_mapping_filtra_por_estado_del_profesor(): void
    {
        $this->nuevoProfesor('EnLicencia', ['estado' => 'Licencia']);

        $activos = $this->como('admin')->getJson('/api/profesores/mapping?anio=1')->assertOk()->json();
        $this->assertCount(1, $activos['Matemática']);

        $todos = $this->como('admin')->getJson('/api/profesores/mapping?anio=1&estado=todos')->assertOk()->json();
        $this->assertCount(2, $todos['Matemática']);
    }

    public function test_el_mapping_no_trae_materias_inactivas(): void
    {
        $this->materia->update(['activo' => false]);

        $mapa = $this->como('admin')->getJson('/api/profesores/mapping?anio=1')->assertOk()->json();
        $this->assertSame([], $mapa);
    }

    // ===== Buscar profesor en los horarios =====

    private function celdaEn(int $cursoId, string $slot, int $dia, ?int $profesorId = null): HorarioCelda
    {
        return HorarioCelda::create([
            'curso_id'   => $cursoId,
            'dia'        => $dia,
            'slot_key'   => $slot,
            'materia_id' => $this->materia->id,
            'profesor_id'=> $profesorId ?? $this->profesor->id,
        ]);
    }

    public function test_buscar_en_horarios_exige_un_termino_de_dos_letras(): void
    {
        $this->como('admin')->getJson('/api/profesores/en-horarios')->assertStatus(422);
        $this->como('admin')->getJson('/api/profesores/en-horarios?q=a')->assertStatus(422);
    }

    public function test_buscar_en_horarios_encuentra_por_nombre_y_por_id(): void
    {
        $this->celdaEn($this->curso->id, 'h1', 1);
        $this->celdaEn($this->curso->id, 'h2', 3);

        foreach (['DePrueba', 'Profesor DePrueba', (string) $this->profesor->id] as $q) {
            $r = $this->como('admin')->getJson('/api/profesores/en-horarios?q=' . urlencode($q))->assertOk();
            $this->assertSame(2, $r->json('total_coincidencias'), "La búsqueda '{$q}' falló.");
            $this->assertSame(1, $r->json('cursos_escaneados'));
            $this->assertFalse($r->json('truncado'));
            $this->assertSame('h1', $r->json('resultados.0.slot_key'));
            $this->assertSame('Lun', $r->json('resultados.0.dia_key'));
            $this->assertSame('Matemática', $r->json('resultados.0.materiaNombre'));
        }
    }

    public function test_buscar_en_horarios_respeta_el_alcance_de_lectura(): void
    {
        $this->celdaEn($this->curso->id, 'h1', 1);
        $this->celdaEn($this->otroCurso->id, 'h1', 1);

        // Gestión y preceptor leen todos los cursos.
        foreach (['admin', 'preceptor'] as $rol) {
            $r = $this->como($rol)->getJson('/api/profesores/en-horarios?q=DePrueba')->assertOk();
            $this->assertSame(2, $r->json('total_coincidencias'));
        }

        // El profesor sólo tiene 1.º 1.ª: la celda de 2.º 2.ª no le aparece.
        $profesor = $this->como('profesor')->getJson('/api/profesores/en-horarios?q=DePrueba')->assertOk();
        $this->assertSame(1, $profesor->json('total_coincidencias'));
        $this->assertSame(1, $profesor->json('resultados.0.anio'));
    }

    public function test_buscar_en_horarios_ignora_los_grupos(): void
    {
        $this->celdaEn($this->curso->id, 'h1', 1);
        HorarioCelda::create([
            'curso_id' => $this->curso->id, 'dia' => 1, 'slot_key' => 'h1', 'grupo' => 'A',
            'materia_id' => $this->materia->id, 'profesor_id' => $this->profesor->id,
        ]);

        $r = $this->como('admin')->getJson('/api/profesores/en-horarios?q=DePrueba')->assertOk();
        $this->assertSame(1, $r->json('total_coincidencias'));
    }

    public function test_buscar_en_horarios_filtra_por_anios(): void
    {
        $this->celdaEn($this->curso->id, 'h1', 1);
        $this->celdaEn($this->otroCurso->id, 'h1', 1);

        $r = $this->como('admin')->getJson('/api/profesores/en-horarios?q=DePrueba&anios=2')->assertOk();
        $this->assertSame(1, $r->json('total_coincidencias'));
        $this->assertSame(2, $r->json('resultados.0.anio'));
    }

    // ===== Suplencias: lectura =====

    public function test_las_suplencias_de_un_profesor_inexistente_dan_404(): void
    {
        $this->como('admin')->getJson('/api/profesores/999999/suplencias')->assertNotFound();
    }

    public function test_la_cadena_se_lee_con_otorga_recibe_y_niveles(): void
    {
        $b = $this->nuevoProfesor('EslabónB');
        $c = $this->nuevoProfesor('EslabónC');
        $this->agregarEslabon($this->profesor, $b)->assertCreated();
        $this->agregarEslabon($b, $c)->assertCreated();

        $r = $this->como('admin')->getJson('/api/profesores/' . $this->profesor->id . '/suplencias')->assertOk();
        $this->assertCount(1, $r->json('otorga'));
        $this->assertSame('EslabónB, Nombre', $r->json('otorga.0.otro_nombre'));
        $this->assertSame('Matemática', $r->json('otorga.0.materia'));
        $this->assertCount(0, $r->json('recibe'));
        $this->assertCount(2, $r->json('cadena'));
        $this->assertSame($b->id, $r->json('cadena.0.0.id'));
        $this->assertSame($c->id, $r->json('cadena.1.0.id'));

        // Del otro lado: C es cubierto por nadie y cubre a B.
        $deC = $this->como('admin')->getJson('/api/profesores/' . $c->id . '/suplencias')->assertOk();
        $this->assertCount(1, $deC->json('recibe'));
        $this->assertSame('EslabónB, Nombre', $deC->json('recibe.0.otro_nombre'));
    }

    public function test_el_listado_rotula_a_quien_cubre_y_por_quien_es_cubierto(): void
    {
        $suplente = $this->nuevoProfesor('Suplente', ['suplente' => true]);
        $this->agregarEslabon($this->profesor, $suplente, ['motivo' => 'Licencia médica'])->assertCreated();

        $r = $this->como('admin')->getJson('/api/profesores')->assertOk();

        $titular = collect($r->json('data'))->firstWhere('id', $this->profesor->id);
        $this->assertSame(['Suplente, Nombre'], $titular['cubierto_por']);
        $this->assertSame([], $titular['suplente_de']);

        $sup = collect($r->json('data'))->firstWhere('id', $suplente->id);
        $this->assertSame(['DePrueba, Profesor'], $sup['suplente_de']);
        $this->assertSame('Matemática', $sup['suplencias_materias'][0]['materia']);
        $this->assertSame('Licencia médica', $sup['suplencias_materias'][0]['motivo']);
        $this->assertSame('1.º 1.ª', $sup['suplencias_materias'][0]['curso']);
    }

    // ===== Suplencias: alta =====

    public function test_agregar_un_eslabon_lo_deja_en_la_base_y_en_el_historial(): void
    {
        $suplente = $this->nuevoProfesor('Suplente');

        $r = $this->agregarEslabon($this->profesor, $suplente, ['motivo' => '  Licencia  '])->assertCreated();
        $this->assertSame($suplente->id, $r->json('suplente_id'));
        $this->assertSame('Licencia', $r->json('motivo'));

        $this->assertDatabaseHas('profesor_suplencias', [
            'titular_id' => $this->profesor->id, 'suplente_id' => $suplente->id,
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id,
        ]);
        $this->assertSame(1, ActivityLog::where('action_type', 'profesor.suplencia.store')->count());
    }

    public function test_un_profesor_no_se_puede_reemplazar_a_si_mismo(): void
    {
        $this->agregarEslabon($this->profesor, $this->profesor)->assertStatus(422)
            ->assertJsonFragment(['message' => 'El suplente tiene que ser otra persona.']);
        $this->assertSame(0, ProfesorSuplencia::count());
    }

    public function test_el_mismo_eslabon_no_se_carga_dos_veces(): void
    {
        $suplente = $this->nuevoProfesor('Suplente');
        $this->agregarEslabon($this->profesor, $suplente)->assertCreated();

        $this->agregarEslabon($this->profesor, $suplente)->assertStatus(422)
            ->assertJsonFragment(['message' => 'Esa suplencia ya está cargada.']);
        $this->assertSame(1, ProfesorSuplencia::count());
    }

    public function test_la_cadena_no_se_puede_cerrar_en_circulo(): void
    {
        $b = $this->nuevoProfesor('EslabónB');
        $this->agregarEslabon($this->profesor, $b)->assertCreated();

        $this->agregarEslabon($b, $this->profesor)->assertStatus(422)
            ->assertJsonFragment(['message' => 'Esa persona ya está en la cadena: se formaría un círculo.']);
        $this->assertSame(1, ProfesorSuplencia::count());
    }

    public function test_la_cadena_admite_hasta_cuatro_eslabones(): void
    {
        $previo = $this->profesor;
        foreach (['B', 'C', 'D', 'E'] as $n) {
            $nuevo = $this->nuevoProfesor('Eslabón' . $n);
            $this->agregarEslabon($previo, $nuevo)->assertCreated();
            $previo = $nuevo;
        }
        $this->assertSame(4, ProfesorSuplencia::count());

        // El quinto eslabón ya supera el tope.
        $sexto = $this->nuevoProfesor('EslabónF');
        $this->agregarEslabon($previo, $sexto)->assertStatus(422)
            ->assertJsonFragment(['message' => 'La cadena ya tiene 4 suplentes, que es el máximo.']);
        $this->assertSame(4, ProfesorSuplencia::count());
    }

    public function test_el_suplente_tiene_que_existir(): void
    {
        $this->agregarEslabon($this->profesor, $this->profesor, ['suplente_id' => 999999])
            ->assertStatus(422);
    }

    public function test_una_materia_inactiva_no_se_puede_suplir(): void
    {
        $suplente = $this->nuevoProfesor('Suplente');
        $this->materia->update(['activo' => false]);

        $this->agregarEslabon($this->profesor, $suplente)->assertStatus(422)
            ->assertJsonFragment(['message' => 'La materia no existe o no está activa.']);
    }

    public function test_no_se_puede_suplir_una_materia_que_el_titular_no_dicta(): void
    {
        $suplente = $this->nuevoProfesor('Suplente');

        // Lengua y Literatura es de 1.º pero el titular del fixture no la dicta.
        $this->agregarEslabon($this->profesor, $suplente, ['materia_id' => $this->otraMateria->id])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'DePrueba, Profesor no enseña esa materia en ese curso. Revisá sus asignaciones.']);
    }

    public function test_no_se_puede_suplir_en_un_curso_que_el_titular_no_tiene(): void
    {
        $suplente = $this->nuevoProfesor('Suplente');

        $this->agregarEslabon($this->profesor, $suplente, ['anio' => 2, 'division' => '2'])
            ->assertStatus(422);
    }

    public function test_en_cadena_se_mira_la_materia_de_la_raiz(): void
    {
        // B no dicta nada en su propia ficha, pero cubre a A: el eslabón B → C
        // se valida contra las asignaciones de A, que es quien tiene la materia.
        $b = $this->nuevoProfesor('EslabónB', [], false);
        $c = $this->nuevoProfesor('EslabónC', [], false);
        $this->agregarEslabon($this->profesor, $b)->assertCreated();

        $this->agregarEslabon($b, $c)->assertCreated();
        $this->assertSame(2, ProfesorSuplencia::count());
    }

    public function test_un_titular_sin_asignaciones_no_puede_tener_suplente(): void
    {
        $suelto = $this->nuevoProfesor('SinMaterias', [], false);
        $suplente = $this->nuevoProfesor('Suplente');

        $this->agregarEslabon($suelto, $suplente)->assertStatus(422);
    }

    public function test_el_alta_de_suplencia_valida_anio_y_motivo(): void
    {
        $suplente = $this->nuevoProfesor('Suplente');

        $this->agregarEslabon($this->profesor, $suplente, ['anio' => 99])->assertStatus(422);
        $this->agregarEslabon($this->profesor, $suplente, ['anio' => 0])->assertStatus(422);
        $this->agregarEslabon($this->profesor, $suplente, ['motivo' => str_repeat('x', 251)])->assertStatus(422);
        $this->agregarEslabon($this->profesor, $suplente, ['division' => 'AB'])->assertStatus(422);
        $this->assertSame(0, ProfesorSuplencia::count());
    }

    // ===== Suplencias: baja =====

    public function test_quitar_un_eslabon_lo_borra_y_lo_deja_en_el_historial(): void
    {
        $suplente = $this->nuevoProfesor('Suplente');
        $this->agregarEslabon($this->profesor, $suplente)->assertCreated();
        $id = ProfesorSuplencia::first()->id;

        $this->como('admin')->deleteJson('/api/profesores/suplencias/' . $id)->assertOk();

        $this->assertDatabaseMissing('profesor_suplencias', ['id' => $id]);
        $this->assertSame(1, ActivityLog::where('action_type', 'profesor.suplencia.destroy')->count());
    }

    public function test_quitar_un_eslabon_inexistente_da_404(): void
    {
        $this->como('admin')->deleteJson('/api/profesores/suplencias/999999')->assertNotFound();
    }

    public function test_quitar_el_eslabon_del_medio_no_deja_la_cadena_colgada(): void
    {
        $b = $this->nuevoProfesor('EslabónB');
        $c = $this->nuevoProfesor('EslabónC');
        $this->agregarEslabon($this->profesor, $b)->assertCreated();
        $this->agregarEslabon($b, $c)->assertCreated();

        $delMedio = ProfesorSuplencia::where('titular_id', $this->profesor->id)->first();
        $this->como('admin')->deleteJson('/api/profesores/suplencias/' . $delMedio->id)->assertOk();

        // Queda B → C, pero desde A ya no se llega a nadie.
        $r = $this->como('admin')->getJson('/api/profesores/' . $this->profesor->id . '/suplencias')->assertOk();
        $this->assertSame([], $r->json('cadena'));
        $this->assertSame(1, ProfesorSuplencia::count());
    }
}