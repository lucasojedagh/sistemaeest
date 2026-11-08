<?php

namespace Tests\Feature;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Alumnos\AlumnoIntensificacion;
use App\Dominios\Alumnos\AlumnoRecursando;
use App\Dominios\Calificaciones\AlumnoMateriaEstado;
use App\Dominios\Profesores\Profesor;
use App\Dominios\Usuarios\RolConfig;
use App\Dominios\Usuarios\User;
use App\Support\Alcances;
use App\Support\CourseLabel;
use App\Support\Permisos;
use Illuminate\Testing\TestResponse;

/**
 * El panel de inicio, recorrido por HTTP con login real.
 *
 * Es la única pantalla que abre cualquiera al entrar, así que lo que se mide
 * acá es que los números que muestra sean los del padrón real (no los de la
 * página visible), que el alcance recorte lo que tiene que recortar y que un
 * rol chico no reciba un error que le deje el inicio en blanco.
 */
class DashboardApiTest extends ApiTestCase
{
    private const CICLO = 2026;

    /** Estado final de una materia, en el formato que escribe la planilla. */
    private function estado(int $alumnoId, int $materiaId, float $nota, array $cambios = []): AlumnoMateriaEstado
    {
        return AlumnoMateriaEstado::create(array_merge([
            'alumno_id'     => $alumnoId,
            'materia_id'    => $materiaId,
            'ciclo_lectivo' => self::CICLO,
            'anio'          => 1,
            'division'      => '1',
            'nota_final'    => $nota,
            'estado_final'  => $nota >= 7 ? 'aprobada' : 'desaprobada',
        ], $cambios));
    }

    private function crearRol(string $nombre, int $nivel, array $permitidos, array $alcance = []): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($nombre)), '_'));
        RolConfig::create([
            'rol'        => $slug,
            'nombre'     => $nombre,
            'nivel'      => $nivel,
            'tipo'       => 'personalizado',
            'permitidos' => Permisos::normalizarLista($slug, $permitidos, 5),
            'negados'    => [],
            'alcance'    => $alcance,
            'modulos'    => null,
        ]);
        RolConfig::olvidarRegistro();

        return $slug;
    }

    private function cuentaConRol(string $email, string $rol, array $datos = []): User
    {
        return User::create(array_merge([
            'name'     => 'Panel',
            'apellido' => 'DePrueba',
            'email'    => $email,
            'password' => self::CLAVE,
            'role'     => $rol,
            'status'   => 'active',
        ], $datos));
    }

    private function tokenDeEmail(string $email): string
    {
        $r = $this->postJson('/api/login', ['email' => $email, 'password' => self::CLAVE]);
        $r->assertOk();

        return $r->json('token');
    }

    // ===== /dashboard/resumen =====

    public function test_el_resumen_lo_puede_pedir_cualquier_rol_autenticado(): void
    {
        foreach (['admin', 'directivo', 'jefe_preceptores', 'prosecretaria', 'preceptor', 'profesor'] as $rol) {
            $this->como($rol)->getJson('/api/dashboard/resumen')->assertOk();
        }
    }

    public function test_sin_sesion_el_resumen_no_se_lee(): void
    {
        $this->getJson('/api/dashboard/resumen')->assertUnauthorized();
        $this->getJson('/api/dashboard/preceptores')->assertUnauthorized();
    }

    public function test_el_resumen_cuenta_el_padron_completo_no_lo_que_cabe_en_una_pagina(): void
    {
        // El panel se armaba bajando /alumnos?per_page=2000 y contando en el
        // navegador: con el padrón real de la escuela se quedaba corto.
        for ($i = 0; $i < 60; $i++) {
            $this->nuevoAlumno(['anio' => 2, 'division' => '2']);
        }

        $r = $this->como('admin')->getJson('/api/dashboard/resumen')->assertOk();

        $this->assertSame(65, $r->json('alumnos.total'));
        $this->assertSame(62, $r->json('alumnos.por_anio.2'));
        $this->assertSame(3, $r->json('alumnos.por_anio.1'));
    }

    public function test_los_contadores_por_curso_y_por_genero_suman_lo_mismo_que_el_total(): void
    {
        $r = $this->como('admin')->getJson('/api/dashboard/resumen')->assertOk();

        $sumaCursos = array_sum(array_column($r->json('alumnos.por_curso'), 'c'));
        $sumaGenero = array_sum($r->json('alumnos.por_genero'));
        $this->assertSame($r->json('alumnos.total'), $sumaCursos);
        $this->assertSame($r->json('alumnos.total'), $sumaGenero);

        // El listado de cursos viene ordenado de más a menos: el gráfico lo usa tal cual.
        $cursos = $r->json('alumnos.por_curso');
        $this->assertSame(1, $cursos[0]['anio']);
        $this->assertSame(3, $cursos[0]['c']);
    }

    public function test_un_genero_externo_o_vacio_no_desaparece_del_contador(): void
    {
        // La columna genero es NOT NULL y el modelo normaliza lo que no es
        // F/M/O a vacío. Antes normalizaba a null y el alta reventaba con un
        // error 500; vacío al menos sigue entrando en la cuenta del panel.
        $this->nuevoAlumno(['genero' => 'X']);
        $this->nuevoAlumno(['genero' => '']);

        $porGenero = $this->como('admin')->getJson('/api/dashboard/resumen')->assertOk()->json('alumnos.por_genero');

        $this->assertSame(3, $porGenero['F'], 'El fixture trae tres chicas');
        $this->assertSame(2, $porGenero['M']);
        $this->assertSame(2, $porGenero[''], 'El sexo raro y el vacío caen en el mismo balde de sin dato');
        $this->assertArrayNotHasKey('X', $porGenero, 'Un género fuera de F/M/O no se guarda tal cual');
        $this->assertSame(7, array_sum($porGenero));
    }

    public function test_alta_por_api_con_sexo_no_entendido_es_422_y_no_500(): void
    {
        $this->postJson('/api/alumnos', [
            'nombre' => 'Norberto', 'apellido' => 'Ñandú', 'dni' => '40999001',
            'genero' => 'No binario', 'anio' => 1, 'division' => '1',
            'fecha_nacimiento' => '2010-03-01',
        ], ['Authorization' => 'Bearer ' . $this->tokenDe('admin')])
            ->assertStatus(422)->assertJsonValidationErrors('genero');

        $this->assertSame(5, Alumno::count(), 'El rechazo no deja una fila a medias en el padrón');
    }

    public function test_el_resumen_de_profesores_separa_titulares_suplentes_y_licencias(): void
    {
        Profesor::create(['nombre' => 'Luis', 'apellido' => 'ConLicencia', 'email' => 'luis@abc.gob.ar', 'estado' => 'Licencia']);
        Profesor::create(['nombre' => 'Sosi', 'apellido' => 'Suplente', 'email' => 'sosi@abc.gob.ar', 'estado' => 'Activo', 'suplente' => true]);

        $profesores = $this->como('admin')->getJson('/api/dashboard/resumen')->assertOk()->json('profesores');

        $this->assertSame(3, $profesores['total']);
        $this->assertSame(1, $profesores['licencia']);
        $this->assertSame(2, $profesores['activos'], 'Un profesor con licencia no cuenta como activo');
        $this->assertSame(1, $profesores['suplentes']);
        $this->assertSame(2, $profesores['titulares']);
        $this->assertSame($profesores['total'], $profesores['suplentes'] + $profesores['titulares']);
    }

    public function test_un_profesor_borrado_deja_de_contarse(): void
    {
        $fuera = Profesor::create(['nombre' => 'Borrado', 'apellido' => 'Rápido', 'email' => 'fuera@abc.gob.ar', 'estado' => 'Activo']);
        $fuera->delete();

        $this->assertSame(1, $this->como('admin')->getJson('/api/dashboard/resumen')->assertOk()->json('profesores.total'));
    }

    public function test_las_materias_a_recursar_e_intensificacion_se_cuentan_por_alumno(): void
    {
        $conAmbas  = $this->alumno;                                        // Álvarez
        $soloRec   = $this->nuevoAlumno(['apellido' => 'Recursando', 'nombre' => 'Rui']);
        $soloInt   = $this->nuevoAlumno(['apellido' => 'Intensiva', 'nombre' => 'Inés']);
        $sinNada   = $this->nuevoAlumno(['apellido' => 'Sin', 'nombre' => 'Nada']);

        // materia_nombre es NOT NULL: la API la llena desde 'materia', así que
        // el fixture directo tiene que escribir lo mismo que escribiría el alta.
        foreach ([$conAmbas, $soloRec] as $a) {
            AlumnoRecursando::create([
                'alumno_id' => $a->id, 'anio' => 1, 'division' => '1',
                'materia_id' => $this->materia->id, 'materia_nombre' => 'Matemática',
            ]);
        }
        foreach ([$conAmbas, $soloInt] as $a) {
            AlumnoIntensificacion::create([
                'alumno_id' => $a->id, 'anio' => 1, 'division' => '1',
                'materia_id' => $this->materia->id, 'materia_nombre' => 'Matemática',
            ]);
        }
        unset($sinNada);

        $estados = $this->como('admin')->getJson('/api/dashboard/resumen')->assertOk()->json('alumnos.estados');

        $this->assertSame(1, $estados['ambos']);
        $this->assertSame(1, $estados['solo_rec']);
        $this->assertSame(1, $estados['solo_int']);
        $this->assertSame(5, $estados['ninguno'], 'Los cinco alumnos que no se tocaron en esta prueba');
        $this->assertSame($estados['ambos'] + $estados['solo_rec'] + $estados['solo_int'] + $estados['ninguno'],
            $this->como('admin')->getJson('/api/dashboard/resumen')->assertOk()->json('alumnos.total'));
    }

    public function test_un_rol_sin_acceso_al_padron_recibe_alumnos_nulos_y_el_resto_igual(): void
    {
        // El panel se arma por partes: si el rol no ve el padrón, la tarjeta de
        // alumnos se avisa sola, pero los profesores siguen llegando.
        $slug = $this->crearRol('Sin Padron', 2, ['dashboard.ver'], ['alumnos' => ['ver' => Alcances::NADA]]);
        $token = $this->tokenDeEmail($this->cuentaConRol('sin.padron@abc.gob.ar', $slug)->email);

        $r = $this->withToken($token)->getJson('/api/dashboard/resumen')->assertOk();

        $this->assertNull($r->json('alumnos'));
        $this->assertSame(1, $r->json('profesores.total'));
    }

    public function test_el_profesor_ve_en_el_panel_solo_los_cursos_que_dicta(): void
    {
        // El profesor del fixture dicta Matemática en 1° 1.ª y nada más.
        $r = $this->como('profesor')->getJson('/api/dashboard/resumen')->assertOk();

        $this->assertSame(3, $r->json('alumnos.total'), 'Las tres filas de 1° 1.ª, no el padrón entero');
        $this->assertSame([[1, '1', 3]], array_map(
            fn ($c) => [$c['anio'], $c['division'], $c['c']],
            $r->json('alumnos.por_curso')
        ));
    }

    public function test_el_preceptor_ve_el_padron_completo_en_el_panel(): void
    {
        $r = $this->como('preceptor')->getJson('/api/dashboard/resumen')->assertOk();

        $this->assertSame(5, $r->json('alumnos.total'));
        $this->assertSame(5, array_sum(array_column($r->json('alumnos.por_curso'), 'c')));
    }

    public function test_un_estado_no_ideal_del_alumno_no_cambia_el_contador_del_panel(): void
    {
        // El padrón de la pantalla trae filtros de estado; el panel cuenta todo.
        // Si algún día se recorta, esta prueba tiene que decirlo.
        $otro = $this->nuevoAlumno(['estado' => 'Inactivo']);
        $this->assertSame(6, $this->como('admin')->getJson('/api/dashboard/resumen')->assertOk()->json('alumnos.total'));
        unset($otro);
    }

    // ===== /dashboard/preceptores =====

    public function test_la_tarjeta_de_preceptores_cuenta_cuentas_no_fichas(): void
    {
        $this->cuentaConRol('pre1@abc.gob.ar', 'preceptor');
        $this->cuentaConRol('pre2@abc.gob.ar', 'preceptor', ['status' => 'pending']);
        $this->cuentaConRol('profe2@abc.gob.ar', 'profesor');

        $r = $this->como('profesor')->getJson('/api/dashboard/preceptores')->assertOk();

        // El fixture ya trae una cuenta de preceptor.
        $this->assertSame(3, $r->json('total'));
        $this->assertSame(2, $r->json('activos'), 'Una cuenta desactivada se cuenta pero no como activa');
    }

    public function test_la_cuenta_pendiente_no_cuenta_como_preceptor_activo(): void
    {
        $r = $this->como('admin')->getJson('/api/dashboard/preceptores')->assertOk();

        $this->assertSame(1, $r->json('total'), 'Sólo el rol preceptor; la cuenta pendiente es sin_rol');
        $this->assertSame(1, $r->json('activos'));
    }

    // ===== /dashboard/top-performers =====

    public function test_el_ranking_de_alumnos_ordena_por_promedio_y_marca_las_aprobadas(): void
    {
        // Ana saca 10 y 4 (promedio 7); Fabián, 9. El orden es por promedio.
        $ana = $this->alumno;
        $bastian = $this->nuevoAlumno(['apellido' => 'Fabián', 'nombre' => 'Fito']);
        $this->estado($ana->id, $this->materia->id, 10);
        $this->estado($ana->id, $this->otraMateria->id, 4, ['estado_final' => 'desaprobada']);
        $this->estado($bastian->id, $this->materia->id, 9);

        $r = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . self::CICLO)->assertOk();

        $this->assertSame(self::CICLO, $r->json('ciclo_lectivo'));
        $top = $r->json('top_students');
        $this->assertCount(2, $top);
        $this->assertSame('Fabián', $top[0]['apellido'], 'El mejor promedio va primero');
        $this->assertEqualsWithDelta(9, $top[0]['promedio'], 0.001);
        $this->assertSame(CourseLabel::label(1, '1'), $top[0]['curso']);
        $this->assertEqualsWithDelta(7, $top[1]['promedio'], 0.001, 'El promedio es de todas las materias con nota, no de la última');
        $this->assertSame(2, $top[1]['materias']);
        $this->assertSame(1, $top[1]['aprobadas']);
    }

    public function test_una_materia_sin_nota_final_no_participa_del_promedio(): void
    {
        $esta = $this->estado($this->alumno->id, $this->materia->id, 8);
        $this->estado($this->alumno->id, $this->otraMateria->id, 0, ['nota_final' => null, 'estado_final' => 'pendiente']);

        $top = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . self::CICLO)
            ->assertOk()->json('top_students.0');

        $this->assertEqualsWithDelta(8, $top['promedio'], 0.001);
        $this->assertSame(1, $top['materias'], 'La fila sin nota no infla el conteo de materias');
        unset($esta);
    }

    public function test_el_ranking_de_cursos_promedia_todos_los_alumnos_del_curso(): void
    {
        $this->estado($this->alumno->id, $this->materia->id, 10);
        $this->estado($this->nuevoAlumno(['apellido' => 'Bolsa', 'nombre' => 'Beto'])->id, $this->materia->id, 4,
            ['estado_final' => 'desaprobada']);
        $afuera = $this->nuevoAlumno(['apellido' => 'Costa', 'nombre' => 'Coti', 'anio' => 2, 'division' => '2']);
        $this->estado($afuera->id, $this->otraMateria->id, 6, ['anio' => 2, 'division' => '2', 'estado_final' => 'desaprobada']);

        $cursos = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . self::CICLO)
            ->assertOk()->json('top_courses');

        $this->assertCount(2, $cursos);
        $this->assertSame(CourseLabel::label(1, '1'), $cursos[0]['curso'], 'El curso con mejor promedio va primero');
        $this->assertEqualsWithDelta(7, $cursos[0]['promedio'], 0.001, '1° 1.ª promedia 10 y 4');
        $this->assertSame(2, $cursos[0]['alumnos']);
        $this->assertSame(2, $cursos[0]['total_materias']);
        $this->assertSame(1, $cursos[0]['aprobadas']);
        $this->assertSame(CourseLabel::label(2, '2'), $cursos[1]['curso']);
        $this->assertEqualsWithDelta(6, $cursos[1]['promedio'], 0.001);
    }

    public function test_el_ranking_trae_diez_filas_aunque_haya_mas(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $a = $this->nuevoAlumno(['anio' => 2, 'division' => '2']);
            $this->estado($a->id, $this->otraMateria->id, 6 + ($i % 3), ['anio' => 2, 'division' => '2']);
        }

        $r = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . self::CICLO)->assertOk();

        $this->assertCount(10, $r->json('top_students'));
        $this->assertCount(1, $r->json('top_courses'), 'Sólo 2° 2.ª tiene notas en este escenario');
    }

    public function test_el_ranking_de_otro_ciclo_lectivo_no_mezcla_las_notas(): void
    {
        $this->estado($this->alumno->id, $this->materia->id, 9);
        $this->estado($this->alumno->id, $this->otraMateria->id, 3,
            ['ciclo_lectivo' => self::CICLO - 1, 'estado_final' => 'desaprobada']);

        $actual = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . self::CICLO)->assertOk();
        $this->assertEqualsWithDelta(9, $actual->json('top_students.0.promedio'), 0.001);
        $this->assertSame(1, $actual->json('top_students.0.materias'));

        $viejo = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . (self::CICLO - 1))->assertOk();
        $this->assertEqualsWithDelta(3, $viejo->json('top_students.0.promedio'), 0.001);
        $this->assertSame(self::CICLO - 1, $viejo->json('ciclo_lectivo'));
    }

    public function test_sin_ciclo_en_el_pedido_se_usa_el_ano_corriente(): void
    {
        $this->estado($this->alumno->id, $this->materia->id, 8, ['ciclo_lectivo' => (int) date('Y')]);

        $r = $this->como('admin')->getJson('/api/dashboard/top-performers')->assertOk();

        $this->assertSame((int) date('Y'), $r->json('ciclo_lectivo'));
        $this->assertEqualsWithDelta(8, $r->json('top_students.0.promedio'), 0.001);
    }

    public function test_un_ciclo_basura_no_rompe_el_panel(): void
    {
        // "abc", "0" o vacío llegan como 0 desde integer(): el panel cae en el
        // año actual en vez de mostrar un ranking inventado o reventar.
        $esteAnio = (int) date('Y');
        foreach (['abc', '0', ''] as $basura) {
            $r = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . $basura)->assertOk();
            $this->assertSame($esteAnio, $r->json('ciclo_lectivo'), "Un ciclo ilegible ('$basura') tiene que caer en el año actual");
            $this->assertIsArray($r->json('top_students'));
        }

        // Un año lejano es un ciclo posible: ranking vacío, no error.
        $lejana = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=1810')->assertOk();
        $this->assertSame(1810, $lejana->json('ciclo_lectivo'));
        $this->assertSame([], $lejana->json('top_students'));
    }

    public function test_el_profesor_solo_ve_su_curso_en_el_ranking(): void
    {
        $dentro = $this->alumno;                        // 1° 1.ª
        $fuera  = $this->nuevoAlumno(['apellido' => 'Lejos', 'nombre' => 'Lía', 'anio' => 2, 'division' => '2']);
        $this->estado($fuera->id, $this->otraMateria->id, 10, ['anio' => 2, 'division' => '2', 'estado_final' => 'aprobada']);
        $this->estado($dentro->id, $this->materia->id, 6, ['estado_final' => 'desaprobada']);

        $r = $this->como('profesor')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . self::CICLO)->assertOk();

        $this->assertSame([$dentro->id], array_column($r->json('top_students'), 'alumno_id'));
        $this->assertSame(CourseLabel::label(1, '1'), $r->json('top_courses.0.curso'));
        $this->assertCount(1, $r->json('top_courses'));
    }

    public function test_una_cuenta_sin_curso_asignado_ve_el_ranking_vacio_en_lugar_de_todos(): void
    {
        $esteAnio = (int) date('Y');
        $this->estado($this->alumno->id, $this->materia->id, 8, ['ciclo_lectivo' => $esteAnio]);
        $suelto = $this->cuentaConRol('suelto@abc.gob.ar', 'profesor');

        $r = $this->withToken($this->tokenDeEmail($suelto->email))
            ->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . $esteAnio)->assertOk();

        $this->assertSame([], $r->json('top_students'), 'Sin cursos asignados no se muestra el rendimiento de toda la escuela');
        $this->assertSame([], $r->json('top_courses'));
    }

    public function test_un_rol_sin_permiso_de_calificaciones_no_descarga_el_ranking(): void
    {
        // Con dashboard.ver abierto pasa el middleware y el 403 lo devuelve el
        // controlador por el alcance de calificaciones, que es lo que se prueba.
        $slug = $this->crearRol('Sin Notas', 4, ['dashboard.ver'], ['calificaciones' => ['ver' => Alcances::NADA]]);
        $token = $this->tokenDeEmail($this->cuentaConRol('sin.notas@abc.gob.ar', $slug)->email);

        $this->withToken($token)->getJson('/api/dashboard/top-performers')->assertForbidden();
    }

    public function test_un_rol_personalizado_sin_la_casilla_del_panel_no_pede_nada(): void
    {
        // Los tres endpoints no llevan role: en la ruta, pero el middleware
        // 'permiso' si exige dashboard.ver. Sin esa casilla el panel queda
        // vacío para el rol, no con un 200 a medias.
        $slug = $this->crearRol('Sin Panel', 3, ['alumnos.ver']);
        $token = $this->tokenDeEmail($this->cuentaConRol('sin.panel@abc.gob.ar', $slug)->email);

        foreach (['resumen', 'preceptores', 'top-performers'] as $ruta) {
            $this->withToken($token)->getJson('/api/dashboard/' . $ruta)->assertForbidden();
        }
    }

    public function test_el_ranking_no_publica_datos_personales_de_mas(): void
    {
        // La tarjeta del panel sólo necesita nombre, curso y notas. Si algún día
        // el select se ensancha, DNI y fechas de nacimiento no pueden colarse.
        $this->estado($this->alumno->id, $this->materia->id, 10);

        $bruto = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . self::CICLO)
            ->assertOk()->getContent();
        $fila = $this->como('admin')->getJson('/api/dashboard/top-performers?ciclo_lectivo=' . self::CICLO)
            ->assertOk()->json('top_students.0');

        $this->assertSame(
            ['alumno_id', 'nombre', 'apellido', 'curso', 'promedio', 'materias', 'aprobadas'],
            array_keys($fila)
        );
        $this->assertStringNotContainsString('40000001', $bruto, 'El ranking no lleva el DNI');
    }

    public function test_los_tres_endpoints_del_panel_devuelven_json_bien_formado(): void
    {
        foreach (['/api/dashboard/resumen', '/api/dashboard/preceptores', '/api/dashboard/top-performers'] as $ruta) {
            /** @var TestResponse $r */
            $r = $this->como('admin')->getJson($ruta)->assertOk();
            $this->assertIsArray($r->json());
            $this->assertNull($r->json('errors'), "La $ruta respondió un error de validación");
        }
    }
}
