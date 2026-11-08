<?php

namespace Tests\Feature;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Calificaciones\AlumnoCalificacion;
use App\Dominios\Calificaciones\AlumnoMateriaEstado;
use App\Dominios\Calificaciones\VentanaNotaFinal;
use App\Dominios\Cursos\Materia;
use App\Dominios\Sistema\ActivityLog;
use Illuminate\Testing\TestResponse;

/**
 * Calificaciones por HTTP: la ficha de notas, la carga individual y por lote,
 * el resumen, la planilla del curso y la ventana de nota final.
 *
 * El profesor del fixture dicta Matemática en 1° 1.ª y nada más: Lengua y
 * Literatura (misma división) y todo 2° 2.ª quedan fuera de su alcance.
 */
class CalificacionesApiTest extends ApiTestCase
{
    private const CICLO = 2026;

    /** Una entrada del formulario nuevo, con los campos que pide la validación. */
    private function entrada(array $cambios = []): array
    {
        return array_merge([
            'anio'             => 1,
            'division'         => '1',
            'ciclo_lectivo'    => self::CICLO,
            'periodo'          => 1,
            'materia_id'       => $this->materia->id,
            'notas'            => [['valor' => 8, 'fecha' => '2026-03-10']],
        ], $cambios);
    }

    private function guardar(array $entradas, string $rol = 'admin', ?Alumno $alumno = null): TestResponse
    {
        return $this->como($rol)->putJson(
            '/api/alumnos/' . ($alumno ?? $this->alumno)->id . '/calificaciones',
            ['entradas' => $entradas]
        );
    }

    /** Deja la ventana de nota final abierta (o cerrada) para las pruebas de finales. */
    private function ventana(string $alcance = 'ambos', bool $abierta = true): void
    {
        $this->como('admin')->postJson('/api/calificaciones/ventana', [
            'fecha_inicio'  => $abierta ? now()->subDay()->toDateString() : now()->subDays(3)->toDateString(),
            'hora_inicio'   => '00:00',
            'fecha_fin'     => $abierta ? now()->addDay()->toDateString() : now()->subDay()->toDateString(),
            'hora_fin'      => '23:59',
            'ciclo_lectivo' => self::CICLO,
            'alcance'       => $alcance,
        ])->assertOk();
    }

    private function estadoDe(?Alumno $alumno = null): ?AlumnoMateriaEstado
    {
        return AlumnoMateriaEstado::where('alumno_id', ($alumno ?? $this->alumno)->id)
            ->where('materia_id', $this->materia->id)
            ->where('ciclo_lectivo', self::CICLO)
            ->first();
    }

    private function urlPlanilla(array $extra = [], string $ruta = '/calificaciones/planilla'): string
    {
        return $ruta . '?' . http_build_query(array_merge([
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
        ], $extra));
    }

    private function ticketDe(string $rol): string
    {
        return $this->como($rol)->postJson('/api/print/ticket')->assertOk()->json('ticket');
    }

    // ===== Ficha de notas =====

    public function test_la_ficha_de_notas_pide_sesion(): void
    {
        $this->sinSesion()->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertUnauthorized();
    }

    public function test_la_ficha_sin_notas_no_inventa_promedios(): void
    {
        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertOk()
            ->assertJsonPath('alumno.apellido', 'Álvarez')
            ->assertJsonPath('detalle', [])
            ->assertJsonPath('promedio_general', null)
            ->assertJsonPath('anios_en_resultado', []);
    }

    public function test_la_ficha_de_un_alumno_que_no_existe_da_404(): void
    {
        $this->como('admin')->getJson('/api/alumnos/999999/calificaciones')->assertNotFound();
    }

    public function test_guardar_notas_y_leerlas_en_la_ficha(): void
    {
        $this->guardar([
            $this->entrada(['notas' => [['valor' => 8], ['valor' => 6]]]),
        ])->assertOk();

        $r = $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertOk()
            ->assertJsonPath('detalle.0.materia_nombre', 'Matemática')
            ->assertJsonPath('detalle.0.anio', 1)
            ->assertJsonPath('detalle.0.division', '1')
            ->assertJsonPath('detalle.0.ciclo_lectivo', self::CICLO)
            ->assertJsonPath('promedio_general', 7);

        $this->assertSame(7.0, (float) $r->json('detalle.0.promedios.1'));
        $this->assertNull($r->json('detalle.0.promedios.2'));
        $this->assertCount(2, $r->json('detalle.0.notas'));

        // El mapa legacy usa la misma clave que arma el formulario al guardar.
        $this->assertArrayHasKey('1.º|1|Matemática', $r->json('notas_por_materia'));
    }

    public function test_el_profesor_ve_la_ficha_de_su_curso_pero_no_la_de_otro(): void
    {
        $ajeno = Alumno::where('anio', 2)->where('division', '2')->firstOrFail();

        $this->como('profesor')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')->assertOk();
        $this->como('profesor')->getJson('/api/alumnos/' . $ajeno->id . '/calificaciones')->assertForbidden();
    }

    public function test_el_preceptor_ve_todas_las_fichas_aunque_no_edite_todas(): void
    {
        $ajeno = Alumno::where('anio', 2)->where('division', '2')->firstOrFail();

        // Ver es de todos los cursos; escribir queda sólo en el asignado.
        $this->como('preceptor')->getJson('/api/alumnos/' . $ajeno->id . '/calificaciones')->assertOk();
        $this->guardar([$this->entrada(['anio' => 2, 'division' => '2'])], 'preceptor', $ajeno)
            ->assertForbidden();
    }

    public function test_una_cuenta_sin_rol_no_ve_calificaciones(): void
    {
        $this->como('sin_rol')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertForbidden();
        $this->guardar([$this->entrada()], 'sin_rol')->assertForbidden();
    }

    public function test_los_filtros_de_la_ficha_recortan_de_verdad(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 8]]]),
            $this->entrada(['periodo' => 2, 'notas' => [['valor' => 4]]]),
            $this->entrada(['ciclo_lectivo' => 2025, 'periodo' => 1, 'notas' => [['valor' => 10]]]),
        ])->assertOk();

        $base = '/api/alumnos/' . $this->alumno->id . '/calificaciones';

        $this->como('admin')->getJson($base . '?ciclo=2025&periodo=1')
            ->assertOk()->assertJsonPath('promedio_general', 10)
            ->assertJsonCount(1, 'detalle');

        $this->como('admin')->getJson($base . '?ciclo=' . self::CICLO . '&periodo=2')
            ->assertOk()->assertJsonPath('promedio_general', 4);

        // Sin filtro entran los dos ciclos: (8 + 4 + 10) / 3.
        $this->como('admin')->getJson($base)->assertOk()->assertJsonPath('promedio_general', 7.33);
    }

    public function test_el_filtro_por_anio_de_cursada_separa_los_ciclos(): void
    {
        $this->guardar([
            $this->entrada(['anio' => 1, 'notas' => [['valor' => 8]]]),
            $this->entrada(['anio' => 2, 'division' => '2', 'notas' => [['valor' => 4]]]),
        ])->assertOk();

        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones?anio=2')
            ->assertOk()
            ->assertJsonPath('promedio_general', 4)
            ->assertJsonPath('anios_en_resultado', [2]);
    }

    // ===== Carga de notas =====

    public function test_un_payload_sin_notas_se_rechaza(): void
    {
        $this->como('admin')->putJson('/api/alumnos/' . $this->alumno->id . '/calificaciones', [])
            ->assertStatus(422);
        $this->guardar([])->assertStatus(422);
    }

    public function test_la_nota_se_guarda_con_coma_decimal(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => '8,5']]])])->assertOk();

        $this->assertSame('8.5', AlumnoCalificacion::where('alumno_id', $this->alumno->id)->value('valor'));
        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertOk()->assertJsonPath('promedio_general', 8.5);
    }

    public function test_la_nota_fuera_de_escala_no_se_guarda(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => 11], ['valor' => -3], ['valor' => 'hola']]])])
            ->assertOk();

        // Ninguna de las tres es una nota de la escuela (1 a 10): no quedan filas.
        $this->assertSame(0, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
    }

    public function test_el_cero_borra_la_nota_en_vez_de_guardar_un_cero(): void
    {
        $this->guardar([$this->entrada()])->assertOk();
        $this->assertSame(1, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());

        // La escuela no tiene nota 0: cargar 0 es el modo de quitar la nota.
        $this->guardar([$this->entrada(['notas' => [['valor' => 0]]])])->assertOk();
        $this->assertSame(0, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
        $this->assertSame(0, \DB::table('alumno_promedios')->where('alumno_id', $this->alumno->id)->count());
    }

    public function test_un_cuatrimestre_no_acepta_mas_de_cuatro_parciales(): void
    {
        $this->guardar([$this->entrada(['notas' => array_fill(0, 5, ['valor' => 8])])])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entradas.0.notas']);
    }

    public function test_guardar_dos_veces_el_mismo_cuatrimestre_no_duplica(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => 8], ['valor' => 6]]])])->assertOk();
        $this->guardar([$this->entrada(['notas' => [['valor' => 9]]])])->assertOk();

        $this->assertSame(1, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertOk()->assertJsonPath('promedio_general', 9);
    }

    public function test_corregir_un_cuatrimestre_no_toca_el_otro(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 8]]]),
            $this->entrada(['periodo' => 2, 'notas' => [['valor' => 4]]]),
        ])->assertOk();

        $this->guardar([$this->entrada(['periodo' => 1, 'notas' => [['valor' => 10]]])])->assertOk();

        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertOk()->assertJsonPath('promedio_general', 7);
        $this->assertSame(2, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
    }

    public function test_la_fecha_de_la_nota_no_termina_en_1970(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => 8, 'fecha' => '2026-03-15']]])])->assertOk();

        $this->assertSame('2026-03-15', AlumnoCalificacion::where('alumno_id', $this->alumno->id)->value('fecha')->toDateString());
        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertOk()->assertJsonPath('detalle.0.notas.0.fecha', '2026-03-15');
    }

    public function test_sin_fecha_la_nota_queda_en_el_dia_de_hoy(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => 8]]])])->assertOk();

        $this->assertSame(
            now()->toDateString(),
            AlumnoCalificacion::where('alumno_id', $this->alumno->id)->value('fecha')->toDateString()
        );
    }

    public function test_el_tea_se_guarda_como_texto_y_no_como_cero(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => 'tea']]])])->assertOk();

        $this->assertSame('TEA', AlumnoCalificacion::where('alumno_id', $this->alumno->id)->value('valor'));
        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertOk()
            ->assertJsonPath('detalle.0.notas.0.valor', 'TEA')
            ->assertJsonPath('promedio_general', null);

        // Sin nota numérica el período queda pendiente, no desaprobado.
        $this->assertSame('pendiente', $this->estadoDe()->p1_estado);
    }

    public function test_el_tea_no_baja_el_promedio_de_las_notas_numericas(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => 8], ['valor' => 'TEA']]])])->assertOk();

        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')
            ->assertOk()->assertJsonPath('promedio_general', 8);
    }

    public function test_el_payload_legacy_crea_la_materia_y_guarda_en_periodo_cero(): void
    {
        $this->como('admin')->putJson('/api/alumnos/' . $this->alumno->id . '/calificaciones', [
            'notas_por_materia' => [
                '1ro|1|Taller de Prueba' => [['valor' => '7,5', 'fecha' => '15/03/2026']],
            ],
        ])->assertOk();

        $materia = Materia::where('nombre', 'Taller de Prueba')->firstOrFail();
        $this->assertSame(1, (int) $materia->anio);

        $fila = AlumnoCalificacion::where('alumno_id', $this->alumno->id)->firstOrFail();
        $this->assertSame('7.5', $fila->valor);
        $this->assertSame(0, (int) $fila->periodo);
        $this->assertSame('2026-03-15', $fila->fecha->toDateString());
        $this->assertSame(2026, (int) $fila->ciclo_lectivo);
    }

    public function test_el_profesor_no_carga_notas_de_una_materia_que_no_dicta(): void
    {
        $this->guardar([$this->entrada(['materia_id' => $this->otraMateria->id])], 'profesor')
            ->assertForbidden();

        $this->assertSame(0, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
    }

    public function test_el_profesor_carga_las_notas_de_su_materia(): void
    {
        $this->guardar([$this->entrada()], 'profesor')->assertOk();

        $this->assertSame(1, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
    }

    public function test_el_profesor_no_carga_notas_en_un_curso_ajeno(): void
    {
        $ajeno = Alumno::where('anio', 2)->where('division', '2')->firstOrFail();

        $this->guardar([$this->entrada(['anio' => 2, 'division' => '2'])], 'profesor', $ajeno)
            ->assertForbidden();
    }

    public function test_el_preceptor_carga_notas_solo_en_su_curso(): void
    {
        $this->guardar([$this->entrada()], 'preceptor')->assertOk();
        $this->assertSame(1, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
    }

    public function test_la_casilla_de_cargar_notas_manda_de_verdad(): void
    {
        // Dirección le quita "Cargar y editar notas" al preceptor desde Roles y
        // permisos: el endpoint tiene que cerrarse aunque el rango no cambie.
        \App\Dominios\Usuarios\RolConfig::updateOrCreate(
            ['rol' => 'preceptor'],
            [
                'nombre' => 'Preceptor', 'nivel' => 2, 'tipo' => 'sistema',
                'permitidos' => array_values(array_diff(
                    \App\Support\Permisos::base('preceptor'),
                    ['calificaciones.editar']
                )),
            ]
        );
        \App\Dominios\Usuarios\RolConfig::olvidarRegistro();

        $this->guardar([$this->entrada()], 'preceptor')->assertForbidden();
        $this->como('preceptor')->getJson('/api/alumnos/' . $this->alumno->id . '/calificaciones')->assertOk();
    }

    // ===== Promedios y estado de la materia =====

    public function test_los_promedios_quedan_persistidos_por_materia_y_periodo(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 8], ['valor' => 6]]]),
            $this->entrada(['periodo' => 2, 'notas' => [['valor' => 9]]]),
        ])->assertOk();

        $r = $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/promedios')
            ->assertOk()
            ->assertJsonPath('materias.0.materia_nombre', 'Matemática')
            ->assertJsonCount(2, 'materias.0.promedios');

        $porPeriodo = collect($r->json('materias.0.promedios'))->keyBy('periodo');
        $this->assertSame(7.0, (float) $porPeriodo[1]['promedio']);
        $this->assertSame(9.0, (float) $porPeriodo[2]['promedio']);
        $this->assertSame(2, $porPeriodo[1]['notas_count']);
    }

    public function test_un_cuatrimestre_solo_con_tea_no_deja_un_promedio_cero(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => 'TEA']]])])->assertOk();

        $fila = \DB::table('alumno_promedios')->where('alumno_id', $this->alumno->id)->first();
        $this->assertNotNull($fila, 'El promedio del cuatrimestre tiene que quedar registrado');
        $this->assertNull($fila->promedio, 'Un TEA no es un 0: no hay promedio numérico que mostrar');
    }

    public function test_los_promedios_de_un_alumno_que_no_existe_dan_404(): void
    {
        $this->como('admin')->getJson('/api/alumnos/999999/promedios')->assertNotFound();
    }

    public function test_dos_cuatrimestres_aprobados_cierran_la_materia(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 8]]]),
            $this->entrada(['periodo' => 2, 'notas' => [['valor' => 10]]]),
        ])->assertOk();

        $estado = $this->estadoDe();
        $this->assertSame('aprobado', $estado->p1_estado);
        $this->assertSame('aprobado', $estado->p2_estado);
        $this->assertSame('aprobada', $estado->estado_final);
        $this->assertSame(9.0, (float) $estado->nota_final);
    }

    public function test_un_cuatrimestre_desaprobado_manda_a_intensificar(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 4]]]),
            $this->entrada(['periodo' => 2, 'notas' => [['valor' => 8]]]),
        ])->assertOk();

        $estado = $this->estadoDe();
        $this->assertSame('desaprobado', $estado->p1_estado);
        $this->assertSame('aprobado', $estado->p2_estado);
        $this->assertSame('intensifica_diciembre', $estado->estado_final);
        $this->assertNull($estado->nota_final);
    }

    public function test_la_intensificacion_aprueba_con_cuatro(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 4]]]),
            $this->entrada(['periodo' => 0, 'tipo_calificacion' => 'intensificacion_diciembre', 'notas' => [['valor' => 4]]]),
        ])->assertOk();

        $estado = $this->estadoDe();
        $this->assertSame('aprobada', $estado->estado_final);
        $this->assertSame(4.0, (float) $estado->nota_final);
        $this->assertSame(4.0, (float) $estado->nota_intensificacion_diciembre);
    }

    public function test_la_intensificacion_desaprobada_deja_la_materia_en_la_siguiente_mesa(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 4]]]),
            $this->entrada(['periodo' => 0, 'tipo_calificacion' => 'intensificacion_diciembre', 'notas' => [['valor' => 2]]]),
        ])->assertOk();

        $this->assertSame('intensifica_febrero', $this->estadoDe()->estado_final);
    }

    public function test_borrar_las_notas_deja_la_materia_pendiente(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 8]]]),
            $this->entrada(['periodo' => 2, 'notas' => [['valor' => 9]]]),
        ])->assertOk();
        $this->assertSame('aprobada', $this->estadoDe()->estado_final);

        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 0]]]),
            $this->entrada(['periodo' => 2, 'notas' => [['valor' => 0]]]),
        ])->assertOk();

        $estado = $this->estadoDe();
        $this->assertSame('pendiente', $estado->p1_estado);
        $this->assertSame('pendiente', $estado->estado_final);
        $this->assertNull($estado->nota_final);
    }

    public function test_los_anios_con_notas_salen_de_las_notas_guardadas(): void
    {
        $this->guardar([
            $this->entrada(['anio' => 1]),
            $this->entrada(['anio' => 2, 'division' => '2']),
        ])->assertOk();

        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/anios-con-notas')
            ->assertOk()->assertJsonPath('anios', [1, 2]);
    }

    // ===== Guardado por lote =====

    public function test_el_lote_guarda_varios_alumnos_y_deja_una_sola_tarjeta(): void
    {
        $ids = Alumno::where('anio', 1)->where('division', '1')->orderBy('id')->pluck('id');
        $this->assertCount(3, $ids);

        $lote = $ids->map(fn ($id) => [
            'alumno_id' => $id,
            'entradas'  => [$this->entrada(['notas' => [['valor' => 8]]])],
        ])->all();

        $this->como('admin')->putJson('/api/calificaciones/batch', ['alumnos' => $lote])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('guardados', 3)
            ->assertJsonPath('fallidos', []);

        $this->assertSame(3, AlumnoCalificacion::whereIn('alumno_id', $ids)->count());
        $this->assertSame(1, ActivityLog::where('action_type', 'calificaciones.update')->count());
        $this->assertStringContainsString(
            'Matemática',
            ActivityLog::where('action_type', 'calificaciones.update')->value('action')
        );
    }

    public function test_el_lote_con_un_alumno_inexistente_no_guarda_nada(): void
    {
        $this->como('admin')->putJson('/api/calificaciones/batch', ['alumnos' => [
            ['alumno_id' => $this->alumno->id, 'entradas' => [$this->entrada()]],
            ['alumno_id' => 999999, 'entradas' => [$this->entrada()]],
        ]])->assertNotFound()->assertJsonPath('ok', false);

        $this->assertSame(0, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
    }

    public function test_el_lote_respeta_el_alcance_del_profesor(): void
    {
        $ajeno = Alumno::where('anio', 2)->where('division', '2')->firstOrFail();

        $this->como('profesor')->putJson('/api/calificaciones/batch', ['alumnos' => [
            ['alumno_id' => $ajeno->id, 'entradas' => [$this->entrada(['anio' => 2, 'division' => '2'])]],
        ]])->assertForbidden();

        $this->como('profesor')->putJson('/api/calificaciones/batch', ['alumnos' => [
            ['alumno_id' => $this->alumno->id, 'entradas' => [$this->entrada(['materia_id' => $this->otraMateria->id])]],
        ]])->assertForbidden();
    }

    public function test_el_lote_valida_antes_de_escribir(): void
    {
        $this->como('admin')->putJson('/api/calificaciones/batch', ['alumnos' => []])
            ->assertStatus(422);
        $this->como('admin')->putJson('/api/calificaciones/batch', ['alumnos' => [
            ['alumno_id' => $this->alumno->id, 'entradas' => [$this->entrada(['periodo' => 7])]],
        ]])->assertStatus(422);

        $this->assertSame(0, AlumnoCalificacion::count());
    }

    // ===== Por curso y materia =====

    public function test_por_curso_materia_pide_los_tres_parametros(): void
    {
        $this->como('admin')->getJson('/api/calificaciones/por-curso-materia')->assertStatus(422);
        $this->como('admin')->getJson('/api/calificaciones/por-curso-materia?anio=1&division=1')->assertStatus(422);
    }

    public function test_por_curso_materia_con_una_materia_inexistente_da_404(): void
    {
        $this->como('admin')
            ->getJson('/api/calificaciones/por-curso-materia?anio=1&division=1&materia_id=999999')
            ->assertNotFound();
    }

    public function test_por_curso_materia_trae_el_curso_completo_con_su_profesor(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => 8], ['valor' => 6]]])])->assertOk();

        $r = $this->como('admin')
            ->getJson('/api/calificaciones/por-curso-materia?anio=1&division=1&materia_id=' . $this->materia->id . '&ciclo=' . self::CICLO)
            ->assertOk()
            ->assertJsonPath('materia.nombre', 'Matemática')
            ->assertJsonPath('curso', ['anio' => 1, 'division' => '1'])
            ->assertJsonPath('profesor', 'DePrueba, Profesor')
            ->assertJsonPath('ciclo', self::CICLO)
            ->assertJsonCount(3, 'alumnos');

        $ana = collect($r->json('alumnos'))->firstWhere('apellido', 'Álvarez');
        $this->assertSame(7.0, (float) $ana['promedio_p1']);
        $this->assertCount(2, $ana['calificaciones']['p1']);
        $this->assertSame('aprobado', $ana['estado']['p1_estado']);

        // El que no tiene notas queda en la planilla igual, con todo vacío.
        $bruno = collect($r->json('alumnos'))->firstWhere('apellido', 'Benítez');
        $this->assertNull($bruno['promedio_p1']);
        $this->assertSame([], $bruno['calificaciones']['p1']);
    }

    public function test_por_curso_materia_separa_preliminar_final_e_intensificacion(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 5]]]),
            $this->entrada(['periodo' => 1, 'tipo_calificacion' => 'preliminar', 'notas' => [['valor' => 'TEA', 'descripcion' => 'TEA']]]),
            $this->entrada(['periodo' => 0, 'tipo_calificacion' => 'intensificacion_diciembre', 'notas' => [['valor' => 6]]]),
        ])->assertOk();

        $this->ventana();
        $this->guardar([
            $this->entrada(['periodo' => 1, 'tipo_calificacion' => 'final_cuatrimestre', 'notas' => [['valor' => 7]]]),
        ])->assertOk();

        $r = $this->como('admin')
            ->getJson('/api/calificaciones/por-curso-materia?anio=1&division=1&materia_id=' . $this->materia->id . '&ciclo=' . self::CICLO)
            ->assertOk();

        $ana = collect($r->json('alumnos'))->firstWhere('apellido', 'Álvarez');
        $this->assertCount(1, $ana['calificaciones']['p1']);
        $this->assertSame('TEA', $ana['calificaciones']['p1_preliminar'][0]['valor']);
        $this->assertSame(7.0, (float) $ana['calificaciones']['p1_final_cuatri'][0]['valor']);
        $this->assertCount(1, $ana['intensificaciones']);
        // La intensificación y la preliminar no entran al promedio del cuatrimestre.
        $this->assertSame(5.0, (float) $ana['promedio_p1']);
    }

    public function test_el_profesor_no_ve_la_planilla_de_una_materia_ajena(): void
    {
        $this->como('profesor')
            ->getJson('/api/calificaciones/por-curso-materia?anio=1&division=1&materia_id=' . $this->otraMateria->id)
            ->assertForbidden();
    }

    public function test_el_profesor_no_ve_la_planilla_de_un_curso_ajeno(): void
    {
        $historia = Materia::where('anio', 2)->firstOrFail();

        $this->como('profesor')
            ->getJson('/api/calificaciones/por-curso-materia?anio=2&division=2&materia_id=' . $historia->id)
            ->assertForbidden();
    }

    // ===== Resumen =====

    public function test_el_resumen_promedia_por_alumno(): void
    {
        $this->guardar([
            $this->entrada(['periodo' => 1, 'notas' => [['valor' => 8], ['valor' => 6]]]),
            $this->entrada(['periodo' => 2, 'notas' => [['valor' => 9]]]),
        ])->assertOk();

        $r = $this->como('admin')->getJson('/api/calificaciones/resumen?ciclo=' . self::CICLO)->assertOk();

        $ana = collect($r->json())->firstWhere('alumno_id', $this->alumno->id);
        $this->assertSame(7.0, (float) $ana['p1']);
        $this->assertSame(9.0, (float) $ana['p2']);
        $this->assertSame(7.67, (float) $ana['general']);
    }

    public function test_el_resumen_no_convierte_un_tea_en_cero(): void
    {
        $this->guardar([$this->entrada(['periodo' => 1, 'notas' => [['valor' => 'TEA']]])])->assertOk();

        $r = $this->como('admin')->getJson('/api/calificaciones/resumen?ciclo=' . self::CICLO)->assertOk();
        $ana = collect($r->json())->firstWhere('alumno_id', $this->alumno->id);

        $this->assertNotNull($ana, 'El alumno con TEA tiene que seguir apareciendo en el resumen');
        $this->assertNull($ana['p1'], 'Un TEA no es un 0: el cuatrimestre no tiene promedio');
    }

    public function test_el_resumen_no_mezcla_ciclos(): void
    {
        $this->guardar([
            $this->entrada(['ciclo_lectivo' => 2025, 'notas' => [['valor' => 2]]]),
            $this->entrada(['ciclo_lectivo' => self::CICLO, 'notas' => [['valor' => 8]]]),
        ])->assertOk();

        $r = $this->como('admin')->getJson('/api/calificaciones/resumen?ciclo=' . self::CICLO)->assertOk();
        $ana = collect($r->json())->firstWhere('alumno_id', $this->alumno->id);
        $this->assertSame(8.0, (float) $ana['p1']);
    }

    public function test_el_resumen_del_profesor_queda_en_sus_cursos(): void
    {
        $ajeno = Alumno::where('anio', 2)->where('division', '2')->firstOrFail();
        $this->guardar([$this->entrada()])->assertOk();
        $this->guardar([$this->entrada(['anio' => 2, 'division' => '2'])], 'admin', $ajeno)->assertOk();

        $r = $this->como('profesor')->getJson('/api/calificaciones/resumen')->assertOk();
        $ids = collect($r->json())->pluck('alumno_id')->all();

        $this->assertContains($this->alumno->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);
    }

    public function test_una_cuenta_sin_rol_no_ve_el_resumen(): void
    {
        $this->como('sin_rol')->getJson('/api/calificaciones/resumen')->assertForbidden();
    }

    // ===== Ventana de nota final =====

    public function test_sin_configurar_la_ventana_esta_cerrada(): void
    {
        $this->como('preceptor')->getJson('/api/calificaciones/ventana')
            ->assertOk()
            ->assertJsonPath('abierta', false)
            ->assertJsonPath('alcance', 'ambos')
            ->assertJsonPath('abierta_p1', false)
            ->assertJsonPath('abierta_p2', false);
    }

    public function test_la_nota_final_no_se_carga_con_la_ventana_cerrada(): void
    {
        $this->guardar([$this->entrada(['tipo_calificacion' => 'final_anio', 'notas' => [['valor' => 8]]])])
            ->assertForbidden()->assertJsonPath('ok', false);
        $this->guardar([$this->entrada(['tipo_calificacion' => 'final_cuatrimestre', 'notas' => [['valor' => 8]]])])
            ->assertForbidden();

        $this->assertSame(0, AlumnoCalificacion::count());
    }

    public function test_la_ventana_abierta_habilita_la_nota_final(): void
    {
        $this->ventana();

        $this->guardar([
            $this->entrada(['periodo' => 1, 'tipo_calificacion' => 'final_cuatrimestre', 'notas' => [['valor' => 8]]]),
            $this->entrada(['periodo' => 2, 'tipo_calificacion' => 'final_cuatrimestre', 'notas' => [['valor' => 10]]]),
        ])->assertOk();

        // Con las dos finales de cuatrimestre, la del año es su promedio.
        $estado = $this->estadoDe();
        $this->assertSame(9.0, (float) $estado->nota_final);
        $this->assertSame('aprobada', $estado->estado_final);
    }

    public function test_la_ventana_de_un_solo_cuatrimestre_no_habilita_el_otro(): void
    {
        $this->ventana('p1');

        $this->guardar([$this->entrada(['periodo' => 1, 'tipo_calificacion' => 'final_cuatrimestre', 'notas' => [['valor' => 8]]])])
            ->assertOk();
        $this->guardar([$this->entrada(['periodo' => 2, 'tipo_calificacion' => 'final_cuatrimestre', 'notas' => [['valor' => 8]]])])
            ->assertForbidden();

        $this->como('admin')->getJson('/api/calificaciones/ventana')
            ->assertOk()->assertJsonPath('abierta_p1', true)->assertJsonPath('abierta_p2', false);
    }

    public function test_configurar_la_ventana_es_de_gestion(): void
    {
        $payload = [
            'fecha_inicio' => now()->toDateString(), 'hora_inicio' => '08:00',
            'fecha_fin' => now()->addDay()->toDateString(), 'hora_fin' => '18:00',
        ];

        foreach (['preceptor', 'profesor', 'sin_rol'] as $rol) {
            $this->como($rol)->postJson('/api/calificaciones/ventana', $payload)->assertForbidden();
        }
        $this->assertSame(0, VentanaNotaFinal::count());

        foreach (['admin', 'directivo', 'jefe_preceptores', 'prosecretaria'] as $rol) {
            $this->como($rol)->postJson('/api/calificaciones/ventana', $payload)->assertOk();
        }
    }

    public function test_la_ventana_rechaza_un_fin_anterior_al_inicio(): void
    {
        $this->como('admin')->postJson('/api/calificaciones/ventana', [
            'fecha_inicio' => '2026-03-10', 'hora_inicio' => '08:00',
            'fecha_fin' => '2026-03-09', 'hora_fin' => '18:00',
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->como('admin')->postJson('/api/calificaciones/ventana', [
            'fecha_inicio' => '2026-03-10', 'hora_inicio' => '08:00',
            'fecha_fin' => '2026-03-10', 'hora_fin' => '08:00',
        ])->assertStatus(422);

        $this->assertSame(0, VentanaNotaFinal::count());
    }

    public function test_la_ventana_es_de_fila_unica(): void
    {
        $this->ventana('p1');
        $this->ventana('p2');

        $this->assertSame(1, VentanaNotaFinal::count());
        $this->como('admin')->getJson('/api/calificaciones/ventana')
            ->assertOk()->assertJsonPath('alcance', 'p2');
    }

    public function test_cerrar_la_ventana_bloquea_la_nota_final_al_instante(): void
    {
        $this->ventana();
        $this->como('admin')->postJson('/api/calificaciones/ventana/cerrar')
            ->assertOk()->assertJsonPath('abierta', false);

        $this->como('admin')->getJson('/api/calificaciones/ventana')->assertOk()->assertJsonPath('abierta', false);
        $this->guardar([$this->entrada(['tipo_calificacion' => 'final_anio', 'notas' => [['valor' => 8]]])])
            ->assertForbidden();

        // Cerrar dos veces no tiene sentido y avisa en vez de romper.
        $this->como('admin')->postJson('/api/calificaciones/ventana/cerrar')
            ->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_cerrar_la_ventana_sin_configurar_avisa(): void
    {
        $this->como('admin')->postJson('/api/calificaciones/ventana/cerrar')->assertStatus(422);
    }

    public function test_cerrar_la_ventana_es_de_gestion(): void
    {
        $this->ventana();
        $this->como('preceptor')->postJson('/api/calificaciones/ventana/cerrar')->assertForbidden();
        $this->assertTrue(VentanaNotaFinal::actual()->estaAbierta());
    }

    public function test_la_ventana_queda_en_el_historial(): void
    {
        $this->ventana();
        $this->como('admin')->postJson('/api/calificaciones/ventana/cerrar')->assertOk();

        $this->assertSame(1, ActivityLog::where('action_type', 'ventana.update')->count());
        $this->assertSame(1, ActivityLog::where('action_type', 'ventana.cerrar')->count());
    }

    // ===== Planilla del curso: imprimir, Excel y guardar =====

    public function test_la_planilla_se_imprime_con_ticket_y_sin_datos_personales(): void
    {
        $this->guardar([$this->entrada()])->assertOk();
        $ticket = $this->ticketDe('admin');

        $r = $this->sinSesion()->get($this->urlPlanilla(['ticket' => $ticket]))->assertOk();
        $html = $r->baseResponse->getContent();

        $this->assertStringContainsString('Álvarez', $html);
        $this->assertStringContainsString('Matemática', $html);
        $this->assertStringNotContainsString('40000001', $html);
        $this->assertStringContainsString('editable-mode', $html);
    }

    public function test_la_planilla_sin_sesion_no_se_abre(): void
    {
        $this->sinSesion()->get($this->urlPlanilla())->assertUnauthorized();
    }

    public function test_la_planilla_de_un_curso_ajeno_no_se_imprime(): void
    {
        $ticket = $this->ticketDe('profesor');
        $historia = Materia::where('anio', 2)->firstOrFail();

        $this->sinSesion()->get($this->urlPlanilla([
            'ticket' => $ticket, 'anio' => 2, 'division' => '2', 'materia_id' => $historia->id,
        ]))->assertForbidden();
    }

    public function test_editable_en_la_url_no_le_da_permiso_a_nadie(): void
    {
        // El profesor no dicta Lengua: aunque fuerce editable=1 la planilla
        // tiene que abrirse en modo lectura, sin inputs para escribir notas.
        $ticket = $this->ticketDe('profesor');

        $r = $this->sinSesion()->get($this->urlPlanilla([
            'ticket' => $ticket, 'materia_id' => $this->otraMateria->id, 'editable' => 1,
        ]));

        // Su alcance de lectura es por curso, así que la planilla abre; lo que
        // no puede es editarla.
        $r->assertOk();
        $this->assertStringNotContainsString('editable-mode', $r->baseResponse->getContent());
    }

    public function test_la_planilla_sin_parametros_avisa(): void
    {
        $ticket = $this->ticketDe('admin');
        $this->sinSesion()->get('/calificaciones/planilla?ticket=' . $ticket)->assertStatus(422);
    }

    public function test_el_excel_de_la_planilla_se_descarga_completo(): void
    {
        $this->guardar([$this->entrada()])->assertOk();
        $ticket = $this->ticketDe('admin');

        $r = $this->sinSesion()->get($this->urlPlanilla(['ticket' => $ticket], '/calificaciones/planilla/excel'))->assertOk();

        $cuerpo = $r->baseResponse->getContent();
        $this->assertStringStartsWith('PK', $cuerpo, 'Un .xlsx es un ZIP: tiene que empezar con PK');
        $this->assertSame(strlen($cuerpo), (int) $r->headers->get('Content-Length'));
        $this->assertStringContainsString('spreadsheetml', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('Planilla-Matematica-1-1-' . self::CICLO . '.xlsx', $r->headers->get('Content-Disposition'));
    }

    public function test_guardar_la_planilla_deja_las_notas_de_todo_el_curso(): void
    {
        $ids = Alumno::where('anio', 1)->where('division', '1')->orderBy('id')->pluck('id');

        $this->como('admin')->postJson('/calificaciones/planilla/guardar', [
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
            'alumnos' => $ids->map(fn ($id) => [
                'alumno_id' => $id,
                'p1_vp' => ['8', '6,5'],
                'p2_vp' => ['9'],
            ])->all(),
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(9, AlumnoCalificacion::where('materia_id', $this->materia->id)->count());
        $this->assertSame('6.5', AlumnoCalificacion::where('alumno_id', $ids[0])->where('periodo', 1)->orderBy('id')->get()->last()->valor);

        $r = $this->como('admin')
            ->getJson('/api/calificaciones/por-curso-materia?anio=1&division=1&materia_id=' . $this->materia->id . '&ciclo=' . self::CICLO)
            ->assertOk();
        $ana = collect($r->json('alumnos'))->firstWhere('apellido', 'Álvarez');
        $this->assertSame(7.25, (float) $ana['promedio_p1']);
        $this->assertSame(9.0, (float) $ana['promedio_p2']);
    }

    public function test_la_planilla_guarda_el_tea_como_texto(): void
    {
        $this->como('admin')->postJson('/calificaciones/planilla/guardar', [
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
            'alumnos' => [['alumno_id' => $this->alumno->id, 'p1_preliminar' => 'tea']],
        ])->assertOk()->assertJsonPath('ok', true);

        $fila = AlumnoCalificacion::where('alumno_id', $this->alumno->id)->firstOrFail();
        $this->assertSame('TEA', $fila->valor);
        $this->assertSame('pendiente', $this->estadoDe()->p1_estado);

        // Y la planilla impresa lo muestra tal cual, no como un 0.
        $ticket = $this->ticketDe('admin');
        $html = $this->sinSesion()->get($this->urlPlanilla(['ticket' => $ticket]))->assertOk()->baseResponse->getContent();
        $this->assertStringContainsString('TEA', $html);
    }

    public function test_la_planilla_no_acepta_mas_de_cuatro_parciales(): void
    {
        $this->como('admin')->postJson('/calificaciones/planilla/guardar', [
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
            'alumnos' => [['alumno_id' => $this->alumno->id, 'p1_vp' => ['8', '8', '8', '8', '8']]],
        ])->assertStatus(422);

        $this->assertSame(0, AlumnoCalificacion::count());
    }

    public function test_la_planilla_no_se_guarda_sin_permiso_de_cargar_notas(): void
    {
        $payload = [
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
            'alumnos' => [['alumno_id' => $this->alumno->id, 'p1_vp' => ['8']]],
        ];

        $this->como('sin_rol')->postJson('/calificaciones/planilla/guardar', $payload)->assertForbidden();
        $this->como('profesor')->postJson('/calificaciones/planilla/guardar', array_merge($payload, [
            'materia_id' => $this->otraMateria->id,
        ]))->assertForbidden();
        $this->como('preceptor')->postJson('/calificaciones/planilla/guardar', array_merge($payload, [
            'anio' => 2, 'division' => '2',
        ]))->assertForbidden();

        $this->assertSame(0, AlumnoCalificacion::count());
    }

    public function test_la_planilla_rechaza_una_materia_de_otro_anio(): void
    {
        $historia = Materia::where('anio', 2)->firstOrFail();

        $this->como('admin')->postJson('/calificaciones/planilla/guardar', [
            'anio' => 1, 'division' => '1', 'materia_id' => $historia->id, 'ciclo' => self::CICLO,
            'alumnos' => [['alumno_id' => $this->alumno->id, 'p1_vp' => ['8']]],
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertSame(0, AlumnoCalificacion::count());
    }

    public function test_la_planilla_avisa_si_un_alumno_no_existe_pero_guarda_el_resto(): void
    {
        $this->como('admin')->postJson('/calificaciones/planilla/guardar', [
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
            'alumnos' => [
                ['alumno_id' => $this->alumno->id, 'p1_vp' => ['8']],
                ['alumno_id' => 999999, 'p1_vp' => ['8']],
            ],
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertSame(1, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
    }

    public function test_la_planilla_conserva_las_notas_finales_que_la_ventana_no_habilita(): void
    {
        $this->ventana();
        $this->como('admin')->postJson('/calificaciones/planilla/guardar', [
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
            'alumnos' => [['alumno_id' => $this->alumno->id, 'p1_final_cuatri' => '8', 'p2_final_cuatri' => '10']],
        ])->assertOk();
        $this->assertSame(9.0, (float) $this->estadoDe()->nota_final);

        // Se cierra la ventana y se corrigen los parciales: las finales ya
        // cargadas no se pueden borrar por no venir en el pedido.
        $this->como('admin')->postJson('/api/calificaciones/ventana/cerrar')->assertOk();
        $this->como('admin')->postJson('/calificaciones/planilla/guardar', [
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
            'alumnos' => [['alumno_id' => $this->alumno->id, 'p1_vp' => ['7']]],
        ])->assertOk();

        $this->assertSame(2, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->where('tipo_calificacion', 'final_cuatrimestre')->count());
        $this->assertSame(9.0, (float) $this->estadoDe()->nota_final);
    }

    public function test_la_planilla_no_deja_cargar_una_final_con_la_ventana_cerrada(): void
    {
        $this->como('admin')->postJson('/calificaciones/planilla/guardar', [
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
            'alumnos' => [['alumno_id' => $this->alumno->id, 'p1_final_cuatri' => '8']],
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertSame(0, AlumnoCalificacion::count());
    }

    public function test_limpiar_la_planilla_borra_las_notas_y_lo_dice_en_el_historial(): void
    {
        $this->guardar([$this->entrada()])->assertOk();
        ActivityLog::query()->delete();

        $this->como('admin')->postJson('/calificaciones/planilla/guardar', [
            'anio' => 1, 'division' => '1', 'materia_id' => $this->materia->id, 'ciclo' => self::CICLO,
            'alumnos' => [['alumno_id' => $this->alumno->id, 'p1_vp' => ['', '', '', ''], 'p2_vp' => []]],
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(0, AlumnoCalificacion::where('alumno_id', $this->alumno->id)->count());
        $this->assertStringContainsString(
            'Limpió planilla',
            ActivityLog::where('action_type', 'calificaciones.update')->value('action')
        );
    }

    public function test_la_carga_de_notas_queda_en_el_historial_con_el_antes_y_el_despues(): void
    {
        $this->guardar([$this->entrada(['notas' => [['valor' => 4]]])])->assertOk();
        $this->guardar([$this->entrada(['notas' => [['valor' => 9]]])])->assertOk();

        $registro = ActivityLog::where('action_type', 'calificaciones.update')->orderBy('id')->get()->last();
        $this->assertNotNull($registro);
        $this->assertNotEmpty($registro->old_values);
        $this->assertNotEmpty($registro->new_values);
        $this->assertNotSame($registro->old_values, $registro->new_values);
    }
}