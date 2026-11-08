<?php

// Trait de autorizacion por ambito. Dos filtros en serie: el permiso (Permisos,
// "esta accion esta habilitada?") y el alcance (Alcances, "sobre que datos?").
// Ambos se pueden configurar por rol en la pantalla "Roles y permisos"; sin
// configuracion guardada cada rol usa su defecto, que reproduce el
// comportamiento historico del sistema.

namespace App\Support;

use App\Dominios\Alumnos\Alumno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait AuthorizesScope
{
    /** Cursos de la ficha profesional, memorizados por usuario dentro del request. */
    private array $fichaProfesoralMemo = [];

    protected function rolDe(Request $request): string
    {
        return (string) $request->user()->role;
    }

    /** Alcance de lectura del rol sobre un area ('todos' | 'asignados' | 'ninguno'). */
    protected function alcanceVer(Request $request, string $area): string
    {
        return Alcances::ver($this->rolDe($request), $area);
    }

    /** Alcance de escritura del rol sobre un area. */
    protected function alcanceEditar(Request $request, string $area): string
    {
        return Alcances::editar($this->rolDe($request), $area);
    }

    /** El rol ve TODOS los datos del area, sin recortar por curso. */
    protected function veTodo(Request $request, string $area): bool
    {
        return $this->alcanceVer($request, $area) === Alcances::TODO;
    }

    /** El rol puede modificar TODOS los datos del area. */
    protected function editaTodo(Request $request, string $area): bool
    {
        return $this->alcanceEditar($request, $area) === Alcances::TODO;
    }

    /** El rol tiene acceso de lectura al area, aunque sea solo a lo suyo. */
    protected function puedeVer(Request $request, string $area): bool
    {
        return $this->alcanceVer($request, $area) !== Alcances::NADA;
    }

    /** El rol tiene acceso de escritura al area, aunque sea solo a lo suyo. */
    protected function puedeEditar(Request $request, string $area): bool
    {
        return $this->alcanceEditar($request, $area) !== Alcances::NADA;
    }

    /**
     * 403 si el rol no puede LEER los datos de ese curso.
     * 'todos' pasa siempre; 'asignados' exige pertenecer al curso; 'ninguno' bloquea.
     */
    protected function assertPuedeVerCurso(Request $request, $anio, ?string $division, string $area): void
    {
        switch ($this->alcanceVer($request, $area)) {
            case Alcances::TODO:
                return;
            case Alcances::ASIGNADO:
                if ($this->userIsAssignedTo($request, (int) $anio, $division)) return;
                break;
        }

        abort(403, 'No tenés permiso para ver este recurso.');
    }

    /** 403 si el rol no puede MODIFICAR los datos de ese curso. */
    protected function assertPuedeEditarCurso(Request $request, $anio, ?string $division, string $area): void
    {
        switch ($this->alcanceEditar($request, $area)) {
            case Alcances::TODO:
                return;
            case Alcances::ASIGNADO:
                if ($this->userIsAssignedTo($request, (int) $anio, $division)) return;
                break;
        }

        abort(403, 'No tenés permiso para modificar este recurso.');
    }

    /** Lanza 403 si el usuario no puede ver los datos de ese curso. */
    protected function assertCanReadCurso(Request $request, $anio, ?string $division, string $area = 'alumnos'): void
    {
        $this->assertPuedeVerCurso($request, $anio, $division, $area);
    }

    /** Lanza 403 si el usuario no puede modificar los datos de ese curso. */
    protected function assertCanWriteDivision(Request $request, int $anio, ?string $division, string $area = 'cursos'): void
    {
        $this->assertPuedeEditarCurso($request, $anio, $division, $area);
    }

    /** Lanza 403 si el usuario no tiene permiso para modificar los datos del alumno. */
    protected function assertCanWriteAlumno(Request $request, Alumno $alumno): void
    {
        $this->assertPuedeEditarCurso($request, $alumno->anio, $alumno->division, 'alumnos');
    }

    /** Permiso de carga de notas, según el rango del rol o su fila en roles_config. */
    protected function userCanGrade(Request $request): bool
    {
        return Permisos::puede($this->rolDe($request), 'calificaciones.editar');
    }

    /**
     * Lanza 403 si el usuario no tiene permiso para calificar al alumno en su curso.
     * Con $materiaId, además exige que la materia esté dentro de las asignaciones
     * del profesor para ese curso.
     */
    protected function assertCanGradeAlumno(Request $request, Alumno $alumno, ?int $materiaId = null): void
    {
        if (!$this->userCanGrade($request)) {
            abort(403, 'No tenés permiso para cargar notas.');
        }

        $this->assertPuedeEditarCurso($request, $alumno->anio, $alumno->division, 'calificaciones');

        if (!$this->materiaDentroDeAsignaciones($request, (int) $alumno->anio, $alumno->division, $materiaId)) {
            abort(403, 'No tenés permiso para cargar notas en este curso.');
        }
    }

    /**
     * ¿Puede este usuario escribir notas de ESA materia en ESE curso?
     * Solo restringe al alcance "solo los asignados": un rol que ve todos los
     * cursos tampoco está atado a las materias de su ficha. Y si la ficha todavía
     * no tiene asignaciones cargadas para ese curso se conserva el alcance por
     * curso de siempre, porque bloquear a los docentes aún no dados de alta en
     * profesor_asignaciones dejaría el sistema sin carga de notas.
     * Cuando la ficha sí tiene datos pero le falta esa materia, alcanzan la
     * suplencia vigente o la materia dictada en el horario del curso.
     */
    protected function materiaDentroDeAsignaciones(Request $request, int $anio, ?string $division, ?int $materiaId): bool
    {
        if ($materiaId === null) return true;
        if ($this->alcanceVer($request, 'calificaciones') !== Alcances::ASIGNADO) return true;

        $profesor = \App\Dominios\Profesores\Profesor::where('email', $request->user()->email)->first();
        if (!$profesor) return true;

        $div = $this->normalizarDivision($division);

        // ProfesorAsignacion no usa SoftDeletes, así que hay que filtrar a mano.
        $materias = \App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $profesor->id)
            ->whereNull('deleted_at')
            ->where('anio', $anio)
            ->where(function ($q) use ($div) {
                $q->where('division', $div)->orWhereNull('division');
            })
            ->pluck('materia_id');

        if ($materias->isEmpty() || $materias->contains(null)) return true;
        if ($materias->contains($materiaId)) return true;

        // Las materias que cubre como suplente también son de su alcance.
        if (\App\Support\Suplencias::cubreMateria($profesor->id, $materiaId, $anio, $div)) return true;

        // Y las que dicta de hecho: si la materia está en el horario del curso
        // con este docente en alguna franja, la ficha de asignaciones quedó
        // incompleta pero el vínculo es real. Sin este paso, un horario cargado
        // a mano (o una ficha que no se actualizó) deja al profesor afuera de su
        // propia planilla con un 403 y sin ninguna manera de ver por qué.
        return DB::table('horario_celdas')
            ->join('cursos', 'cursos.id', '=', 'horario_celdas.curso_id')
            ->where('cursos.anio', $anio)
            ->when($div !== null, fn ($q) => $q->where('cursos.division', $div))
            ->where('horario_celdas.materia_id', $materiaId)
            ->where('horario_celdas.profesor_id', $profesor->id)
            ->exists();
    }

    /**
     * ¿El usuario pertenece a ese curso? Une las asignaciones de la cuenta
     * (user_anio_division) con los cursos de su ficha de profesor y las
     * suplencias vigentes. El rango del rol ya no interviene: lo que decide si el
     * rango alcanza es el mapa de Alcances.
     */
    protected function userIsAssignedTo(Request $request, int $anio, ?string $division): bool
    {
        $div = $this->normalizarDivision($division);

        $asignada = $request->user()->anioDivisiones()
            ->where('anio', $anio)
            ->where(function ($q) use ($div) {
                $q->where('division', $div)->orWhereNull('division');
            })
            ->exists();
        if ($asignada) return true;

        foreach ($this->cursosDeFichaProfesoral($request) as $curso) {
            if ($curso['anio'] !== $anio) continue;
            if ($curso['division'] === null || $curso['division'] === $div) return true;
        }

        return false;
    }

    /**
     * Cursos de la ficha profesional (tabla profesores, vinculada por email con la
     * cuenta) más los que cubre como suplente. Se calcula una sola vez por
     * usuario y request.
     */
    protected function cursosDeFichaProfesoral(Request $request): array
    {
        $userId = (int) $request->user()->id;
        if (array_key_exists($userId, $this->fichaProfesoralMemo)) {
            return $this->fichaProfesoralMemo[$userId];
        }

        $out = [];
        $profesor = \App\Dominios\Profesores\Profesor::where('email', $request->user()->email)->first();
        if ($profesor) {
            foreach (\App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $profesor->id)
                         ->whereNull('deleted_at')
                         ->get(['anio', 'division']) as $r) {
                $out[] = ['anio' => (int) $r->anio, 'division' => $this->normalizarDivision($r->division)];
            }
            foreach (\App\Support\Suplencias::coberturasDe($profesor->id) as $c) {
                $out[] = ['anio' => (int) $c['anio'], 'division' => $this->normalizarDivision($c['division'])];
            }
        }

        return $this->fichaProfesoralMemo[$userId] = $out;
    }

    /**
     * Pares (anio, division) asignados al usuario, uniendo user_anio_division y
     * la ficha profesional. division null = todo el año. Devuelve lista dedupicada.
     */
    protected function assignedPairs(Request $request): array
    {
        $pairs = [];
        foreach ($request->user()->anioDivisiones()->get(['anio', 'division']) as $r) {
            $pairs[] = ['anio' => (int) $r->anio, 'division' => $this->normalizarDivision($r->division)];
        }

        foreach ($this->cursosDeFichaProfesoral($request) as $curso) {
            $pairs[] = $curso;
        }

        $uniq = [];
        foreach ($pairs as $p) {
            $k = $p['anio'] . '|' . (string) ($p['division'] ?? '');
            $uniq[$k] = $p;
        }
        return array_values($uniq);
    }

    private function normalizarDivision(?string $division): ?string
    {
        if ($division === null) return null;
        $div = strtoupper(trim($division));
        return $div === '' ? null : $div;
    }

    /**
     * Restringe un query a los pares (anio, division) del usuario. Un par con
     * division null abre todo el año.
     */
    private function filtrarPorPares($query, array $pairs): void
    {
        if (!$pairs) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($q) use ($pairs) {
            foreach ($pairs as $p) {
                $q->orWhere(function ($qq) use ($p) {
                    $qq->where('anio', $p['anio']);
                    if ($p['division'] !== null && $p['division'] !== '') {
                        $qq->where('division', $p['division']);
                    }
                });
            }
        });
    }

    /**
     * Restringe un query de alumnos al alcance del rol para esa area: 'todos' no
     * filtra, 'ninguno' devuelve 403 y 'asignados' deja solo sus cursos.
     */
    protected function scopeAlumnoIndex(Request $request, $query, string $area = 'alumnos'): void
    {
        $alcance = $this->alcanceVer($request, $area);

        if ($alcance === Alcances::TODO) return;

        if ($alcance === Alcances::NADA) {
            abort(403, 'No tenés permiso para ver el padrón de alumnos.');
        }

        $this->filtrarPorPares($query, $this->assignedPairs($request));
    }

    /** Restringe un query de cursos/horarios al alcance del rol para esa area. */
    protected function scopeCursoIndex(Request $request, $query, string $area = 'cursos'): void
    {
        $alcance = $this->alcanceVer($request, $area);

        if ($alcance === Alcances::TODO) return;

        if ($alcance === Alcances::NADA) {
            abort(403, 'No tenés permiso para ver los cursos.');
        }

        $this->filtrarPorPares($query, $this->assignedPairs($request));
    }

    /**
     * Ficha completa (datos personales: DNI, estado, observaciones) solo para
     * quien tenga el permiso de datos personales. Los rangos bajos reciben
     * contacto (nombre/email) y cursos asignados.
     */
    protected function userCanSeeFullFicha(Request $request): bool
    {
        return Permisos::puede($this->rolDe($request), 'ficha.datos_personales');
    }

    /**
     * Exige confirmación de contraseña reciente (server-side, 5 min) para
     * acciones destructivas. Se otorga en POST /auth/confirm-password.
     */
    protected function assertPasswordConfirmed(Request $request): void
    {
        $confirmed = \Illuminate\Support\Facades\Cache::get('pwdconfirm_' . $request->user()->id);
        if (!$confirmed) {
            abort(422, 'Confirmá tu contraseña para realizar esta acción.');
        }
    }

    /**
     * Exige la casilla "impresión" para las vistas imprimibles. Van por routes/web.php,
     * que no pasa por EnsurePermiso: sin esto la casilla sólo frenaba al ticket y
     * con un Bearer se podía bajar la planilla igual.
     */
    protected function assertPuedeImprimir(Request $request): void
    {
        if (!Permisos::puede($this->rolDe($request), 'impresion.usar')) {
            abort(403, 'No tenés permiso para imprimir o descargar planillas.');
        }
    }
}
