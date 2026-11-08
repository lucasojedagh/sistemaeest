<?php

namespace Tests\Feature;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\HorarioCelda;
use App\Dominios\Cursos\Materia;
use App\Dominios\Cursos\ParametroDivision;
use App\Dominios\Sistema\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Cursos, divisiones, materias y horarios recorridos por HTTP con login real.
 *
 * El fixture de ApiTestCase deja dos cursos (1.º 1.ª y 2.º 2.ª) y el preceptor
 * y el profesor asignados sólo a 1.º 1.ª, así que todo lo que se pida de 2.º 2.ª
 * sirve para comprobar que el alcance se respeta.
 */
class CursosApiTest extends ApiTestCase
{
    /** Deja la contraseña confirmada, como hace el modal antes de una baja. */
    private function confirmar(string $rol = 'admin'): void
    {
        $this->como($rol)->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])
            ->assertOk();
    }

    private function celda(array $cambios = []): array
    {
        return array_merge(['materia' => 'Matemática', 'profesor_id' => $this->profesor->id], $cambios);
    }

    private function guardarHorario(array $grilla, string $rol = 'admin', int $anio = 1, string $division = '1'): TestResponse
    {
        return $this->como($rol)->putJson("/api/horarios/{$anio}/{$division}", $grilla);
    }

    /**
     * El sistema no deja un año 1-7 sin divisiones activas. Para probar las otras
     * reglas de baja hay que darle al año una segunda división: si no, ese guard
     * responde primero y tapa el mensaje que se quiere medir.
     */
    private function otraDivisionDelAnio(int $anio, string $division = '2'): Curso
    {
        return Curso::create(['anio' => $anio, 'division' => $division, 'activo' => true]);
    }

    // ===== Listado de cursos =====

    public function test_el_listado_de_cursos_pide_sesion(): void
    {
        $this->sinSesion()->getJson('/api/cursos')->assertUnauthorized();
    }

    public function test_el_listado_trae_los_cursos_con_su_curso_y_cupo(): void
    {
        $r = $this->como('admin')->getJson('/api/cursos')->assertOk();

        $cursos = collect($r->json('data'));
        $this->assertCount(2, $cursos);
        $this->assertSame([1, 2], $cursos->pluck('anio')->all());
        $this->assertSame(['1', '2'], $cursos->pluck('division')->all());
        $this->assertSame(30, $cursos->first()['cupo']);
    }

    public function test_el_listado_filtra_por_anio_y_por_activo(): void
    {
        $this->otroCurso->update(['activo' => false]);

        $porAnio = $this->como('admin')->getJson('/api/cursos?anio=2')->assertOk();
        $this->assertCount(1, $porAnio->json('data'));
        $this->assertSame(2, $porAnio->json('data.0.anio'));

        $activos = $this->como('admin')->getJson('/api/cursos?activo=true')->assertOk();
        $this->assertCount(1, $activos->json('data'));
        $this->assertSame(1, $activos->json('data.0.anio'));
    }

    public function test_el_profesor_solo_ve_los_cursos_que_tiene_asignados(): void
    {
        $r = $this->como('profesor')->getJson('/api/cursos')->assertOk();

        $this->assertCount(1, $r->json('data'));
        $this->assertSame(1, $r->json('data.0.anio'));
        $this->assertSame('1', $r->json('data.0.division'));
    }

    public function test_el_preceptor_ve_todos_los_cursos_pero_editarlos_es_de_gestion(): void
    {
        // Ver: TODO. Pero el alta, la edición y la baja de cursos son de gestión
        // (admin, directivo y jefe): el alcance ASIGNADO del preceptor no alcanza.
        $this->assertCount(2, $this->como('preceptor')->getJson('/api/cursos')->assertOk()->json('data'));

        foreach ([$this->curso, $this->otroCurso] as $curso) {
            $this->como('preceptor')->patchJson('/api/cursos/' . $curso->id, ['cupo' => 25])
                ->assertForbidden();
        }

        // Lo que el preceptor sí edita en su ámbito es la grilla de horarios.
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]], 'preceptor')->assertOk();
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]], 'preceptor', 2, '2')->assertForbidden();
    }

    public function test_una_cuenta_sin_rol_no_ve_los_cursos(): void
    {
        $this->como('sin_rol')->getJson('/api/cursos')->assertForbidden();
    }

    // ===== Alta de curso =====

    public function test_crear_un_curso_lo_normaliza_y_lo_deja_en_el_historial(): void
    {
        $r = $this->como('admin')->postJson('/api/cursos', [
            'anio' => 3, 'division' => 'b', 'turno' => 'tarde', 'cupo' => 28,
        ])->assertCreated();

        // La división se guarda en mayúscula aunque llegue en minúscula.
        $this->assertSame('B', $r->json('division'));
        $this->assertSame('tarde', $r->json('turno'));
        $this->assertTrue((bool) $r->json('activo'));

        $this->assertDatabaseHas('cursos', ['anio' => 3, 'division' => 'B', 'cupo' => 28]);
        $this->assertSame(1, ActivityLog::where('action_type', 'curso.store')->count());
    }

    public function test_no_se_puede_repetir_anio_y_division(): void
    {
        $this->como('admin')->postJson('/api/cursos', ['anio' => 1, 'division' => '1'])
            ->assertStatus(422);

        $this->assertSame(2, Curso::count());
    }

    public function test_el_alta_valida_anio_division_turno_y_cupo(): void
    {
        $this->como('admin')->postJson('/api/cursos', ['anio' => 99, 'division' => '1'])->assertStatus(422);
        $this->como('admin')->postJson('/api/cursos', ['anio' => 0, 'division' => '1'])->assertStatus(422);
        $this->como('admin')->postJson('/api/cursos', ['anio' => 3, 'division' => '12'])->assertStatus(422);
        $this->como('admin')->postJson('/api/cursos', ['anio' => 3])->assertStatus(422);
        $this->como('admin')->postJson('/api/cursos', ['anio' => 3, 'division' => '1', 'turno' => 'madrugada'])->assertStatus(422);
        $this->como('admin')->postJson('/api/cursos', ['anio' => 3, 'division' => '1', 'cupo' => -5])->assertStatus(422);

        $this->assertSame(2, Curso::count());
    }

    public function test_crear_cursos_es_de_gestion(): void
    {
        foreach (['profesor', 'preceptor', 'sin_rol'] as $rol) {
            $this->como($rol)->postJson('/api/cursos', ['anio' => 3, 'division' => '1'])
                ->assertForbidden();
        }
        $this->como('jefe_preceptores')->postJson('/api/cursos', ['anio' => 3, 'division' => '1'])
            ->assertCreated();
    }

    public function test_la_casilla_de_crear_cursos_manda_de_verdad(): void
    {
        $this->quitarPermiso('jefe_preceptores', 'cursos.crear');

        $this->como('jefe_preceptores')->postJson('/api/cursos', ['anio' => 3, 'division' => '1'])
            ->assertForbidden();
    }

    // ===== Edición de curso =====

    public function test_editar_un_curso_cambia_turno_cupo_y_estado(): void
    {
        $this->otraDivisionDelAnio(1);

        $this->como('admin')->patchJson('/api/cursos/' . $this->curso->id, [
            'turno' => 'noche', 'cupo' => 20, 'activo' => false,
        ])->assertOk();

        $this->curso->refresh();
        $this->assertSame('noche', $this->curso->turno);
        $this->assertSame(20, (int) $this->curso->cupo);
        $this->assertFalse((bool) $this->curso->activo);

        $this->assertSame(1, ActivityLog::where('action_type', 'curso.update')->count());
    }

    public function test_la_division_de_un_curso_no_se_cambia_por_edicion(): void
    {
        $this->como('admin')->patchJson('/api/cursos/' . $this->curso->id, ['division' => '9'])->assertOk();

        $this->assertSame('1', $this->curso->fresh()->division);
    }

    public function test_no_se_puede_desactivar_la_ultima_division_de_un_anio(): void
    {
        $this->como('admin')->patchJson('/api/cursos/' . $this->curso->id, ['activo' => false])
            ->assertStatus(422);

        $this->assertTrue((bool) $this->curso->fresh()->activo);
    }

    public function test_editar_cursos_es_de_gestion(): void
    {
        foreach (['profesor', 'sin_rol'] as $rol) {
            $this->como($rol)->patchJson('/api/cursos/' . $this->curso->id, ['cupo' => 10])
                ->assertForbidden();
        }
    }

    // ===== Baja de curso =====

    public function test_borrar_un_curso_pide_la_clave(): void
    {
        $vacio = Curso::create(['anio' => 3, 'division' => '1', 'activo' => true]);
        $this->otraDivisionDelAnio(3);

        $this->como('admin')->deleteJson('/api/cursos/' . $vacio->id)->assertStatus(422);
        $this->assertDatabaseHas('cursos', ['id' => $vacio->id]);

        $this->confirmar();
        $this->como('admin')->deleteJson('/api/cursos/' . $vacio->id)->assertOk();
        $this->assertDatabaseMissing('cursos', ['id' => $vacio->id]);
    }

    public function test_un_curso_con_alumnos_no_se_borra(): void
    {
        $this->otraDivisionDelAnio(1);
        $this->confirmar();

        $r = $this->como('admin')->deleteJson('/api/cursos/' . $this->curso->id)->assertStatus(422);

        $this->assertStringContainsString('alumnos', $r->json('message'));
        $this->assertDatabaseHas('cursos', ['id' => $this->curso->id]);
        $this->assertSame(3, Alumno::where('anio', 1)->where('division', '1')->count());
    }

    public function test_un_curso_con_horario_no_se_borra(): void
    {
        $vacio = Curso::create(['anio' => 3, 'division' => '1', 'activo' => true]);
        $this->otraDivisionDelAnio(3);
        HorarioCelda::create([
            'curso_id' => $vacio->id, 'dia' => 1, 'slot_key' => 'h1', 'materia_id' => $this->materia->id,
        ]);

        $this->confirmar();
        $r = $this->como('admin')->deleteJson('/api/cursos/' . $vacio->id)->assertStatus(422);

        $this->assertStringContainsString('horario', $r->json('message'));
        $this->assertDatabaseHas('cursos', ['id' => $vacio->id]);
    }

    public function test_no_se_puede_borrar_la_ultima_division_activa_de_un_anio(): void
    {
        $this->confirmar();

        $this->como('admin')->deleteJson('/api/cursos/' . $this->curso->id)->assertStatus(422);
        $this->assertDatabaseHas('cursos', ['id' => $this->curso->id]);
    }

    public function test_borrar_cursos_es_de_gestion(): void
    {
        $vacio = Curso::create(['anio' => 3, 'division' => '1', 'activo' => true]);
        $this->confirmar('preceptor');

        $this->como('preceptor')->deleteJson('/api/cursos/' . $vacio->id)->assertForbidden();
    }

    // ===== Sincronización con divisiones =====

    public function test_sincronizar_crea_los_cursos_que_faltan(): void
    {
        // El parámetro de divisiones trae 9 divisiones para cada uno de los 7 años;
        // el fixture sólo tiene dos cursos creados. Sync tiene que completar el mapa.
        $esperados = ParametroDivision::where('activo', true)->count();
        $this->assertGreaterThan(Curso::count(), $esperados);

        $this->confirmar();
        $this->como('admin')->postJson('/api/cursos/sync')->assertOk();

        $this->assertSame($esperados, Curso::where('activo', true)->count());
        $this->assertDatabaseHas('cursos', ['anio' => 4, 'division' => '1', 'activo' => true]);
        $this->assertSame(1, ActivityLog::where('action_type', 'curso.sync')->count());
    }

    public function test_sincronizar_pide_la_clave_y_es_de_gestion(): void
    {
        $this->como('admin')->postJson('/api/cursos/sync')->assertStatus(422);

        $this->confirmar('preceptor');
        $this->como('preceptor')->postJson('/api/cursos/sync')->assertForbidden();
    }

    public function test_sincronizar_sin_movimientos_no_ensucia_el_historial(): void
    {
        $this->confirmar();
        // La primera pasada completa el mapa y deja su entrada; la segunda no
        // tiene nada que mover y no tiene que escribir otra.
        $this->como('admin')->postJson('/api/cursos/sync')->assertOk();
        $this->assertSame(1, ActivityLog::where('action_type', 'curso.sync')->count());

        $this->como('admin')->postJson('/api/cursos/sync')->assertOk();
        $this->assertSame(1, ActivityLog::where('action_type', 'curso.sync')->count());
    }

    // ===== Divisiones =====

    public function test_divisiones_pide_sesion_y_cubre_los_siete_anios(): void
    {
        $this->sinSesion()->getJson('/api/divisiones')->assertUnauthorized();

        $r = $this->como('profesor')->getJson('/api/divisiones')->assertOk();
        foreach (range(1, 7) as $anio) {
            $this->assertArrayHasKey($anio, $r->json());
        }
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9'], $r->json('1'));
    }

    public function test_editar_divisiones_activa_y_desactiva_cursos(): void
    {
        $this->confirmar();

        $this->como('admin')->putJson('/api/divisiones', ['1' => ['1', '2']])->assertOk();

        $this->assertDatabaseHas('cursos', ['anio' => 1, 'division' => '2', 'activo' => true]);
        $this->assertSame(1, ActivityLog::where('action_type', 'divisiones.update')->count());
    }

    public function test_un_anio_no_puede_quedar_sin_divisiones(): void
    {
        $this->confirmar();

        $this->como('admin')->putJson('/api/divisiones', ['1' => []])->assertStatus(422);
        $this->assertTrue((bool) $this->curso->fresh()->activo);
    }

    public function test_las_divisiones_invalidas_se_descartan(): void
    {
        $this->confirmar();

        $this->como('admin')->putJson('/api/divisiones', ['1' => ['1', 'Z', '12', '']])->assertOk();

        $this->assertSame(['1'], $this->como('admin')->getJson('/api/divisiones')->json('1'));
    }

    public function test_editar_divisiones_rechaza_un_anio_fuera_de_rango(): void
    {
        $this->confirmar();

        $this->como('admin')->putJson('/api/divisiones', ['99' => ['1']])->assertStatus(422);

        $this->assertDatabaseMissing('cursos', ['anio' => 99]);
        $this->assertDatabaseMissing('parametro_divisiones', ['anio' => 99]);
    }

    public function test_editar_divisiones_pide_la_clave_y_es_de_gestion(): void
    {
        $this->como('admin')->putJson('/api/divisiones', ['1' => ['1', '2']])->assertStatus(422);

        $this->confirmar('preceptor');
        $this->como('preceptor')->putJson('/api/divisiones', ['1' => ['1', '2']])->assertForbidden();
    }

    public function test_la_cache_de_divisiones_se_descarta_al_crear_un_curso(): void
    {
        ParametroDivision::query()->update(['activo' => false]);

        // Con el parámetro vacío el índice se arma desde cursos.
        $this->como('admin')->getJson('/api/divisiones')->assertOk();
        $this->como('admin')->postJson('/api/cursos', ['anio' => 5, 'division' => '3'])->assertCreated();

        $this->assertContains('3', $this->como('admin')->getJson('/api/divisiones')->json('5'));
    }

    // ===== Materias: lectura =====

    public function test_las_materias_de_un_anio_piden_sesion(): void
    {
        $this->sinSesion()->getJson('/api/anios/1/materias')->assertUnauthorized();
    }

    public function test_las_materias_de_un_anio_salen_activas_y_ordenadas(): void
    {
        $inactiva = Materia::create(['anio' => 1, 'nombre' => 'Zanjita', 'activo' => 0]);

        $r = $this->como('profesor')->getJson('/api/anios/1/materias')->assertOk();

        $nombres = collect($r->json())->pluck('nombre')->all();
        $this->assertSame(['Lengua y Literatura', 'Matemática'], $nombres);
        $this->assertNotContains($inactiva->id, collect($r->json())->pluck('id')->all());
    }

    public function test_materias_todas_agrupa_por_anio(): void
    {
        $r = $this->como('preceptor')->getJson('/api/materias/todas')->assertOk();

        $this->assertCount(2, $r->json('1'));
        $this->assertCount(1, $r->json('2'));
        $this->assertSame('Historia', $r->json('2.0.nombre'));
    }

    // ===== Materias: reemplazo por año =====

    public function test_reemplazar_materias_conserva_el_id_en_un_rename(): void
    {
        $this->confirmar();
        $idOriginal = $this->materia->id;

        $r = $this->como('admin')->putJson('/api/anios/1/materias', ['materias' => [
            ['id' => $idOriginal, 'nombre' => 'Matemática Aplicada'],
            ['nombre' => 'Física'],
        ]])->assertOk();

        // La respuesta viene ordenada por nombre, así que se busca por nombre y
        // no por posición: "Física" queda primera.
        $renombrada = collect($r->json())->firstWhere('nombre', 'Matemática Aplicada');
        $this->assertSame($idOriginal, $renombrada['id']);
        $this->assertSame('Matemática Aplicada', Materia::find($idOriginal)->nombre);
        $this->assertDatabaseHas('materias', ['anio' => 1, 'nombre' => 'Física', 'activo' => 1]);
        // Lengua no vino: se desactiva, no se borra.
        $this->assertSame(0, (int) $this->otraMateria->fresh()->activo);
        $this->assertDatabaseHas('materias', ['id' => $this->otraMateria->id]);
    }

    public function test_reemplazar_materias_acepta_una_lista_de_nombres(): void
    {
        $this->confirmar();

        $r = $this->como('admin')->putJson('/api/anios/1/materias', ['materias' => ['Geografía', '  Historia  ']])
            ->assertOk();

        $this->assertSame(['Geografía', 'Historia'], collect($r->json())->pluck('nombre')->all());
        $this->assertSame('aula', $r->json('0.tipo'));
    }

    public function test_reemplazar_materias_normaliza_tipo_y_especialidad(): void
    {
        $this->confirmar();

        $r = $this->como('admin')->putJson('/api/anios/1/materias', ['materias' => [
            ['nombre' => 'Taller de Diseño', 'tipo' => 'TALLER'],
            ['nombre' => 'Dibujo', 'tipo' => 'especialidad', 'especialidad' => 'MMO'],
            ['nombre' => 'Rara', 'tipo' => 'inventado', 'especialidad' => 'cualquiera'],
        ]])->assertOk();

        $porNombre = collect($r->json())->keyBy('nombre');
        $this->assertSame('taller', $porNombre['Taller de Diseño']['tipo']);
        $this->assertSame('mmo', $porNombre['Dibujo']['especialidad']);
        $this->assertSame('aula', $porNombre['Rara']['tipo']);
        $this->assertNull($porNombre['Rara']['especialidad']);
    }

    public function test_reemplazar_materias_no_admite_dos_iguales(): void
    {
        $this->confirmar();

        $this->como('admin')->putJson('/api/anios/1/materias', ['materias' => [
            ['id' => $this->materia->id, 'nombre' => 'Lengua y Literatura'],
        ]])->assertStatus(422);

        $this->assertSame('Matemática', $this->materia->fresh()->nombre);
    }

    public function test_reemplazar_materias_pide_la_clave(): void
    {
        $this->como('admin')->putJson('/api/anios/1/materias', ['materias' => ['Otra']])
            ->assertStatus(422);

        $this->assertSame(2, Materia::where('anio', 1)->count());
    }

    public function test_reemplazar_materias_es_de_gestion(): void
    {
        $this->confirmar('preceptor');

        $this->como('preceptor')->putJson('/api/anios/1/materias', ['materias' => ['Otra']])
            ->assertForbidden();
    }

    public function test_reemplazar_materias_deja_el_antes_y_el_despues_en_el_historial(): void
    {
        $this->confirmar();

        $this->como('admin')->putJson('/api/anios/1/materias', [
            'materias' => [['id' => $this->materia->id, 'nombre' => 'Matemática Aplicada']],
            '_detalles' => 'Cambio de plan de estudios',
        ])->assertOk();

        $log = ActivityLog::where('action_type', 'materias.replace')->firstOrFail();
        $this->assertSame('Cambio de plan de estudios', $log->detalles);
        $this->assertNotEmpty($log->old_values);
        $this->assertNotEmpty($log->new_values);
    }

    public function test_reemplazar_materias_invalida_la_cache_del_catalogo(): void
    {
        $this->como('admin')->getJson('/api/materias/todas')->assertOk();
        $this->confirmar();

        $this->como('admin')->putJson('/api/anios/1/materias', ['materias' => ['Biología']])->assertOk();

        $nombres = collect($this->como('admin')->getJson('/api/materias/todas')->json('1'))->pluck('nombre')->all();
        $this->assertSame(['Biología'], $nombres);
    }

    // ===== Materias: renombre y baja =====

    public function test_renombrar_una_materia(): void
    {
        $this->como('admin')->putJson('/api/materias/' . $this->materia->id, ['nombre' => 'Matemática II'])
            ->assertOk();

        $this->assertSame('Matemática II', $this->materia->fresh()->nombre);
        $this->assertSame(1, ActivityLog::where('action_type', 'materias.update')->count());
    }

    public function test_renombrar_no_admite_un_nombre_vacio_ni_repetido(): void
    {
        $this->como('admin')->putJson('/api/materias/' . $this->materia->id, ['nombre' => '   '])
            ->assertStatus(422);
        $this->como('admin')->putJson('/api/materias/' . $this->materia->id, ['nombre' => 'Lengua y Literatura'])
            ->assertStatus(422);

        $this->assertSame('Matemática', $this->materia->fresh()->nombre);
    }

    public function test_renombrar_propaga_a_las_tablas_con_nombre_copiado(): void
    {
        DB::table('alumno_recursandos')->insert([
            'alumno_id' => $this->alumno->id, 'materia_id' => $this->materia->id,
            'materia_nombre' => 'Matemática', 'anio' => 1, 'division' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->como('admin')->putJson('/api/materias/' . $this->materia->id, ['nombre' => 'Matemática II'])
            ->assertOk();

        $this->assertSame('Matemática II', DB::table('alumno_recursandos')->where('materia_id', $this->materia->id)->value('materia_nombre'));
    }

    public function test_renombrar_materias_es_de_gestion(): void
    {
        $this->como('preceptor')->putJson('/api/materias/' . $this->materia->id, ['nombre' => 'Otra'])
            ->assertForbidden();
    }

    public function test_borrar_una_materia_sin_datos(): void
    {
        $this->confirmar();

        $this->como('admin')->deleteJson('/api/materias/' . $this->otraMateria->id)->assertOk();

        $this->assertDatabaseMissing('materias', ['id' => $this->otraMateria->id]);
        $this->assertSame(1, ActivityLog::where('action_type', 'materias.destroy')->count());
    }

    public function test_una_materia_con_notas_no_se_borra(): void
    {
        $this->guardarNotas();
        $this->confirmar();

        $r = $this->como('admin')->deleteJson('/api/materias/' . $this->materia->id)->assertStatus(422);

        $this->assertStringContainsString('nota', $r->json('message'));
        $this->assertDatabaseHas('materias', ['id' => $this->materia->id]);
    }

    public function test_borrar_una_materia_pide_la_clave(): void
    {
        $this->como('admin')->deleteJson('/api/materias/' . $this->otraMateria->id)->assertStatus(422);
        $this->assertDatabaseHas('materias', ['id' => $this->otraMateria->id]);
    }

    // ===== Horarios: lectura =====

    public function test_el_horario_pide_sesion(): void
    {
        $this->sinSesion()->getJson('/api/horarios/1/1')->assertUnauthorized();
    }

    public function test_el_horario_de_un_curso_inexistente_avisa(): void
    {
        $this->como('admin')->getJson('/api/horarios/5/5')->assertNotFound();
    }

    public function test_el_horario_vuelve_la_grilla_con_materia_y_profesor(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]])->assertOk();

        $r = $this->como('admin')->getJson('/api/horarios/1/1')->assertOk();

        $this->assertSame('Matemática', $r->json('h1.Lun.materia'));
        $this->assertSame($this->profesor->id, $r->json('h1.Lun.profesorId'));
        // La grilla siempre trae los nueve módulos aunque estén vacíos.
        $this->assertCount(9, $r->json());
    }

    public function test_el_alias_de_cursos_devuelve_el_mismo_horario(): void
    {
        $this->guardarHorario(['h2' => ['Mar' => $this->celda()]])->assertOk();

        $canonical = $this->como('admin')->getJson('/api/horarios/1/1')->assertOk()->json();
        $alias = $this->como('admin')->getJson('/api/cursos/1/1/horario')->assertOk()->json();

        $this->assertSame($canonical, $alias);
    }

    public function test_el_horario_separa_los_grupos(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]])->assertOk();
        $this->guardarHorario(['grupo' => 'A', 'h1' => ['Lun' => $this->celda(['materia' => 'Lengua y Literatura'])]])->assertOk();

        $this->assertSame('Matemática', $this->como('admin')->getJson('/api/horarios/1/1')->json('h1.Lun.materia'));
        $this->assertSame('Lengua y Literatura', $this->como('admin')->getJson('/api/horarios/1/1?grupo=A')->json('h1.Lun.materia'));
        // ?grupo= vacío tiene que leer el grupo sin grupo, no buscar grupo=''.
        $this->assertSame('Matemática', $this->como('admin')->getJson('/api/horarios/1/1?grupo=')->json('h1.Lun.materia'));
    }

    public function test_un_grupo_inventado_se_rechaza(): void
    {
        $this->como('admin')->getJson('/api/horarios/1/1?grupo=Z')->assertStatus(422);
    }

    public function test_el_profesor_no_ve_el_horario_de_un_curso_ajeno(): void
    {
        $this->como('profesor')->getJson('/api/horarios/1/1')->assertOk();
        $this->como('profesor')->getJson('/api/horarios/2/2')->assertForbidden();
    }

    public function test_una_cuenta_sin_rol_no_ve_horarios(): void
    {
        $this->como('sin_rol')->getJson('/api/horarios/1/1')->assertForbidden();
    }

    // ===== Horarios: escritura =====

    public function test_guardar_el_horario_crea_la_materia_que_no_existia(): void
    {
        $r = $this->guardarHorario(['h1' => ['Lun' => $this->celda(['materia' => 'Educación Física'])]])->assertOk();

        $this->assertSame('Educación Física', $r->json('h1.Lun.materia'));
        $this->assertDatabaseHas('materias', ['anio' => 1, 'nombre' => 'Educación Física', 'activo' => 1]);
        $this->assertSame(1, ActivityLog::where('action_type', 'horario.update')->count());
    }

    public function test_una_celda_vacia_borra_lo_que_habia(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]])->assertOk();
        $this->assertSame(1, HorarioCelda::where('curso_id', $this->curso->id)->count());

        $this->guardarHorario(['h1' => ['Lun' => null]])->assertOk();
        $this->assertSame(0, HorarioCelda::where('curso_id', $this->curso->id)->count());

        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]])->assertOk();
        $this->guardarHorario(['h1' => ['Lun' => ['materia' => '  ']]])->assertOk();
        $this->assertSame(0, HorarioCelda::where('curso_id', $this->curso->id)->count());
    }

    public function test_guardar_el_horario_no_duplica_la_celda(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]])->assertOk();
        $this->guardarHorario(['h1' => ['Lun' => $this->celda(['materia' => 'Lengua y Literatura'])]])->assertOk();

        $this->assertSame(1, HorarioCelda::where('curso_id', $this->curso->id)->count());
        $this->assertSame('Lengua y Literatura', $this->como('admin')->getJson('/api/horarios/1/1')->json('h1.Lun.materia'));
    }

    public function test_el_horario_acepta_profesor_id_o_profesorId(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => ['materia' => 'Matemática', 'profesorId' => $this->profesor->id]]])
            ->assertOk();

        $this->assertSame($this->profesor->id, (int) HorarioCelda::where('curso_id', $this->curso->id)->value('profesor_id'));
    }

    public function test_el_horario_guarda_las_horas_del_modulo(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda(['horaInicio' => '07:30', 'horaFin' => '08:30'])]])
            ->assertOk();

        $r = $this->como('admin')->getJson('/api/horarios/1/1')->assertOk();
        $this->assertSame('07:30', $r->json('h1.Lun.horaInicio'));
        $this->assertSame('08:30', $r->json('h1.Lun.horaFin'));
    }

    public function test_el_horario_rechaza_una_hora_que_no_es_hora(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda(['horaInicio' => 'a las siete'])]])
            ->assertStatus(422);

        $this->assertSame(0, HorarioCelda::where('curso_id', $this->curso->id)->count());
    }

    public function test_el_horario_no_acepta_un_profesor_que_no_existe(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda(['profesor_id' => 987654])]])
            ->assertStatus(422);

        $this->assertSame(0, HorarioCelda::where('curso_id', $this->curso->id)->count());
    }

    public function test_el_horario_ignora_las_claves_de_dia_inventadas(): void
    {
        $this->guardarHorario(['h1' => ['Sab' => $this->celda(), 'Lun' => $this->celda()]])->assertOk();

        $this->assertSame(1, HorarioCelda::where('curso_id', $this->curso->id)->count());
    }

    public function test_guardar_el_horario_de_un_curso_inexistente_avisa(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]], 'admin', 5, '5')->assertNotFound();
    }

    public function test_el_preceptor_edita_el_horario_de_su_curso_y_no_el_ajeno(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]], 'preceptor')->assertOk();
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]], 'preceptor', 2, '2')->assertForbidden();
    }

    public function test_el_profesor_no_carga_horarios(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]], 'profesor')->assertForbidden();
    }

    public function test_una_cuenta_sin_rol_no_carga_horarios(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]], 'sin_rol')->assertForbidden();
    }

    public function test_la_casilla_de_editar_horarios_manda_de_verdad(): void
    {
        $this->quitarPermiso('preceptor', 'horario.editar');

        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]], 'preceptor')->assertForbidden();
    }

    public function test_el_alias_de_cursos_tambien_guarda(): void
    {
        $this->como('admin')->putJson('/api/cursos/1/1/horario', ['h3' => ['Mie' => $this->celda()]])
            ->assertOk();

        $this->assertSame('Matemática', $this->como('admin')->getJson('/api/horarios/1/1')->json('h3.Mie.materia'));
    }

    /**
     * El horario se reemplaza sólo en los slots que vienen en el pedido. Un
     * payload vacío o a medias no puede vaciar la grilla: es el caso de un
     * pedido que se corta o una pantalla que todavía no terminó de cargar y
     * manda a guardar igual. Se mide con dos slots cargados de verdad.
     */
    public function test_un_pedido_vacio_no_vacia_el_horario_de_la_division(): void
    {
        $this->guardarHorario([
            'h1' => ['Lun' => $this->celda()],
            'h3' => ['Mie' => $this->celda(['materia' => 'Lengua y Literatura'])],
        ])->assertOk();

        $this->guardarHorario([])->assertOk();

        $grilla = $this->como('admin')->getJson('/api/horarios/1/1')->assertOk()->json();
        $this->assertSame('Matemática', $grilla['h1']['Lun']['materia'] ?? null);
        $this->assertSame('Lengua y Literatura', $grilla['h3']['Mie']['materia'] ?? null);

        // Y por el alias de /cursos/, que es el mismo código.
        $this->como('admin')->putJson('/api/cursos/1/1/horario', [])->assertOk();
        $this->assertSame('Matemática', $this->como('admin')->getJson('/api/horarios/1/1')->json('h1.Lun.materia'));

        // Borrar una celda sigue siendo explícito: hay que mandarla vacía.
        $this->guardarHorario(['h1' => ['Lun' => null]])->assertOk();
        $this->assertNull($this->como('admin')->getJson('/api/horarios/1/1')->json('h1.Lun.materia'));
        $this->assertSame('Lengua y Literatura', $this->como('admin')->getJson('/api/horarios/1/1')->json('h3.Mie.materia'));
    }

    // ===== Horario imprimible =====

    public function test_el_horario_imprimible_pide_sesion(): void
    {
        $this->sinSesion()->get('/horarios/planilla?anio=1&division=1')->assertUnauthorized();
    }

    public function test_el_horario_imprimible_pide_los_parametros(): void
    {
        $this->como('admin')->get('/horarios/planilla')->assertStatus(422);
    }

    public function test_el_horario_imprimible_de_un_curso_inexistente_avisa(): void
    {
        $this->como('admin')->get('/horarios/planilla?anio=5&division=5')->assertNotFound();
    }

    public function test_el_horario_imprimible_muestra_las_materias(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]])->assertOk();

        $r = $this->como('admin')->get('/horarios/planilla?anio=1&division=1')->assertOk();

        $this->assertStringContainsString('Matemática', $r->baseResponse->getContent());
    }

    public function test_el_horario_imprimible_respeta_el_alcance(): void
    {
        $this->como('profesor')->get('/horarios/planilla?anio=2&division=2')->assertForbidden();
    }

    public function test_el_horario_imprimible_pide_el_permiso_de_imprimir(): void
    {
        $this->quitarPermiso('profesor', 'impresion.usar');

        $this->como('profesor')->get('/horarios/planilla?anio=1&division=1')->assertForbidden();
    }

    public function test_el_horario_se_baja_en_pdf(): void
    {
        $this->guardarHorario(['h1' => ['Lun' => $this->celda()]])->assertOk();

        $r = $this->como('admin')->get('/horarios/planilla?anio=1&division=1&download=1')->assertOk();

        $this->assertStringStartsWith('%PDF', $r->baseResponse->getContent());
    }

    /* ========================= Auxiliares ========================= */

    private function guardarNotas(): void
    {
        $this->como('admin')->putJson('/api/alumnos/' . $this->alumno->id . '/calificaciones', [
            'entradas' => [[
                'anio' => 1, 'division' => '1', 'ciclo_lectivo' => 2026, 'periodo' => 1,
                'materia_id' => $this->materia->id,
                'notas' => [['valor' => 8, 'fecha' => '2026-03-10']],
            ]],
        ])->assertOk();
    }

    /** Deja al rol sin una casilla puntual, como desde la pantalla de Roles. */
    private function quitarPermiso(string $rol, string $clave): void
    {
        $base = \App\Support\Permisos::base($rol);

        \App\Dominios\Usuarios\RolConfig::updateOrCreate(
            ['rol' => $rol],
            [
                'nombre'     => ucfirst(str_replace('_', ' ', $rol)),
                'nivel'      => \App\Support\RoleHierarchy::level($rol),
                'tipo'       => 'sistema',
                'permitidos' => array_values(array_filter($base, fn ($c) => $c !== $clave)),
                'negados'    => [$clave],
            ]
        );
        \App\Dominios\Usuarios\RolConfig::olvidarRegistro();
    }
}