<?php

namespace Tests\Feature;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\ParametroDivision;
use App\Dominios\Usuarios\ApiSesion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;

/**
 * Las rutas que el resto de la red de pruebas no pisaba.
 *
 * `node scripts/cobertura-rutas-api.js` cruza las rutas de la API contra los
 * archivos de prueba y lista las que ningún pedido toca. Aquí vive lo que salió
 * de esa lista: la promoción del ciclo, el alta de años, el bulk de divisiones,
 * los manuales publicados, la revisión del sistema, el horario de un alumno y
 * el recupero de clave por correo.
 *
 * Son justo las funciones que se usan dos o tres veces al año (o las que nadie
 * sin rol admin ve), así que un arreglo en un módulo vecino las rompe sin que
 * ningún otra prueba se entere.
 */
class RutasHuerfanasApiTest extends ApiTestCase
{
    /** Dejar confirmada la clave de una cuenta, como hace la pantalla antes de una acción masiva. */
    private function confirmarClaveDe(string $rol): void
    {
        Cache::put('pwdconfirm_' . $this->cuenta($rol)->id, 1, 600);
    }

    /* ============================ manuales ============================ */

    public function test_la_lista_de_manuales_depende_del_rol(): void
    {
        $comoAdmin = $this->como('admin')->getJson('/api/manuales')->assertOk();
        $clavesAdmin = array_column((array) $comoAdmin->json('manuales'), 'clave');
        $this->assertContains('usuario', $clavesAdmin, 'el manual de usuario no está en la lista');
        $this->assertContains('programador', $clavesAdmin, 'administración no ve el manual técnico');

        $comoPreceptor = $this->como('preceptor')->getJson('/api/manuales')->assertOk();
        $clavesPreceptor = array_column((array) $comoPreceptor->json('manuales'), 'clave');
        $this->assertContains('usuario', $clavesPreceptor);
        $this->assertNotContains('programador', $clavesPreceptor, 'el manual técnico se le escapó a un preceptor');

        // Cada manual de la lista tiene que traer su tamaño y su fecha: la
        // pantalla los muestra y sin eso quedan en blanco.
        foreach ((array) $comoAdmin->json('manuales') as $m) {
            $this->assertIsInt($m['tamano_kb'], 'el manual ' . $m['clave'] . ' no informa el tamaño');
            $this->assertGreaterThan(0, $m['tamano_kb'], 'el manual ' . $m['clave'] . ' pesa 0 KB');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $m['actualizado'], 'fecha del manual ' . $m['clave']);
            // Nunca un camino del servidor: la lista se muestra tal cual.
            $this->assertStringNotContainsString('\\', (string) $m['archivo']);
            $this->assertStringNotContainsString('/', (string) $m['archivo']);
        }

        $this->sinSesion()->getJson('/api/manuales')->assertUnauthorized();
    }

    public function test_un_manual_se_abre_en_el_navegador_y_el_que_no_existe_avisa(): void
    {
        $pdf = $this->como('admin')->getJson('/api/manuales/usuario');
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'), 'el manual no se sirve como PDF');
        $this->assertStringContainsString('inline', (string) $pdf->headers->get('Content-Disposition'), 'el manual fuerza la descarga en vez de abrirse');

        $descarga = $this->como('admin')->get('/api/manuales/usuario?descarga=1');
        $descarga->assertOk();
        $this->assertStringContainsString('attachment', (string) $descarga->headers->get('Content-Disposition'));

        // El técnico es sólo de dirección.
        $this->como('preceptor')->getJson('/api/manuales/programador')->assertForbidden();
        $this->como('admin')->getJson('/api/manuales/programador')->assertOk();

        // Una clave inventada no puede terminar en un 500 ni abrir otro archivo.
        foreach (['no-existe', '../../backend/.env', 'usuario;'] as $clave) {
            $r = $this->como('admin')->getJson('/api/manuales/' . rawurlencode($clave));
            $this->assertLessThan(500, $r->getStatusCode(), "la clave {$clave} reventó");
            $this->assertSame(404, $r->getStatusCode(), "la clave {$clave} no devolvió 404");
            $this->assertStringNotContainsString('.env', $r->getContent());
        }
    }

    /* ============================ recupero de clave ============================ */

    public function test_el_enlace_de_recupero_responde_igual_con_cualquier_correo(): void
    {
        Cache::flush();

        $existente = $this->sinSesion()->postJson('/api/password/forgot', ['email' => 'admin@abc.gob.ar']);
        $existente->assertOk();
        $this->assertTrue($existente->json('ok'));

        // Segundo pedido en el mismo minuto: antes respondía igual que si
        // hubiera enviado el correo y la persona se quedaba esperando.
        $repetido = $this->sinSesion()->postJson('/api/password/forgot', ['email' => 'admin@abc.gob.ar']);
        $this->assertSame(429, $repetido->getStatusCode(), 'pedir el enlace dos veces pasó desapercibido');
        $this->assertMatchesRegularExpression('/esperá un minuto/i', (string) $repetido->json('message'));

        // Un correo que no existe no puede delatarlo.
        Cache::flush();
        $ajeno = $this->sinSesion()->postJson('/api/password/forgot', ['email' => 'nadie@abc.gob.ar']);
        $ajeno->assertOk();
        $this->assertSame($existente->json('message'), $ajeno->json('message'), 'el aviso revela si el correo existe');

        $malo = $this->sinSesion()->postJson('/api/password/forgot', ['email' => 'no-es-mail']);
        $malo->assertStatus(422);
        $this->assertStringNotContainsString('validation.', $malo->getContent());
    }

    public function test_el_token_del_enlace_cambia_la_clave_y_cierra_las_sesiones(): void
    {
        $cuenta = $this->cuenta('preceptor');
        $token = Password::createToken($cuenta);
        $this->assertIsString($token, 'el enlace de recupero no armó token');

        // Dejar una sesión abierta para comprobar que el cambio la cierre.
        $this->tokenDe('preceptor');
        $this->assertGreaterThanOrEqual(1, ApiSesion::where('user_id', $cuenta->id)->count());

        $r = $this->sinSesion()->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $cuenta->email,
            'password' => 'ClaveNueva2026',
            'password_confirmation' => 'ClaveNueva2026',
        ]);
        $r->assertOk();
        $this->assertTrue($r->json('ok'));

        $cuenta->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('ClaveNueva2026', $cuenta->password), 'la clave no cambió');
        $this->assertFalse((bool) $cuenta->debe_cambiar_password, 'quedó marcada como provisoria');
        $this->assertSame(0, ApiSesion::where('user_id', $cuenta->id)->count(), 'quedaron sesiones abiertas tras cambiar la clave');

        // Con la clave vieja no se entra, con la nueva sí.
        $this->postJson('/api/login', ['email' => $cuenta->email, 'password' => self::CLAVE])->assertStatus(422);
        $this->postJson('/api/login', ['email' => $cuenta->email, 'password' => 'ClaveNueva2026'])->assertOk();
    }

    public function test_un_token_invalido_no_cambia_nada_y_avisa_en_espanol(): void
    {
        $cuenta = $this->cuenta('profesor');
        $antes = $cuenta->password;

        $casos = [
            'token inventado'     => ['token' => 'no-es-un-token', 'email' => $cuenta->email],
            'correo que no es'    => ['token' => Password::createToken($cuenta), 'email' => 'otro@abc.gob.ar'],
            'sin confirmación'    => ['token' => Password::createToken($cuenta), 'email' => $cuenta->email],
        ];

        foreach ($casos as $nombre => $datos) {
            $r = $this->sinSesion()->postJson('/api/password/reset', array_merge($datos, [
                'password' => 'ClaveDistinta2026',
                'password_confirmation' => $nombre === 'sin confirmación' ? 'otra' : 'ClaveDistinta2026',
            ]));
            $this->assertLessThan(500, $r->getStatusCode(), "{$nombre} reventó");
            $this->assertSame(422, $r->getStatusCode(), "{$nombre} no frenó el cambio");
            $mensaje = (string) $r->json('message');
            // Que arranque con mayúscula y que no quede una frase en inglés:
            // la pantalla muestra este texto tal cual.
            $this->assertMatchesRegularExpression('/^[A-ZÁÉÍÓÚÑ]/u', $mensaje, "{$nombre} avisó en crudo: {$mensaje}");
            $this->assertDoesNotMatchRegularExpression(
                '/\b(this|the|invalid|expired|credentials|does not match)\b/i',
                $mensaje,
                "{$nombre} avisó en inglés: {$mensaje}"
            );
            $this->assertStringNotContainsString('passwords.', $mensaje, "{$nombre} mostró la clave del idioma");
        }

        $this->assertSame($antes, $cuenta->fresh()->password, 'se cambió la clave con un token inválido');
    }

    /* ============================ ciclo lectivo ============================ */

    public function test_habilitar_anios_crea_los_cursos_y_se_ve_al_instante(): void
    {
        // Cargar la caché ANTES: GET /divisiones guarda el mapa 24 h. Si el alta
        // no la invalida, el año nuevo recién se vería al día siguiente.
        $antesDelAlta = $this->como('admin')->getJson('/api/divisiones')->assertOk();
        $this->assertNull($antesDelAlta->json('8'), 'el fixture ya tenía el año 8 habilitado');

        $this->confirmarClaveDe('admin');

        $r = $this->como('admin')->postJson('/api/admin/years/enable', [
            'years' => [8, 9], 'divisions' => ['1', '2'], 'activate_courses' => true,
        ]);
        $r->assertOk();

        $this->assertSame(['1', '2'], array_values((array) $r->json('8')), 'el mapa devuelto no trae las divisiones nuevas');
        $this->assertTrue(ParametroDivision::where('anio', 9)->where('division', '1')->where('activo', true)->exists());
        $this->assertTrue(Curso::where('anio', 9)->where('division', '2')->exists());

        // GET /divisiones devuelve el mapa directo ({"1":["1","2"], "8":[...]}) y
        // eso es lo que consume la pantalla.
        $divisiones = $this->como('admin')->getJson('/api/divisiones')->assertOk();
        $this->assertSame(['1', '2'], array_values((array) $divisiones->json('8')), 'la pantalla no ve el año recién habilitado');
        $this->assertSame(['1', '2'], array_values((array) $divisiones->json('9')), 'la pantalla no ve el año 9 recién habilitado');
    }

    public function test_habilitar_anios_pide_la_clave_y_rechaza_un_anio_fuera_de_rango(): void
    {
        Cache::flush();
        $sinConfirmar = $this->como('admin')->postJson('/api/admin/years/enable', ['years' => [8]]);
        $sinConfirmar->assertStatus(422);
        $this->assertMatchesRegularExpression('/onfirm/', (string) $sinConfirmar->json('message'));

        $this->confirmarClaveDe('admin');
        foreach (['anio 0' => ['years' => [0]], 'anio 999' => ['years' => [999]], 'sin años' => ['years' => []], 'años como texto' => ['years' => ['ocho']]] as $nombre => $cuerpo) {
            $r = $this->como('admin')->postJson('/api/admin/years/enable', $cuerpo);
            $this->assertLessThan(500, $r->getStatusCode(), "{$nombre} reventó");
            $this->assertSame(422, $r->getStatusCode(), "{$nombre} no se rechazó");
        }
        $this->assertFalse(Curso::where('anio', 999)->exists(), 'quedó creado el año 999');
    }

    public function test_la_promocion_del_ciclo_simula_y_despues_sube_un_ano(): void
    {
        // Alumno en el año máximo activo: tiene que egresar. Los demás suben.
        $this->nuevoAlumno(['apellido' => 'Tope', 'nombre' => 'Tomás', 'anio' => 2, 'division' => '2']);

        $instantanea = Alumno::orderBy('id')->get(['id', 'anio', 'division', 'estado', 'egreso_year'])->toArray();

        $simulacro = $this->como('admin')->postJson('/api/ciclo/promocion', ['dry_run' => true])->assertOk();
        $this->assertTrue($simulacro->json('dry_run'));
        $prometidos = (int) $simulacro->json('promote');
        $egresados = (int) $simulacro->json('graduate');
        $this->assertGreaterThan(0, $prometidos, 'la simulación no encontró a nadie que suba');

        $this->assertSame(
            $instantanea,
            Alumno::orderBy('id')->get(['id', 'anio', 'division', 'estado', 'egreso_year'])->toArray(),
            'la simulación modificó alumnos'
        );

        // Aplicar sí exige la clave reciente; simular no.
        Cache::flush();
        $sinClave = $this->como('admin')->postJson('/api/ciclo/promocion', ['dry_run' => false]);
        $sinClave->assertStatus(422);
        $this->assertMatchesRegularExpression('/onfirm/', (string) $sinClave->json('message'));
        $this->assertSame(
            $instantanea,
            Alumno::orderBy('id')->get(['id', 'anio', 'division', 'estado', 'egreso_year'])->toArray(),
            'el rechazo por clave igual tocó alumnos'
        );

        $this->confirmarClaveDe('admin');
        $aplicada = $this->como('admin')->postJson('/api/ciclo/promocion', ['dry_run' => false])->assertOk();
        $this->assertTrue($aplicada->json('done'));
        $this->assertSame($prometidos, (int) $aplicada->json('promote'));
        $this->assertSame($egresados, (int) $aplicada->json('graduate'));

        $this->assertSame(2, (int) Alumno::find($this->alumno->id)->anio, 'el alumno de 1° no subió a 2°');
        $this->assertSame('1', (string) Alumno::find($this->alumno->id)->division, 'la promoción cambió la división');
        $egresado = Alumno::where('apellido', 'Tope')->first();
        $this->assertSame('Egresado', $egresado->estado, 'el alumno del año tope no egresó');
        $this->assertNotNull($egresado->egreso_year, 'el egreso no quedó fechado');
        $this->assertSame(2, (int) $egresado->egreso_anio, 'no recordó de qué año egresó');

        // Acá no se revierte: si alguien manda sentido=down tiene que escuchar
        // un no, no ejecutar una promoción destructiva.
        $reversion = $this->como('admin')->postJson('/api/ciclo/promocion', ['sentido' => 'down']);
        $reversion->assertStatus(422);
        $this->assertMatchesRegularExpression('/revertir|reversión|reversion/i', (string) $reversion->json('message'));
    }

    /* ============================ promoción masiva ============================ */

    public function test_la_promocion_masiva_pide_simular_primero(): void
    {
        // Vaciar primero y confirmar después: la caché guarda tanto la
        // confirmación de la clave como el salvoconducto del simulacro, y un
        // flush posterior borra la confirmación (el pedido frenaría por ese
        // otro motivo y la prueba no estaría midiendo lo que dice medir).
        Cache::flush();
        $this->confirmarClaveDe('admin');

        // La pantalla llama a /aplicar; /promocionar es el alias histórico.

        // Aplicar sin la ficha del simulacro no mueve nada.
        $sinFicha = $this->como('admin')->postJson('/api/alumnos/promocionar/aplicar', ['sentido' => 'up', 'anioLectivo' => 2027]);
        $this->assertLessThan(500, $sinFicha->getStatusCode());
        $sinFicha->assertStatus(422);
        $this->assertMatchesRegularExpression('/simul/', (string) $sinFicha->json('message'));

        $antes = Alumno::orderBy('id')->get(['id', 'anio', 'division', 'estado', 'egreso_year'])->toArray();

        $preview = $this->como('admin')->postJson('/api/alumnos/promocionar/preview', ['sentido' => 'up', 'anioLectivo' => 2027])->assertOk();
        $this->assertTrue($preview->json('ok'));
        $this->assertIsString($preview->json('confirmacion'), 'el simulacro no dejó salvoconducto');
        $this->assertArrayHasKey('resumen', (array) $preview->json());
        $this->assertSame($antes, Alumno::orderBy('id')->get(['id', 'anio', 'division', 'estado', 'egreso_year'])->toArray(), 'el preview tocó alumnos');

        $aplicada = $this->como('admin')->postJson('/api/alumnos/promocionar/aplicar', [
            'sentido' => 'up', 'anioLectivo' => 2027, 'confirmacion' => $preview->json('confirmacion'),
        ]);
        $aplicada->assertSuccessful();
        $despues = Alumno::orderBy('id')->get(['id', 'anio', 'division', 'estado', 'egreso_year'])->toArray();
        $this->assertNotSame($antes, $despues, 'aplicar la promoción no movió a nadie');

        // El salvoconducto es de un solo uso: un segundo clic no sube otro año.
        $repetida = $this->como('admin')->postJson('/api/alumnos/promocionar/aplicar', [
            'sentido' => 'up', 'anioLectivo' => 2027, 'confirmacion' => $preview->json('confirmacion'),
        ]);
        $repetida->assertStatus(422);
        $this->assertSame($despues, Alumno::orderBy('id')->get(['id', 'anio', 'division', 'estado', 'egreso_year'])->toArray(), 'el doble clic subió dos años');

        // Revertir con su propio simulacro devuelve exactamente el estado inicial,
        // egresados incluidos: es el botón "Bajar de año" de la pantalla.
        $previewBajar = $this->como('admin')
            ->postJson('/api/alumnos/promocionar/preview', ['sentido' => 'down', 'anioLectivo' => 2027])->assertOk();
        $bajada = $this->como('admin')->postJson('/api/alumnos/promocionar/aplicar', [
            'sentido' => 'down', 'anioLectivo' => 2027, 'confirmacion' => $previewBajar->json('confirmacion'),
        ]);
        $bajada->assertSuccessful();
        $this->assertSame(
            $antes,
            Alumno::orderBy('id')->get(['id', 'anio', 'division', 'estado', 'egreso_year'])->toArray(),
            'revertir la promoción no dejó el sistema como estaba'
        );

        // El alias tiene que ejecutar la misma acción: si se desincroniza, una
        // publicación sin la ruta canónica promociona distinto sin avisar.
        $previewAlias = $this->como('admin')
            ->postJson('/api/alumnos/promocionar/preview', ['sentido' => 'up', 'anioLectivo' => 2027])->assertOk();
        $this->como('admin')->postJson('/api/alumnos/promocionar', [
            'sentido' => 'up', 'anioLectivo' => 2027, 'confirmacion' => $previewAlias->json('confirmacion'),
        ])->assertSuccessful();
        $this->assertSame(
            $despues,
            Alumno::orderBy('id')->get(['id', 'anio', 'division', 'estado', 'egreso_year'])->toArray(),
            'el alias promocionó distinto que la ruta canónica'
        );
    }

    /* ============================ divisiones en bloque ============================ */

    public function test_el_bulde_de_divisiones_deja_al_menos_una_por_anio(): void
    {
        $this->confirmarClaveDe('admin');

        // Un payload que sólo menciona el año 1 no puede borrar los demás.
        $r = $this->como('admin')->putJson('/api/divisiones/bulk', ['1' => ['1', '2', '3']]);
        $r->assertSuccessful();
        $this->assertTrue(Curso::where('anio', 2)->where('division', '2')->value('activo'), 'el bulk borró un curso que no mencionó');

        foreach (['año vacío' => ['1' => []], 'año 999' => ['999' => ['1']], 'año sin número' => ['uno' => ['1']]] as $nombre => $cuerpo) {
            $resp = $this->como('admin')->putJson('/api/divisiones/bulk', $cuerpo);
            $this->assertLessThan(500, $resp->getStatusCode(), "{$nombre} reventó el bulk");
            $this->assertSame(422, $resp->getStatusCode(), "{$nombre} pasó");
        }

        // El cuerpo puede llegar como lista vacía o como texto: tampoco rompe.
        $basura = $this->como('admin')->putJson('/api/divisiones/bulk', ['1' => 'no-es-lista']);
        $this->assertLessThan(500, $basura->getStatusCode());

        // "_detalles" es el motivo que manda la pantalla (el Deshacer lo usa):
        // no es un año y tampoco puede rechazarse.
        $conMotivo = $this->como('admin')->putJson('/api/divisiones/bulk', [
            '1' => ['1', '2'], '_detalles' => 'Deshace el último guardado',
        ]);
        $conMotivo->assertOk();
        $this->assertSame(['1', '2'], array_values((array) $conMotivo->json('1')), 'guardar con motivo no dejó las divisiones');
    }

    /* ============================ reporte de importación ============================ */

    public function test_el_reporte_de_importacion_baja_un_excel_con_lo_que_no_entro(): void
    {
        // Una fila con el año fuera de tabla: la importación la rechaza y el
        // reporte tiene que explicar por qué, en un archivo que se pueda abrir.
        $archivo = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'lista.xlsx',
            $this->planillaCon([['Apellido', 'Nombre', '40000999', '1', 'F']])
        );

        $r = $this->como('admin')->post('/api/alumnos/importar/reporte', ['archivo' => $archivo]);
        $r->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $r->headers->get('Content-Type'), 'el reporte no es un Excel');
        $this->assertStringContainsString('reporte_importacion_alumnos.xlsx', (string) $r->headers->get('Content-Disposition'));
        $this->assertGreaterThan(1000, strlen($r->getContent()), 'el reporte salió vacío');

        // Sin archivo no puede reventar: avisa.
        $this->como('admin')->postJson('/api/alumnos/importar/reporte', [])->assertStatus(422);
    }

    /** Un .xlsx mínimo con las columnas que lee el importador. */
    private function planillaCon(array $filas): string
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $h = $ss->getActiveSheet();
        $h->fromArray(['Apellido', 'Nombre', 'DNI', 'Año', 'División', 'Sexo'], null, 'A1');
        foreach ($filas as $i => $fila) {
            $h->fromArray($fila, null, 'A' . ($i + 2));
        }
        $escritor = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        ob_start();
        $escritor->save('php://output');
        return (string) ob_get_clean();
    }

    /* ============================ revisión del sistema ============================ */

    public function test_la_revision_del_sistema_enumera_los_huecos_y_es_de_direccion(): void
    {
        $r = $this->como('admin')->getJson('/api/cobertura')->assertOk();
        $this->assertIsArray($r->json('problemas'), 'la revisión no trajo la lista de problemas');
        $this->assertIsInt($r->json('total'));
        $this->assertIsString($r->json('generado'));

        // Un curso sin nadie asignado tiene que aparecer: es el motivo por el
        // que esta pantalla existe.
        $suelto = Curso::create(['anio' => 2, 'division' => '1', 'turno' => 'manana', 'cupo' => 30, 'activo' => true]);
        $despues = $this->como('admin')->getJson('/api/cobertura')->assertOk();
        $this->assertNotEmpty($despues->json('problemas'), 'la revisión no vio un curso sin responsable');
        $texto = json_encode($despues->json('problemas'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('2', $texto);
        $this->assertGreaterThanOrEqual($r->json('total'), $despues->json('total'));

        // Un preceptor no mira la revisión del sistema.
        $this->como('preceptor')->getJson('/api/cobertura')->assertForbidden();

        $suelto->delete();
    }
}
