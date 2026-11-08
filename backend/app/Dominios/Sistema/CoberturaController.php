<?php

// Revisión de cobertura del sistema: los errores que no se ven en pantalla.
// Un curso puede tener horario completo, materias y alumnos y aun así nadie
// poder pasar la lista, porque ninguna cuenta tiene alcance de edición sobre
// él. Del mismo modo una cuenta de preceptor sin curso asignado ve todo pero no
// puede corregir nada, y un docente con horario pero sin cuenta nunca va a
// poder cargar sus notas. Todo esto es válido en la base de datos y por eso
// ningún formulario lo rechaza: se detecta cruzando cursos, horarios, fichas de
// profesor, cuentas y la configuración de alcance de cada rol.

namespace App\Dominios\Sistema;

use App\Http\Controllers\Controller;
use App\Support\Alcances;
use App\Support\CourseLabel;
use App\Support\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoberturaController extends Controller
{
    /** Áreas que definen si una cuenta puede escribir sobre un curso. */
    private const AREAS = ['alumnos', 'asistencia', 'calificaciones'];

    public function index(Request $req): JsonResponse
    {
        if (!Permisos::puede((string) $req->user()->role, 'sistema.cobertura')) {
            abort(403, 'No tenés permiso para ver la revisión del sistema.');
        }

        $cursos  = $this->cursos();
        $alcance = $this->alcancePorCuenta();

        $problemas = [
            $this->cursosSinCobertura($cursos, $alcance),
            $this->cursosSinResponsable($cursos, $alcance),
            $this->cuentasSinCurso($alcance),
            $this->docentesSinCuenta(),
            $this->horarioFueraDeFicha(),
            $this->celdasSinDocente($cursos),
            $this->cursosFueraDelCatalogo($cursos),
            $this->alumnosSinCurso(),
            $this->cursosSinAlumnos($cursos),
            $this->asistenciasFueraDeCurso(),
        ];

        $problemas = array_values(array_filter($problemas));

        return response()->json([
            'generado'  => now()->toDateTimeString(),
            'total'     => array_sum(array_map(fn ($p) => count($p['items']), $problemas)),
            'problemas' => $problemas,
        ]);
    }

    // ------------------------------------------------------------------
    // Datos base
    // ------------------------------------------------------------------

    /** Cursos activos con su cantidad de alumnos y sus celdas de horario. */
    private function cursos(): array
    {
        $out = [];
        $filas = DB::select('
            select c.anio, c.division,
                   (select count(*) from alumnos a
                     where a.anio = c.anio and a.division = c.division
                       and a.estado not in ("Egresado","Inactivo")) alumnos,
                   (select count(*) from horario_celdas h
                     where h.curso_id = c.id) celdas,
                   (select count(*) from horario_celdas h
                     where h.curso_id = c.id and (h.profesor_id is null or h.profesor_id = 0)) celdas_sin_docente
            from cursos c
            where c.activo = 1
            order by c.anio, c.division');

        foreach ($filas as $f) {
            $div = strtoupper(trim((string) $f->division));
            $out[] = [
                'anio'  => (int) $f->anio,
                'div'   => $div,
                'label' => CourseLabel::label((int) $f->anio, $div),
                'alumnos' => (int) $f->alumnos,
                'celdas'  => (int) $f->celdas,
                'celdas_sin_docente' => (int) $f->celdas_sin_docente,
            ];
        }
        return $out;
    }

    /**
     * Qué puede escribir cada cuenta activa y sobre qué cursos. Es el mismo
     * criterio que AuthorizesScope (asignaciones de la cuenta + ficha de profesor
     * vinculada por email + suplencias), así la revisión no se pelea con lo que
     * el sistema realmente permite.
     *
     * @return array<int, array{usuario: string, rol: string, modo: string, pares: array}>
     */
    private function alcancePorCuenta(): array
    {
        $usuarios = DB::table('users')
            ->where('status', 'active')
            ->get(['id', 'name', 'apellido', 'role', 'email']);

        $porCorreo = [];
        foreach ($usuarios as $u) {
            $correo = strtolower(trim((string) $u->email));
            if ($correo !== '') $porCorreo[$correo][] = (int) $u->id;
        }

        // Cursos de las fichas de profesor, indexados por email.
        $fichas = [];
        $filas = DB::select('
            select lower(trim(p.email)) correo, pa.anio, pa.division
            from profesor_asignaciones pa
            join profesores p on p.id = pa.profesor_id
            where pa.deleted_at is null and p.deleted_at is null and p.email <> ""
            union
            select lower(trim(p.email)), s.anio, s.division
            from profesor_suplencias s
            join profesores p on p.id = s.suplente_id
            where p.deleted_at is null and p.email <> ""');
        foreach ($filas as $f) {
            foreach ($porCorreo[$f->correo] ?? [] as $uid) {
                $fichas[$uid][] = [(int) $f->anio, $this->div($f->division)];
            }
        }

        $paresCuenta = [];
        foreach (DB::table('user_anio_division')->get(['user_id', 'anio', 'division']) as $r) {
            $paresCuenta[(int) $r->user_id][] = [(int) $r->anio, $this->div($r->division)];
        }

        $out = [];
        foreach ($usuarios as $u) {
            $uid = (int) $u->id;
            $rol = (string) $u->role;

            $modo = 'nada';
            foreach (self::AREAS as $area) {
                $alcance = Alcances::editar($rol, $area);
                if ($alcance === Alcances::TODO) { $modo = 'todo'; break; }
                if ($alcance === Alcances::ASIGNADO) $modo = 'pares';
            }

            $mezcla = array_merge($paresCuenta[$uid] ?? [], $fichas[$uid] ?? []);
            $unicos = [];
            foreach ($mezcla as $p) {
                $unicos[$p[0] . '|' . (string) ($p[1] ?? '')] = $p;
            }
            $lista = array_values($unicos);
            usort($lista, fn ($a, $b) => [$a[0], (string) ($a[1] ?? '')] <=> [$b[0], (string) ($b[1] ?? '')]);

            $out[$uid] = [
                'usuario' => trim($u->apellido . ', ' . $u->name),
                'rol'     => $rol,
                'modo'    => $modo,
                'pares'   => $lista,
            ];
        }
        return $out;
    }

    private function div($division): ?string
    {
        $div = strtoupper(trim((string) $division));
        return $div === '' ? null : $div;
    }

    /** ¿La cuenta puede escribir en ese curso? */
    private function cubre(array $dato, int $anio, string $division): bool
    {
        if ($dato['modo'] === 'todo') return true;
        if ($dato['modo'] !== 'pares') return false;
        foreach ($dato['pares'] as $p) {
            if ($p[0] !== $anio) continue;
            if ($p[1] === null || $p[1] === $division) return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Problemas
    // ------------------------------------------------------------------

    /** Cursos activos con alumnos y ninguna cuenta que pueda pasarles lista. */
    private function cursosSinCobertura(array $cursos, array $alcance): ?array
    {
        $items = [];
        foreach ($cursos as $c) {
            if ($c['alumnos'] === 0) continue;
            $nadie = true;
            foreach ($alcance as $dato) {
                if ($this->cubre($dato, $c['anio'], $c['div'])) { $nadie = false; break; }
            }
            if ($nadie) $items[] = ['curso' => $c['label'], 'alumnos' => $c['alumnos'], 'celdas' => $c['celdas']];
        }

        return $this->bloque('cursos_sin_cobertura', [
            'nivel'    => 'alto',
            'titulo'   => 'Cursos que ninguna cuenta puede modificar',
            'que_pasa' => 'Nadie, ni la dirección, tiene permiso para editar estos cursos: no se les puede pasar lista, corregir un alumno ni cargar notas.',
            'arreglo'  => 'En Usuarios › Roles y permisos, subí el alcance de edición del rol (Alumnos, Asistencia o Calificaciones) a "Todos los cursos".',
            'ir_a'     => '/admin/usuarios?vista=roles',
            'boton'    => 'Abrir Roles y permisos',
        ], $items);
    }

    /**
     * Cursos que sólo puede editar la administración (alcance 'todos'), porque
     * ninguna cuenta de a pie —preceptores, profesores, roles personalizados con
     * alcance 'solo asignados— los tiene cargados. No es un bloqueo absoluto, pero
     * en la práctica nadie los pasa lista: la dirección no lo hace día por día.
     */
    private function cursosSinResponsable(array $cursos, array $alcance): ?array
    {
        $operativos = array_filter($alcance, fn ($d) => $d['modo'] === 'pares');

        $items = [];
        foreach ($cursos as $c) {
            if ($c['alumnos'] === 0) continue;
            $nadie = true;
            foreach ($operativos as $dato) {
                if ($this->cubre($dato, $c['anio'], $c['div'])) { $nadie = false; break; }
            }
            if ($nadie) $items[] = ['curso' => $c['label'], 'alumnos' => $c['alumnos']];
        }

        return $this->bloque('cursos_sin_responsable', [
            'nivel'    => 'alto',
            'titulo'   => 'Cursos sin preceptor ni docente asignado',
            'que_pasa' => 'Estos cursos sólo los puede editar la administración; ningún preceptor ni profesor los tiene cargados. En la práctica nadie les pasa lista día por día.',
            'arreglo'  => 'Asigná cada curso a la cuenta que corresponde, o cargalo en la ficha del profesor que lo tiene a su cargo.',
            'ir_a'     => '/admin/usuarios',
            'boton'    => 'Asignar los cursos a las cuentas',
        ], $items);
    }

    /** Cuentas atadas a "solo lo asignado" que no tienen ningún curso. */
    private function cuentasSinCurso(array $alcance): ?array
    {
        $items = [];
        foreach ($alcance as $dato) {
            if ($dato['modo'] !== 'pares' || $dato['pares'] !== []) continue;
            $items[] = ['usuario' => $dato['usuario'], 'rol' => $dato['rol']];
        }

        return $this->bloque('cuentas_sin_curso', [
            'nivel'    => 'alto',
            'titulo'   => 'Cuentas que no pueden cargar nada',
            'que_pasa' => 'Estas cuentas sólo pueden escribir en sus cursos asignados y no tienen ninguno: entran al sistema y no encuentran dónde pasar lista ni editar un alumno.',
            'arreglo'  => 'Asignales el curso o la división que les toca desde la ficha de cada cuenta.',
            'ir_a'     => '/admin/usuarios',
            'boton'    => 'Asignar los cursos a las cuentas',
        ], $items);
    }

    /** Docentes con horas en el horario que no pueden iniciar sesión. */
    private function docentesSinCuenta(): ?array
    {
        $filas = DB::select('
            select p.apellido, p.nombre, p.email, count(h.id) celdas
            from profesores p
            join horario_celdas h on h.profesor_id = p.id
            left join users u on lower(trim(u.email)) = lower(trim(p.email)) and u.status = "active"
            where p.deleted_at is null and p.email <> "" and u.id is null
            group by p.apellido, p.nombre, p.email
            order by celdas desc');

        $items = array_map(fn ($f) => [
            'docente' => trim($f->apellido . ', ' . $f->nombre),
            'email'   => (string) $f->email,
            'celdas'  => (int) $f->celdas,
        ], $filas);

        return $this->bloque('docentes_sin_cuenta', [
            'nivel'    => 'medio',
            'titulo'   => 'Docentes con horario y sin cuenta',
            'que_pasa' => 'Aparecen repartidos en los horarios, pero no tienen una cuenta con ese email: no pueden entrar a cargar sus notas ni su asistencia.',
            'arreglo'  => 'Creales la cuenta con el mismo email de la ficha, o corregí el email de la ficha para que coincida con la cuenta que ya existe.',
            'ir_a'     => '/admin/usuarios',
            'boton'    => 'Crear las cuentas',
        ], $items);
    }

    /** Franjas del horario cuya materia no está en la ficha del docente. */
    private function horarioFueraDeFicha(): ?array
    {
        $filas = DB::select('
            select c.anio, c.division, m.nombre materia, p.apellido, p.nombre, count(*) celdas
            from horario_celdas h
            join cursos c on c.id = h.curso_id
            join materias m on m.id = h.materia_id
            join profesores p on p.id = h.profesor_id
            where c.activo = 1
              and not exists (
                    select 1 from profesor_asignaciones pa
                    where pa.profesor_id = h.profesor_id
                      and pa.materia_id = h.materia_id
                      and pa.anio = c.anio
                      and pa.deleted_at is null
                      and (pa.division = c.division or pa.division is null))
            group by c.anio, c.division, m.nombre, p.apellido, p.nombre
            order by c.anio, c.division, m.nombre');

        $items = array_map(fn ($f) => [
            'curso'   => CourseLabel::label((int) $f->anio, $this->div($f->division)),
            'materia' => (string) $f->materia,
            'docente' => trim($f->apellido . ', ' . $f->nombre),
            'celdas'  => (int) $f->celdas,
        ], $filas);

        return $this->bloque('horario_fuera_de_ficha', [
            'nivel'    => 'medio',
            'titulo'   => 'El horario dice más que la ficha del docente',
            'que_pasa' => 'El horario le asigna una materia que el docente no tiene cargada en su ficha. Puede pasar lista y cargar notas, pero otras pantallas no lo muestran como profesor de esa materia.',
            'arreglo'  => 'Completá la ficha del profesor con esa materia y ese curso.',
            'ir_a'     => '/profesores',
            'boton'    => 'Completar fichas de profesores',
        ], $items);
    }

    /** Franjas del horario sin profesor asignado. */
    private function celdasSinDocente(array $cursos): ?array
    {
        $items = [];
        foreach ($cursos as $c) {
            if ($c['celdas_sin_docente'] === 0) continue;
            $items[] = ['curso' => $c['label'], 'celdas' => $c['celdas_sin_docente']];
        }

        return $this->bloque('celdas_sin_docente', [
            'nivel'    => 'medio',
            'titulo'   => 'Franjas del horario sin docente',
            'que_pasa' => 'Hay materias ubicadas en el horario que no tienen profesor asignado: en esa franja nadie pasa lista ni carga notas.',
            'arreglo'  => 'Cargá el docente en el horario del curso.',
            'ir_a'     => '/cursos',
            'boton'    => 'Completar el horario',
        ], $items);
    }

    /**
     * Cursos activos que no están en el catálogo de divisiones (parametro_divisiones).
     * El selector de Asistencia para los roles que ven todo se dibuja con ese
     * catálogo, no con la tabla de cursos: una división que falta o está dada de
     * baja desaparece de la pantalla aunque el curso exista y tenga alumnos, y
     * nadie puede pasar lista desde la interfaz.
     */
    private function cursosFueraDelCatalogo(array $cursos): ?array
    {
        $catalogo = [];
        foreach (DB::table('parametro_divisiones')->where('activo', 1)->get(['anio', 'division']) as $p) {
            $catalogo[(int) $p->anio . '|' . $this->div($p->division)] = true;
        }

        $items = [];
        foreach ($cursos as $c) {
            if ($c['alumnos'] === 0) continue;
            if (isset($catalogo[$c['anio'] . '|' . $c['div']])) continue;
            $items[] = ['curso' => $c['label'], 'alumnos' => $c['alumnos']];
        }

        return $this->bloque('cursos_fuera_del_catalogo', [
            'nivel'    => 'alto',
            'titulo'   => 'Cursos que no aparecen en el Asistencia',
            'que_pasa' => 'El curso existe y tiene alumnos, pero su división no está dada de alta (o está inactiva) en el catálogo de divisiones. Quien ve todos los cursos arma la lista desde ese catálogo, así que el curso no aparece en pantalla y no se le puede pasar lista.',
            'arreglo'  => 'Dalá de alta la división en Cursos › Divisiones.',
            'ir_a'     => '/cursos',
            'boton'    => 'Dar de alta la división',
        ], $items);
    }

    /** Alumnos activos cuyo curso no existe. */
    private function alumnosSinCurso(): ?array
    {
        $filas = DB::select('
            select a.anio, a.division, count(*) n
            from alumnos a
            left join cursos c on c.anio = a.anio and c.division = a.division
            where a.estado not in ("Egresado","Inactivo") and c.id is null
            group by a.anio, a.division
            order by a.anio, a.division');

        $items = array_map(fn ($f) => [
            'curso'   => CourseLabel::label((int) $f->anio, $this->div($f->division)),
            'alumnos' => (int) $f->n,
        ], $filas);

        return $this->bloque('alumnos_sin_curso', [
            'nivel'    => 'alto',
            'titulo'   => 'Alumnos en un curso que no existe',
            'que_pasa' => 'Estos alumnos tienen año y división cargados, pero no hay curso dado de alta: no aparecen en la lista de asistencia ni en las planillas de notas.',
            'arreglo'  => 'Creá el curso en Cursos, o corregí el año y la división del alumno en su ficha.',
            'ir_a'     => '/cursos',
            'boton'    => 'Crear el curso',
        ], $items);
    }

    /** Cursos activos con horario pero sin alumnos. */
    private function cursosSinAlumnos(array $cursos): ?array
    {
        $items = [];
        foreach ($cursos as $c) {
            if ($c['alumnos'] > 0 || $c['celdas'] === 0) continue;
            $items[] = ['curso' => $c['label'], 'celdas' => $c['celdas']];
        }

        return $this->bloque('cursos_sin_alumnos', [
            'nivel'    => 'bajo',
            'titulo'   => 'Cursos con horario y sin alumnos',
            'que_pasa' => 'Tienen horario cargado pero ningún alumno cursando. Suelen ser divisiones que no arrancaron este ciclo.',
            'arreglo'  => 'Si no van a arrancar, desactivá la división en Cursos › Divisiones para que no llene los selectores.',
            'ir_a'     => '/cursos',
            'boton'    => 'Revisar las divisiones',
        ], $items);
    }

    /**
     * Clases marcadas con alumnos que hoy no pertenecen a ese curso.
     * Sucede después de una promoción o de un cambio de división: el listado de
     * clases sigue contando esas marcas, pero al abrir la clase la planilla queda
     * vacía porque se arma con los alumnos actuales del curso.
     */
    private function asistenciasFueraDeCurso(): ?array
    {
        $filas = DB::select('
            select c.id, c.fecha, c.numero_clase, cu.anio, cu.division,
                   sum(case when al.id is null or al.anio <> cu.anio or al.division <> cu.division
                            then 1 else 0 end) fuera
            from clases c
            join cursos cu on cu.id = c.curso_id
            join asistencias a on a.clase_id = c.id
            left join alumnos al on al.id = a.alumno_id
            group by c.id, c.fecha, c.numero_clase, cu.anio, cu.division
            having fuera > 0
            order by c.fecha desc
            limit 200');

        $items = array_map(fn ($f) => [
            'curso'  => CourseLabel::label((int) $f->anio, $this->div($f->division)),
            'clase'  => 'N.°' . (int) $f->numero_clase,
            'fecha'  => (string) $f->fecha,
            'marcas' => (int) $f->fuera,
        ], $filas);

        return $this->bloque('asistencias_fuera_de_curso', [
            'nivel'    => 'medio',
            'titulo'   => 'Asistencia marcada con alumnos que ya no están en el curso',
            'que_pasa' => 'Son clases con marcas de alumnos que hoy están en otro curso, por una promoción o un cambio de división. Al abrir la clase la planilla aparece vacía aunque el listado muestre marcas.',
            'arreglo'  => 'Revisá el curso actual de esos alumnos antes de volver a pasar lista sobre esa fecha.',
            'ir_a'     => '/asistencia',
            'boton'    => 'Ver el Asistencia',
        ], $items);
    }

    /**
     * Un tema de la revisión. Sin casos no se manda nada: la tarjeta del inicio
     * sólo muestra lo que hay que atender. El texto viene armado, no traducido
     * de nombres de tabla, para que se lea como una indicación de secretaría.
     */
    private function bloque(string $clave, array $texto, array $items): ?array
    {
        return $items === [] ? null : array_merge(['clave' => $clave, 'items' => $items], $texto);
    }
}
