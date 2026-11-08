<?php

namespace Tests\Feature;

use App\Dominios\Documentos\Documento;
use App\Dominios\Sistema\ActivityLog;
use App\Dominios\Usuarios\RolConfig;
use App\Dominios\Usuarios\User;
use App\Support\Permisos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Documentos recorridos por HTTP con login real.
 *
 * Es el único módulo donde el sistema escribe archivos en el disco, así que las
 * pruebas miran dos cosas: quién puede bajar qué (visibilidad por rol) y si el
 * nombre que manda el cliente puede romper una cabecera o una ruta. Las subidas
 * van al disco falso (Storage::fake en ApiTestCase), nunca al storage real.
 */
class DocumentosApiTest extends ApiTestCase
{
    /** Sube un documento y devuelve la respuesta. */
    private function subir(string $rol, array $cambios = [], string $archivo = 'planilla.pdf'): TestResponse
    {
        $this->como($rol);

        return $this->postJson('/api/documentos', array_merge([
            'nombre'  => 'Planilla de examen',
            'archivo' => UploadedFile::fake()->create($archivo, 12, 'application/pdf'),
        ], $cambios));
    }

    /** Fila directa: permite probar documentos viejos, sin subir un archivo real. */
    private function documentar(array $datos = []): Documento
    {
        return Documento::create(array_merge([
            'user_id'        => $this->cuenta('admin')->id,
            'nombre'         => 'Documento de prueba',
            'nombre_archivo' => 'documento.pdf',
            'ruta'           => 'documentos/guardado.pdf',
            'mime'           => 'application/pdf',
            'tamano'         => 1024,
            'roles'          => ['admin'],
        ], $datos));
    }

    /** Deja el archivo físico en el disco falso para que la descarga lo encuentre. */
    private function crearArchivoEnDisco(Documento $doc, string $contenido = '%PDF-1.4 prueba'): void
    {
        Storage::disk('local')->put($doc->ruta, $contenido);
    }

    /** Rol personalizado con una lista de acciones dada, como crea la pantalla. */
    private function rolPersonalizado(string $nombre, int $nivel, array $permitidos): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($nombre)), '_'));
        RolConfig::create([
            'rol'        => $slug,
            'nombre'     => $nombre,
            'nivel'      => $nivel,
            'tipo'       => 'personalizado',
            'permitidos' => Permisos::normalizarLista($slug, $permitidos, 5),
            'negados'    => [],
            'alcance'    => [],
            'modulos'    => null,
        ]);
        RolConfig::olvidarRegistro();

        return $slug;
    }

    private function cuentaConRol(string $email, string $rol): User
    {
        $u = User::create([
            'name'     => 'Prueba',
            'apellido' => 'DeDocumento',
            'email'    => $email,
            'password' => self::CLAVE,
            'role'     => $rol,
            'status'   => 'active',
        ]);
        // El login no trae los permisos: hay que pedir /me para que el cliente sepa.
        return $u;
    }

    private function tokenDeEmail(string $email): string
    {
        $r = $this->postJson('/api/login', ['email' => $email, 'password' => self::CLAVE]);
        $r->assertOk();

        return $r->json('token');
    }

    private function confirmar(string $rol = 'admin'): void
    {
        $this->como($rol)->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])
            ->assertOk();
    }

    // ===== Lista y visibilidad =====

    public function test_la_lista_de_documentos_pide_el_permiso_de_ver(): void
    {
        $this->como('profesor')->getJson('/api/documentos')->assertOk();
        $this->documentar(['roles' => null]);

        $respuesta = $this->como('profesor')->getJson('/api/documentos');
        $respuesta->assertOk();
        $this->assertGreaterThanOrEqual(1, $respuesta->json('total'));
    }

    public function test_un_rol_personalizado_sin_ver_documentos_no_lee_la_lista(): void
    {
        $slug = $this->rolPersonalizado('Archivo Limpido', 2, ['alumnos.ver']);
        $cuenta = $this->cuentaConRol('archivo.limpido@abc.gob.ar', $slug);

        $token = $this->tokenDeEmail($cuenta->email);
        $this->withToken($token)->getJson('/api/documentos')->assertForbidden();
    }

    public function test_cada_rol_ve_solo_los_documentos_habilitados_para_el(): void
    {
        $soloAdmin = $this->documentar(['nombre' => 'Secreto de dirección', 'roles' => ['admin']]);
        $this->documentar(['nombre' => 'Calendario', 'roles' => null]);
        // Subido por una cuenta que no es ninguna de las tres que miramos acá,
        // para medir solo la lista de visibilidad y no el acceso del autor.
        $this->documentar([
            'nombre'  => 'Guía de aula',
            'roles'   => ['profesor'],
            'user_id' => $this->cuenta('directivo')->id,
        ]);

        $apellidos = fn (TestResponse $r) => array_column($r->json('data'), 'nombre');

        $delAdmin = $apellidos($this->como('admin')->getJson('/api/documentos'));
        $this->assertContains('Secreto de dirección', $delAdmin);
        $this->assertNotContains('Guía de aula', $delAdmin);

        $delPreceptor = $apellidos($this->como('preceptor')->getJson('/api/documentos'));
        $this->assertNotContains('Secreto de dirección', $delPreceptor);
        $this->assertContains('Calendario', $delPreceptor);

        $delProfesor = $apellidos($this->como('profesor')->getJson('/api/documentos'));
        $this->assertContains('Guía de aula', $delProfesor);
        $this->assertNotContains('Secreto de dirección', $delProfesor);

        unset($soloAdmin);
    }

    public function test_el_autor_ve_su_documento_aunque_su_rol_no_este_en_la_lista(): void
    {
        // La regla del autor: quien subió algo siempre lo puede abrir, editar y
        // borrar, aunque después se haya excluido de la visibilidad.
        $doc = $this->documentar([
            'nombre'  => 'Mi borrador',
            'roles'   => ['directivo'],
            'user_id' => $this->cuenta('preceptor')->id,
        ]);
        $this->crearArchivoEnDisco($doc);

        $nombres = array_column($this->como('preceptor')->getJson('/api/documentos')->assertOk()->json('data'), 'nombre');
        $this->assertContains('Mi borrador', $nombres);
        $this->como('preceptor')->getJson('/api/documentos/' . $doc->id . '/download')->assertOk();
        $this->como('profesor')->getJson('/api/documentos/' . $doc->id . '/download')->assertForbidden();
    }

    public function test_un_rol_personalizado_no_ve_los_documentos_repartidos_por_rol_de_sistema(): void
    {
        // Los documentos se reparten por nombre de rol. Un rol nuevo creado en la
        // pantalla no está en ninguna lista: solo ve los que se abrieron a todos.
        $slug = $this->rolPersonalizado('Biblioteca', 2, [
            'documentos.ver', 'documentos.descargar', 'documentos.subir',
        ]);
        $cuenta = $this->cuentaConRol('biblioteca@abc.gob.ar', $slug);
        $abierto = $this->documentar(['nombre' => 'Abierto a todos', 'roles' => null]);
        $this->documentar(['nombre' => 'Solo preceptores', 'roles' => ['preceptor']]);

        $nombres = array_column(
            $this->withToken($this->tokenDeEmail($cuenta->email))->getJson('/api/documentos')->assertOk()->json('data'),
            'nombre'
        );

        $this->assertSame(['Abierto a todos'], $nombres);
        unset($abierto);
    }

    public function test_el_que_subio_un_documento_puede_verlo_y_bajarlo_despues(): void
    {
        // El creador conserva el acceso a lo suyo por user_id en editar y borrar;
        // si su rol no queda en la lista de visibilidad, el documento le desaparece
        // de la pantalla y no lo puede descargar nunca más.
        $subido = $this->subir('admin', ['nombre' => 'Acta de reunión'])->assertCreated();
        $id = $subido->json('id');
        $this->crearArchivoEnDisco(Documento::find($id));

        $this->como('admin')->getJson('/api/documentos')
            ->assertOk()->assertJsonPath('data.0.nombre', 'Acta de reunión');
        $this->como('admin')->getJson('/api/documentos/' . $id . '/download')->assertOk();
    }

    public function test_un_rol_nuevo_que_subio_un_documento_lo_sigue_viendo(): void
    {
        $slug = $this->rolPersonalizado('Secretaria Dir', 2, [
            'documentos.ver', 'documentos.descargar', 'documentos.subir',
        ]);
        $cuenta = $this->cuentaConRol('secretaria.dir@abc.gob.ar', $slug);
        $token = $this->tokenDeEmail($cuenta->email);

        $subido = $this->withToken($token)->postJson('/api/documentos', [
            'nombre'  => 'Nota propia',
            'archivo' => UploadedFile::fake()->create('nota.pdf', 8, 'application/pdf'),
        ])->assertCreated();
        $this->crearArchivoEnDisco(Documento::find($subido->json('id')));

        $nombres = array_column(
            $this->withToken($token)->getJson('/api/documentos')->assertOk()->json('data'),
            'nombre'
        );
        $this->assertContains('Nota propia', $nombres);
        $this->withToken($token)->getJson('/api/documentos/' . $subido->json('id') . '/download')->assertOk();
    }

    public function test_la_busqueda_de_la_lista_coincide_con_subcadena(): void
    {
        $this->documentar(['nombre' => 'Planilla anual', 'roles' => ['admin']]);
        $this->documentar(['nombre' => 'Acta de reunión', 'roles' => ['admin']]);

        $r = $this->como('admin')->getJson('/api/documentos?search=plan')->assertOk();
        $this->assertSame(1, $r->json('total'));
        $this->assertSame('Planilla anual', $r->json('data.0.nombre'));
    }

    public function test_un_porcentaje_en_la_busqueda_no_devuelve_todos_los_documentos(): void
    {
        // LIKE sin escapar: "%" matchea cualquier nombre y la pantalla muestra
        // el archivo completo aunque se buscó un carácter suelto.
        $this->documentar(['nombre' => 'Planilla anual', 'roles' => ['admin']]);
        $this->documentar(['nombre' => 'Acta de reunión', 'roles' => ['admin']]);

        $r = $this->como('admin')->getJson('/api/documentos?search=%')->assertOk();
        $this->assertSame(0, $r->json('total'), 'Un % literal no tiene que matchear todos los títulos');
    }

    // ===== Subida =====

    public function test_subir_un_documento_guarda_la_fila_el_archivo_y_su_huella(): void
    {
        $r = $this->subir('admin', ['nombre' => 'Reglamento interno', 'archivo' => UploadedFile::fake()->create('regla.docx', 20)])->assertCreated();

        $doc = Documento::find($r->json('id'));
        $this->assertNotNull($doc);
        $this->assertSame('Reglamento interno', $doc->nombre);
        $this->assertSame('regla.docx', $doc->nombre_archivo);
        $this->assertSame($this->cuenta('admin')->id, (int) $doc->user_id);
        $this->assertStringStartsWith('documentos/', $doc->ruta);
        Storage::disk('local')->assertExists($doc->ruta);

        $entrada = ActivityLog::where('action_type', 'documento.store')->first();
        $this->assertNotNull($entrada, 'Subir un documento tiene que quedar en el historial');
        $this->assertStringContainsString('Reglamento interno', $entrada->action);
    }

    public function test_sin_lista_de_roles_el_documento_queda_en_direccion(): void
    {
        $r = $this->subir('admin')->assertCreated();
        $roles = Documento::find($r->json('id'))->roles;

        $this->assertSame(['admin', 'jefe_preceptores', 'preceptor'], $roles);
    }

    public function test_se_puede_abrir_un_documento_a_roles_concretos(): void
    {
        $r = $this->subir('admin', ['roles' => ['profesor', 'preceptor']])->assertCreated();
        $this->assertSame(['profesor', 'preceptor'], Documento::find($r->json('id'))->roles);
    }

    public function test_un_rol_inventado_en_la_lista_de_visibilidad_se_rechaza(): void
    {
        $this->subir('admin', ['roles' => ['superusuario']])->assertStatus(422);
        $this->assertSame(0, Documento::count());
    }

    public function test_no_se_suben_executables_ni_scripts_al_archivo(): void
    {
        foreach (['maldito.php', 'virus.exe', 'Hoja.html'] as $nombre) {
            $this->subir('admin', ['archivo' => UploadedFile::fake()->create($nombre, 5)])
                ->assertStatus(422);
        }
        $this->assertSame(0, Documento::count());
    }

    public function test_el_tamano_maximo_del_archivo_se_frena_con_un_error_claro(): void
    {
        // 51 MB contra el tope de 50 (51200 KB).
        $this->subir('admin', ['archivo' => UploadedFile::fake()->create('pesado.pdf', 51 * 1024)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['archivo']);
        $this->assertSame(0, Documento::count());
    }

    public function test_una_archivo_con_caracteres_de_control_no_deja_un_nombre_roto(): void
    {
        // El nombre lo manda el cliente: un tab o un salto de línea guardado
        // crudo rompe la cabecera de descarga más abajo.
        $r = $this->subir('admin', ['archivo' => UploadedFile::fake()->create("parcial\tfinal.pdf", 4)])->assertCreated();
        $doc = Documento::find($r->json('id'));

        $this->assertStringNotContainsString("\t", $doc->nombre_archivo);
        $this->assertStringContainsString('.pdf', $doc->nombre_archivo);
        Storage::disk('local')->assertExists($doc->ruta);
    }

    public function test_el_nombre_guardado_no_lleva_partes_de_ruta(): void
    {
        // El nombre lo manda el cliente: si se guarda tal cual, un "../../etc"
        // o un nombre con barra puede confusionar la descarga.
        $r = $this->subir('admin', [
            'archivo' => UploadedFile::fake()->create('../../secreto.txt', 4, 'text/plain'),
        ])->assertCreated();

        $doc = Documento::find($r->json('id'));
        $this->assertStringNotContainsString('/', $doc->nombre_archivo);
        $this->assertStringNotContainsString('..', $doc->nombre_archivo);
    }

    public function test_el_profesor_no_subio_documentos(): void
    {
        // La ruta de escritura exige rango de preceptora o superior.
        $this->subir('profesor')->assertForbidden();
        $this->assertSame(0, Documento::count());
    }

    public function test_la_casilla_de_subir_gana_sobre_el_rango_del_rol(): void
    {
        // EnsureRole: un rol con configuración guardada se rige por el catálogo,
        // no por la jerarquía histórica. Marcada "Subir archivos nuevos", un rol
        // de rango 1 puede subir aunque la ruta pida rango de preceptora.
        $slug = $this->rolPersonalizado('Ayudante Archivo', 1, ['documentos.ver', 'documentos.subir']);
        $cuenta = $this->cuentaConRol('ayudante@abc.gob.ar', $slug);

        $estaMarcada = Permisos::puede($slug, 'documentos.subir');
        $this->assertTrue($estaMarcada, 'La casilla quedó sin marcar: el test no midió nada');

        $this->withToken($this->tokenDeEmail($cuenta->email))->postJson('/api/documentos', [
            'nombre'  => 'Desde rango 1',
            'archivo' => UploadedFile::fake()->create('a.pdf', 4, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame(1, Documento::count());
    }

    // ===== Descarga e impresión =====

    public function test_la_descarga_devuelve_el_mismo_contenido_y_el_nombre_original(): void
    {
        $doc = $this->documentar(['nombre_archivo' => 'Planilla final.pdf', 'roles' => ['admin']]);
        $this->crearArchivoEnDisco($doc, '%PDF-1.4 contenido real');

        $r = $this->como('admin')->getJson('/api/documentos/' . $doc->id . '/download')->assertOk();
        $this->assertStringContainsString('Planilla final.pdf', $r->headers->get('content-disposition'));
        $this->assertSame('%PDF-1.4 contenido real', $r->streamedContent());
    }

    public function test_descargar_un_documento_de_otro_rol_esta_bloqueado(): void
    {
        $doc = $this->documentar(['roles' => ['admin']]);
        $this->crearArchivoEnDisco($doc);

        $this->como('preceptor')->getJson('/api/documentos/' . $doc->id . '/download')->assertForbidden();
    }

    public function test_un_rol_personalizado_sin_permiso_de_descargar_no_baja_el_archivo(): void
    {
        $slug = $this->rolPersonalizado('Solo Lectura', 2, ['documentos.ver']);
        $cuenta = $this->cuentaConRol('solo.lectura@abc.gob.ar', $slug);
        $doc = $this->documentar(['roles' => null]);
        $this->crearArchivoEnDisco($doc);

        $token = $this->tokenDeEmail($cuenta->email);
        $this->withToken($token)->getJson('/api/documentos')->assertOk();
        $this->withToken($token)->getJson('/api/documentos/' . $doc->id . '/download')->assertForbidden();
    }

    public function test_descargar_un_documento_sin_archivo_en_el_disco_da_404(): void
    {
        $doc = $this->documentar(['ruta' => 'documentos/no-existe.pdf', 'roles' => ['admin']]);

        $this->como('admin')->getJson('/api/documentos/' . $doc->id . '/download')->assertStatus(404);
    }

    public function test_un_documento_borrado_de_la_base_da_404_no_500(): void
    {
        $this->como('admin')->getJson('/api/documentos/987654/download')->assertNotFound();
    }

    public function test_imprimir_solo_funciona_con_pdf(): void
    {
        $doc = $this->documentar(['roles' => ['admin'], 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
        $this->crearArchivoEnDisco($doc);

        $this->como('admin')->getJson('/api/documentos/' . $doc->id . '/print')->assertStatus(422);
    }

    public function test_imprimir_un_pdf_abre_en_linea_sin_romper_la_cabecera(): void
    {
        // El nombre de un registro viejo puede traer comillas o saltos de línea:
        // si van crudos a Content-Disposition el navegador corta o inyecta cabeceras.
        $doc = $this->documentar([
            'nombre_archivo' => 'acta "final".pdf',
            'roles'          => ['admin'],
        ]);
        $this->crearArchivoEnDisco($doc);

        $r = $this->como('admin')->getJson('/api/documentos/' . $doc->id . '/print')->assertOk();
        $disp = $r->headers->get('content-disposition');
        $this->assertStringContainsString('inline', $disp);
        $this->assertStringNotContainsString("\n", $disp);
        $this->assertStringNotContainsString("\r", $disp);
    }

    public function test_un_nombre_con_inyeccion_de_cabecera_no_inyecta_nada(): void
    {
        $doc = $this->documentar(['roles' => ['admin']]);
        DB::table('documentos')->where('id', $doc->id)->update([
            'nombre_archivo' => "a.pdf\r\nX-Inyectado: si-esto-sale-la-cabecera-esta-rota",
        ]);
        $this->crearArchivoEnDisco($doc);

        $r = $this->como('admin')->getJson('/api/documentos/' . $doc->id . '/print')->assertOk();
        $disp = (string) $r->headers->get('content-disposition');

        // Lo que importa: no se abre una segunda cabecera ni se corta la respuesta.
        $this->assertNull($r->headers->get('X-Inyectado'));
        $this->assertStringNotContainsString("\n", $disp);
        $this->assertStringNotContainsString("\r", $disp);
        $this->assertMatchesRegularExpression('/filename="[^"]*"$/', $disp);
    }

    // ===== Edición =====

    public function test_renombrar_un_documento_deja_huella_con_el_nombre_viejo(): void
    {
        $doc = $this->documentar(['nombre' => 'Viejo', 'roles' => ['admin']]);

        $this->como('admin')->putJson('/api/documentos/' . $doc->id, ['nombre' => 'Nuevo'])->assertOk();

        $this->assertSame('Nuevo', $doc->fresh()->nombre);
        $entrada = ActivityLog::where('action_type', 'documento.update')->latest('id')->first();
        $this->assertStringContainsString('Viejo → Nuevo', $entrada->action);
    }

    public function test_editar_sin_nada_que_aplicar_avisa_en_lugar_de_guardar(): void
    {
        $doc = $this->documentar(['roles' => ['admin']]);
        $this->como('admin')->putJson('/api/documentos/' . $doc->id, [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No hay cambios para aplicar.');
    }

    public function test_la_visibilidad_no_se_puede_dejar_vacia(): void
    {
        $doc = $this->documentar(['roles' => ['admin']]);
        $this->como('admin')->putJson('/api/documentos/' . $doc->id, ['roles' => []])->assertStatus(422);
        $this->assertSame(['admin'], $doc->fresh()->roles);
    }

    public function test_el_creador_edita_su_documento_aunque_excluya_su_propio_rol(): void
    {
        $doc = $this->documentar(['roles' => ['admin']]);

        $this->como('admin')->putJson('/api/documentos/' . $doc->id, ['roles' => ['directivo']])
            ->assertOk();
        $this->assertSame(['directivo'], $doc->fresh()->roles);
    }

    public function test_un_preceptor_no_edita_un_documento_de_la_direccion(): void
    {
        $doc = $this->documentar(['roles' => ['admin']]);
        $this->como('preceptor')->putJson('/api/documentos/' . $doc->id, ['nombre' => 'Pifia'])->assertForbidden();
        $this->assertSame('Documento de prueba', $doc->fresh()->nombre);
    }

    public function test_un_preceptor_edita_un_documento_al_que_tiene_acceso(): void
    {
        $doc = $this->documentar(['nombre' => 'Circular', 'roles' => ['preceptor'], 'user_id' => $this->cuenta('admin')->id]);
        $this->como('preceptor')->putJson('/api/documentos/' . $doc->id, ['nombre' => 'Circular 12'])->assertOk();
        $this->assertSame('Circular 12', $doc->fresh()->nombre);
    }

    public function test_editar_un_documento_inexistente_da_404(): void
    {
        $this->como('admin')->putJson('/api/documentos/999999', ['nombre' => 'Nada'])->assertNotFound();
    }

    // ===== Borrado =====

    public function test_borrar_un_documento_pide_confirmar_la_clave(): void
    {
        $doc = $this->documentar(['roles' => ['admin']]);
        $this->como('admin')->deleteJson('/api/documentos/' . $doc->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Confirmá tu contraseña para realizar esta acción.');
        $this->assertDatabaseHas('documentos', ['id' => $doc->id]);
    }

    public function test_borrar_un_documento_se_lleva_el_archivo_del_disco(): void
    {
        $doc = $this->documentar(['roles' => ['admin']]);
        $this->crearArchivoEnDisco($doc);
        $this->confirmar();

        $this->como('admin')->deleteJson('/api/documentos/' . $doc->id)->assertOk();

        $this->assertDatabaseMissing('documentos', ['id' => $doc->id]);
        Storage::disk('local')->assertMissing($doc->ruta);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'documento.destroy']);
    }

    public function test_borrar_deja_el_archivo_si_la_ruta_no_existe_no_rompe_nada(): void
    {
        $doc = $this->documentar(['ruta' => 'documentos/jamas-existio.pdf', 'roles' => ['admin']]);
        $this->confirmar();

        $this->como('admin')->deleteJson('/api/documentos/' . $doc->id)->assertOk();
        $this->assertDatabaseMissing('documentos', ['id' => $doc->id]);
    }

    public function test_un_preceptor_no_borra_un_documento_de_la_direccion(): void
    {
        $doc = $this->documentar(['roles' => ['admin']]);
        $this->confirmar('preceptor');

        $this->como('preceptor')->deleteJson('/api/documentos/' . $doc->id)->assertForbidden();
        $this->assertDatabaseHas('documentos', ['id' => $doc->id]);
    }

    public function test_un_rol_personalizado_sin_eliminar_documentos_no_borra(): void
    {
        $slug = $this->rolPersonalizado('Sin Borrado', 2, ['documentos.ver']);
        $cuenta = $this->cuentaConRol('sin.borrado@abc.gob.ar', $slug);
        $doc = $this->documentar(['roles' => null]);

        $token = $this->tokenDeEmail($cuenta->email);
        $this->withToken($token)->deleteJson('/api/documentos/' . $doc->id)->assertForbidden();
        $this->assertDatabaseHas('documentos', ['id' => $doc->id]);
    }

    public function test_borrar_el_documento_de_otro_tambien_borra_el_archivo(): void
    {
        // Un preceptor borra un documento abierto a todos: el archivo físico es
        // del que lo subió, pero se tiene que ir igual (no queda basura en el server).
        $doc = $this->documentar(['roles' => null, 'user_id' => $this->cuenta('admin')->id]);
        $this->crearArchivoEnDisco($doc);
        $this->confirmar('preceptor');

        $this->como('preceptor')->deleteJson('/api/documentos/' . $doc->id)->assertOk();
        Storage::disk('local')->assertMissing($doc->ruta);
    }

    public function test_la_lista_del_documento_no_expone_la_ruta_interna_del_disco(): void
    {
        // La ruta física no es un dato útil en pantalla y revela cómo se guardan
        // los archivos del server.
        $this->documentar(['roles' => ['admin']]);
        $json = $this->como('admin')->getJson('/api/documentos')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('ruta', $json);
    }
}
