<?php

namespace Database\Seeders;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Calificaciones\AlumnoCalificacion;
use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\HorarioCelda;
use App\Dominios\Cursos\Materia;
use App\Dominios\Cursos\ParametroDivision;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Poblando datos de demostración...');

        // 1. Divisiones (A, B, C para años 1-6)
        $this->seedDivisiones();

        // 2. Cursos
        $this->seedCursos();

        // 3. Materias generales + por grupo
        $this->seedMaterias();

        // 4. Alumnos con grupo A/B/General
        $alumnos = $this->seedAlumnos();

        // 5. Horarios diferenciales por grupo (solo 1°A como demo)
        $this->seedHorarios();

        // 6. Calificaciones de ejemplo
        $this->seedCalificaciones($alumnos);

        $this->command->info('¡Datos de demostración creados!');
    }

    /* ----------------------------------------------------------- */
    /*  1. Divisiones                                               */
    /* ----------------------------------------------------------- */
    private function seedDivisiones(): void
    {
        $this->command->info('  Creando divisiones (1°-6°, 1/2/3)...');
        $map = [];
        for ($a = 1; $a <= 6; $a++) {
            foreach (['1', '2', '3'] as $d) {
                ParametroDivision::updateOrInsert(
                    ['anio' => $a, 'division' => $d],
                    ['activo' => true, 'created_at' => now(), 'updated_at' => now()]
                );
                $map[] = ['anio' => $a, 'division' => $d];
            }
        }
    }

    /* ----------------------------------------------------------- */
    /*  2. Cursos                                                   */
    /* ----------------------------------------------------------- */
    private function seedCursos(): void
    {
        $this->command->info('  Creando cursos...');
        $divs = ParametroDivision::where('activo', true)->get(['anio', 'division']);
        foreach ($divs as $d) {
            Curso::firstOrCreate(
                ['anio' => $d->anio, 'division' => $d->division],
                ['activo' => true]
            );
        }
    }

    /* ----------------------------------------------------------- */
    /*  3. Materias                                                 */
    /* ----------------------------------------------------------- */
    private function seedMaterias(): void
    {
        $this->command->info('  Creando materias...');

        // Generales (compartidas por todos los grupos)
        $generales = [
            [1, 3, ['Matemática', 'Lengua', 'Inglés', 'Ciencias Naturales', 'Ciencias Sociales', 'Educación Física', 'Formación Ética']],
            [4, 6, ['Matemática', 'Lengua', 'Inglés', 'Biología', 'Historia', 'Educación Física', 'Formación Ética']],
        ];

        // Grupo A — taller/especialidad
        $grupoA = [
            [1, 3, ['Taller de Huerta', 'Música']],
            [4, 6, ['Taller de Robótica', 'Teatro']],
        ];

        // Grupo B — taller/especialidad
        $grupoB = [
            [1, 3, ['Taller de Carpintería', 'Danza']],
            [4, 6, ['Taller de Gastronomía', 'Artes Visuales']],
        ];

        $materiaIdMap = []; // [anio][nombre] => id

        foreach ([[1, 6, $generales], [1, 6, $grupoA], [1, 6, $grupoB]] as [$minY, $maxY, $list]) {
            foreach ($list as [$lo, $hi, $nombres]) {
                for ($a = $lo; $a <= $hi; $a++) {
                    foreach ($nombres as $nom) {
                        $m = Materia::firstOrCreate(
                            ['anio' => $a, 'nombre' => $nom],
                            ['activo' => true]
                        );
                        $materiaIdMap[$a][$nom] = $m->id;
                    }
                }
            }
        }

        // Guardamos para uso posterior
        $this->materiaIdMap = $materiaIdMap;
    }

    /* ----------------------------------------------------------- */
    /*  4. Alumnos                                                  */
    /* ----------------------------------------------------------- */
    private function seedAlumnos(): array
    {
        $this->command->info('  Creando alumnos...');

        $nombres = [
            'Sofía', 'Mateo', 'Valentina', 'Benjamín', 'Camila',
            'Santiago', 'Isabella', 'Sebastián', 'Luciana', 'Facundo',
            'Martina', 'Joaquín', 'Emilia', 'Tomás', 'Julieta',
            'Nicolás', 'Catalina', 'Diego', 'Victoria', 'Lautaro',
        ];
        $apellidos = [
            'García', 'Rodríguez', 'Martínez', 'López', 'Fernández',
            'González', 'Pérez', 'Romero', 'Torres', 'Álvarez',
        ];
        $generos = ['F', 'M', 'M', 'F', 'M', 'F', 'F', 'M', 'F', 'M',
                    'F', 'M', 'F', 'M', 'F', 'M', 'F', 'M', 'F', 'M'];

        $alumnos = [];
        $dnisBase = 40000000;

        // Creamos alumnos para los primeros 9 cursos (1°1, 1°2, 1°3, 2°1, 2°2, 2°3, 3°1, 3°2, 3°3)
        $cursos = Curso::whereIn('anio', [1, 2, 3])
            ->whereIn('division', ['1', '2', '3'])
            ->orderBy('anio')->orderBy('division')
            ->get();

        $idx = 0;
        foreach ($cursos as $curso) {
            // 6 alumnos por curso: 2 Grupo A, 2 Grupo B, 2 General (sin grupo)
            $grupos = ['A', 'A', 'B', 'B', null, null];
            foreach ($grupos as $g) {
                $nombre = $nombres[$idx % count($nombres)];
                $apellido = $apellidos[($idx / count($nombres)) % count($apellidos)];
                $genero = $generos[$idx % count($generos)];
                $dni = (string)($dnisBase + $idx);

                $alumno = Alumno::create([
                    'nombre'           => $nombre,
                    'apellido'         => $apellido,
                    'genero'           => $genero,
                    'dni'              => $dni,
                    'fecha_nacimiento' => now()->subYears(6 + $curso->anio)->addDays($idx),
                    'anio'             => $curso->anio,
                    'division'         => $curso->division,
                    'grupo'            => $g,
                    'estado'           => 'Activo',
                ]);

                $alumnos[] = $alumno;
                $idx++;
            }
        }

        $this->command->info("    Creados " . count($alumnos) . " alumnos");
        return $alumnos;
    }

    /* ----------------------------------------------------------- */
    /*  5. Horarios diferenciales por grupo (solo 1°1 como demo)    */
    /* ----------------------------------------------------------- */
    private function seedHorarios(): void
    {
        $this->command->info('  Creando horarios diferenciales (1°1)...');

        $curso = Curso::where('anio', 1)->where('division', '1')->first();
        if (!$curso) return;

        $mat = $this->materiaIdMap[1] ?? [];

        // Días y slots
        $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes'];
        $slots = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

        // General (grupo=null)
        $generalHorario = [
            'h1' => ['Matemática', 'Lengua', 'Inglés', 'Matemática', 'Lengua'],
            'h2' => ['Lengua', 'Ciencias Naturales', 'Matemática', 'Lengua', 'Ciencias Sociales'],
            'h3' => ['Inglés', 'Educación Física', 'Ciencias Sociales', 'Ciencias Naturales', 'Educación Física'],
            'h4' => ['Ciencias Naturales', 'Matemática', 'Lengua', 'Inglés', 'Formación Ética'],
            'h5' => ['Ciencias Sociales', 'Formación Ética', 'Educación Física', 'Taller de Huerta', 'Taller de Carpintería'],
            'h6' => ['Educación Física', null, null, 'Música', 'Danza'],
        ];

        // Grupo A (sobrescribe slots de taller)
        $grupoAHorario = [
            'h5' => [null, null, null, 'Taller de Huerta', 'Música'],
            'h6' => [null, null, null, 'Música', 'Taller de Huerta'],
        ];

        // Grupo B
        $grupoBHorario = [
            'h5' => [null, null, null, 'Taller de Carpintería', 'Danza'],
            'h6' => [null, null, null, 'Danza', 'Taller de Carpintería'],
        ];

        $insert = [];

        foreach ($dias as $diaNum => $diaLabel) {
            foreach ($slots as $slot) {
                // General
                $matNombre = $generalHorario[$slot][$diaNum - 1] ?? null;
                if ($matNombre && isset($mat[$matNombre])) {
                    $insert[] = [
                        'curso_id'   => $curso->id,
                        'dia'        => $diaNum,
                        'slot_key'   => $slot,
                        'materia_id' => $mat[$matNombre],
                        'grupo'      => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Grupo A
                $matANombre = $grupoAHorario[$slot][$diaNum - 1] ?? $generalHorario[$slot][$diaNum - 1] ?? null;
                if ($matANombre && isset($mat[$matANombre])) {
                    $insert[] = [
                        'curso_id'   => $curso->id,
                        'dia'        => $diaNum,
                        'slot_key'   => $slot,
                        'materia_id' => $mat[$matANombre],
                        'grupo'      => 'A',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Grupo B
                $matBNombre = $grupoBHorario[$slot][$diaNum - 1] ?? $generalHorario[$slot][$diaNum - 1] ?? null;
                if ($matBNombre && isset($mat[$matBNombre])) {
                    $insert[] = [
                        'curso_id'   => $curso->id,
                        'dia'        => $diaNum,
                        'slot_key'   => $slot,
                        'materia_id' => $mat[$matBNombre],
                        'grupo'      => 'B',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        // Limpieza previa
        HorarioCelda::where('curso_id', $curso->id)->delete();

        foreach ($insert as $row) {
            HorarioCelda::create($row);
        }

        $this->command->info("    Creadas " . count($insert) . " celdas de horario");
    }

    /* ----------------------------------------------------------- */
    /*  6. Calificaciones de ejemplo                                */
    /* ----------------------------------------------------------- */
    private function seedCalificaciones($alumnos): void
    {
        $this->command->info('  Creando calificaciones de ejemplo...');

        $ciclo = now()->year;

        foreach ($alumnos as $alumno) {
            $mat = $this->materiaIdMap[$alumno->anio] ?? [];
            if (empty($mat)) continue;

            // Elegir 4 materias al azar para poner notas
            $keys = array_keys($mat);
            shuffle($keys);
            $elegidas = array_slice($keys, 0, min(4, count($keys)));

            $insert = [];
            foreach ($elegidas as $matNombre) {
                $materiaId = $mat[$matNombre];

                // P1 y P2
                for ($periodo = 1; $periodo <= 2; $periodo++) {
                    $valor = rand(6, 10); // notas entre 6 y 10
                    $dia = rand(1, 28);
                    $mes = $periodo === 1 ? rand(3, 6) : rand(9, 11);
                    $fecha = sprintf('%d-%02d-%02d', $ciclo, $mes, $dia);

                    $insert[] = [
                        'alumno_id'     => $alumno->id,
                        'materia_id'    => $materiaId,
                        'anio'          => $alumno->anio,
                        'division'      => $alumno->division,
                        'valor'         => $valor,
                        'fecha'         => $fecha,
                        'ciclo_lectivo' => $ciclo,
                        'periodo'       => $periodo,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
            }

            if (!empty($insert)) {
                DB::table('alumno_calificaciones')->insert($insert);
            }
        }

        $this->command->info('    Calificaciones creadas');
    }
}
