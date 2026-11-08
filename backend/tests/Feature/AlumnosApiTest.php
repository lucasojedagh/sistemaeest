<?php

namespace Tests\Feature;

use App\Dominios\Alumnos\Alumno;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

// El padrón, la ficha y las altas/bajas: el módulo que usa la escuela todos los
// días. Se prueba el alcance (el preceptor escribe solo en su curso) y la regla
// de producto: un alumno con historial no se borra, se etiqueta.
class AlumnosApiTest extends ApiTestCase
{
    private function payload(array $cambios = []): array
    {
        return array_merge([
            'apellido'         => 'Fuentes',
            'nombre'           => 'Federico',
            'genero'           => 'M',
            'dni'              => '40000200',
            'anio'             => 1,
            'division'         => '1',
            'fecha_nacimiento' => '2010-03-02',
        ], $cambios);
    }

    private function abrirXlsx(string $bytes): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        Storage::disk('local')->put('prueba.xlsx', $bytes);

        return (new Xlsx())->load(Storage::disk('local')->path('prueba.xlsx'));
    }

    /* ======================= Padrón ======================= */

    public function test_el_padron_trae_los_alumnos_ordenados_por_apellido(): void
    {
        $r = $this->como('preceptor')->getJson('/api/alumnos')->assertOk();

        $r->assertJsonPath('total', 5)->assertJsonPath('per_page', 100);
        $apellidos = array_column($r->json('data'), 'apellido');
        $this->assertSame(['Álvarez', 'Benítez', 'Cabrera', 'Domínguez', 'Escobar'], $apellidos);
        $this->assertSame('1.º 1.ª', $r->json('data.0.anio'));
        $this->assertSame(1, $r->json('data.0.anio_num'));
    }

    public function test_el_paginado_respeta_el_pedido_y_no_se_pasa_del_tope(): void
    {
        $chico = $this->como('preceptor')->getJson('/api/alumnos?per_page=2')->assertOk();
        $chico->assertJsonPath('per_page', 2)->assertJsonPath('total', 5);
        $this->assertCount(2, $chico->json('data'));

        // Basura en per_page no rompe el SQL: vuelve al valor por defecto.
        foreach (['abc', '-3', '0', '99999'] as $valor) {
            $r = $this->getJson('/api/alumnos?per_page=' . $valor)->assertOk();
            $this->assertContains($r->json('per_page'), [100, 2000], 'per_page=' . $valor);
        }
    }

    public function test_los_filtros_del_padron_recortan_de_verdad(): void
    {
        $this->como('preceptor');

        $this->getJson('/api/alumnos?anio=1')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/alumnos?anio=2&division=2')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/alumnos?genero=M')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/alumnos?genero=Masculino')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/alumnos?q=Benítez')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/alumnos?q=40000003')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/alumnos?estado=Inactivo')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/alumnos?estado=todos')->assertOk()->assertJsonPath('total', 5);
    }

    public function test_el_profesor_solo_ve_los_alumnos_de_su_curso(): void
    {
        $r = $this->como('profesor')->getJson('/api/alumnos')->assertOk();

        $r->assertJsonPath('total', 3);
        foreach ($r->json('data') as $fila) {
            $this->assertSame(1, $fila['anio_num']);
            $this->assertSame('1', $fila['division']);
        }
    }

    public function test_sin_rol_no_ve_el_padron(): void
    {
        $this->como('sin_rol')->getJson('/api/alumnos')->assertForbidden();
    }

    /* ======================= Ficha ======================= */

    public function test_la_ficha_trae_los_datos_del_alumno(): void
    {
        $this->como('preceptor')->getJson('/api/alumnos/' . $this->alumno->id)->assertOk()
            ->assertJsonPath('id', $this->alumno->id)
            ->assertJsonPath('apellido', 'Álvarez')
            ->assertJsonPath('dni', '40000001')
            ->assertJsonPath('estado', 'Activo')
            // La ficha devuelve el curso en forma compacta ("1° 1"): el
            // formulario la vuelve a parsear para armar el guardado.
            ->assertJsonPath('anio', '1° 1');
    }

    public function test_el_profesor_no_abre_la_ficha_de_otro_curso(): void
    {
        $ajeno = Alumno::where('anio', 2)->firstOrFail();

        $this->como('profesor')->getJson('/api/alumnos/' . $ajeno->id)->assertForbidden();
        $this->como('profesor')->getJson('/api/alumnos/' . $this->alumno->id)->assertOk();
    }

    public function test_una_ficha_que_no_existe_da_404(): void
    {
        $this->como('admin')->getJson('/api/alumnos/999999')->assertNotFound();
    }

    /* ======================= Alta ======================= */

    public function test_el_alta_guarda_alumno_y_contactos(): void
    {
        $r = $this->como('jefe_preceptores')->postJson('/api/alumnos', $this->payload([
            'direcciones'  => [['titular' => 'Madre', 'direccion' => 'Calle 123']],
            'telefonos'    => [['titular' => 'Madre', 'telefono' => '221-555-1234']],
            'responsables' => [['nombre' => 'Marta', 'apellido' => 'Fuentes', 'parentesco' => 'Madre', 'convive' => true, 'tutor_legal' => true]],
        ]))->assertCreated();

        $id = $r->json('id');
        $this->assertDatabaseHas('alumnos', ['id' => $id, 'apellido' => 'Fuentes', 'estado' => 'Activo']);
        $this->assertDatabaseHas('alumno_direcciones', ['alumno_id' => $id, 'direccion' => 'Calle 123']);
        $this->assertDatabaseHas('alumno_telefonos', ['alumno_id' => $id, 'telefono' => '221-555-1234']);
        $this->assertDatabaseHas('alumno_responsables', ['alumno_id' => $id, 'parentesco' => 'Madre', 'tutor_legal' => 1]);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'alumno.store', 'auditable_id' => $id]);
    }

    public function test_el_preceptor_y_el_profesor_no_dan_de_alta_alumnos(): void
    {
        $this->como('preceptor')->postJson('/api/alumnos', $this->payload())->assertForbidden();
        $this->como('profesor')->postJson('/api/alumnos', $this->payload())->assertForbidden();
        $this->assertDatabaseMissing('alumnos', ['apellido' => 'Fuentes']);
    }

    public function test_el_alta_rechaza_datos_incompletos_o_repetidos(): void
    {
        $this->como('admin');

        $this->postJson('/api/alumnos', $this->payload(['apellido' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('apellido');
        $this->postJson('/api/alumnos', $this->payload(['genero' => 'X']))
            ->assertStatus(422)->assertJsonValidationErrors('genero');
        $this->postJson('/api/alumnos', $this->payload(['dni' => '40000001']))
            ->assertStatus(422)->assertJsonValidationErrors('dni');
        $this->postJson('/api/alumnos', $this->payload(['anio' => 8]))
            ->assertStatus(422)->assertJsonValidationErrors('anio');
        $this->postJson('/api/alumnos', $this->payload(['email' => 'no-es-un-mail']))
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_la_division_tiene_que_estar_activa_para_ese_anio(): void
    {
        // El catálogo trae las divisiones 1 a 9: la letra Z no existe.
        $this->como('admin')->postJson('/api/alumnos', $this->payload(['division' => 'Z']))
            ->assertStatus(422)->assertJsonValidationErrors('division');
    }

    public function test_la_especialidad_es_solo_a_partir_de_cuarto(): void
    {
        $this->como('admin')->postJson('/api/alumnos', $this->payload([
            'anio' => 1, 'especialidad' => 'informatica',
        ]))->assertStatus(422)->assertJsonValidationErrors('especialidad');

        $this->postJson('/api/alumnos', $this->payload([
            'anio' => 4, 'division' => '1', 'especialidad' => 'informatica', 'dni' => '40000201',
        ]))->assertCreated()->assertJsonPath('anio_num', 4);
    }

    public function test_sin_dni_no_se_pisa_con_otros_alumnos_sin_dni(): void
    {
        $this->como('admin');

        // El DNI vacío se guarda como null: dos alumnos sin documento no son
        // el mismo alumno y no tienen que chocar contra el índice único.
        $this->postJson('/api/alumnos', $this->payload(['dni' => '', 'apellido' => 'SinDocumento']))
            ->assertCreated();
        $this->postJson('/api/alumnos', $this->payload(['dni' => '', 'apellido' => 'OtroSinDocumento']))
            ->assertCreated();

        $this->assertSame(2, Alumno::whereNull('dni')->count());
    }

    /* ======================= Edición ======================= */

    public function test_el_preceptor_edita_solo_los_alumnos_de_su_curso(): void
    {
        $ajeno = Alumno::where('anio', 2)->firstOrFail();

        $this->como('preceptor')->putJson('/api/alumnos/' . $this->alumno->id, [
            'apellido' => 'Álvarez', 'nombre' => 'Ana María', 'genero' => 'F', 'anio' => 1, 'division' => '1',
        ])->assertOk();
        $this->assertDatabaseHas('alumnos', ['id' => $this->alumno->id, 'nombre' => 'Ana María']);

        $this->putJson('/api/alumnos/' . $ajeno->id, [
            'apellido' => $ajeno->apellido, 'nombre' => 'Cambiado', 'genero' => 'M', 'anio' => 2, 'division' => '2',
        ])->assertForbidden();
        $this->assertDatabaseHas('alumnos', ['id' => $ajeno->id, 'nombre' => $ajeno->nombre]);
    }

    public function test_el_profesor_no_edita_alumnos(): void
    {
        $this->como('profesor')->putJson('/api/alumnos/' . $this->alumno->id, $this->payload([
            'apellido' => 'Álvarez', 'nombre' => 'Ana', 'dni' => '40000001',
        ]))->assertForbidden();
    }

    public function test_la_edicion_con_version_vieja_avisa_en_vez_de_pisar(): void
    {
        // La ficha quedó abierta hace un rato: su versión es la de entonces.
        $this->alumno->updated_at = now()->subSeconds(30);
        $this->alumno->save();
        $version = (int) $this->alumno->updated_at->getTimestamp();
        $this->como('admin');

        $datos = ['apellido' => 'Álvarez', 'nombre' => 'Ana', 'genero' => 'F', 'anio' => 1, 'division' => '1'];
        $this->putJson('/api/alumnos/' . $this->alumno->id, $datos + ['_version' => $version])->assertOk();

        // Otra persona guardó en el medio: la segunda ficha llega con la versión vieja.
        $this->putJson('/api/alumnos/' . $this->alumno->id, $datos + ['nombre' => 'Pisado', '_version' => $version])
            ->assertStatus(409)->assertJsonPath('conflicto', true);

        $this->assertDatabaseMissing('alumnos', ['id' => $this->alumno->id, 'nombre' => 'Pisado']);
    }

    public function test_guardar_solo_un_telefono_tambien_avanza_la_version(): void
    {
        $this->alumno->updated_at = now()->subSeconds(30);
        $this->alumno->save();
        $version = (int) $this->alumno->updated_at->getTimestamp();

        $this->como('admin')->putJson('/api/alumnos/' . $this->alumno->id, [
            'apellido' => 'Álvarez', 'nombre' => 'Ana', 'genero' => 'F', 'anio' => 1, 'division' => '1',
            'telefonos' => [['titular' => 'Madre', 'telefono' => '221-555-9999']],
            '_version' => $version,
        ])->assertOk();

        // Cambió un hijo, no los campos del alumno: igual es una edición real y
        // la otra persona tiene que enterarse.
        $this->assertNotSame($version, (int) $this->alumno->fresh()->updated_at->getTimestamp());
    }

    public function test_el_estado_no_acepta_cualquier_palabra(): void
    {
        $this->como('admin')->putJson('/api/alumnos/' . $this->alumno->id, [
            'apellido' => 'Álvarez', 'nombre' => 'Ana', 'genero' => 'F', 'anio' => 1, 'division' => '1',
            'estado' => 'Abandonó',
        ])->assertStatus(422);

        $this->assertDatabaseHas('alumnos', ['id' => $this->alumno->id, 'estado' => 'Activo']);
    }

    /* ======================= Bajas ======================= */

    public function test_inactivar_marca_y_conserva_todos_los_datos(): void
    {
        $this->como('jefe_preceptores')->postJson('/api/alumnos/' . $this->alumno->id . '/inactivar', [
            'anioLectivo' => 2026,
        ])->assertOk()
            ->assertJsonPath('estado', 'Inactivo')
            ->assertJsonPath('egreso_year', 2026)
            ->assertJsonPath('egreso_anio', 1)
            ->assertJsonPath('egreso_division', '1');

        // La ficha sigue existiendo con su DNI y su curso: no se borró nada.
        $this->assertDatabaseHas('alumnos', [
            'id' => $this->alumno->id, 'estado' => 'Inactivo', 'dni' => '40000001', 'anio' => 1,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'alumno.inactivar']);
        // Sale de la cursada: el filtro de activos ya no lo trae.
        $this->getJson('/api/alumnos?estado=Activo')->assertOk()->assertJsonPath('total', 4);
    }

    public function test_egresar_es_solo_para_el_ultimo_anio(): void
    {
        $this->como('jefe_preceptores');

        $this->postJson('/api/alumnos/' . $this->alumno->id . '/egresar', ['anioLectivo' => 2026])
            ->assertStatus(422);
        $this->assertDatabaseHas('alumnos', ['id' => $this->alumno->id, 'estado' => 'Activo']);

        $ultimo = $this->nuevoAlumno(['apellido' => 'Zárate', 'nombre' => 'Zoe', 'anio' => 7, 'division' => '1']);
        $this->postJson('/api/alumnos/' . $ultimo->id . '/egresar', ['anioLectivo' => 2026])
            ->assertOk()->assertJsonPath('estado', 'Egresado')->assertJsonPath('egreso_anio', 7);
    }

    public function test_borrar_pide_la_clave_confirmada(): void
    {
        $nuevo = $this->nuevoAlumno(['apellido' => 'Temporal', 'nombre' => 'Teresa']);

        $this->como('admin')->deleteJson('/api/alumnos/' . $nuevo->id)->assertStatus(422);
        $this->assertDatabaseHas('alumnos', ['id' => $nuevo->id]);

        $this->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])->assertOk();
        $this->deleteJson('/api/alumnos/' . $nuevo->id)->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseMissing('alumnos', ['id' => $nuevo->id]);
    }

    public function test_un_alumno_con_notas_no_se_borra(): void
    {
        DB::table('alumno_calificaciones')->insert([
            'alumno_id' => $this->alumno->id, 'materia_id' => $this->materia->id,
            'anio' => 1, 'division' => '1', 'periodo' => 1, 'tipo_calificacion' => 'regular',
            'valor' => '8', 'ciclo_lectivo' => 2026, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->como('admin')->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])->assertOk();
        $r = $this->deleteJson('/api/alumnos/' . $this->alumno->id)->assertStatus(422);

        $this->assertSame(1, $r->json('historial.notas'));
        $this->assertStringContainsString('no elimina ese histórico', $r->json('message'));
        $this->assertDatabaseHas('alumnos', ['id' => $this->alumno->id]);
        $this->assertDatabaseHas('alumno_calificaciones', ['alumno_id' => $this->alumno->id]);
    }

    public function test_un_alumno_con_asistencia_no_se_borra(): void
    {
        $clase = DB::table('clases')->insertGetId([
            'curso_id' => $this->curso->id, 'fecha' => '2026-03-10', 'numero_clase' => 1,
            'tipo_responsable' => 'preceptor', 'estado' => 'dictada',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('asistencias')->insert([
            'clase_id' => $clase, 'alumno_id' => $this->alumno->id, 'estado' => 'presente',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->como('admin')->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])->assertOk();
        $this->deleteJson('/api/alumnos/' . $this->alumno->id)
            ->assertStatus(422)->assertJsonPath('historial.asistencia', 1);

        $this->assertDatabaseHas('alumnos', ['id' => $this->alumno->id]);
        $this->assertDatabaseHas('asistencias', ['alumno_id' => $this->alumno->id]);
    }

    public function test_el_preceptor_no_borra_alumnos(): void
    {
        // Puede confirmar su clave, pero la baja definitiva de un alumno es de
        // gestión: el candado de la ruta lo deja afuera igual.
        $this->como('preceptor')->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])
            ->assertOk();
        $this->deleteJson('/api/alumnos/' . $this->alumno->id)->assertForbidden();
    }

    /* ======================= Excel ======================= */

    public function test_el_padron_baja_un_xlsx_con_las_dos_hojas(): void
    {
        $r = $this->como('preceptor')->get('/api/alumnos/padron/excel')->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $r->headers->get('Content-Type')
        );
        $this->assertStringContainsString('lista_alumnos.xlsx', $r->headers->get('Content-Disposition'));

        $bytes = $r->baseResponse->getContent();
        // El largo declarado tiene que ser el del cuerpo: si no, la descarga
        // llega cortada y el archivo no abre.
        $this->assertSame(strlen($bytes), (int) $r->headers->get('Content-Length'));
        $this->assertSame('PK', substr($bytes, 0, 2));

        $excel = $this->abrirXlsx($bytes);
        $this->assertSame(['Padrón', 'Resumen'], $excel->getSheetNames());
        $this->assertSame('RESUMEN DEL PADRÓN', $excel->getSheetByName('Resumen')->getCell('A1')->getValue());
    }

    public function test_el_padron_excel_respeta_el_alcance_del_profesor(): void
    {
        $bytes = $this->como('profesor')->get('/api/alumnos/padron/excel?anio=2')
            ->assertOk()->baseResponse->getContent();
        $excel = $this->abrirXlsx($bytes);

        // Pidió 2° 2.ª pero solo ve 1° 1.ª: el archivo dice cuántos alumnos entraron
        // en vez de bajar el padrón entero por la puerta de atrás.
        $this->assertStringContainsString('0 alumnos', (string) $excel->getSheetByName('Resumen')->getCell('A2')->getValue());
        $this->assertDatabaseCount('alumnos', 5);
    }

    public function test_la_plantilla_de_importacion_es_solo_para_gestion(): void
    {
        $r = $this->como('admin')->get('/api/alumnos/importar/plantilla')->assertOk();
        $this->assertStringContainsString('plantilla_alumnos.xlsx', $r->headers->get('Content-Disposition'));
        $this->assertSame('PK', substr($r->baseResponse->getContent(), 0, 2));

        $this->como('preceptor')->get('/api/alumnos/importar/plantilla')->assertForbidden();
        $this->como('profesor')->get('/api/alumnos/importar/plantilla')->assertForbidden();
    }
}