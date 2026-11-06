<?php

namespace App\Support;

use App\Dominios\Alumnos\Alumno;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Lee y valida la planilla de alta masiva de alumnos.
 *
 * Solo lee: no escribe nada. El controlador decide qué hacer con las filas
 * (vista previa o importación), así la misma validación sirve para las dos.
 */
class AlumnosExcelImport
{
    /**
     * Columnas de la planilla: clave interna, título visible, ancho y ejemplo.
     * El orden es el orden de la plantilla descargable.
     */
    public const COLUMNAS = [
        ['clave' => 'apellido',        'titulo' => 'Apellido*',                  'ancho' => 22, 'ejemplo' => 'Gómez'],
        ['clave' => 'nombre',          'titulo' => 'Nombre*',                    'ancho' => 20, 'ejemplo' => 'Luisa'],
        ['clave' => 'dni',             'titulo' => 'DNI',                        'ancho' => 13, 'ejemplo' => '35123456'],
        ['clave' => 'genero',          'titulo' => 'Sexo*',                      'ancho' => 8,  'ejemplo' => 'F'],
        ['clave' => 'fecha_nacimiento','titulo' => 'Fecha de nacimiento',        'ancho' => 18, 'ejemplo' => '01/03/2008'],
        ['clave' => 'nacionalidad',    'titulo' => 'Nacionalidad',               'ancho' => 16, 'ejemplo' => 'Argentina'],
        ['clave' => 'matricula',       'titulo' => 'N° de matrícula',            'ancho' => 15, 'ejemplo' => ''],
        ['clave' => 'email',           'titulo' => 'Correo electrónico',         'ancho' => 26, 'ejemplo' => 'luisa.gomez@correo.com'],
        ['clave' => 'anio',            'titulo' => 'Año*',                       'ancho' => 8,  'ejemplo' => '4'],
        ['clave' => 'division',        'titulo' => 'División',                   'ancho' => 10, 'ejemplo' => '2'],
        ['clave' => 'grupo',           'titulo' => 'Grupo',                      'ancho' => 8,  'ejemplo' => 'A'],
        ['clave' => 'especialidad',    'titulo' => 'Especialidad',               'ancho' => 28, 'ejemplo' => 'Técnico en Informática P. y P.'],
        ['clave' => 'estado',          'titulo' => 'Estado',                     'ancho' => 12, 'ejemplo' => 'Activo'],
        ['clave' => 'tiene_ppi',       'titulo' => 'PPI',                        'ancho' => 8,  'ejemplo' => 'No'],
        ['clave' => 'tiene_ae',        'titulo' => 'AE',                         'ancho' => 8,  'ejemplo' => 'No'],
        ['clave' => 'telefono',        'titulo' => 'Teléfono del alumno',        'ancho' => 18, 'ejemplo' => '3454555555'],
        ['clave' => 'direccion',       'titulo' => 'Dirección del alumno',       'ancho' => 28, 'ejemplo' => 'Av. San Martín 1234'],
        ['clave' => 'resp_apellido',   'titulo' => 'Responsable apellido',       'ancho' => 20, 'ejemplo' => 'Gómez'],
        ['clave' => 'resp_nombre',     'titulo' => 'Responsable nombre',         'ancho' => 20, 'ejemplo' => 'Ana'],
        ['clave' => 'resp_parentesco', 'titulo' => 'Parentesco',                 'ancho' => 16, 'ejemplo' => 'Madre'],
        ['clave' => 'resp_telefono',   'titulo' => 'Teléfono del responsable',   'ancho' => 22, 'ejemplo' => '3454566666'],
        ['clave' => 'observaciones',   'titulo' => 'Observaciones',              'ancho' => 32, 'ejemplo' => ''],
    ];

    /** Alias aceptados al leer el encabezado (además del título de la columna). */
    private const ALIAS = [
        'apellido'        => ['apellido', 'apellidos'],
        'nombre'          => ['nombre', 'nombres'],
        'dni'             => ['dni', 'documento', 'nro de documento'],
        'genero'          => ['genero', 'sexo', 'cdad', 'cuota'],
        'fecha_nacimiento'=> ['fecha de nacimiento', 'fecha nacimiento', 'nacimiento', 'fn'],
        'nacionalidad'    => ['nacionalidad'],
        'matricula'       => ['n de matricula', 'matricula', 'nro de matricula', 'numero de matricula', 'n° de matrícula'],
        'email'           => ['email', 'correo', 'correo electronico', 'mail', 'e mail'],
        'anio'            => ['anio', 'grado', 'año'],
        'division'        => ['division', 'div'],
        'grupo'           => ['grupo', 'rotacion'],
        'especialidad'    => ['especialidad', 'carrera', 'orientacion'],
        'estado'          => ['estado', 'condicion'],
        'tiene_ppi'       => ['ppi', 'tiene ppi'],
        'tiene_ae'        => ['ae', 'tiene ae', 'acompanante externo', 'acompañante externo'],
        'telefono'        => ['telefono', 'telefono del alumno', 'celular'],
        'direccion'       => ['direccion', 'domicilio', 'direccion del alumno'],
        'resp_apellido'   => ['responsable apellido', 'apellido del responsable'],
        'resp_nombre'     => ['responsable nombre', 'nombre del responsable'],
        'resp_parentesco' => ['parentesco', 'vinculo', 'relacion'],
        'resp_telefono'   => ['telefono del responsable', 'telefono responsable', 'contacto responsable'],
        'observaciones'   => ['observaciones', 'observacion', 'notas'],
    ];

    /**
     * Rótulos de las planillas que usa la escuela y no están en la plantilla propia.
     * En "REGISTRO ALUMNADO 2026" el apellido y el nombre vienen en una sola
     * columna, y el curso trae el año y la división juntos; se separan al validar.
     */
    private const EXTERNOS = [
        'APELLIDO Y NOMBRE'     => 'nombre_apellido',
        'APELLIDO/NOMBRE'       => 'nombre_apellido',
        'NOMBRE Y APELLIDO'     => 'nombre_apellido',
        'Curso'                 => 'curso',
        'ANO Y DIVISION'        => 'curso',
        'DOCUMENTO TIPO N°'     => 'dni',
        'T.E.L.'                => 'telefono',
        'DOMICILIO Y LOCALIDAD' => 'direccion',
        'E-MAIL'                => 'email',
    ];

    /** Géneros aceptados escritos en cualquier forma razonable. */
    private const GENEROS = [
        'f' => 'F', 'femenino' => 'F', 'mujer' => 'F',
        'm' => 'M', 'masculino' => 'M', 'varon' => 'M',
        'o' => 'O', 'otros' => 'O', 'otro' => 'O', 'x' => 'O',
    ];

    private const SI = ['si', 'sí', 's', 'y', 'yes', '1', 'x', 'true', 'verdadero'];
    private const NO = ['no', 'n', '0', 'false', 'falso', '-'];

    private const MAX_FILAS = 3000;

    /**
     * Lee el archivo y devuelve las filas clasificadas.
     *
     * @return array{filas: array, totales: array, errores_generales: array, total_leido: int}
     */
    public function leer(string $rutaArchivo): array
    {
        $hojas = LectorXlsx::hojas($rutaArchivo);
        if ($hojas === []) {
            return [
                'filas' => [],
                'totales' => ['nuevas' => 0, 'existentes' => 0, 'rechazadas' => 0],
                'errores_generales' => ['La planilla no es un Excel (.xlsx) válido. Guardala como .xlsx desde Excel o LibreOffice y volvé a subirla.'],
                'avisos' => [],
                'total_leido' => 0,
            ];
        }

        // Se prueba hoja por hoja leyendo sólo las 12 primeras filas, que es donde
        // están los títulos; así el registro de 16 hojas se reconoce en un segundo.
        $elegida = null;
        $cabezado = null;
        foreach ($this->hojasEnOrden($hojas) as $h) {
            $lector = LectorXlsx::abrir($rutaArchivo, $h['parte'], 12);
            $cabezado = $this->buscarEncabezado($lector);
            if ($cabezado !== null) {
                $elegida = $h;
                break;
            }
        }

        if ($elegida === null || $cabezado === null) {
            LectorXlsx::olvidar($rutaArchivo);
            return $this->sinEncabezado();
        }

        $resultado = $this->procesarHoja(
            LectorXlsx::abrir($rutaArchivo, $elegida['parte']),
            $cabezado
        );
        LectorXlsx::olvidar($rutaArchivo);

        return $resultado;
    }

    /** El archivo no tiene ninguna fila de títulos reconocible en ninguna hoja. */
    private function sinEncabezado(): array
    {
        return [
            'filas' => [],
            'totales' => ['nuevas' => 0, 'existentes' => 0, 'rechazadas' => 0],
            'errores_generales' => ['No se encontró la fila de encabezados. Descargá la plantilla y fijate que la primera fila tenga "Apellido", "Nombre", "DNI"...'],
            'avisos' => [],
            'total_leido' => 0,
        ];
    }

    /**
     * Hojas en el orden en que conviene probarlas.
     *
     * Primero las que se llaman como la lista de alumnos (ALUMNOS de la plantilla
     * propia, "Matrícula" o "Datos personales 2026" del registro de la escuela),
     * después el resto, y las ocultas al final.
     *
     * @param array<int, array{titulo: string, parte: string, oculta: bool}> $hojas
     * @return array<int, array{titulo: string, parte: string, oculta: bool}>
     */
    private function hojasEnOrden(array $hojas): array
    {
        $prioritarias = ['alumnos', 'padron', 'matricula', 'datos personales', 'alumnado', 'listado'];
        usort($hojas, function (array $a, array $b) use ($prioritarias) {
            return ($this->puestoPrioridad($a['titulo'], $prioritarias, $a['oculta']))
                <=> ($this->puestoPrioridad($b['titulo'], $prioritarias, $b['oculta']));
        });

        return $hojas;
    }

    /** 0 = se llama como la lista de alumnos; las ocultas van siempre al final. */
    private function puestoPrioridad(string $titulo, array $prioritarias, bool $oculta): int
    {
        $puesto = $oculta ? 900 : 0;
        $t = $this->pareceClave($titulo);
        foreach ($prioritarias as $i => $buscar) {
            if (str_contains($t, $buscar)) return $puesto + $i;
        }
        return $puesto + count($prioritarias) + 1;
    }

    /**
     * Convierte la hoja en filas ya validadas.
     *
     * @param array{0:int,1:array<string,int>} $cabezado fila de títulos y columna de cada clave
     */
    private function procesarHoja(LectorXlsx $hoja, array $cabezado): array
    {
        $alto = $hoja->alto();
        [$filaEncabezado, $columnas] = $cabezado;
        $colMax = $hoja->ancho();

        $faltan = $this->faltantesObligatorias($columnas);
        if ($faltan !== []) {
            return [
                'filas' => [],
                'totales' => ['nuevas' => 0, 'existentes' => 0, 'rechazadas' => 0],
                'errores_generales' => ['Al archivo le faltan columnas obligatorias: ' . implode(', ', $faltan) . '. Descargá la plantilla para ver el orden correcto.'],
                'avisos' => [],
                'total_leido' => 0,
            ];
        }

        if ($alto - $filaEncabezado > self::MAX_FILAS) {
            return [
                'filas' => [],
                'totales' => ['nuevas' => 0, 'existentes' => 0, 'rechazadas' => 0],
                'errores_generales' => ['El archivo tiene más de ' . self::MAX_FILAS . ' filas. Dividilo en dos planillas.'],
                'avisos' => [],
                'total_leido' => 0,
            ];
        }

        $contexto = $this->contexto();
        $contexto['tieneColumnaGenero'] = isset($columnas['genero']);
        $dnisDelArchivo = [];
        $filas = [];
        $avisos = ['correo_no_mail' => 0, 'matricula_anotacion' => 0, 'fuera_de_lista' => 0];
        // En el registro de la escuela la hoja es un bloque por curso: cada bloque
        // vuelve a escribir los títulos, así que se rastrea la columna del nombre.
        $colNombre = $columnas['nombre_apellido'] ?? $columnas['apellido'] ?? $columnas['nombre'] ?? null;
        $cursoVigente = null;

        for ($f = $filaEncabezado + 1; $f <= $alto; $f++) {
            if ($colNombre !== null && $this->esRotuloNombre($hoja->texto($colNombre, $f))) {
                // El bloque nuevo puede no repetir el rótulo "Curso" aunque la
                // columna siga ahí: se suma lo nuevo y se conserva lo conocido.
                $columnas = $columnas + $this->mapearFila($hoja, $f, $colMax);
                $colNombre = $columnas['nombre_apellido'] ?? $columnas['apellido'] ?? $columnas['nombre'] ?? $colNombre;
                $contexto['tieneColumnaGenero'] = isset($columnas['genero']);
                $cursoVigente = null;
                continue;
            }

            $crudos = [];
            $vacia = true;
            foreach ($columnas as $clave => $colIndex) {
                if ($colIndex === null) continue;
                $valor = $hoja->texto($colIndex, $f);
                // Las celdas vacías de esa planilla vienen de una fórmula y traen
                // "0": un cero suelto no es un dato, es el hueco sin completar.
                if ($valor === '0') $valor = '';
                if ($valor !== '') $vacia = false;
                $crudos[$clave] = $valor;
            }
            if ($vacia) continue;

            if (isset($crudos['curso'])) {
                if ($crudos['curso'] !== '') $cursoVigente = $crudos['curso'];
                elseif ($cursoVigente !== null) $crudos['curso'] = $cursoVigente;
            }

            if ($this->noEsAlumno($crudos)) {
                // No es un alumno: son los huecos que el registro deja hasta
                // completar el bloque y la fila "AÑO: 1º Año 2º / TURNO: Tarde"
                // que antecede a cada título.
                $avisos['fuera_de_lista']++;
                continue;
            }

            [$datos, $errores] = $this->validarFila($crudos, $contexto);
            if ($datos['correo_movido'] !== null) $avisos['correo_no_mail']++;
            if ($datos['matricula_movida'] !== null) $avisos['matricula_anotacion']++;

            $estado = 'rechazada';
            if ($errores === []) {
                if ($datos['dni'] !== null && isset($contexto['dnisExistentes'][$datos['dni']])) {
                    $estado = 'existente';
                } elseif ($datos['dni'] !== null && isset($dnisDelArchivo[$datos['dni']])) {
                    $errores[] = 'El mismo DNI ya está en la fila ' . $dnisDelArchivo[$datos['dni']]
                        . ' de esta planilla: se carga una sola vez (revisá si es un pase de división).';
                    $estado = 'rechazada';
                } else {
                    $estado = 'nueva';
                    if ($datos['dni'] !== null) $dnisDelArchivo[$datos['dni']] = $f;
                }
            }

            $filas[] = [
                'fila' => $f,
                'estado' => $estado,
                'apellido' => $datos['apellido'],
                'nombre' => $datos['nombre'],
                'dni' => $datos['dni'],
                'curso' => trim($datos['anio'] . '° ' . ($datos['division'] ?? '')),
                'datos' => $datos,
                'errores' => $errores,
                'avisos' => array_values(array_filter([
                    $datos['correo_movido'] !== null
                        ? 'El texto de "Correo electrónico" no es un mail y se guardó en Observaciones.'
                        : null,
                    $datos['matricula_movida'] !== null
                        ? 'El texto de "N° de matrícula" («' . $datos['matricula_movida'] . '») no es un número y se guardó en Observaciones.'
                        : null,
                ])),
            ];
        }

        $totales = [
            'nuevas' => count(array_filter($filas, fn($r) => $r['estado'] === 'nueva')),
            'existentes' => count(array_filter($filas, fn($r) => $r['estado'] === 'existente')),
            'rechazadas' => count(array_filter($filas, fn($r) => $r['estado'] === 'rechazada')),
        ];

        return [
            'filas' => $filas,
            'totales' => $totales,
            'errores_generales' => [],
            'avisos' => $this->avisos($avisos),
            'total_leido' => count($filas),
        ];
    }

    /**
     * Lo que hay que saber del archivo sin que sea un error.
     *
     * @param array{correo_no_mail:int,matricula_anotacion:int,fuera_de_lista:int} $contadores
     * @return array<int,string>
     */
    private function avisos(array $contadores): array
    {
        $avisos = [];
        if ($contadores['correo_no_mail'] > 0) {
            $avisos[] = 'En ' . $contadores['correo_no_mail'] . ($contadores['correo_no_mail'] === 1 ? ' fila' : ' filas')
                . ' la columna "Correo electrónico" tiene una anotación (pases, bajas) que no es un mail: se guardó en Observaciones y el alumno se carga igual.';
        }
        if ($contadores['matricula_anotacion'] > 0) {
            $avisos[] = 'En ' . $contadores['matricula_anotacion'] . ($contadores['matricula_anotacion'] === 1 ? ' fila' : ' filas')
                . ' la columna "N° de matrícula" trae la división o la escuela de origen ("3º Año 6º", "Otra Escuela") en lugar de un número:'
                . ' se guardó en Observaciones y el alumno se carga igual.';
        }
        if ($contadores['fuera_de_lista'] > 0) {
            $avisos[] = 'Se saltaron ' . $contadores['fuera_de_lista']
                . ' filas que no son alumnos (huecos del bloque o la fila del curso y el turno).';
        }
        return $avisos;
    }

    /**
     * Fila que no corresponde a un alumno.
     *
     * El registro de la escuela completa cada curso hasta 45 lugares y deja el
     * resto con el curso escrito, y antes de cada encabezado pone "AÑO: / 1º Año
     * 2º SECUNDARIA / TURNO: Tarde". Ninguna de esas dos es una persona.
     *
     * @param array<string,string> $crudos
     */
    private function noEsAlumno(array $crudos): bool
    {
        $nombre = trim((string) ($crudos['nombre_apellido'] ?? '')) !== ''
            ? (string) $crudos['nombre_apellido']
            : trim((string) ($crudos['apellido'] ?? '')) . ' ' . trim((string) ($crudos['nombre'] ?? ''));

        if ($this->texto($nombre) === '' && $this->normalizarDni((string) ($crudos['dni'] ?? '')) === null) {
            return true;
        }

        if (str_contains($nombre, ',')) return false;
        $clave = $this->pareceClave($nombre);
        foreach (['anio', 'secundaria', 'turno', 'curso', 'division', 'nombre', 'apellido'] as $palabra) {
            if (str_contains($clave, $palabra)) return true;
        }

        return false;
    }

    /** Datos que se consultan una sola vez para no repetir consultas por fila. */
    private function contexto(): array
    {
        $mapa = [];
        foreach (\App\Dominios\Cursos\ParametroDivision::where('activo', true)
            ->get(['anio', 'division']) as $r) {
            $d = strtoupper(trim($r->division));
            if ($d === '') continue;
            $mapa[(int) $r->anio][$d] = true;
        }
        if ($mapa === []) {
            foreach (range(1, SchoolYear::maxYear()) as $i) {
                foreach (range(1, 9) as $d) $mapa[$i][(string) $d] = true;
            }
        }

        $dnis = [];
        foreach (\DB::table('alumnos')->whereNotNull('dni')->pluck('dni') as $d) {
            $normal = $this->normalizarDni((string) $d);
            if ($normal !== null) $dnis[$normal] = true;
        }

        $especialidades = [];
        foreach (Alumno::ESPECIALIDADES as $clave => $label) {
            $especialidades[$this->pareceClave($clave)] = $clave;
            $especialidades[$this->pareceClave($label)] = $clave;
        }

        return [
            'maxAnio' => SchoolYear::maxYear(),
            'divisiones' => $mapa,
            'dnisExistentes' => $dnis,
            'especialidades' => $especialidades,
        ];
    }

    /**
     * Columnas que faltan para que la planilla sirva, en lenguaje de la escuela.
     * El nombre puede venir en una columna o en dos, y el curso en una o en dos;
     * el sexo ya no es obligatorio porque el registro docente no lo tiene.
     *
     * @return array<int,string>
     */
    private function faltantesObligatorias(array $columnas): array
    {
        $tiene = static fn (string $clave): bool => !empty($columnas[$clave]);
        $faltan = [];
        if (!$tiene('nombre_apellido') && !($tiene('apellido') && $tiene('nombre'))) {
            $faltan[] = '"Apellido y Nombre" (o "Apellido" y "Nombre" separados)';
        }
        if (!$tiene('curso') && !$tiene('anio')) {
            $faltan[] = '"Año" (o "Curso", por ejemplo "1º Año 1º")';
        }
        return $faltan;
    }

    /** true si el contenido de una celda es el rótulo de la columna del nombre. */
    private function esRotuloNombre(string $v): bool
    {
        static $rotulos = null;
        if ($rotulos === null) {
            $rotulos = [];
            foreach (self::EXTERNOS as $titulo => $clave) {
                if ($clave === 'nombre_apellido') $rotulos[] = $this->pareceClave($titulo);
            }
            foreach (['apellido', 'nombre'] as $titulo) $rotulos[] = $titulo;
        }
        return in_array($this->pareceClave($v), $rotulos, true);
    }

    /** clave => índice de columna para una fila que tiene títulos de planilla. */
    private function mapearFila(LectorXlsx $hoja, int $fila, int $colMax): array
    {
        $buscadas = $this->mapeoEncabezados();
        $columnas = [];
        for ($c = 1; $c <= $colMax; $c++) {
            $txt = $this->pareceClave($hoja->texto($c, $fila));
            if ($txt !== '' && isset($buscadas[$txt])) {
                $columnas[$buscadas[$txt]] = $c;
            }
        }
        return $columnas;
    }

    /**
     * Busca la fila de encabezados de la hoja y arma clave => columna.
     *
     * @return array{0:int,1:array<string,int>}|null
     */
    private function buscarEncabezado(LectorXlsx $hoja): ?array
    {
        $hasta = min($hoja->alto(), 12);
        $colMax = min($hoja->ancho(), 40);
        for ($f = 1; $f <= $hasta; $f++) {
            $columnas = $this->mapearFila($hoja, $f, $colMax);
            // Se reconocen los títulos por las columnas obligatorias; si aparece
            // otra cosa arriba (el título del informe) se sigue buscando.
            if ($this->faltantesObligatorias($columnas) === []) {
                return [$f, $columnas];
            }
        }
        return null;
    }

    /** titulo normalizado => clave interna, con sus alias. */
    private function mapeoEncabezados(): array
    {
        $mapa = [];
        foreach (self::COLUMNAS as $col) {
            $mapa[$this->pareceClave($col['titulo'])] = $col['clave'];
            foreach (self::ALIAS[$col['clave']] ?? [] as $alias) {
                $mapa[$this->pareceClave($alias)] = $col['clave'];
            }
        }
        foreach (self::EXTERNOS as $rotulo => $clave) {
            // Un rótulo ajeno nunca pisa a los títulos de la plantilla propia.
            $claveMapa = $this->pareceClave($rotulo);
            if (!isset($mapa[$claveMapa])) $mapa[$claveMapa] = $clave;
        }
        return $mapa;
    }
    /**
     * Convierte el crudo de una fila en datos listos para guardar.
     *
     * @return array{0:array,1:array<int,string>}
     */
    private function validarFila(array $crudos, array $contexto): array
    {
        $errores = [];

        $apellido = $this->texto((string) ($crudos['apellido'] ?? ''));
        $nombre   = $this->texto((string) ($crudos['nombre'] ?? ''));

        // "APELLIDO Y NOMBRE" del registro de la escuela trae todo junto y con el
        // apellido primero, separado por coma: "AGUIRRE, Tiziano Jeremias".
        if (isset($crudos['nombre_apellido']) && $crudos['nombre_apellido'] !== '') {
            [$apellido, $nombre] = $this->partirNombre((string) $crudos['nombre_apellido']);
        }

        if ($apellido === '') $errores[] = 'Falta el apellido.';
        if ($nombre === '')   $errores[] = 'Falta el nombre.';
        if (mb_strlen($apellido) > 120) $errores[] = 'El apellido es demasiado largo (máximo 120 caracteres).';
        if (mb_strlen($nombre) > 120)   $errores[] = 'El nombre es demasiado largo (máximo 120 caracteres).';

        $dni = $this->normalizarDni((string) ($crudos['dni'] ?? ''));
        if ($dni !== null && strlen($dni) > 20) $errores[] = 'El DNI es demasiado largo.';

        $genero = $this->genero((string) ($crudos['genero'] ?? ''));
        if ($genero === null) {
            if (empty($contexto['tieneColumnaGenero'])) {
                // El registro de alumnado no tiene columna de sexo: se carga "Otros"
                // y se corrige desde la ficha, en vez de rechazar los 1200 alumnos.
                $genero = 'O';
            } else {
                $errores[] = 'El sexo tiene que ser F, M u O (o Femenino / Masculino / Otros).';
            }
        }

        $fecha = $this->fecha((string) ($crudos['fecha_nacimiento'] ?? ''));
        if ($fecha === false) $errores[] = 'La fecha de nacimiento no se entiende. Usá dd/mm/aaaa.';

        // "1º Año 1º" es el curso completo: de ahí salen el año y la división.
        $cursoTexto = $this->texto((string) ($crudos['curso'] ?? ''));
        [$anioCurso, $divisionCurso] = $this->cursoAnioDivision($cursoTexto);

        $anioTexto = $this->texto((string) ($crudos['anio'] ?? ''));
        if ($anioTexto === '' && $anioCurso !== null) $anioTexto = (string) $anioCurso;
        $anio = $this->anio($anioTexto);
        if ($anio === null) {
            $errores[] = 'Falta el año o no se entiende. Poné el número (1 a ' . $contexto['maxAnio'] . ').';
        } elseif ($anio < 1 || $anio > $contexto['maxAnio']) {
            $errores[] = 'El año ' . $anioTexto . ' está fuera del rango (1 a ' . $contexto['maxAnio'] . ').';
        }

        $division = $this->division((string) ($crudos['division'] ?? ''));
        if ($division === null) $division = $divisionCurso;
        if ($division !== null && $anio !== null) {
            if (!isset($contexto['divisiones'][$anio][$division])) {
                $errores[] = 'La división ' . $division . ' no está activa para ' . $anio . '°.';
            }
        }

        $grupo = strtoupper($this->texto((string) ($crudos['grupo'] ?? '')));
        if ($grupo !== '') {
            $grupo = substr($grupo, 0, 1);
            if (!in_array($grupo, ['A', 'B'], true)) {
                $errores[] = 'El grupo tiene que ser A o B.';
                $grupo = '';
            }
        }

        $especialidad = $this->especialidad((string) ($crudos['especialidad'] ?? ''), $contexto['especialidades']);
        if ($especialidad === false) {
            $errores[] = 'Esa especialidad no existe. Mirá la hoja "Cómo llenarlo".';
        } elseif ($especialidad !== null && $anio !== null && $anio < 4) {
            $errores[] = 'La especialidad solo se carga a partir de 4° año.';
        }

        $estado = $this->estado((string) ($crudos['estado'] ?? ''));
        if ($estado === false) $errores[] = 'El estado tiene que ser Activo o Egresado.';

        $nacionalidad = $this->texto((string) ($crudos['nacionalidad'] ?? ''));
        // El registro se escribe en mayúsculas; la pantalla agrupa por valor, así
        // que "ARGENTINA" y "Argentina" serían dos nacionalidades distintas.
        if ($nacionalidad !== '') {
            $nacionalidad = mb_convert_case($nacionalidad, MB_CASE_TITLE, 'UTF-8');
        }
        if (mb_strlen($nacionalidad) > 60) $errores[] = 'La nacionalidad es demasiado larga (máximo 60).';

        $matricula = $this->texto((string) ($crudos['matricula'] ?? ''));
        // En "REGISTRO ALUMNADO 2026" la columna G dice "Nº DE MATRICULA" pero no
        // trae un número: trae la división del año anterior ("3º Año 6º") o la
        // procedencia ("Otra Escuela"). El mismo texto se repite en 31 alumnos, así
        // que no identifica a nadie; se conserva en Observaciones.
        $matriculaMovida = null;
        if ($matricula !== '' && !self::esMatriculaNumero($matricula)) {
            $matriculaMovida = $matricula;
            $matricula = '';
        }
        if (mb_strlen($matricula) > 30) $errores[] = 'El número de matrícula es demasiado largo (máximo 30).';

        $email = $this->email((string) ($crudos['email'] ?? ''));
        // En el registro docente la columna E-MAIL se usa para anotar pases y
        // bajas. Un texto que no es mail no puede rechazar al alumno: se guarda
        // en Observaciones para no perder la anotación.
        $correoMovido = null;
        if ($email === false) {
            $correoMovido = $this->texto((string) ($crudos['email'] ?? ''));
            $email = null;
        }

        $telefono = $this->texto((string) ($crudos['telefono'] ?? ''));
        if (mb_strlen($telefono) > 40) $errores[] = 'El teléfono es demasiado largo (máximo 40).';

        $direccion = $this->texto((string) ($crudos['direccion'] ?? ''));
        if (mb_strlen($direccion) > 255) $errores[] = 'La dirección es demasiado larga (máximo 255).';

        $respApellido = $this->texto((string) ($crudos['resp_apellido'] ?? ''));
        $respNombre   = $this->texto((string) ($crudos['resp_nombre'] ?? ''));
        $respParent   = $this->texto((string) ($crudos['resp_parentesco'] ?? ''));
        $respTel      = $this->texto((string) ($crudos['resp_telefono'] ?? ''));
        if (mb_strlen($respApellido) > 120 || mb_strlen($respNombre) > 120) $errores[] = 'El nombre del responsable es demasiado largo.';
        if (mb_strlen($respParent) > 40) $errores[] = 'El parentesco es demasiado largo (máximo 40).';
        if (mb_strlen($respTel) > 40) $errores[] = 'El teléfono del responsable es demasiado largo (máximo 40).';

        $ppi = $this->siNo((string) ($crudos['tiene_ppi'] ?? ''));
        if ($ppi === null) $errores[] = 'La columna PPI admite Sí o No.';
        $ae = $this->siNo((string) ($crudos['tiene_ae'] ?? ''));
        if ($ae === null) $errores[] = 'La columna AE admite Sí o No.';

        $observaciones = $this->texto((string) ($crudos['observaciones'] ?? ''));
        foreach ([$correoMovido, $matriculaMovida] as $anotacion) {
            if ($anotacion === null) continue;
            $observaciones = $observaciones === '' ? $anotacion : $observaciones . ' — ' . $anotacion;
        }

        $datos = [
            'apellido' => $apellido,
            'nombre' => $nombre,
            'dni' => $dni,
            'genero' => $genero,
            'fecha_nacimiento' => $fecha === false ? null : $fecha,
            'nacionalidad' => $nacionalidad !== '' ? $nacionalidad : null,
            'matricula' => $matricula !== '' ? $matricula : null,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'anio' => $anio,
            'division' => $division,
            'grupo' => $grupo !== '' ? $grupo : null,
            'especialidad' => is_string($especialidad) && $especialidad !== '' ? $especialidad : null,
            'estado' => $estado === false ? null : ($estado ?? 'Activo'),
            'tiene_ppi' => (bool) $ppi,
            'tiene_ae' => (bool) $ae,
            'telefono' => $telefono !== '' ? $telefono : null,
            'direccion' => $direccion !== '' ? $direccion : null,
            'responsable' => [
                'apellido' => $respApellido,
                'nombre' => $respNombre,
                'parentesco' => $respParent,
                'telefono' => $respTel,
            ],
            'observaciones' => $observaciones !== '' ? $observaciones : null,
            'correo_movido' => $correoMovido,
            'matricula_movida' => $matriculaMovida,
        ];

        return [$datos, $errores];
    }

    /* ==================== normalizadores ==================== */

    /**
     * Si el texto de la columna "N° de matrícula" es de verdad un número.
     *
     * Acepta los separadores que usa un legajo escrito a mano (42.115/3, 00231).
     * Público porque la migración 2026_11_05_000006 usa exactamente el mismo
     * criterio para los alumnos que ya están cargados.
     */
    public static function esMatriculaNumero(string $v): bool
    {
        $v = trim($v);
        return $v !== '' && preg_match('#^\d[\d\s./-]*\z#', $v) === 1;
    }

    private function texto(string $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    }

    /** "AGUIRRE, Tiziano Jeremias" => ["AGUIRRE", "Tiziano Jeremias"]. */
    private function partirNombre(string $v): array
    {
        $t = $this->texto($v);
        if ($t === '') return ['', ''];
        if (str_contains($t, ',')) {
            [$pri, $seg] = array_pad(explode(',', $t, 2), 2, '');
            return [$this->texto($pri), $this->texto($seg)];
        }
        // Sin coma se asume que la primera palabra es el apellido, como en las
        // planillas viejas: "GOMEZ Ana Luisa".
        $palabras = explode(' ', $t);
        $apellido = array_shift($palabras);
        return [$apellido, $this->texto(implode(' ', $palabras))];
    }

    /** "1º Año 1º" / "4 ° 2º Electromecánica" => [anio, division]; "7" => [7, null]. */
    private function cursoAnioDivision(string $v): array
    {
        $t = $this->pareceClave($v);
        if ($t === '') return [null, null];
        preg_match_all('/\d+/', $t, $m);
        $numeros = array_map('intval', $m[0] ?? []);
        if (count($numeros) >= 2) {
            return [$numeros[0] >= 1 && $numeros[0] <= 20 ? $numeros[0] : null, (string) $numeros[1]];
        }
        return [$this->anio($t), null];
    }

    /** Mail limpio en minúsculas; null si viene vacío, false si no es un mail. */
    private function email(string $v)
    {
        $t = preg_replace('/\s+/u', '', $v) ?? '';
        if (stripos($t, 'mailto:') === 0) $t = substr($t, 7);
        $t = mb_strtolower(trim($t, " \t\n\r\0\x0B.,;"), 'UTF-8');
        if ($t === '' || $t === '-' || $t === 's/n') return null;
        if (mb_strlen($t) > 120 || substr_count($t, '@') !== 1 || !filter_var($t, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        return $t;
    }

    /** "35.123.456" / " 35123456 " => "35123456"; vacío => null. */
    private function normalizarDni(?string $v): ?string
    {
        if ($v === null) return null;
        $limpio = preg_replace('/[.\s-]/u', '', $v) ?? '';
        $limpio = trim($limpio);
        if ($limpio === '' || $limpio === '0') return null;
        if (is_numeric($limpio)) {
            $limpio = (string) (int) $limpio;
        }
        return $limpio;
    }

    private function genero(string $v): ?string
    {
        $t = $this->pareceClave($v);
        if ($t === '') return null;
        return self::GENEROS[$t] ?? null;
    }

    /** Devuelve 'Y-m-d', null si viene vacío o false si no se entiende. */
    private function fecha(string $v)
    {
        $t = $this->texto($v);
        if ($t === '') return null;

        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$#', $t, $m)) {
            $a = (int) $m[3];
            if ($a < 100) $a += ($a < 50) ? 2000 : 1900;
            return $this->armarFecha($a, (int) $m[2], (int) $m[1]);
        }
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $t, $m)) {
            return $this->armarFecha((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        // Excel guarda la fecha como cantidad de días desde 1900. Al leer sólo el
        // XML de la hoja no se sabe si la celda está formateada como fecha, así que
        // un número de cinco dígitos en la columna de nacimiento es una fecha.
        if (preg_match('#^\d{5}(\.\d+)?$#', $t)) {
            try {
                $d = ExcelDate::excelToDateTimeObject((float) $t);
                return $this->armarFecha((int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j'));
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false;
    }

    private function armarFecha(int $anio, int $mes, int $dia)
    {
        if (!checkdate($mes, $dia, $anio)) return false;
        if ($anio > (int) date('Y') || $anio < 1900) return false;
        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    /** "1°", "1ro", "1ra división", "4" => entero del año escolar. */
    private function anio(string $v): ?int
    {
        $t = mb_strtolower($this->pareceClave($v));
        if ($t === '') return null;
        if (preg_match('/\d+/', $t, $m)) {
            $n = (int) $m[0];
            return $n >= 1 && $n <= 20 ? $n : null;
        }
        $palabras = [
            'primer' => 1, 'primero' => 1, '1' => 1,
            'segundo' => 2, 'tercero' => 3, 'cuarto' => 4,
            'quinto' => 5, 'sexto' => 6, 'septimo' => 7,
        ];
        foreach ($palabras as $palabra => $n) {
            if (str_starts_with($t, $palabra)) return $n;
        }
        return null;
    }

    private function division(string $v): ?string
    {
        $t = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v) ?? '');
        if ($t === '') return null;
        return substr($t, 0, 1);
    }

    /** Acepta la clave o el rótulo; null si viene vacío, false si no existe. */
    private function especialidad(string $v, array $mapa)
    {
        $t = $this->pareceClave($v);
        if ($t === '') return null;
        return $mapa[$t] ?? false;
    }

    /** Activo / Egresado; null si viene vacío, false si no es un estado conocido. */
    private function estado(string $v)
    {
        $t = $this->pareceClave($v);
        if ($t === '') return null;
        if (in_array($t, ['activo', 'en curso', 'cursando', 'regular'], true)) return 'Activo';
        if (in_array($t, ['egresado', 'egresada', 'graduado', 'finalizado'], true)) return 'Egresado';
        return false;
    }

    /** true si es afirmativo, false si es negativo o vacío, null si no se entiende. */
    private function siNo(string $v)
    {
        $t = $this->pareceClave($v);
        if ($t === '') return false;
        if (in_array($t, self::SI, true)) return true;
        if (in_array($t, self::NO, true)) return false;
        return null;
    }

    /** Rótulo de encabezado comparable: minúsculas, sin acentos ni símbolos. */
    private function pareceClave(string $v): string
    {
        $t = mb_strtolower($this->sinAcentos($v), 'UTF-8');
        $t = str_replace(['*', '(', ')', '°', '·'], ' ', $t);
        $t = preg_replace('/[^a-z0-9 ]/u', ' ', $t) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    }

    private function sinAcentos(string $v): string
    {
        $mapa = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N'];
        return strtr($v, $mapa);
    }
}
