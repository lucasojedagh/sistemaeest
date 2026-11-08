<?php

namespace Tests\Feature;

use App\Dominios\Sistema\ActivityLog;
use App\Dominios\Usuarios\RolConfig;
use App\Dominios\Usuarios\User;
use App\Support\Permisos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Historial de actividad recorrido por HTTP con login real.
 *
 * Es la pantalla que la escuela usa para dar de vuelta un cambio, así que lo
 * que se mide acá es que cada tarjeta cuente la verdad (quién, qué, antes y
 * después), que los filtros no se coman filas y que nadie sin derecho pueda
 * leer la auditoría ni vaciarla. La escritura de cada módulo ya está cubierta
 * por su propia prueba; acá se prueba la lectura y la exportación.
 */
class HistorialApiTest extends ApiTestCase
{
    /** Fila de historial directa, para armar escenarios que la UI no produce. */
    private function registrar(array $datos = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'user_id'        => $this->cuenta('admin')->id,
            'user_email'     => $this->cuenta('admin')->email,
            'user_role'      => 'admin',
            'action'         => 'Acción de prueba',
            'action_type'    => 'alumno.update',
            'auditable_type' => 'Alumno',
            'auditable_id'   => $this->alumno->id,
            'old_values'     => null,
            'new_values'     => null,
            'detalles'       => null,
            'ip_address'     => '127.0.0.1',
            'user_agent'     => 'phpunit',
        ], $datos));
    }

    /**
     * Historial en cero con las sesiones de los roles ya abiertas.
     *
     * Entrar por /api/login escribe su propia tarjeta ("Inició sesión") en la
     * auditoría, y esa tarjeta es siempre la más nueva. Sin este orden, un
     * conteo sale de a uno y la primera tarjeta de la lista es la del login en
     * lugar de la que armó la prueba.
     */
    private function historialEnCero(string ...$roles): void
    {
        foreach ($roles as $rol) {
            $this->tokenDe($rol);
        }
        ActivityLog::query()->delete();
    }

    private function crearRol(string $nombre, int $nivel, array $permitidos): string
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
        return User::create([
            'name'     => 'Prueba',
            'apellido' => 'DeHistorial',
            'email'    => $email,
            'password' => self::CLAVE,
            'role'     => $rol,
            'status'   => 'active',
        ]);
    }

    private function tokenDeEmail(string $email): string
    {
        $r = $this->postJson('/api/login', ['email' => $email, 'password' => self::CLAVE]);
        $r->assertOk();

        return $r->json('token');
    }

    private function confirmar(string $rol = 'admin'): void
    {
        $this->como($rol)->postJson('/api/auth/confirm-password', ['password' => self::CLAVE])->assertOk();
    }

    // =====Quién puede leer la auditoría =====

    public function test_la_conduccion_lee_el_historial(): void
    {
        $this->registrar();

        foreach (['admin', 'directivo', 'jefe_preceptores'] as $rol) {
            $this->como($rol)->getJson('/api/historial')->assertOk();
        }
    }

    public function test_un_preceptor_o_un_profesor_no_leen_el_historial(): void
    {
        $this->registrar();

        foreach (['preceptor', 'profesor'] as $rol) {
            $this->como($rol)->getJson('/api/historial')->assertForbidden();
        }
    }

    public function test_sin_sesion_no_se_lee_el_historial(): void
    {
        $this->getJson('/api/historial')->assertUnauthorized();
        $this->getJson('/api/historial/export')->assertUnauthorized();
    }

    public function test_un_rol_personalizado_sin_la_casilla_de_historial_no_lee_la_auditoria(): void
    {
        $slug = $this->crearRol('Mirada Corta', 3, ['alumnos.ver']);
        $token = $this->tokenDeEmail($this->cuentaConRol('mirada.corta@abc.gob.ar', $slug)->email);

        $this->withToken($token)->getJson('/api/historial')->assertForbidden();
    }

    public function test_la_casilla_de_ver_historial_abre_la_auditoria_a_un_rol_nuevo(): void
    {
        // EnsureRole deja decidir al catálogo: un rol rango 1 con la casilla
        // marcada tiene que poder leer, porque la pantalla le ofreció esa casilla.
        $slug = $this->crearRol('Ombudsman', 1, ['historial.ver']);
        $cuenta = $this->cuentaConRol('ombudsman@abc.gob.ar', $slug);
        $this->registrar();

        $this->withToken($this->tokenDeEmail($cuenta->email))->getJson('/api/historial')->assertOk();
    }

    public function test_la_casilla_de_exportar_no_se_puede_regalar_fuera_del_admin(): void
    {
        $slug = $this->crearRol('Copia Propia', 5, ['historial.exportar', 'historial.ver']);
        $cuenta = $this->cuentaConRol('copia.propia@abc.gob.ar', $slug);

        $estaMarcada = Permisos::puede($slug, 'historial.exportar');
        $token = $this->tokenDeEmail($cuenta->email);
        $export = $this->withToken($token)->getJson('/api/historial/export');

        $this->assertFalse($estaMarcada, 'Un rol que no es Admin no puede recibir historial.exportar');
        $export->assertForbidden();
    }

    public function test_la_casilla_de_limpiar_no_se_puede_regalar_fuera_del_admin(): void
    {
        $slug = $this->crearRol('Borrador Eterno', 5, ['historial.limpiar', 'historial.ver']);
        $cuenta = $this->cuentaConRol('borrador.eterno@abc.gob.ar', $slug);

        $this->assertFalse(Permisos::puede($slug, 'historial.limpiar'));
        $this->withToken($this->tokenDeEmail($cuenta->email))
            ->deleteJson('/api/historial')->assertForbidden();
    }

    // ===== Filtros =====

    public function test_el_filtro_de_area_toma_prefijos_del_tipo_de_accion(): void
    {
        $this->registrar(['action_type' => 'alumno.update', 'action' => 'Editó un alumno']);
        $this->registrar(['action_type' => 'calificacion.batch', 'action' => 'Guardó la planilla']);
        $this->registrar(['action_type' => 'documento.store', 'action' => 'Subió un documento']);

        $r = $this->como('admin')->getJson('/api/historial?categoria=alumno')->assertOk();
        $tipos = array_column($r->json('data'), 'action_type');

        $this->assertSame(['alumno.update'], $tipos);
        $this->assertSame(1, $r->json('total'));
    }

    public function test_el_filtro_de_operacion_toma_el_sufijo_de_la_accion(): void
    {
        $this->registrar(['action_type' => 'alumno.store', 'action' => 'Alta 1']);
        $this->registrar(['action_type' => 'alumno.update', 'action' => 'Edición 1']);

        $r = $this->como('admin')->getJson('/api/historial?operacion=create')->assertOk();
        $this->assertSame(0, $r->json('total'), '"create" no existe como sufijo en este sistema');

        $store = $this->como('admin')->getJson('/api/historial?operacion=store')->assertOk();
        $this->assertSame(['alumno.store'], array_column($store->json('data'), 'action_type'));
    }

    public function test_la_busqueda_encuentra_la_accion_el_actor_y_el_detalle(): void
    {
        $this->registrar(['action' => 'Importó el padrón 2026', 'user_email' => 'otro@abc.gob.ar']);
        $this->registrar(['action' => 'Otra cosa', 'user_email' => 'buscado@abc.gob.ar']);
        $this->registrar([
            'action'     => 'Cargó notas',
            'new_values' => json_encode(['Matemática' => 7], JSON_UNESCAPED_UNICODE),
        ]);

        $porTexto = $this->como('admin')->getJson('/api/historial?search=padron')->assertOk();
        $this->assertSame(1, $porTexto->json('total'));

        $porEmail = $this->como('admin')->getJson('/api/historial?search=buscado@abc')->assertOk();
        $this->assertSame(1, $porEmail->json('total'));

        $porCambios = $this->como('admin')->getJson('/api/historial?search=Matemática')->assertOk();
        $this->assertSame(1, $porCambios->json('total'), 'Buscar por la materia editada tiene que encontrar la nota');
    }

    public function test_un_simbolo_en_la_busqueda_no_devuelve_todos_los_movimientos(): void
    {
        $this->registrar(['action' => 'Una acción sin símbolo']);
        $this->registrar(['action' => 'Otra acción sin símbolo']);

        foreach (['%', '_'] as $simbolo) {
            $total = $this->como('admin')->getJson('/api/historial?search=' . urlencode($simbolo))->assertOk()->json('total');
            $this->assertSame(0, $total, "La búsqueda literal \"$simbolo\" no tiene que matchear todo");
        }
    }

    public function test_el_filtro_por_fecha_usa_el_rango_y_descarta_lo_de_afuera(): void
    {
        $this->historialEnCero('admin');
        $hoy   = $this->registrar(['action' => 'Movimiento de hoy']);
        $vieja = $this->registrar(['action' => 'Movimiento de hace mes y medio']);
        DB::table('activity_logs')->where('id', $vieja->id)->update([
            'created_at' => now()->subDays(45),
        ]);

        $desde = $this->como('admin')->getJson('/api/historial?desde=' . now()->subDays(5)->toDateString())->assertOk();
        $this->assertSame([$hoy->id], array_column($desde->json('data'), 'id'), 'Con "desde" hace 5 días lo viejísimo queda afuera');

        $hasta = $this->como('admin')->getJson('/api/historial?hasta=' . now()->subDays(30)->toDateString())->assertOk();
        $this->assertSame([$vieja->id], array_column($hasta->json('data'), 'id'), 'Con "hasta" hace 30 días entra solo lo de antes');
    }

    public function test_una_fecha_sin_formato_devuelve_un_error_claro_no_una_pantalla_vacia(): void
    {
        $r = $this->como('admin')->getJson('/api/historial?desde=anteayer')->assertStatus(422);
        $this->assertStringContainsString('no tiene un formato válido', $r->json('errors.fecha.0'));
    }

    public function test_una_fecha_futura_se_rechaza(): void
    {
        $r = $this->como('admin')->getJson('/api/historial?desde=2030-01-01')->assertStatus(422);
        $this->assertStringContainsString('no puede ser futura', $r->json('errors.fecha.0'));
    }

    public function test_desde_mayor_que_hasta_se_rechaza_con_el_mensaje_correcto(): void
    {
        $r = $this->como('admin')->getJson('/api/historial?desde=2026-05-10&hasta=2026-05-01')
            ->assertStatus(422);
        $this->assertStringContainsString('debe ser menor o igual', $r->json('errors.fecha.0'));
    }

    public function test_una_fecha_que_no_existe_en_el_calendario_se_rechaza(): void
    {
        // Carbon resuelve "2026-02-30" como 2 de marzo: sin esta guarda el filtro
        // se corre un mes entero y muestra movimientos que nadie pidió.
        $r = $this->como('admin')->getJson('/api/historial?desde=2026-02-30')->assertStatus(422);
        $this->assertStringContainsString('no existe en el calendario', $r->json('errors.fecha.0'));
    }

    public function test_una_fecha_bisiesta_valida_si_se_acepta(): void
    {
        // La guarda no tiene que rechazar lo legítimo: 29/2 sólo existe en bisiesto.
        $this->como('admin')->getJson('/api/historial?desde=2024-02-29')->assertOk();
        $this->como('admin')->getJson('/api/historial?desde=2026-02-29')->assertStatus(422);
    }

    // ===== Paginación y conteos =====

    public function test_el_listado_pagina_y_el_tope_de_filas_por_pagina_se_respeta(): void
    {
        $this->historialEnCero('admin');
        for ($i = 0; $i < 55; $i++) {
            $this->registrar(['action' => 'Movimiento ' . $i]);
        }

        $primera = $this->como('admin')->getJson('/api/historial')->assertOk();
        $this->assertSame(55, $primera->json('total'));
        $this->assertCount(50, $primera->json('data'));

        $segunda = $this->como('admin')->getJson('/api/historial?page=2')->assertOk();
        $this->assertCount(5, $segunda->json('data'));

        // El pedido pide 5000: sin el tope, MySQL se lleva la auditoría entera a memoria.
        $grande = $this->como('admin')->getJson('/api/historial?per_page=5000')->assertOk();
        $this->assertLessThanOrEqual(200, count($grande->json('data')));
    }

    public function test_los_conteos_de_area_reflejan_todo_el_historial_no_la_pagina(): void
    {
        $this->historialEnCero('admin');
        for ($i = 0; $i < 52; $i++) {
            $this->registrar(['action_type' => 'alumno.update', 'action' => 'Alumno ' . $i]);
        }
        $this->registrar(['action_type' => 'documento.store', 'action' => 'Documento']);

        $conteos = $this->como('admin')->getJson('/api/historial')->assertOk()->json('conteos');

        $this->assertSame(52, $conteos['alumno']);
        $this->assertSame(1, $conteos['documento']);
    }

    public function test_un_parametro_de_pagina_basura_no_rompe_el_listado(): void
    {
        $this->registrar();
        $this->como('admin')->getJson('/api/historial?per_page=NaN')->assertOk();
        $this->como('admin')->getJson('/api/historial?per_page=-5')->assertOk();
        $this->como('admin')->getJson('/api/historial?page=tres')->assertOk();
    }

    // ===== Qué trae cada tarjeta =====

    public function test_cada_tarjeta_trae_los_campos_que_la_pantalla_necesita(): void
    {
        $this->historialEnCero('admin');
        $this->registrar(['action_type' => 'alumno.update', 'action' => 'Editó a Ana']);

        $fila = $this->como('admin')->getJson('/api/historial')->assertOk()->json('data.0');

        foreach (['id', 'icono', 'color', 'categoria', 'operacion', 'titulo', 'actor',
                  'actor_role_legible', 'entidad', 'resumen', 'fecha', 'fecha_legible'] as $campo) {
            $this->assertArrayHasKey($campo, $fila, "la tarjeta vino sin '$campo'");
        }
        $this->assertSame('Alumno', $fila['entidad'], 'La entidad se rotula en español, no con el nombre de la clase');
    }

    public function test_el_actor_se_muestra_con_apellido_y_nombre_no_con_su_numero(): void
    {
        $this->historialEnCero('admin');
        $this->registrar(['action' => 'Editó un alumno']);

        $fila = $this->como('admin')->getJson('/api/historial')->assertOk()->json('data.0');

        $this->assertStringContainsString('Admin', $fila['actor']);
        $this->assertStringNotContainsString('#' . $this->cuenta('admin')->id, $fila['actor']);
    }

    public function test_el_rol_interno_no_sale_en_la_tarjeta_como_clave_tecnica(): void
    {
        $this->historialEnCero('admin');
        $this->registrar(['user_role' => 'jefe_preceptores', 'user_email' => 'jefe@abc.gob.ar']);

        $fila = $this->como('admin')->getJson('/api/historial')->assertOk()->json('data.0');
        $this->assertSame('Jefe de preceptores', $fila['actor_role_legible']);
    }

    public function test_un_movimiento_de_una_cuenta_borrada_no_desaparece_ni_rompe(): void
    {
        $this->historialEnCero('admin');
        $fantasma = User::create([
            'name' => 'Fuga', 'apellido' => 'DePrueba', 'email' => 'fuga@abc.gob.ar',
            'password' => self::CLAVE, 'role' => 'preceptor', 'status' => 'active',
        ]);
        $this->registrar(['user_id' => $fantasma->id, 'user_email' => $fantasma->email, 'user_role' => 'preceptor']);
        $fantasma->delete();

        $fila = $this->como('admin')->getJson('/api/historial')->assertOk()->json('data.0');

        // La tarjeta se puede leer, pero el nombre ya no es un link a una ficha que no existe.
        $this->assertNull($fila['actor_user_id'], 'Sin cuenta no hay ficha que abrir');
        $this->assertSame('fuga@abc.gob.ar', $fila['actor_email'], 'El correo deja identificado a quién fue');
        $this->assertSame('Fuga', $fila['actor'], 'El nombre se reconstruye del correo, no de un número suelto');
    }

    public function test_la_marca_de_un_alumno_se_muestra_con_apellido_y_nombre(): void
    {
        // Las planillas de asistencia guardan la marca como "alumno_<id>": la
        // tarjeta tiene que traducirlo, un número suelto no le dice nada a nadie.
        $this->historialEnCero('admin');
        $this->registrar([
            'action'         => 'Corrigió una marca de asistencia',
            'action_type'    => 'asistencia.update',
            'auditable_type' => 'Clase',
            'new_values'     => ['alumno_' . $this->alumno->id => 'P'],
        ]);

        $fila = $this->como('admin')->getJson('/api/historial')->assertOk()->json('data.0');
        $texto = json_encode($fila['resumen'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Álvarez, Ana', $texto);
        $this->assertStringNotContainsString('alumno_' . $this->alumno->id, $texto);
    }

    public function test_un_alumno_ya_borrado_del_padron_no_deja_un_numero_suelto(): void
    {
        $this->historialEnCero('admin');
        $fuera = $this->nuevoAlumno(['apellido' => 'Fugaz', 'nombre' => 'Nicolás']);
        $this->registrar([
            'action'         => 'Corrigió una marca de asistencia',
            'action_type'    => 'asistencia.update',
            'auditable_type' => 'Clase',
            'new_values'     => ['alumno_' . $fuera->id => 'P'],
        ]);
        $fuera->forceDelete();

        $fila = $this->como('admin')->getJson('/api/historial')->assertOk()->json('data.0');
        $this->assertStringNotContainsString('alumno_', json_encode($fila['resumen']));
    }

    public function test_el_historial_nunca_devuelve_una_clave_aun_cuando_alguien_la_escribio(): void
    {
        // Un registro viejo puede traer el hash dentro de new_values: la pantalla
        // no lo tiene que mostrar nunca.
        $this->historialEnCero('admin');
        $this->registrar([
            'action'     => 'Actualizó usuario',
            'new_values' => ['password' => '$2y$04$unhashficticioque nopuede salir', 'name' => 'Ana'],
        ]);

        $json = $this->como('admin')->getJson('/api/historial')->assertOk()->json();
        $this->assertStringNotContainsString('$2y$', json_encode($json));
    }

    public function test_sin_motivo_la_tarjeta_no_repite_la_frase(): void
    {
        $this->historialEnCero('admin');
        $sinMotivo = $this->registrar(['detalles' => 'sin motivo']);
        $fila = $this->como('admin')->getJson('/api/historial')->assertOk()->json('data.0');
        $this->assertSame($sinMotivo->id, $fila['id']);
        $this->assertNull($fila['detalles']);

        $conMotivo = $this->registrar(['detalles' => 'Corrección pedida por la familia']);
        $leida = $this->como('admin')->getJson('/api/historial?search=' . urlencode('Corrección pedida'))
            ->assertOk()->json('data.0');
        $this->assertSame($conMotivo->id, $leida['id']);
        $this->assertSame('Corrección pedida por la familia', $leida['detalles']);
    }

    // ===== Caché y refresco =====

    public function test_sin_filtros_la_primera_carga_se_cachea_y_refresh_la_refresca(): void
    {
        $this->historialEnCero('admin');
        $primero = $this->registrar(['action' => 'uno']);
        $primera = $this->como('admin')->getJson('/api/historial')->assertOk();
        $this->assertSame([$primero->id], array_column($primera->json('data'), 'id'));
        $this->assertSame(1, $primera->json('total'));

        // Escritura nueva: el cache de la carga inicial todavía la esconde.
        $this->registrar(['action' => 'Movimiento posterior']);
        $cacheada = $this->como('admin')->getJson('/api/historial')->assertOk();
        $totalCacheado = $cacheada->json('total');

        $refrescada = $this->como('admin')->postJson('/api/historial/refresh')->assertOk();

        $this->assertSame(1, $totalCacheado, 'La carga inicial no tendría que ver aún el movimiento nuevo');
        $this->assertSame(2, $refrescada->json('total'));
    }

    public function test_los_filtros_nunca_salieron_de_la_caché(): void
    {
        $this->historialEnCero('admin');
        $this->como('admin')->getJson('/api/historial?search=uno')->assertOk();
        $dos = $this->registrar(['action' => 'dos']);
        $filtrada = $this->como('admin')->getJson('/api/historial?search=dos')->assertOk();

        $this->assertSame([$dos->id], array_column($filtrada->json('data'), 'id'), 'Una búsqueda siempre tiene que consultar la base');
    }

    public function test_la_auditoria_se_mantiene_aun_con_la_cache_del_listado_caliente(): void
    {
        // Un filtro lee la base real y no ensucia el cache de la carga inicial:
        // medir ambos en el mismo pedido es lo que hace la pantalla al cambiar de solapa.
        $this->historialEnCero('admin');
        $unico = $this->registrar(['action' => 'uno']);
        $this->como('admin')->getJson('/api/historial')->assertOk();
        $this->como('admin')->getJson('/api/historial?categoria=alumno')->assertOk();
        $sinFiltro = $this->como('admin')->getJson('/api/historial')->assertOk();

        $this->assertSame([$unico->id], array_column($sinFiltro->json('data'), 'id'));
        $this->assertSame(1, $sinFiltro->json('total'));
    }

    // ===== Exportar =====

    public function test_exportar_baja_todas_las_filas_no_solo_la_pagina_visible(): void
    {
        $this->historialEnCero('admin');
        for ($i = 0; $i < 55; $i++) {
            $this->registrar(['action' => 'Movimiento ' . $i]);
        }

        $csv = $this->como('admin')->get('/api/historial/export')->assertOk()->streamedContent();
        $lineas = array_filter(explode("\n", $csv), fn ($l) => trim($l) !== '');

        // BOM + cabecera + 55 filas.
        $this->assertCount(56, $lineas);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Sin BOM Excel abre mal los acentos');
    }

    public function test_la_descarga_del_historial_viene_con_cabecera_y_nombre_con_fecha(): void
    {
        $this->registrar();
        $r = $this->como('admin')->get('/api/historial/export')->assertOk();

        $this->assertStringContainsString('text/csv', $r->headers->get('content-type'));
        $this->assertStringContainsString('historial_', $r->headers->get('content-disposition'));
    }

    public function test_exportar_en_json_devuelve_una_lista_valida(): void
    {
        $this->historialEnCero('admin');
        $this->registrar(['action' => 'Uno']);
        $this->registrar(['action' => 'Dos']);

        $json = json_decode($this->como('admin')->get('/api/historial/export?format=json')->assertOk()->streamedContent(), true);

        $this->assertIsArray($json);
        $this->assertCount(2, $json);
    }

    public function test_exportar_respeta_los_mismos_filtros_que_la_pantalla(): void
    {
        $this->registrar(['action_type' => 'alumno.update', 'action' => 'Alumno']);
        $this->registrar(['action_type' => 'documento.store', 'action' => 'Documento']);

        $csv = $this->como('admin')->get('/api/historial/export?categoria=documento')->assertOk()->streamedContent();
        $lineas = array_filter(explode("\n", $csv), fn ($l) => trim($l) !== '');

        $this->assertCount(2, $lineas, 'El archivo tiene que traer exactamente lo que se está viendo');
        $this->assertStringContainsString('Documento', $lineas[1]);
    }

    public function test_una_fecha_invalida_en_la_descarga_se_rechaza_antes_de_escribir_nada(): void
    {
        $this->registrar();
        $this->como('admin')->get('/api/historial/export?desde=nope')->assertStatus(422);
    }

    public function test_solo_el_admin_exporta_el_historial(): void
    {
        $this->registrar();
        foreach (['directivo', 'jefe_preceptores'] as $rol) {
            $this->como($rol)->get('/api/historial/export')->assertForbidden();
        }
    }

    // ===== Vaciar =====

    public function test_vaciar_el_historial_pide_confirmar_la_clave(): void
    {
        $this->historialEnCero('admin');
        $this->registrar();
        $this->como('admin')->deleteJson('/api/historial')->assertStatus(422);
        $this->assertSame(1, ActivityLog::count(), 'Sin la clave confirmada no se borra ni una fila');
    }

    public function test_vaciar_deja_un_solo_movimiento_y_su_copia_en_el_servidor(): void
    {
        Storage::fake('local');
        $this->historialEnCero('admin');
        for ($i = 0; $i < 20; $i++) {
            $this->registrar(['action' => 'Movimiento ' . $i]);
        }
        $this->confirmar();

        $r = $this->como('admin')->deleteJson('/api/historial')->assertOk();

        $this->assertSame(20, $r->json('borrados'));
        $this->assertNotNull($r->json('respaldo'));
        $this->assertSame(1, ActivityLog::count());

        $nuevo = $this->como('admin')->postJson('/api/historial/refresh')->assertOk()->json('data.0');
        $this->assertSame('historial.vaciar', $nuevo['action_type']);
        $this->assertStringContainsString('Vació el historial', $nuevo['titulo']);
    }

    public function test_vaciar_un_historial_vacio_no_escribe_ninguna_copia(): void
    {
        $this->historialEnCero('admin');
        $this->confirmar();

        $r = $this->como('admin')->deleteJson('/api/historial')->assertOk();

        $this->assertSame(0, $r->json('borrados'));
        $this->assertNull($r->json('respaldo'));
    }

    public function test_solo_el_admin_vacia_el_historial(): void
    {
        $this->historialEnCero('admin', 'directivo');
        $this->registrar();
        $this->confirmar('directivo');

        $this->como('directivo')->deleteJson('/api/historial')->assertForbidden();
        $this->assertSame(1, ActivityLog::count(), 'La auditoría de la escuela sigue intacta');
    }

    // ===== Sello de cambios =====

    public function test_el_sello_de_cambios_lo_puede_pedir_cualquier_rol(): void
    {
        foreach (['admin', 'preceptor', 'profesor'] as $rol) {
            $this->como($rol)->getJson('/api/datos/sello')->assertOk();
        }
    }

    public function test_el_sello_subue_al_escribir_un_documento(): void
    {
        $antes = $this->como('admin')->getJson('/api/datos/sello')->assertOk()->json();
        $this->assertIsArray($antes);

        $this->como('admin')->postJson('/api/documentos', [
            'nombre'  => 'Circular',
            'archivo' => UploadedFile::fake()->create('circular.pdf', 4, 'application/pdf'),
        ])->assertCreated();

        $despues = $this->como('admin')->getJson('/api/datos/sello')->assertOk()->json();
        $this->assertGreaterThan((int) ($antes['documentos'] ?? 0), (int) ($despues['documentos'] ?? 0));
    }
}
