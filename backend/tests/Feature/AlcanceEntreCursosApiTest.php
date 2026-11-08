<?php

namespace Tests\Feature;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Asistencia\Asistencia;
use App\Dominios\Asistencia\Clase;
use App\Dominios\Cursos\Materia;
use App\Dominios\Profesores\Profesor;
use App\Dominios\Profesores\ProfesorAsignacion;
use App\Dominios\Usuarios\RolConfig;
use App\Dominios\Usuarios\User;
use App\Dominios\Usuarios\UserAnioDivision;
use App\Support\Alcances;
use App\Support\Permisos;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * El mismo pedido, dos cuentas: lo que ve quien tiene el curso asignado y lo que
 * NO tiene que ver quien no lo tiene.
 *
 * Cada sonda apunta a 2° 2.ª — el curso que el fixture deliberately deja fuera
 * del alcance del preceptor y del profesor — y declara dos tipos de marcador:
 *
 *  · ids: los identificadores del curso ajeno, buscados como VALOR de una clave
 *    de identificador (id, materia_id, profesorId…). Un número suelto en el
 *    cuerpo no cuenta: "2" aparece en cualquier año, turno o cantidad, y una
 *    lista que sólo muestra eso no está filtrando nada.
 *  · textos: datos que sólo existen en ese curso (el DNI del alumno, su
 *    apellido, el nombre de la materia, el motivo de una justificación).
 *
 * La prueba aprueba una de dos: 403, o 200 sin ninguna traza. Con candado, en
 * cambio, exige el 403: hay rutas que no devuelven un dato identificable pero
 * contestan una pregunta sobre un curso ajeno (qué años tienen notas ese
 * alumno), y ese "sí" también es información.
 *
 * La primera prueba valida cada sonda contra el Admin. Si él tampoco muestra el
 * marcador, la sonda está mal armada — la ruta no existe, el parámetro se llama
 * distinto o el dato no está sembrado — y todo el barrido aprobaría sin mirar
 * nada.
 *
 * Quedan afuera a propósito: el listado y la ficha de profesores son un
 * directorio de toda la escuela por diseño (lo fija ProfesoresApiTest con la
 * casilla profesores.ver; la frontera real es ficha.datos_personales y eso lo
 * prueba la última de este archivo) y la ventana de nota final es una sola
 * configuración del ciclo, no un dato por curso.
 */
class AlcanceEntreCursosApiTest extends ApiTestCase
{
    private const CICLO = 2026;

    private Alumno $alumnoAjeno;
    private Clase $claseAjena;
    private Materia $materiaAjena;
    private Profesor $profeAjeno;

    /** El alumno de 1° 1.ª sirve para lo contrario: que el candado no corte el trabajo propio. */
    private Alumno $alumnoPropio;

    protected function setUp(): void
    {
        parent::setUp();

        // El curso ajeno tiene que tener datos de verdad: sin clase tomada, sin
        // profesor asignado y sin notas, un "no se filtró nada" no probaría nada.
        $this->alumnoAjeno = Alumno::where('anio', 2)->orderBy('id')->firstOrFail();
        $this->alumnoPropio = $this->alumno;

        $this->materiaAjena = Materia::create(['anio' => 2, 'nombre' => 'Geografía', 'tipo' => 'aula']);

        $this->profeAjeno = Profesor::create([
            'nombre' => 'Pablo', 'apellido' => 'FueraDeAlcance', 'email' => 'pablo.ajeno@abc.gob.ar',
            'dni' => '30111222', 'estado' => 'Activo',
        ]);
        ProfesorAsignacion::create([
            'profesor_id' => $this->profeAjeno->id,
            'materia_id'  => $this->materiaAjena->id,
            'anio'        => 2, 'division' => '2',
        ]);

        // Horario del curso ajeno con su profesor en una franja.
        DB::table('horario_celdas')->insert([
            'curso_id'    => $this->otroCurso->id,
            'dia'         => 1,
            'slot_key'    => 'H1',
            'materia_id'  => $this->materiaAjena->id,
            'profesor_id' => $this->profeAjeno->id,
        ]);

        // Clase tomada en 2° 2.ª, por quien sí puede (alcance de escritura total).
        $nomina = Alumno::where('anio', 2)->where('division', '2')->cursando()->orderBy('id')->get()
            ->map(fn (Alumno $a) => ['alumno_id' => $a->id, 'estado' => 'ausente'])->all();

        $creada = $this->como('admin')->postJson('/api/asistencia/clases', [
            'anio' => 2, 'division' => '2', 'fecha' => '2026-03-10',
            'hora_inicio' => '07:30', 'hora_fin' => '08:30',
            'alumnos' => $nomina,
        ]);
        $creada->assertCreated();
        $this->claseAjena = Clase::findOrFail((int) $creada->json('clase.id'));

        // Una inasistencia justificada del curso ajeno: sin fila en esa solapa la
        // sonda de justificaciones estaría vacía para todos y no mediría nada.
        $asistenciaAjena = (int) Asistencia::where('clase_id', $this->claseAjena->id)
            ->where('alumno_id', $this->alumnoAjeno->id)->value('id');
        $this->como('admin')->postJson('/api/asistencia/justificaciones', [
            'asistencia_id' => $asistenciaAjena,
            'motivo'        => 'Certificado de 2do 2da',
        ])->assertCreated();

        // Una nota en el curso ajeno: la planilla y el resumen la tienen que ver.
        $this->como('admin')->putJson('/api/alumnos/' . $this->alumnoAjeno->id . '/calificaciones', [
            'entradas' => [[
                'anio' => 2, 'division' => '2', 'ciclo_lectivo' => self::CICLO, 'periodo' => 1,
                'materia_id' => $this->materiaAjena->id,
                'notas' => [['valor' => 6, 'fecha' => '2026-03-10']],
            ]],
        ])->assertSuccessful();
    }

    /**
     * Filas del barrido. Cada una apunta a 2° 2.ª y dice cómo se notaría que ese
     * dato llegó.
     *
     * @return array<int, array{area:string,uri:string,ids:array<int,string>,textos:array<int,string>,binario?:bool,candado?:bool}>
     */
    private function sondas(): array
    {
        $alumno  = (string) $this->alumnoAjeno->id;
        $dni     = (string) $this->alumnoAjeno->dni;
        $clase   = (string) $this->claseAjena->id;
        $profe   = (string) $this->profeAjeno->id;
        $materia = (string) $this->materiaAjena->id;
        $curso   = (string) $this->otroCurso->id;

        return [
            ['area' => 'alumnos', 'uri' => '/api/alumnos?anio=2&division=2', 'ids' => [$alumno], 'textos' => ['Domínguez']],
            ['area' => 'alumnos', 'uri' => '/api/alumnos?q=Dom%C3%ADnguez', 'ids' => [$alumno], 'textos' => ['Domínguez']],
            ['area' => 'alumnos', 'uri' => '/api/alumnos/' . $alumno, 'ids' => [$alumno], 'textos' => ['Domínguez', $dni], 'candado' => true],
            ['area' => 'alumnos', 'uri' => '/api/alumnos/' . $alumno . '/horario', 'ids' => [$materia, $profe], 'textos' => ['Geograf'], 'candado' => true],
            ['area' => 'alumnos', 'uri' => '/api/alumnos/padron/excel?anio=2&division=2', 'ids' => [], 'textos' => ['Domínguez', $dni], 'binario' => true],

            ['area' => 'cursos', 'uri' => '/api/cursos?anio=2', 'ids' => [$curso], 'textos' => []],
            ['area' => 'cursos', 'uri' => '/api/horarios/2/2', 'ids' => [$materia, $profe], 'textos' => ['Geograf']],
            ['area' => 'cursos', 'uri' => '/api/cursos/2/2/horario', 'ids' => [$materia, $profe], 'textos' => ['Geograf']],

            ['area' => 'asistencia', 'uri' => '/api/asistencia/clases?anio=2&division=2', 'ids' => [$clase], 'textos' => []],
            ['area' => 'asistencia', 'uri' => '/api/asistencia/clases/' . $clase, 'ids' => [$clase, $alumno], 'textos' => ['Domínguez'], 'candado' => true],
            ['area' => 'asistencia', 'uri' => '/api/asistencia/inasistencias?anio=2&division=2', 'ids' => [$alumno], 'textos' => ['Domínguez']],
            ['area' => 'asistencia', 'uri' => '/api/asistencia/historial?anio=2&division=2', 'ids' => [$alumno], 'textos' => ['Domínguez']],
            ['area' => 'asistencia', 'uri' => '/api/asistencia/estadisticas?anio=2&division=2', 'ids' => [$alumno], 'textos' => ['Domínguez']],
            ['area' => 'asistencia', 'uri' => '/api/asistencia/justificaciones?anio=2&division=2', 'ids' => [$alumno], 'textos' => ['Certificado de 2do 2da']],

            ['area' => 'calificaciones', 'uri' => '/api/alumnos/' . $alumno . '/calificaciones', 'ids' => [$alumno], 'textos' => ['Geograf'], 'candado' => true],
            ['area' => 'calificaciones', 'uri' => '/api/alumnos/' . $alumno . '/promedios', 'ids' => [$alumno], 'textos' => [], 'candado' => true],
            ['area' => 'calificaciones', 'uri' => '/api/alumnos/' . $alumno . '/anios-con-notas', 'ids' => [], 'textos' => [], 'candado' => true],
            ['area' => 'calificaciones', 'uri' => '/api/calificaciones/por-curso-materia?anio=2&division=2&materia_id=' . $materia, 'ids' => [$alumno], 'textos' => []],
            ['area' => 'calificaciones', 'uri' => '/api/calificaciones/resumen?anio=2&division=2', 'ids' => [$alumno], 'textos' => ['Domínguez']],

            ['area' => 'cursos', 'uri' => '/api/profesores/mapping?anio=2&division=2', 'ids' => [$profe], 'textos' => ['FueraDeAlcance']],
            ['area' => 'cursos', 'uri' => '/api/profesores/en-horarios?q=FueraDeAlcance&anios=2', 'ids' => [$profe], 'textos' => ['FueraDeAlcance']],
        ];
    }

    /** El texto buscable de una respuesta: la planilla .xlsx viaja comprimida. */
    private function textoDe(TestResponse $r, bool $binario): string
    {
        if (!$binario) {
            return (string) json_encode($r->json(), JSON_UNESCAPED_UNICODE);
        }

        $ruta = tempnam(sys_get_temp_dir(), 'padron') . '.xlsx';
        file_put_contents($ruta, $r->getContent());
        $texto = '';
        $zip = new \ZipArchive();
        if ($zip->open($ruta) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = (string) $zip->getNameIndex($i);
                if (str_contains($nombre, 'sharedStrings') || str_contains($nombre, 'sheet')) {
                    $texto .= $zip->getFromIndex($i);
                }
            }
            $zip->close();
        }
        unlink($ruta);

        return $texto;
    }

    /**
     * Todo valor escalado que cuelga de una clave de identificador: id,
     * alumno_id, materiaId… Es lo que delata una fila ajena sin confundirse
     * con cualquier número del cuerpo.
     *
     * @return array<int,string>
     */
    private function idsEnRespuesta(mixed $nodo): array
    {
        $salida = [];
        $caminar = static function ($actual) use (&$caminar, &$salida) {
            if (!is_array($actual)) return;
            foreach ($actual as $clave => $valor) {
                $esId = $clave === 'id' || str_ends_with((string) $clave, '_id')
                    || preg_match('/[a-z]Id$/', (string) $clave) === 1;
                if ($esId && is_scalar($valor) && $valor !== null && $valor !== '') {
                    $salida[] = (string) $valor;
                }
                if (is_array($valor)) $caminar($valor);
            }
        };
        $caminar($nodo);

        return array_values(array_unique($salida));
    }

    /** Marcadores del curso ajeno que efectivamente llegaron en esta respuesta. */
    private function trazasDelCursoAjeno(TestResponse $r, array $sonda): array
    {
        $binario = (bool) ($sonda['binario'] ?? false);

        // En el .xlsx no hay claves de identificador, sólo valores de celda: la
        // planilla se juzga por sus textos.
        $colados = $binario ? [] : array_values(array_intersect(
            $sonda['ids'],
            $this->idsEnRespuesta($r->json())
        ));
        $texto = $this->textoDe($r, $binario);
        foreach ($sonda['textos'] as $marca) {
            if ($marca !== '' && str_contains($texto, $marca)) $colados[] = $marca;
        }

        return $colados;
    }

    private function etiquetas(array $sonda): string
    {
        return implode('/', array_merge($sonda['ids'], $sonda['textos']));
    }

    public function test_el_que_ve_todos_los_cursos_realmente_ve_el_curso_ajeno(): void
    {
        $fallas = [];
        foreach ($this->sondas() as $sonda) {
            $uri = $sonda['uri'];
            $this->assertSame('todos', Alcances::ver('admin', $sonda['area']), 'el Admin no ve todo en ' . $sonda['area']);

            $r = $this->como('admin')->getJson($uri);
            $codigo = $r->getStatusCode();
            if ($codigo !== 200) {
                $fallas[] = "{$uri} → HTTP {$codigo} (la sonda no se puede validar)";
                continue;
            }

            // Sin marcadores declarados la ruta sólo acredita que existe: es el
            // caso de las que contestan sobre el curso sin nombrar ningún dato.
            if ($this->etiquetas($sonda) === '') continue;

            if (!$this->trazasDelCursoAjeno($r, $sonda)) {
                $fallas[] = "{$uri} no muestra ninguno de los marcadores " . $this->etiquetas($sonda);
            }
        }

        $this->assertSame([], $fallas, "sondas que no miden nada:\n" . implode("\n", $fallas));
    }

    public function test_el_profesor_no_ve_un_dato_del_curso_que_no_es_suyo(): void
    {
        $this->assertSame(Alcances::ASIGNADO, Alcances::ver('profesor', 'alumnos'), 'el profesor perdió su alcance por curso');

        $filtraciones = [];
        foreach ($this->sondas() as $sonda) {
            $uri = $sonda['uri'];
            $r = $this->como('profesor')->getJson($uri);
            $codigo = $r->getStatusCode();

            if ($codigo >= 500) {
                $filtraciones[] = "{$uri} → HTTP {$codigo}";
                continue;
            }
            if ($codigo === 403) continue; // el candado funcionó

            if (!empty($sonda['candado'])) {
                $filtraciones[] = "{$uri} respondió {$codigo} en vez de cerrar el curso ajeno";
                continue;
            }

            $trazas = $this->trazasDelCursoAjeno($r, $sonda);
            if ($trazas) {
                $filtraciones[] = "{$uri} le devolvió " . implode('/', $trazas);
            }
        }

        $this->assertSame([], $filtraciones, "el profesor ve datos de 2° 2.ª:\n" . implode("\n", $filtraciones));
    }

    public function test_el_alcance_del_preceptor_es_de_lectura_total_por_diseno(): void
    {
        // No es un descuido: el preceptor atiende el padrón completo y sólo puede
        // escribir en sus cursos. Si alguien cambia el mapa de Alcances y esto
        // se rompe, la pantalla le va a quedar mostrando menos de lo prometido.
        $ajeno = (string) $this->alumnoAjeno->id;

        $lista = $this->como('preceptor')->getJson('/api/alumnos?anio=2&division=2')->assertOk();
        $this->assertContains($ajeno, array_map('strval', array_column((array) $lista->json('data'), 'id')), 'el preceptor perdió la lectura del padrón ajeno');

        $this->como('preceptor')->getJson('/api/alumnos/' . $ajeno)->assertOk();

        // Pero escribir en ese curso sigue estando cerrado.
        $this->como('preceptor')->putJson('/api/alumnos/' . $ajeno, [
            'apellido' => 'Domínguez', 'nombre' => 'Diego', 'genero' => 'M', 'anio' => 2, 'division' => '2',
        ])->assertForbidden();
    }

    public function test_el_candado_no_deja_al_profesor_sin_su_propio_curso(): void
    {
        // El mismo pedido sobre 1° 1.ª, que sí es suyo: si esto falla, el filtro
        // está de más y el docente no puede trabajar.
        $propio = (string) $this->alumnoPropio->id;

        $lista = $this->como('profesor')->getJson('/api/alumnos?anio=1&division=1')->assertOk();
        $ids = array_map('strval', array_column((array) $lista->json('data'), 'id'));
        $this->assertContains($propio, $ids, 'el profesor no ve su propio curso');
        $this->assertNotContains((string) $this->alumnoAjeno->id, $ids, 'la lista del profesor trae alumnos de otro curso');

        $this->como('profesor')->getJson('/api/alumnos/' . $propio)->assertOk();
        $this->como('profesor')->getJson('/api/horarios/1/1')->assertOk();
        $this->como('profesor')->getJson('/api/profesores/mapping?anio=1&division=1')->assertOk();

        // Las mismas rutas del barrido, esta vez sobre su curso: tienen que abrir.
        $this->como('profesor')->getJson('/api/alumnos/' . $propio . '/calificaciones')->assertOk();
        $this->como('profesor')->getJson('/api/alumnos/' . $propio . '/promedios')->assertOk();
        $this->como('profesor')->getJson('/api/asistencia/clases?anio=1&division=1')->assertOk();
    }

    public function test_un_rol_de_lectura_total_no_lee_el_curso_ajeno_si_direccion_lo_restringe(): void
    {
        // El alcance se configura desde la pantalla; el backend tiene que obedecer
        // la restricción, no sólo el defecto del rol. Se parte de Preceptor para
        // que el rol nuevo llegue con sus acciones: un rol sin permisos daría 403
        // en todo y la prueba estaría midiendo el candado de acciones, no el de
        // alcance.
        $creado = $this->como('jefe_preceptores')->postJson('/api/admin/roles', [
            'nombre'    => 'Preceptor acotado',
            'nivel'     => 2,
            'desde_rol' => 'preceptor',
            'alcance'   => ['alumnos' => ['ver' => 'asignados']],
        ]);
        $creado->assertSuccessful();

        $cuenta = User::create([
            'name' => 'Acotado', 'apellido' => 'DePrueba', 'email' => 'acotado@abc.gob.ar',
            'password' => self::CLAVE, 'role' => 'preceptor_acotado', 'status' => 'active',
        ]);
        UserAnioDivision::create(['user_id' => $cuenta->id, 'anio' => 1, 'division' => '1']);
        RolConfig::olvidarRegistro();

        $this->assertSame(
            Alcances::ASIGNADO,
            Alcances::ver('preceptor_acotado', 'alumnos'),
            'la pantalla guardó el alcance pero Alcances no lo aplica: ' . json_encode(Alcances::de('preceptor_acotado')['alumnos'])
        );

        $token = $this->tokenDeCuenta($cuenta);

        $lista = $this->withToken($token)->getJson('/api/alumnos?anio=2&division=2')->assertOk();
        $this->assertEmpty((array) $lista->json('data'), 'la lista de un curso ajeno no quedó vacía: ' . json_encode($lista->json('data')));

        // Y su propio curso sigue a mano: la restricción recorta, no cierra.
        $suyo = $this->withToken($token)->getJson('/api/alumnos?anio=1&division=1')->assertOk();
        $this->assertContains(
            (string) $this->alumnoPropio->id,
            array_map('strval', array_column((array) $suyo->json('data'), 'id')),
            'el alcance "solo los asignados" dejó al rol sin su propio curso'
        );
    }

    public function test_el_directorio_de_profesores_es_de_toda_la_escuela_y_ahi_esta_la_frontera(): void
    {
        // Un docente puede ver la nómina completa (quién da qué clase); lo que no
        // puede es leer los datos personales. Esta prueba fija la frontera: si
        // alguien le enciende la casilla de datos personales al profesor, el DNI
        // de un par ajeno empezaría a viajar en la lista y en la ficha.
        $this->assertFalse(
            Permisos::puede('profesor', 'ficha.datos_personales'),
            'el profesor tiene permiso de datos personales: el directorio ya no es inocuo'
        );

        $lista = $this->como('profesor')->getJson('/api/profesores?anio=2')->assertOk();
        $this->assertContains('FueraDeAlcance', array_column((array) $lista->json('data'), 'apellido'), 'el directorio dejó de ser escolar');
        foreach ((array) $lista->json('data') as $fila) {
            $this->assertNull($fila['dni'] ?? null, 'la lista sin permiso de ficha igual trajo DNI: ' . json_encode($fila));
        }

        $ficha = (string) json_encode($this->como('profesor')->getJson('/api/profesores/' . $this->profeAjeno->id)->assertOk()->json());
        $this->assertStringNotContainsString('30111222', $ficha, 'la ficha recortada dejó el DNI del par');
    }

    /**
     * Guarda estática del barrido: toda lectura de la API que recibe un curso
     * (por parámetro o por query) tiene que llamar a algún filtro de alcance.
     *
     * El barrido dinámico de arriba prueba los 21 pedidos que la pantalla usa
     * hoy; esto prueba TODAS, incluidas las que todavía no tienen sonda. Cuando
     * se agregue una lectura nueva sin recorte, acá aparece antes de que alguien
     * la baje con un token de otro curso.
     */
    public function test_toda_lectura_de_curso_tiene_su_filtro_de_alcance(): void
    {
        // Rutas que responden sin recorte por diseño, con el motivo y su prueba.
        $listaBlanca = [
            // Directorio de toda la escuela: la casilla profesores.ver lo habilita
            // y ficha.datos_personales le corta el DNI (ProfesoresApiTest y el
            // test_el_directorio_de_profesores… de este archivo).
            'api/profesores',
            // Catálogo de plan de estudios: las materias de un año, sin datos de
            // nadie.
            'api/anios/{anio}/materias',
        ];

        $sellos = ['assertCanReadCurso', 'assertPuedeVerCurso', 'assertCanWriteDivision', 'assertPuedeEditarCurso',
                   'assertCanWriteAlumno', 'scopeAlumnoIndex', 'scopeCursoIndex', 'cursoIdsVisibles', 'scopeCursoIds',
                   'assignedPairs', 'userIsAssignedTo', 'assertCanGradeAlumno', 'assertCanOperar', 'assertCanVerCurso',
                   'alcanceVer', 'alcanceEditar', 'veTodo', 'editaTodo', 'puedeVer', 'puedeEditar'];

        $sinFiltro = [];
        foreach (app('router')->getRoutes() as $ruta) {
            if (!in_array('GET', $ruta->methods(), true)) continue;
            $uri = $ruta->uri();
            if (!str_starts_with($uri, 'api/') || in_array($uri, $listaBlanca, true)) continue;

            $accion = $ruta->getActionName();
            if (!$accion || !str_contains($accion, '@')) continue;
            [$clase, $metodo] = explode('@', $accion);
            if (!class_exists($clase) || !method_exists($clase, $metodo)) continue;

            $ref = new \ReflectionMethod($clase, $metodo);
            $src = implode('', array_slice(
                (array) file($ref->getFileName()),
                $ref->getStartLine() - 1,
                $ref->getEndLine() - $ref->getStartLine() + 1
            ));

            // ¿Le está pidiendo un curso o un alumno al pedido?
            $parametros = implode(',', $ruta->parameterNames());
            $pideCurso = preg_match('/(anio|division|curso|alumno)/i', $parametros) === 1
                || preg_match('/(query|input|validate)\s*\(\s*[\'"](anio|division|curso_id)[\'"]/', $src) === 1;
            if (!$pideCurso) continue;

            $tieneFiltro = false;
            foreach ($sellos as $sello) {
                if (str_contains($src, $sello)) { $tieneFiltro = true; break; }
            }
            if (!$tieneFiltro) {
                $sinFiltro[] = $uri . ' → ' . class_basename($clase) . '::' . $metodo;
            }
        }

        $this->assertSame([], $sinFiltro, "lecturas de curso sin recorte de alcance:\n" . implode("\n", $sinFiltro));
    }

    /** Login con una cuenta creada a mano (no es uno de los roles del fixture). */
    private function tokenDeCuenta(User $cuenta): string
    {
        $r = $this->postJson('/api/login', ['email' => $cuenta->email, 'password' => self::CLAVE])->assertOk();
        $this->assertIsString($r->json('token'));

        return (string) $r->json('token');
    }
}
