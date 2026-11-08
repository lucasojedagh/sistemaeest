<?php

namespace Tests\Feature;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Asistencia\Asistencia;
use App\Dominios\Asistencia\Clase;
use Illuminate\Support\Facades\DB;

// Tomar lista es lo que la escuela hace todos los días. Se prueba el ciclo
// completo (crear clase, marcar, corregir, suspender, borrar) y las reglas que
// protegen los papeles: una clase con justificaciones no se borra ni se suspende.
class AsistenciaApiTest extends ApiTestCase
{
    /** Los tres alumnos de 1° 1.ª, como los manda la pantalla al guardar. */
    private function nomina(string $estado = 'presente'): array
    {
        return Alumno::where('anio', 1)->where('division', '1')->cursando()->orderBy('id')->get()
            ->map(fn (Alumno $a) => ['alumno_id' => $a->id, 'estado' => $estado])
            ->all();
    }

    private function crearClase(array $cambios = [], string $rol = 'preceptor'): \Illuminate\Testing\TestResponse
    {
        return $this->como($rol)->postJson('/api/asistencia/clases', array_merge([
            'anio' => 1, 'division' => '1', 'fecha' => '2026-03-10',
            'hora_inicio' => '07:30', 'hora_fin' => '08:30',
            'alumnos' => $this->nomina(),
        ], $cambios));
    }

    /** Una clase ya guardada, con las marcas indicadas por alumno. */
    private function claseGuardada(array $estados = []): int
    {
        $alumnos = Alumno::where('anio', 1)->where('division', '1')->cursando()->orderBy('id')->get();
        $nomina = $alumnos->map(fn (Alumno $a, $i) => [
            'alumno_id' => $a->id,
            'estado' => $estados[$i] ?? 'presente',
        ])->all();

        return $this->crearClase(['alumnos' => $nomina])->assertCreated()->json('clase.id');
    }

    /* ======================= Qué cursos ve y puede marcar ======================= */

    public function test_el_preceptor_ve_todos_los_cursos_pero_solo_marca_los_suyos(): void
    {
        $r = $this->como('preceptor')->getJson('/api/asistencia/cursos')->assertOk();

        // Ve el catálogo entero (años 1 a 7) porque su alcance de lectura es total.
        $this->assertArrayHasKey('1', $r->json());
        $this->assertArrayHasKey('7', $r->json());
        // Pero para escribir solo tiene 1° 1.ª: sin esta lista la pantalla
        // ofrecería cursos que después devuelven 403 al guardar.
        $this->assertSame([[1, '1']], $r->json('editables'));
    }

    public function test_el_profesor_ve_su_curso_pero_no_toma_lista(): void
    {
        $r = $this->como('profesor')->getJson('/api/asistencia/cursos')->assertOk();

        $this->assertSame([1 => ['1']], array_diff_key($r->json(), ['editables' => 1, 'puede_marcar' => 1]));
        $this->assertSame([], $r->json('editables'));
        $this->assertFalse($r->json('puede_marcar'));

        $this->postJson('/api/asistencia/clases', [
            'anio' => 1, 'division' => '1', 'fecha' => '2026-03-10', 'alumnos' => $this->nomina(),
        ])->assertForbidden();
        $this->assertDatabaseCount('clases', 0);
    }

    public function test_direccion_marca_en_cualquier_curso(): void
    {
        $r = $this->como('directivo')->getJson('/api/asistencia/cursos')->assertOk();

        // Alcance total: no se manda la clave, que el frontend lee como "todos".
        $this->assertArrayNotHasKey('editables', $r->json());
        $this->crearClase(['anio' => 2, 'division' => '2', 'alumnos' => []], 'directivo')
            ->assertCreated();
    }

    public function test_sin_rol_no_consulta_asistencia(): void
    {
        $this->como('sin_rol');

        foreach (['cursos', 'clases', 'historial?anio=1', 'inasistencias', 'justificaciones', 'estadisticas'] as $ruta) {
            $this->getJson('/api/asistencia/' . $ruta)->assertForbidden();
        }
    }

    /* ======================= Tomar lista ======================= */

    public function test_tomar_lista_guarda_la_clase_y_las_marcas(): void
    {
        $r = $this->crearClase()->assertCreated();

        $r->assertJsonPath('clase.numero_clase', 1)
            ->assertJsonPath('clase.estado', 'dictada')
            ->assertJsonPath('clase.curso.label', '1.º 1.ª')
            ->assertJsonPath('clase.responsable.rol', 'preceptor');
        $this->assertCount(3, $r->json('alumnos'));

        $this->assertDatabaseCount('asistencias', 3);
        $this->assertDatabaseHas('clases', [
            'id' => $r->json('clase.id'), 'responsable_id' => $this->cuenta('preceptor')->id,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'asistencia.store']);
    }

    public function test_el_numero_de_clase_sigue_al_curso(): void
    {
        $this->crearClase()->assertCreated()->assertJsonPath('clase.numero_clase', 1);
        $this->crearClase(['fecha' => '2026-03-11'])->assertCreated()->assertJsonPath('clase.numero_clase', 2);

        // Otro curso arranca su propia cuenta.
        $this->crearClase(['anio' => 2, 'division' => '2', 'alumnos' => []], 'jefe_preceptores')
            ->assertCreated()->assertJsonPath('clase.numero_clase', 1);
    }

    public function test_dos_clases_el_mismo_dia_en_el_mismo_curso_no_se_pisan(): void
    {
        $primera = $this->crearClase()->assertCreated()->json('clase.id');

        $r = $this->crearClase()->assertStatus(409);
        $this->assertSame($primera, $r->json('clase_id'));
        $this->assertStringContainsString('Ya existe asistencia cargada', $r->json('message'));
        $this->assertDatabaseCount('clases', 1);
        $this->assertDatabaseCount('asistencias', 3);
    }

    public function test_no_se_marca_a_un_alumno_de_otro_curso(): void
    {
        $ajeno = Alumno::where('anio', 2)->firstOrFail();

        $r = $this->crearClase(['alumnos' => [['alumno_id' => $ajeno->id, 'estado' => 'presente']]])
            ->assertStatus(422);

        $this->assertSame([$ajeno->id], $r->json('alumno_ids'));
        $this->assertDatabaseCount('clases', 0);
        $this->assertDatabaseCount('asistencias', 0);
    }

    public function test_un_alumno_inactivo_no_aparece_para_pasar_lista(): void
    {
        $sale = Alumno::where('anio', 1)->orderBy('id')->skip(1)->firstOrFail();
        $sale->estado = 'Inactivo';
        $sale->save();

        $this->crearClase(['alumnos' => [['alumno_id' => $sale->id, 'estado' => 'presente']]])
            ->assertStatus(422);

        $r = $this->crearClase()->assertCreated();
        $this->assertCount(2, $r->json('alumnos'));
        $this->assertNotContains($sale->id, array_column($r->json('alumnos'), 'alumno_id'));
    }

    public function test_el_preceptor_no_toma_lista_en_un_curso_ajeno(): void
    {
        $this->crearClase(['anio' => 2, 'division' => '2', 'alumnos' => []])->assertForbidden();
        $this->assertDatabaseCount('clases', 0);
    }

    public function test_una_clase_sin_marcas_queda_suspendida(): void
    {
        $this->crearClase(['alumnos' => []])->assertCreated()
            ->assertJsonPath('clase.estado', 'suspendida');
        $this->assertDatabaseCount('asistencias', 0);
    }

    public function test_suspender_de_entrada_no_guarda_las_marcas_que_lleguen(): void
    {
        $this->crearClase(['estado' => 'suspendida'])->assertCreated()
            ->assertJsonPath('clase.estado', 'suspendida');
        $this->assertDatabaseCount('asistencias', 0);
    }

    public function test_la_fecha_y_el_estado_se_validan(): void
    {
        $this->como('preceptor');

        $this->postJson('/api/asistencia/clases', ['anio' => 1, 'division' => '1'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->postJson('/api/asistencia/clases', [
            'anio' => 1, 'division' => '1', 'fecha' => 'no-es-fecha',
        ])->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->postJson('/api/asistencia/clases', [
            'anio' => 1, 'division' => '1', 'fecha' => '2026-03-10', 'estado' => 'feriado',
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
        $this->postJson('/api/asistencia/clases', [
            'anio' => 1, 'division' => '1', 'fecha' => '2026-03-10',
            'alumnos' => [['alumno_id' => $this->alumno->id, 'estado' => 'maso']],
        ])->assertStatus(422)->assertJsonValidationErrors('alumnos.0.estado');
    }

    /* ======================= Ver y corregir ======================= */

    public function test_el_detalle_trae_la_nomina_con_las_marcas(): void
    {
        $id = $this->claseGuardada(['presente', 'ausente', 'tarde']);

        $r = $this->como('preceptor')->getJson('/api/asistencia/clases/' . $id)->assertOk();

        $r->assertJsonPath('clase.id', $id)->assertJsonPath('marcas_fuera', []);
        $estados = array_column($r->json('alumnos'), 'estado');
        $this->assertSame(['presente', 'ausente', 'tarde'], $estados);
    }

    public function test_el_profesor_ve_la_clase_de_su_curso_pero_no_la_de_otro(): void
    {
        $suya = $this->claseGuardada();
        $ajena = $this->crearClase(['anio' => 2, 'division' => '2', 'alumnos' => []], 'jefe_preceptores')
            ->assertCreated()->json('clase.id');

        $this->como('profesor')->getJson('/api/asistencia/clases/' . $suya)->assertOk();
        $this->getJson('/api/asistencia/clases/' . $ajena)->assertForbidden();
        $this->putJson('/api/asistencia/clases/' . $suya, ['comentario' => 'Retoque'])->assertForbidden();
    }

    public function test_corregir_una_marca_no_recrea_las_filas(): void
    {
        $id = $this->claseGuardada();
        $antes = Asistencia::where('clase_id', $id)->orderBy('alumno_id')->pluck('id')->all();

        $nomina = $this->nomina();
        $nomina[1]['estado'] = 'ausente';
        $nomina[1]['anotacion'] = 'Llegó el aviso de la madre';

        $this->como('preceptor')->putJson('/api/asistencia/clases/' . $id, ['alumnos' => $nomina])
            ->assertOk();

        // Mismos ids: si se borraran y recrearan, las justificaciones colgadas
        // se irían por cascada.
        $this->assertSame($antes, Asistencia::where('clase_id', $id)->orderBy('alumno_id')->pluck('id')->all());
        $this->assertDatabaseHas('asistencias', [
            'clase_id' => $id, 'alumno_id' => $nomina[1]['alumno_id'],
            'estado' => 'ausente', 'anotacion' => 'Llegó el aviso de la madre',
        ]);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'asistencia.update']);
    }

    public function test_el_horario_se_puede_borrar(): void
    {
        $id = $this->claseGuardada();

        $this->como('preceptor')->putJson('/api/asistencia/clases/' . $id, [
            'hora_inicio' => null, 'hora_fin' => null,
        ])->assertOk()->assertJsonPath('clase.hora_inicio', null);

        $this->assertDatabaseHas('clases', ['id' => $id, 'hora_inicio' => null, 'hora_fin' => null]);
    }

    public function test_suspender_una_clase_ya_tomada_se_lleva_las_marcas(): void
    {
        $id = $this->claseGuardada();
        $this->assertDatabaseCount('asistencias', 3);

        $this->como('preceptor')->putJson('/api/asistencia/clases/' . $id, ['estado' => 'suspendida'])
            ->assertOk()->assertJsonPath('clase.estado', 'suspendida');

        // Las marcas no pueden seguir contando en Inasistencias y Estadísticas
        // con la clase marcada como suspendida.
        $this->assertDatabaseCount('asistencias', 0);
    }

    public function test_una_clase_que_no_existe_da_404(): void
    {
        $this->como('admin')->getJson('/api/asistencia/clases/999999')->assertNotFound();
    }

    /* ======================= Listado y filtros ======================= */

    public function test_el_listado_resume_las_marcas_de_cada_clase(): void
    {
        $this->claseGuardada(['presente', 'ausente', 'tarde']);
        $this->crearClase(['fecha' => '2026-03-11', 'alumnos' => []])->assertCreated();

        $r = $this->como('preceptor')->getJson('/api/asistencia/clases?anio=1&division=1')->assertOk();

        $r->assertJsonPath('total_clases', 2)
            ->assertJsonPath('recortado', false)
            ->assertJsonPath('resumen.dictadas', 1)
            ->assertJsonPath('resumen.suspendidas', 1)
            ->assertJsonPath('resumen.total_asistencias', 3)
            ->assertJsonPath('resumen.presentes', 1)
            ->assertJsonPath('resumen.tardanzas', 1);

        $this->assertSame(['presente' => 1, 'ausente' => 1, 'justificado' => 0, 'tarde' => 1], $r->json('clases.1.resumen'));
        $this->assertTrue($r->json('clases.1.tomada'));
        $this->assertFalse($r->json('clases.0.tomada'));
    }

    public function test_el_profesor_solo_lista_las_clases_de_su_curso(): void
    {
        $this->claseGuardada();
        $this->crearClase(['anio' => 2, 'division' => '2', 'fecha' => '2026-03-11', 'alumnos' => []], 'jefe_preceptores')
            ->assertCreated();

        $this->como('profesor')->getJson('/api/asistencia/clases')->assertOk()
            ->assertJsonPath('total_clases', 1);
    }

    public function test_los_filtros_de_fecha_recortan_de_verdad(): void
    {
        $this->claseGuardada();
        $this->crearClase(['fecha' => '2026-04-15'])->assertCreated();
        $this->como('preceptor');

        $this->getJson('/api/asistencia/clases?anio=1&mes=2026-03')->assertOk()->assertJsonPath('total_clases', 1);
        $this->getJson('/api/asistencia/clases?anio=1&mes=3')->assertOk()->assertJsonPath('total_clases', 1);
        $this->getJson('/api/asistencia/clases?anio=1&desde=2026-04-01')->assertOk()->assertJsonPath('total_clases', 1);
        $this->getJson('/api/asistencia/clases?anio=1&hasta=2026-03-31')->assertOk()->assertJsonPath('total_clases', 1);
        $this->getJson('/api/asistencia/clases?anio=1&year=2025')->assertOk()->assertJsonPath('total_clases', 0);
        $this->getJson('/api/asistencia/clases?anio=1&mes=marzo')->assertStatus(422);
    }

    public function test_el_mes_con_anio_no_mezcla_ciclos(): void
    {
        $this->claseGuardada();
        $this->crearClase(['fecha' => '2025-03-10'])->assertCreated();

        $this->como('preceptor')->getJson('/api/asistencia/clases?anio=1&mes=2026-03')
            ->assertOk()->assertJsonPath('total_clases', 1);
    }

    /* ======================= Borrar ======================= */

    public function test_el_preceptor_puede_deshacer_la_clase_que_acaba_de_tomar(): void
    {
        $id = $this->claseGuardada();
        $this->como('preceptor');

        // Sin la clave confirmada no se borra nada.
        $this->deleteJson('/api/asistencia/clases/' . $id)->assertStatus(422);
        $this->assertDatabaseHas('clases', ['id' => $id]);

        $this->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])->assertOk();
        $this->deleteJson('/api/asistencia/clases/' . $id)->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('clases', ['id' => $id]);
        $this->assertDatabaseCount('asistencias', 0);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'asistencia.destroy']);
    }

    public function test_un_preceptor_no_borra_la_clase_que_tomo_otro(): void
    {
        $id = $this->crearClase([], 'jefe_preceptores')->assertCreated()->json('clase.id');

        $this->como('preceptor')->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])->assertOk();
        $this->deleteJson('/api/asistencia/clases/' . $id)->assertForbidden();
        $this->assertDatabaseHas('clases', ['id' => $id]);
    }

    /* ======================= Justificaciones ======================= */

    public function test_justificar_una_falta_la_deja_pendiente(): void
    {
        $id = $this->claseGuardada(['presente', 'ausente', 'presente']);
        $falta = Asistencia::where('clase_id', $id)->where('estado', 'ausente')->firstOrFail();

        $r = $this->como('preceptor')->postJson('/api/asistencia/justificaciones', [
            'asistencia_id' => $falta->id, 'motivo' => 'Turno médico',
        ])->assertCreated();

        $this->assertDatabaseHas('asistencia_justificaciones', [
            'id' => $r->json('id'), 'asistencia_id' => $falta->id,
            'estado' => 'pendiente', 'motivo' => 'Turno médico',
            'registrado_por' => $this->cuenta('preceptor')->id,
        ]);
        // Pendiente todavía no cambia la marca.
        $this->assertDatabaseHas('asistencias', ['id' => $falta->id, 'estado' => 'ausente']);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'asistencia.justificar']);
    }

    public function test_aprobar_la_justificacion_cambia_la_marca_a_justificado(): void
    {
        $id = $this->claseGuardada(['presente', 'ausente', 'presente']);
        $falta = Asistencia::where('clase_id', $id)->where('estado', 'ausente')->firstOrFail();
        $just = $this->como('preceptor')->postJson('/api/asistencia/justificaciones', [
            'asistencia_id' => $falta->id, 'motivo' => 'Turno médico',
        ])->assertCreated()->json('id');

        $this->putJson('/api/asistencia/justificaciones/' . $just, ['estado' => 'aprobada'])->assertOk();

        $this->assertDatabaseHas('asistencias', ['id' => $falta->id, 'estado' => 'justificado']);
        $this->assertDatabaseHas('asistencia_justificaciones', [
            'id' => $just, 'estado' => 'aprobada', 'aprobado_por' => $this->cuenta('preceptor')->id,
        ]);

        // Ya resuelta no se vuelve a resolver.
        $this->putJson('/api/asistencia/justificaciones/' . $just, ['estado' => 'rechazada'])
            ->assertStatus(422);
    }

    public function test_rechazar_deja_la_falta_como_ausente(): void
    {
        $id = $this->claseGuardada(['presente', 'ausente', 'presente']);
        $falta = Asistencia::where('clase_id', $id)->where('estado', 'ausente')->firstOrFail();
        $just = $this->como('preceptor')->postJson('/api/asistencia/justificaciones', [
            'asistencia_id' => $falta->id, 'motivo' => 'Sin certificado',
        ])->assertCreated()->json('id');

        $this->putJson('/api/asistencia/justificaciones/' . $just, ['estado' => 'rechazada'])->assertOk();

        $this->assertDatabaseHas('asistencias', ['id' => $falta->id, 'estado' => 'ausente']);
        $this->assertDatabaseHas('asistencia_justificaciones', ['id' => $just, 'estado' => 'rechazada']);
    }

    public function test_no_se_justifica_una_presencia_ni_dos_veces_la_misma_falta(): void
    {
        $id = $this->claseGuardada(['presente', 'ausente', 'presente']);
        $this->como('preceptor');

        $presente = Asistencia::where('clase_id', $id)->where('estado', 'presente')->firstOrFail();
        $this->postJson('/api/asistencia/justificaciones', [
            'asistencia_id' => $presente->id, 'motivo' => 'No corresponde',
        ])->assertStatus(422);

        $falta = Asistencia::where('clase_id', $id)->where('estado', 'ausente')->firstOrFail();
        $this->postJson('/api/asistencia/justificaciones', [
            'asistencia_id' => $falta->id, 'motivo' => 'Turno médico',
        ])->assertCreated();
        $this->postJson('/api/asistencia/justificaciones', [
            'asistencia_id' => $falta->id, 'motivo' => 'Otra vez',
        ])->assertStatus(422);

        $this->assertDatabaseCount('asistencia_justificaciones', 1);
    }

    public function test_una_clase_con_papeles_encima_no_se_borra_ni_se_suspende(): void
    {
        $id = $this->claseGuardada(['presente', 'ausente', 'presente']);
        $falta = Asistencia::where('clase_id', $id)->where('estado', 'ausente')->firstOrFail();
        $this->como('preceptor')->postJson('/api/asistencia/justificaciones', [
            'asistencia_id' => $falta->id, 'motivo' => 'Turno médico',
        ])->assertCreated();

        $this->putJson('/api/asistencia/clases/' . $id, ['estado' => 'suspendida'])
            ->assertStatus(422);
        $this->assertDatabaseCount('asistencias', 3);

        $this->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])->assertOk();
        $r = $this->deleteJson('/api/asistencia/clases/' . $id)->assertStatus(422);
        $this->assertStringContainsString('1 justificación cargada', $r->json('message'));
        $this->assertDatabaseHas('clases', ['id' => $id]);
        $this->assertDatabaseCount('asistencia_justificaciones', 1);
    }

    public function test_el_listado_de_justificaciones_trae_el_nombre_y_el_curso(): void
    {
        $id = $this->claseGuardada(['presente', 'ausente', 'presente']);
        $falta = Asistencia::where('clase_id', $id)->where('estado', 'ausente')->firstOrFail();
        $this->como('preceptor')->postJson('/api/asistencia/justificaciones', [
            'asistencia_id' => $falta->id, 'motivo' => 'Turno médico',
        ])->assertCreated();

        $r = $this->getJson('/api/asistencia/justificaciones?anio=1&division=1')->assertOk();

        $r->assertJsonPath('total_justificaciones', 1)
            ->assertJsonPath('justificaciones.0.curso', '1.º 1.ª')
            ->assertJsonPath('justificaciones.0.estado', 'pendiente')
            ->assertJsonPath('justificaciones.0.alumno.apellido', 'Benítez')
            ->assertJsonPath('justificaciones.0.registrado_por', 'Preceptor DePrueba');

        $this->getJson('/api/asistencia/justificaciones?estado=aprobada')->assertOk()
            ->assertJsonPath('total_justificaciones', 0);
    }

    /* ======================= Historial ======================= */

    public function test_el_historial_del_alumno_cuenta_sobre_todas_las_marcas(): void
    {
        $this->claseGuardada(['presente', 'ausente', 'tarde']);
        $this->crearClase(['fecha' => '2026-03-11', 'alumnos' => $this->nomina('presente')])->assertCreated();

        $r = $this->como('preceptor')->getJson('/api/asistencia/historial?alumno_id=' . $this->alumno->id)
            ->assertOk();

        $r->assertJsonPath('tipo', 'alumno')
            ->assertJsonPath('alumno.apellido', 'Álvarez')
            ->assertJsonPath('resumen.clases_dictadas', 2)
            ->assertJsonPath('resumen.presentes', 2)
            ->assertJsonPath('resumen.porcentaje', 100)
            ->assertJsonPath('total_filas', 2)
            ->assertJsonPath('recortado', false);
    }

    public function test_el_historial_del_curso_ordena_por_asistencia(): void
    {
        $this->claseGuardada(['presente', 'ausente', 'tarde']);

        $r = $this->como('preceptor')->getJson('/api/asistencia/historial?anio=1&division=1')->assertOk();

        $r->assertJsonPath('tipo', 'curso')->assertJsonPath('curso.label', '1.º 1.ª');
        // Tarde cuenta como presencia: 2 de 3.
        $r->assertJsonPath('totales.presentes', 1)
            ->assertJsonPath('totales.tardanzas', 1)
            ->assertJsonPath('totales.ausentes', 1)
            ->assertJsonPath('totales.porcentaje', 66.67);

        $porcentajes = array_column($r->json('alumnos'), 'porcentaje');
        $this->assertSame([100, 100, 0], $porcentajes);
    }

    public function test_el_historial_se_busca_por_dni(): void
    {
        $this->claseGuardada();

        $this->como('preceptor')->getJson('/api/asistencia/historial?dni=40000001')->assertOk()
            ->assertJsonPath('alumno.id', $this->alumno->id);
        $this->getJson('/api/asistencia/historial?dni=abc')->assertStatus(422);
        $this->getJson('/api/asistencia/historial?dni=99999999')->assertNotFound();
    }

    public function test_el_historial_pide_alumno_o_curso(): void
    {
        $this->como('preceptor')->getJson('/api/asistencia/historial')->assertStatus(422);
    }

    public function test_el_profesor_no_ve_el_historial_de_un_curso_ajeno(): void
    {
        $ajeno = Alumno::where('anio', 2)->firstOrFail();

        $this->como('profesor')->getJson('/api/asistencia/historial?anio=2')->assertForbidden();
        $this->getJson('/api/asistencia/historial?alumno_id=' . $ajeno->id)->assertForbidden();
        // El año entero también queda afuera: tiene asignada una división, no
        // todas las del año.
        $this->getJson('/api/asistencia/historial?anio=1')->assertForbidden();
        $this->getJson('/api/asistencia/historial?anio=1&division=1')->assertOk();
    }

    /* ======================= Inasistencias ======================= */

    public function test_inasistencias_respeta_el_umbral_y_la_racha(): void
    {
        // Álvarez falta las tres clases seguidas; los otros dos vienen siempre.
        $nomina = $this->nomina();
        $nomina[0]['estado'] = 'ausente';
        foreach (['2026-03-10', '2026-03-11', '2026-03-12'] as $fecha) {
            $this->crearClase(['fecha' => $fecha, 'alumnos' => $nomina])->assertCreated();
        }

        $r = $this->como('preceptor')->getJson('/api/asistencia/inasistencias?anio=1&division=1&minimo=50')
            ->assertOk();

        $r->assertJsonPath('minimo', 50)->assertJsonPath('total_alumnos', 1);
        $fila = $r->json('alumnos.0');
        $this->assertSame($this->alumno->id, $fila['alumno_id']);
        $this->assertSame(100, $fila['porcentaje_inasistencia']);
        $this->assertSame(3, $fila['inasistencias']);
        $this->assertSame(3, $fila['faltas_consecutivas']);
        $this->assertSame('2026-03-12', $fila['ultima_falta']);

        // Con el umbral alto no entra nadie.
        $this->getJson('/api/asistencia/inasistencias?anio=1&minimo=101')->assertOk()
            ->assertJsonPath('total_alumnos', 0);
    }

    public function test_la_racha_se_corta_el_dia_que_volvio_a_clase(): void
    {
        $nomina = $this->nomina();
        $nomina[0]['estado'] = 'ausente';
        $this->crearClase(['fecha' => '2026-03-10', 'alumnos' => $nomina])->assertCreated();
        $this->crearClase(['fecha' => '2026-03-11', 'alumnos' => $this->nomina()])->assertCreated();
        $this->crearClase(['fecha' => '2026-03-12', 'alumnos' => $nomina])->assertCreated();

        $fila = $this->como('preceptor')->getJson('/api/asistencia/inasistencias?anio=1&minimo=50')
            ->assertOk()->json('alumnos.0');

        $this->assertSame(2, $fila['inasistencias']);
        // Volvió el 11: la racha actual es solo la falta del 12.
        $this->assertSame(1, $fila['faltas_consecutivas']);
    }

    /* ======================= Estadísticas ======================= */

    public function test_las_estadisticas_cierran_con_las_marcas(): void
    {
        $this->claseGuardada(['presente', 'ausente', 'tarde']);

        $r = $this->como('preceptor')->getJson('/api/asistencia/estadisticas?anio=1&division=1')->assertOk();

        $r->assertJsonPath('totales.clases', 1)
            ->assertJsonPath('totales.asistencias', 3)
            ->assertJsonPath('totales.presente', 1)
            ->assertJsonPath('totales.ausente', 1)
            ->assertJsonPath('totales.tarde', 1)
            ->assertJsonPath('totales.alumnos_registrados', 3)
            ->assertJsonPath('totales.alumnos_totales', 3)
            ->assertJsonPath('totales.porcentaje', 66.67);

        $this->assertSame('Marzo', $r->json('evolucion_mensual.0.label'));
        $this->assertSame(1, $r->json('evolucion_mensual.0.clases'));
        $this->assertSame('1.º 1.ª', $r->json('mejores_cursos.0.curso'));
    }

    public function test_las_estadisticas_sin_datos_no_dividen_por_cero(): void
    {
        $r = $this->como('preceptor')->getJson('/api/asistencia/estadisticas?anio=1')->assertOk();

        $r->assertJsonPath('totales.asistencias', 0)->assertJsonPath('totales.porcentaje', null);
        $this->assertSame([], $r->json('mejores_alumnos'));
        $this->assertSame([], $r->json('evolucion_mensual'));
    }

    /* ======================= Planilla imprimible ======================= */

    public function test_la_planilla_del_dia_se_imprime_con_ticket(): void
    {
        $id = $this->claseGuardada();
        $ticket = $this->como('preceptor')->postJson('/api/print/ticket')->assertOk()->json('ticket');

        $r = $this->sinSesion()->get('/print/asistencia?ticket=' . $ticket . '&clase_id=' . $id)->assertOk();
        $this->assertStringContainsString('Álvarez', $r->baseResponse->getContent());

        $this->get('/print/asistencia?ticket=' . $ticket)->assertStatus(422);
    }

    public function test_la_planilla_de_un_curso_ajeno_no_se_imprime(): void
    {
        $ajena = $this->crearClase(['anio' => 2, 'division' => '2', 'alumnos' => []], 'jefe_preceptores')
            ->assertCreated()->json('clase.id');
        $ticket = $this->como('profesor')->postJson('/api/print/ticket')->assertOk()->json('ticket');

        $this->sinSesion()->get('/print/asistencia?ticket=' . $ticket . '&clase_id=' . $ajena)
            ->assertForbidden();
    }
}