<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Barrido de los pedidos de escritura con cuerpos deformes.
 *
 * El barrido de GET cubre la dirección; las pruebas de módulo cubren el camino
 * feliz. Este archivo cubre lo que ninguna de las dos mira: el cuerpo del
 * pedido escrito con el tipo equivocado. En la pantalla esos cuerpos no se
 * escriben a mano, pero llegan solos: una extensión del navegador que rellena
 * formularios, un pedido cortado a mitad, un script de migración, o cualquier
 * pestaña con una versión vieja de la pantalla que manda el formato anterior.
 *
 * Lo que se exige es de sentido común: que la aplicación responda algo —422,
 * 404, 403, lo que sea— pero nunca un 500. Un 500 en una escritura además deja
 * la duda de si medio dato quedó guardado.
 */
class BarridoEscrituraApiTest extends ApiTestCase
{
    /** Cada placeholder de ruta, resuelto con un valor real del fixture. */
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
            'materia'   => (string) $this->materia->id,
        ];
    }

    /** @return array<int, array{uri:string, verbo:string}> */
    private function rutasDeEscritura(): array
    {
        $valores = $this->valores();
        $salida = [];

        foreach (Route::getRoutes() as $ruta) {
            $metodos = array_diff($ruta->methods(), ['HEAD', 'OPTIONS', 'GET']);
            if ($metodos === []) {
                continue;
            }
            $uri = $ruta->uri();
            if (!str_starts_with($uri, 'api/')) {
                continue;
            }

            $resuelta = preg_replace_callback(
                '/\{(\w+)[^}]*\}/',
                fn ($m) => $valores[$m[1]] ?? '1',
                $uri
            );

            foreach ($metodos as $verbo) {
                $salida[] = ['uri' => '/' . $resuelta, 'verbo' => $verbo];
            }
        }

        usort($salida, fn ($a, $b) => [$a['uri'], $a['verbo']] <=> [$b['uri'], $b['verbo']]);

        return $salida;
    }

    /**
     * Los cuerpos que una pantalla honesta nunca manda. Cada uno ataca una
     * forma distinta de equivocarse: el tipo invertido, la lista de escalares
     * donde se espera una lista de objetos, el objeto donde se espera un id.
     */
    private function cuerposHostiles(): array
    {
        // Campos que en los controladores se leen con (int), trim() o foreach.
        $escalares = [
            'anio', 'division', 'ano', 'fecha', 'estado', 'genero', 'nombre', 'apellido',
            'dni', 'email', 'materia', 'materia_id', 'profesor', 'profesor_id', 'turno',
            'cupo', 'observaciones', 'telefono', 'direccion', 'fecha_nacimiento', 'q',
            'search', 'busqueda', 'rol', 'especialidad', 'ciclo', 'periodo', 'grupo',
            'dia', 'bloque', 'slot_key', 'marcado', 'motivo', 'comentario', 'hora_inicio',
            'hora_fin', 'password', 'password_confirmation', 'token', 'ticket', 'clave',
        ];
        $colecciones = [
            'entries', 'filas', 'alumnos', 'marcas', 'notas', 'items', 'asignaciones',
            'years', 'divisions', 'celdas', 'dias', 'anios', 'ids', 'emails', 'suplencias',
            'justificaciones', 'cursos', 'materias', 'responsables', 'agregarAnios',
            'usarDivisionesBase', 'divisionesExtra', 'porcurso', 'datos', 'permisos',
        ];

        $todoLista = [];
        foreach (array_merge($escalares, $colecciones) as $campo) {
            $todoLista[$campo] = ['x'];
        }

        $listaDeStrings = [];
        foreach ($colecciones as $campo) {
            $listaDeStrings[$campo] = ['hola', 'chau'];
        }

        $listaDeNulos = [];
        foreach ($colecciones as $campo) {
            $listaDeNulos[$campo] = [null, null];
        }

        $listaDeListas = [];
        foreach ($colecciones as $campo) {
            $listaDeListas[$campo] = [['a' => ['b' => ['c' => [['d']]]]]];
        }

        $objetoEnLugarDeLista = [];
        foreach ($colecciones as $campo) {
            $objetoEnLugarDeLista[$campo] = ['clave' => 'valor'];
        }

        $textoDondeVaNumero = [];
        foreach ($escalares as $campo) {
            $textoDondeVaNumero[$campo] = 'no-es-un-número';
        }
        foreach (['anio', 'materia_id', 'profesor_id', 'dia', 'bloque', 'page', 'cupo', 'porPagina'] as $n) {
            $textoDondeVaNumero[$n] = 'abc';
        }

        $numerosImposibles = [
            'anio' => 999999999, 'max_year' => 999999999, 'anioLectivo' => -5,
            'cupo' => -1, 'page' => -3, 'porPagina' => 0, 'nota' => 999999,
            'division' => str_repeat('A', 4000), 'nombre' => str_repeat('Ñ', 4000),
            'apellido' => str_repeat('Z', 4000), 'dni' => '9999999999999999999999',
            'observaciones' => str_repeat('x', 70000), 'fecha' => '2026-02-30',
            'hora_inicio' => '25:99',
        ];

        $etiquetas = [];
        foreach (['nombre', 'apellido', 'observaciones', 'motivo', 'comentario', 'q', 'search', 'division', 'email'] as $campo) {
            $etiquetas[$campo] = '<script>alert(1)</script>';
        }

        $soloTextos = [];
        foreach ($colecciones as $campo) {
            $soloTextos[$campo] = 'esto-deberia-ser-una-lista';
        }

        $clavesRaras = [
            ' ' => '', '[]' => 1, 'a.b' => ['c'], '/' => '.',
            'entries' => [['dia' => 'uno', 'bloque' => 'siete', 'materia' => ['x']]],
            'years' => ['uno'], 'divisions' => ['diez'],
        ];

        return [
            'todo como lista'          => $todoLista,
            'lista de texto'           => $listaDeStrings,
            'lista de nulos'           => $listaDeNulos,
            'lista anidada'            => $listaDeListas,
            'objeto en vez de lista'   => $objetoEnLugarDeLista,
            'texto en vez de número'   => $textoDondeVaNumero,
            'números imposibles'       => $numerosImposibles,
            'texto con etiquetas'      => $etiquetas,
            'texto en vez de colección'=> $soloTextos,
            'claves raras'             => $clavesRaras,
        ];
    }

    public function test_ningun_pedido_de_escritura_reventa_con_un_cuerpo_deforme(): void
    {
        $token = $this->tokenDe('admin');
        $rutas = $this->rutasDeEscritura();
        $cuerpos = $this->cuerposHostiles();

        $fallas = [];
        $pedidos = 0;

        foreach ($rutas as $ruta) {
            foreach ($cuerpos as $nombreCuerpo => $cuerpo) {
                Cache::flush();
                $respuesta = $this->withToken($token)->json($ruta['verbo'], $ruta['uri'], $cuerpo);
                $codigo = $respuesta->getStatusCode();
                $pedidos++;

                if ($codigo >= 500) {
                    $ex = $respuesta->exception;
                    $donde = $ex
                        ? sprintf('[%s] %s  @ %s:%d', get_class($ex), $ex->getMessage(), $ex->getFile(), $ex->getLine())
                        : mb_substr(strip_tags((string) ($respuesta->json('message') ?? '')), 0, 160);
                    $fallas[] = sprintf('%-6s %-46s %-24s %s', $ruta['verbo'], $ruta['uri'], $nombreCuerpo, $donde);
                }
            }
        }

        $this->assertGreaterThanOrEqual(400, $pedidos, 'el barrido de escrituras perdió pedidos');

        $unicas = array_values(array_unique($fallas));
        $this->assertSame([], $unicas, "cuerpos deformes con 5xx (" . count($fallas) . "):\n"
            . implode("\n", $unicas));
    }

    /**
     * Que no reviente no significa que avise. Acá se comprueba que los mismos
     * cuerpos deformes respondan con el motivo en español, para que la pantalla
     * lo pueda mostrar en vez de un fallo genérico.
     */
    public function test_un_cuerpo_deforme_avisa_el_motivo_en_espanol(): void
    {
        // El registro público es la ruta más expuesta: la puede golpear
        // cualquiera sin cuenta. Con el mail como lista antes daba 500.
        $registro = $this->postJson('/api/register', [
            'name'     => 'Alguien',
            'email'    => ['alguien@abc.gob.ar'],
            'password' => 'ClaveLarga1',
            'password_confirmation' => 'ClaveLarga1',
        ]);
        $registro->assertStatus(422);
        $errores = (array) $registro->json('errors.email');
        $this->assertNotEmpty($errores, 'el registro público rechazó el mail sin decir nada');
        foreach ($errores as $uno) {
            $this->assertMatchesRegularExpression('/^(El|No|La|Ese)/', (string) $uno, 'el aviso de correo quedó en inglés o crudo: ' . $uno);
        }
        $this->assertStringNotContainsString('Array to string', $registro->getContent());

        // El horario de un alumno: la lista de filas tiene que ser de objetos.
        $horario = $this->como('admin')->putJson('/api/alumnos/' . $this->alumno->id . '/horario', [
            'entries' => ['hola'],
        ]);
        $horario->assertStatus(422);
        $this->assertMatchesRegularExpression('/filas/', (string) $horario->json('message'), 'no avisa qué está mal en el horario');

        // Curso con la división como lista.
        $curso = $this->como('admin')->postJson('/api/cursos', [
            'anio' => 1, 'division' => ['1'], 'turno' => 'manana',
        ]);
        $curso->assertStatus(422);
        $this->assertArrayHasKey('division', (array) $curso->json('errors'));
    }

    /**
     * La guarda del horario no puede cerrar el camino legítimo: así se guarda
     * de verdad la grilla de un alumno desde la pantalla.
     */
    public function test_el_horario_de_un_alumno_sigua_guardandose_normal(): void
    {
        $antes = $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/horario')->assertOk();

        $this->como('admin')->putJson('/api/alumnos/' . $this->alumno->id . '/horario', [
            'entries' => [
                ['dia' => 1, 'bloque' => 1, 'materia' => 'Matemática'],
                ['dia' => 2, 'slot_key' => 'h3', 'materia_id' => $this->materia->id, 'marcado' => 'recursando'],
            ],
        ])->assertSuccessful();

        $despues = $this->como('admin')->getJson('/api/alumnos/' . $this->alumno->id . '/horario')->assertOk();
        $this->assertNotSame($antes->getContent(), $despues->getContent(), 'el guardado no se notó en la lectura');

        // Una celda con un material que no es texto se ignora sin romper el resto.
        $this->como('admin')->putJson('/api/alumnos/' . $this->alumno->id . '/horario', [
            'entries' => [['dia' => 1, 'bloque' => 1, 'materia' => ['Matemática']]],
        ])->assertSuccessful();
    }

    /**
     * El cuerpo no es lo único deformable: los filtros de la URL también viajan
     * en un POST o un PUT. Acá se comprueba que el aplanado del query cubra los
     * verbos de escritura y no sólo los de lectura.
     */
    public function test_una_escritura_con_el_filtro_de_la_url_como_lista_no_reventa(): void
    {
        $token = $this->tokenDe('admin');
        $cuerpo = ['nombre' => 'Prueba', 'apellido' => 'DeForma', 'anio' => 1, 'division' => 1];

        $pedidos = [
            'POST alumnos'            => ['POST', '/api/alumnos'],
            'PUT alumno'              => ['PUT', '/api/alumnos/' . $this->alumno->id],
            'POST asistencia'         => ['POST', '/api/asistencia/clases'],
            'PUT horario del curso'   => ['PUT', '/api/horarios/1/1'],
            'POST documentos'         => ['POST', '/api/documentos'],
            'PUT divisiones bulk'     => ['PUT', '/api/divisiones/bulk'],
            'POST promocionar preview' => ['POST', '/api/alumnos/promocionar/preview'],
        ];

        foreach ($pedidos as $nombre => [$verbo, $uri]) {
            foreach (['?division[]=1', '?anio[]=1', '?estado[]=x', '?materia[]=1', '?ids[]=1'] as $query) {
                Cache::flush();
                $respuesta = $this->withToken($token)->json($verbo, $uri . $query, $cuerpo);
                $this->assertLessThan(
                    500,
                    $respuesta->getStatusCode(),
                    "{$nombre} {$uri}{$query} reventó con el filtro como lista: "
                    . ($respuesta->exception
                        ? get_class($respuesta->exception) . ': ' . $respuesta->exception->getMessage()
                          . ' @ ' . $respuesta->exception->getFile() . ':' . $respuesta->exception->getLine()
                        : mb_substr(strip_tags((string) $respuesta->getContent()), 0, 200))
                );
            }
        }
    }
}
