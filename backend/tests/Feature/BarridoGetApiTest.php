<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Barrido de los GET de /api: cada ruta, con cada rol, pidiendo de verdad.
 *
 * Sirve para lo que una prueba de módulo no ve: un filtro nuevo, un join que
 * cambia de nombre o un rol al que falta declararle algo revientan acá aunque
 * las pruebas por módulo estén verdes. Lo que se exige es mínimo y de sentido
 * común: que ninguna ruta de 5xx, que ninguna mande 401 a una sesión válida y
 * que las rutas sin parámetros existan (un 404 ahí es una ruta borrada o mal
 * escrita, no un dato que falta).
 *
 * Los parámetros salen del fixture de ApiTestCase; las rutas que piden un id
 * inexistente pueden devolver 404 y está bien: se comprueba que no revienten.
 */
class BarridoGetApiTest extends ApiTestCase
{
    /** Cada placeholder de ruta resuelto con un valor real del fixture. */
    private function valores(): array
    {
        return [
            'alumno'    => (string) $this->alumno->id,
            'profesor'  => (string) $this->profesor->id,
            'anio'      => '1',
            'division'  => '1',
            'id'        => (string) $this->cuenta('preceptor')->id,
            'clave'     => 'usuario',
            'clase'     => '1',
        ];
    }

    /** @return array<int, array{uri:string, con_params:bool}> */
    private function rutasGet(): array
    {
        $valores = $this->valores();
        $salida = [];

        foreach (Route::getRoutes() as $ruta) {
            if (!in_array('GET', $ruta->methods(), true)) {
                continue;
            }
            $uri = $ruta->uri();
            if (!str_starts_with($uri, 'api/')) {
                continue;
            }

            $conParams = false;
            $resuelta = preg_replace_callback(
                '/\{(\w+)[^}]*\}/',
                function ($m) use ($valores, &$conParams) {
                    $conParams = true;
                    return $valores[$m[1]] ?? '1';
                },
                $uri
            );

            $salida[] = ['uri' => '/' . $resuelta, 'con_params' => $conParams];
        }

        usort($salida, fn($a, $b) => strcmp($a['uri'], $b['uri']));

        return $salida;
    }

    public function test_ningun_get_de_la_api_reventa_con_ningun_rol(): void
    {
        $rutas = $this->rutasGet();

        // Si el mapeo de parámetros se desfasa, el barrido prueba menos de lo
        // que dice: el piso obliga a que sigan entrando todas las rutas.
        $this->assertGreaterThanOrEqual(50, count($rutas), 'el barrido perdió rutas GET de /api');

        $fallas = [];
        foreach (self::ROLES as $rol) {
            $token = $this->tokenDe($rol);

            foreach ($rutas as $ruta) {
                // El throttle de la API corta mucho antes de recorrer las 50
                // rutas: sin esto el barrido sería una lista de 429.
                Cache::flush();

                $respuesta = $this->withToken($token)->getJson($ruta['uri']);
                $codigo = $respuesta->getStatusCode();

                if ($codigo >= 500 || $codigo === 401) {
                    $mensaje = mb_substr((string) ($respuesta->json('message') ?? $respuesta->getContent()), 0, 160);
                    $fallas[] = sprintf('%-16s %-46s %d  %s', $rol, $ruta['uri'], $codigo, trim($mensaje));
                }

                if ($codigo === 404 && !$ruta['con_params']) {
                    $fallas[] = sprintf('%-16s %-46s 404  ruta sin parámetros que no existe', $rol, $ruta['uri']);
                }

                unset($i);
            }
        }

        $this->assertSame([], $fallas, "5xx, 401 con sesión válida o 404 en ruta fija:\n" . implode("\n", $fallas));
    }

    /**
     * Las rutas de escritura con el cuerpo vacío: un campo que faltaba tenía
     * que salir con 422 y su mensaje, no con un 500. Es el pedido que hace un
     * usuario apurado sin llenar la formulario, o un client que manda el JSON
     * roto. Se excluyen las que descargan o restauran: ahí el 500 sería del
     * entorno de pruebas (no hay mysqldump ni archivos), no del código.
     */
    public function test_los_pedidos_de_escritura_con_cuerpo_vacio_no_reventan(): void
    {
        $excluir = [
            '/api/login', '/api/register', '/api/password/forgot', '/api/password/reset',
            '/api/backups', '/api/backups/sql/download', '/api/backups/sql/restore',
            '/api/alumnos/importar', '/api/alumnos/importar/preview',
            '/api/avatars', '/api/me/avatar',
        ];

        $valores = $this->valores();
        $fallas = [];
        $vistosas = 0;

        foreach (Route::getRoutes() as $ruta) {
            $metodos = array_diff($ruta->methods(), ['HEAD', 'OPTIONS']);
            if ($metodos === ['GET'] || $metodos === []) {
                continue;
            }
            $uri = '/' . $ruta->uri();
            if (!str_starts_with($uri, '/api/')) {
                continue;
            }
            $uri = '/' . preg_replace_callback(
                '/\{(\w+)[^}]*\}/',
                fn($m) => $valores[$m[1]] ?? '1',
                substr($uri, 1)
            );
            if (in_array($uri, $excluir, true)) {
                continue;
            }

            foreach ($metodos as $metodo) {
                Cache::flush();
                // Token nuevo en cada pedido: en el medio hay rutas que cierran
                // sesiones (el "cerrar todas mis sesiones" del propio usuario)
                // y con un token guardado el resto del barrido daría 401.
                $login = $this->postJson('/api/login', [
                    'email'    => $this->cuenta('admin')->email,
                    'password' => self::CLAVE,
                ]);
                $login->assertOk();

                $respuesta = $this->withToken((string) $login->json('token'))
                    ->json(strtolower($metodo), $uri, []);
                $vistosas++;
                $codigo = $respuesta->getStatusCode();

                if ($codigo >= 500 || $codigo === 401) {
                    $mensaje = mb_substr((string) ($respuesta->json('message') ?? $respuesta->getContent()), 0, 160);
                    $fallas[] = sprintf('%-6s %-46s %d  %s', $metodo, $uri, $codigo, trim($mensaje));
                }

                // Un error de validación en crudo ("validation.required") es
                // una regla sin mensaje en lang/es: lo ve el usuario en el
                // formulario, así que se comprueba acá y no por módulo.
                if ($codigo === 422 && preg_match('/validation\.[a-z_]+/', (string) $respuesta->getContent(), $cruel)) {
                    $fallas[] = sprintf('%-6s %-46s 422  mensaje sin traducir: %s', $metodo, $uri, $cruel[0]);
                }
            }
        }

        $this->assertGreaterThanOrEqual(40, $vistosas, 'el barrido de escritura perdió pedidos');
        $this->assertSame([], $fallas, "los cuerpos vacíos deberían dar 422/404/405, no:\n" . implode("\n", $fallas));
    }

    /**
     * Los errores que arma el framework sin pasar por un controlador (ruta que
     * no existe, método equivocado, modelo borrado) llegan al toast tal cual.
     * Laravel los escribe en inglés y con el nombre interno de la ruta, así que
     * el Handler los traduce; esta prueba es la guarda de ese texto.
     */
    /**
     * Los mismos GET pero con los parámetros deformes: el barrido de arriba usa
     * valores válidos del fixture, así que no ve lo que rompe una URL escrita a
     * mano, un enlace guardado o un filtro vacío que quedó en la consulta. Acá
     * cada ruta se pide además con valores imposibles (fechas con palabras, años
     * alfa, divisiones con comilla, páginas negativas) y, lo que más dolió, con
     * los filtros llegados como lista ("?division[]=1"): más de 60 lecturas en
     * los controladores pasan ese valor derecho a trim() o strtoupper() y PHP
     * cortaba con "Array to string conversion" — la pantalla entera terminaba en
     * "Algo se trabó de nuestro lado". Lo resuelve AplanaFiltrosQuery.
     *
     * Se exige sólo que no revienten: 422 y 404 están bien porque son la
     * respuesta honesta a un pedido mal hecho.
     */
    public function test_los_get_no_reventan_con_parametros_deformes(): void
    {
        $hostiles = [
            'anio=abc',
            'anio=0',
            'anio=-1',
            "division=%27",
            'division[]=x',
            'estado[]=x',
            'anio[]=1',
            'q[]=Pe',
            'search[]=x',
            'orden[]=x',
            'genero[]=F',
            'materia[]=x',
            'grupo[]=A',
            'ciclo[]=2026',
            'periodo[]=1',
            'rol[]=suplente',
            'especialidad[]=x',
            'anios[]=1',
            'desde[]=ayer&hasta[]=hoy',
            'desde=2026-02-30&hasta=no-existe',
            'mes=99',
            'mes[]=2026-13',
            'year[]=0',
            'ticket[]=abc',
            'page=-3&porPagina=0',
            'q=<script>alert(1)</script>',
            // "ids" es la unica clave del query que de verdad admite lista (el
            // lote de fotos): no se aplana y tampoco tiene que romper.
            'ids[]=1',
        ];

        $valores = $this->valores();
        $fallas = [];
        $pedidos = 0;

        foreach ($this->rutasGet() as $ruta) {
            // Token nuevo por ruta: en el medio hay GET que cierran la sesión del
            // propio usuario y con un token guardado el resto daría 401, tapando
            // el 500 que se quiere ver.
            Cache::flush();
            $token = (string) $this->postJson('/api/login', [
                'email'    => $this->cuenta('admin')->email,
                'password' => self::CLAVE,
            ])->json('token');
            $this->assertNotSame('', $token, 'el barrido hostil se quedó sin sesión');

            foreach ($hostiles as $query) {
                $uri = $ruta['uri'] . (str_contains($ruta['uri'], '?') ? '&' : '?') . $query;

                Cache::flush();
                $respuesta = $this->withToken($token)->getJson($uri);
                $codigo = $respuesta->getStatusCode();
                $pedidos++;

                if ($codigo >= 500) {
                    $mensaje = mb_substr(strip_tags((string) ($respuesta->json('message') ?? $respuesta->getContent())), 0, 200);
                    $ex = $respuesta->exception;
                    $donde = $ex ? sprintf('  [%s: %s @ %s:%d]', get_class($ex), $ex->getMessage(), $ex->getFile(), $ex->getLine()) : '';
                    $fallas[] = sprintf('%-52s %d  %s%s', $uri, $codigo, trim($mensaje), $donde);
                }
            }
        }

        $this->assertGreaterThanOrEqual(800, $pedidos, 'el barrido hostil perdió pedidos');
        $this->assertSame([], $fallas, "parámetros deformes con 5xx:\n" . implode("\n", $fallas));
    }

    public function test_los_errores_del_framework_se_leen_en_espanol(): void
    {
        $token = $this->tokenDe('admin');

        $cuadriculados = [
            '404 de ruta'     => $this->withToken($token)->getJson('/api/no-hay-nada-aca'),
            '405 de método'   => $this->withToken($token)->deleteJson('/api/ping'),
            '404 de modelo'   => $this->withToken($token)->getJson('/api/alumnos/987654'),
        ];

        foreach ($cuadriculados as $nombre => $respuesta) {
            $cuerpo = (string) $respuesta->getContent();
            $this->assertMatchesRegularExpression(
                '/^(La|No|Esa|Algo|Un|El|Ese|Te)/',
                (string) ($respuesta->json('message') ?? ''),
                "{$nombre} respondió sin mensaje en español: " . mb_substr(strip_tags($cuerpo), 0, 120)
            );
            $this->assertStringNotContainsString('Not Found', $cuerpo, "{$nombre} quedó en inglés");
            $this->assertStringNotContainsString('not supported for route', $cuerpo, "{$nombre} quedó en inglés");
            $this->assertStringNotContainsString('Illuminate\\', $cuerpo, "{$nombre} filtró la clase interna");
        }
    }

    /**
     * Los filtros de la pantalla pueden llegar como lista: "?division[]=1" lo
     * arma un enlace guardado, una URL copiada de otro sistema o cualquier
     * serializador de parámetros. Antes de AplanaFiltrosQuery eso reventaba en
     * "Array to string conversion" y la pantalla terminaba con el mensaje
     * genérico de fallo; lo que se comprueba acá es que el filtro sigua
     * funcionando, o sea que no sólo dejó de romperse sino que sigue trayendo
     * el curso pedido.
     */
    public function test_un_filtro_llegado_como_lista_sigua_filtrando(): void
    {
        $una = $this->como('admin')->getJson('/api/alumnos?division[]=1')->assertOk();
        $esta = $this->como('admin')->getJson('/api/alumnos?division=1')->assertOk();
        $this->assertSame($esta->json('data'), $una->json('data'), 'la lista aplana distinto que el valor suelto');
        $this->assertNotEmpty($una->json('data'), 'el filtro por division[]=1 se quedó sin alumnos');

        // Varios valores: gana el primero, igual que un filtro mal escrito.
        $varios = $this->como('admin')->getJson('/api/alumnos?division[]=1&division[]=2')->assertOk();
        $this->assertSame($esta->json('data'), $varios->json('data'));

        // El resto de los módulos que se caían con esta forma.
        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/promedios?division[]=1')->assertOk();
        $this->como('admin')->getJson('/api/profesores?division[]=1')->assertOk();
        $this->como('admin')->getJson('/api/calificaciones/por-curso-materia?anio=1&division[]=1&materia_id=' . $this->materia->id)->assertOk();
        // El padrón en Excel y la ficha de un alumno con el mismo filtro de
        // frente: validate() mezcla la query con el cuerpo, así que una clave
        // llegada como lista también llegaba hasta ahí adentro.
        $padron = $this->como('admin')->getJson('/api/alumnos/padron/excel?division[]=1');
        $this->assertSame(200, $padron->getStatusCode(), 'el padrón en Excel sigue sin aceptar el filtro como lista');
        $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '?estado[]=x')->assertOk();
    }
}

