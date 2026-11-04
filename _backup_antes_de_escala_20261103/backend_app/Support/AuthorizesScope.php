<?php

// Trait de autorizacion por ambito. Verifica permisos segun el rol del usuario y su asignacion a cursos.

namespace App\Support;

use App\Dominios\Alumnos\Alumno;
use App\Support\RoleHierarchy;
use Illuminate\Http\Request;

trait AuthorizesScope
{
    // Verifica si el usuario tiene permisos globales de escritura (admin, directivo, jefe de preceptores).
    protected function userCanWriteAll(Request $request): bool
    {
        return in_array($request->user()->role, [
            RoleHierarchy::ADMIN,
            RoleHierarchy::DIRECTIVO,
            RoleHierarchy::JEFE_PRECEPTORES,
        ], true);
    }

    // Verifica si el usuario tiene permiso para calificar (incluye profesor, preceptor y prosecretaria).
    protected function userCanGrade(Request $request): bool
    {
        return in_array($request->user()->role, [
            RoleHierarchy::ADMIN,
            RoleHierarchy::DIRECTIVO,
            RoleHierarchy::JEFE_PRECEPTORES,
            RoleHierarchy::PROFESOR,
            RoleHierarchy::PRECEPTOR,
            RoleHierarchy::PROSECRETARIA,
        ], true);
    }

    // Verifica si el usuario es admin o directivo.
    protected function userIsAdminOrDirectivo(Request $request): bool
    {
        return in_array($request->user()->role, [
            RoleHierarchy::ADMIN,
            RoleHierarchy::DIRECTIVO,
        ], true);
    }

    // Verifica si el usuario tiene permiso de lectura global (todos los roles autenticados).
    protected function userCanReadAll(Request $request): bool
    {
        return RoleHierarchy::level($request->user()->role) >= RoleHierarchy::level(RoleHierarchy::PROFESOR);
    }

    // Verifica si el usuario esta asignado al anio/division especificado segun sus asignaciones.
    protected function userIsAssignedTo(Request $request, int $anio, ?string $division): bool
    {
        $role = $request->user()->role;
        // Roles superiores tienen acceso global sin restriccion de ambito.
        if (in_array($role, [
            RoleHierarchy::ADMIN,
            RoleHierarchy::DIRECTIVO,
            RoleHierarchy::JEFE_PRECEPTORES,
        ], true)) {
            return true;
        }

        // Profesor: los cursos de su ficha (profesor_asignaciones, vinculado por
        // email con la tabla profesores) tambien habilitan acceso, aunque no tenga
        // asignacion a nivel usuario. Lo mismo vale para los cursos que cubre
        // como suplente de otro profesor.
        if ($role === RoleHierarchy::PROFESOR) {
            $profesor = \App\Dominios\Profesores\Profesor::where('email', $request->user()->email)->first();
            if ($profesor && \App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $profesor->id)
                ->where('anio', $anio)
                ->where(function ($q) use ($division) {
                    $q->where('division', $division)->orWhereNull('division');
                })
                ->exists()) {
                return true;
            }
            if ($profesor && \App\Support\Suplencias::cubreCurso($profesor->id, $anio, $division)) {
                return true;
            }
        }

        return $request->user()->anioDivisiones()
            ->where('anio', $anio)
            ->where(function ($q) use ($division) {
                $q->where('division', $division)->orWhereNull('division');
            })
            ->exists();
    }

    // Lanza 403 si el usuario no tiene permiso para modificar los datos del alumno.
    protected function assertCanWriteAlumno(Request $request, Alumno $alumno): void
    {
        // Usuarios con permisos globales pueden escribir en cualquier alumno.
        if ($this->userCanWriteAll($request)) return;

        $role = $request->user()->role;

        // Preceptor y prosecretaria pueden escribir si están asignados al curso del alumno.
        if (in_array($role, ['preceptor', 'prosecretaria'], true) && $this->userIsAssignedTo($request, $alumno->anio, $alumno->division)) return;

        abort(403, 'No tenés permiso para modificar este recurso.');
    }

    // Lanza 403 si el usuario no tiene permiso para calificar al alumno en su curso.
    // Con $materiaId, además exige que la materia esté dentro de las asignaciones
    // del profesor para ese curso.
    protected function assertCanGradeAlumno(Request $request, Alumno $alumno, ?int $materiaId = null): void
    {
        if ($this->userCanGrade($request)
            && $this->userIsAssignedTo($request, $alumno->anio, $alumno->division)
            && $this->materiaDentroDeAsignaciones($request, (int) $alumno->anio, $alumno->division, $materiaId)) return;

        abort(403, 'No tenés permiso para cargar notas en este curso.');
    }

    // ¿Puede este usuario escribir notas de ESA materia en ESE curso?
    // Solo restringe al profesor cuya ficha ya tiene asignaciones cargadas para
    // ese curso: sin ficha o sin asignaciones en el curso se conserva el alcance
    // por curso de siempre, porque bloquear a los docentes todavía no dados de
    // alta en profesor_asignaciones dejaría el sistema sin carga de notas.
    protected function materiaDentroDeAsignaciones(Request $request, int $anio, ?string $division, ?int $materiaId): bool
    {
        if ($materiaId === null) return true;
        if ($request->user()->role !== RoleHierarchy::PROFESOR) return true;

        $profesor = \App\Dominios\Profesores\Profesor::where('email', $request->user()->email)->first();
        if (!$profesor) return true;

        $div = $division !== null && $division !== '' ? strtoupper(trim($division)) : null;

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
        return \App\Support\Suplencias::cubreMateria($profesor->id, $materiaId, $anio, $div);
    }

    // Lanza 403 si el usuario no tiene permiso para modificar datos del curso especificado.
    protected function assertCanWriteDivision(Request $request, int $anio, ?string $division): void
    {
        if ($this->userCanWriteAll($request)) return;

        $role = $request->user()->role;

        if (in_array($role, ['preceptor', 'prosecretaria'], true) && $this->userIsAssignedTo($request, $anio, $division)) return;

        abort(403, 'No tenés permiso para modificar este curso.');
    }

    // Lanza 403 si el usuario no tiene permiso para LEER datos del curso especificado.
    // A diferencia de la escritura, el profesor también puede leer su ámbito asignado.
    // sin_rol (nivel 0) nunca pasa: recibe 403.
    protected function assertCanReadCurso(Request $request, $anio, ?string $division): void
    {
        if ($this->userCanWriteAll($request)) return;

        $role = $request->user()->role;

        if (in_array($role, [RoleHierarchy::PROFESOR, RoleHierarchy::PRECEPTOR, RoleHierarchy::PROSECRETARIA], true)
            && $this->userIsAssignedTo($request, (int) $anio, $division)) return;

        abort(403, 'No tenés permiso para ver este recurso.');
    }

    // Pares (anio, division) asignados al usuario, uniendo user_anio_division
    // y (para profesor) la ficha profesional profesor_asignaciones.
    // division null = todo el año. Devuelve lista dedupicada.
    protected function assignedPairs(Request $request): array
    {
        $pairs = [];
        foreach ($request->user()->anioDivisiones()->get(['anio', 'division']) as $r) {
            $pairs[] = ['anio' => (int) $r->anio, 'division' => $r->division];
        }

        if ($request->user()->role === RoleHierarchy::PROFESOR) {
            $profesor = \App\Dominios\Profesores\Profesor::where('email', $request->user()->email)->first();
            if ($profesor) {
                foreach (\App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $profesor->id)->get(['anio', 'division']) as $r) {
                    $pairs[] = ['anio' => (int) $r->anio, 'division' => $r->division];
                }
                // Cursos que cubre como suplente: también son su ámbito.
                foreach (\App\Support\Suplencias::coberturasDe($profesor->id) as $c) {
                    $pairs[] = ['anio' => $c['anio'], 'division' => $c['division']];
                }
            }
        }

        $uniq = [];
        foreach ($pairs as $p) {
            $k = $p['anio'] . '|' . (string) ($p['division'] ?? '');
            $uniq[$k] = $p;
        }
        return array_values($uniq);
    }

    // Ficha completa (datos personales: DNI, estado, observaciones) solo para
    // gestión: admin, directivo y jefe de preceptores. Rangos bajos solo
    // reciben contacto (nombre/email) y cursos asignados.
    protected function userCanSeeFullFicha(Request $request): bool
    {
        return in_array($request->user()->role, [
            RoleHierarchy::ADMIN,
            RoleHierarchy::DIRECTIVO,
            RoleHierarchy::JEFE_PRECEPTORES,
        ], true);
    }

    // Exige confirmación de contraseña reciente (server-side, 5 min) para
    // acciones destructivas. Se otorga en POST /auth/confirm-password.
    protected function assertPasswordConfirmed(Request $request): void
    {
        $confirmed = \Illuminate\Support\Facades\Cache::get('pwdconfirm_' . $request->user()->id);
        if (!$confirmed) {
            abort(422, 'Confirmá tu contraseña para realizar esta acción.');
        }
    }

    // Restringe un query de alumnos al ámbito del usuario.
    // admin/directivo/jefe_preceptores: sin restricción. profesor/preceptor:
    // solo sus cursos asignados (vacío si no tiene). sin_rol: 403.
    protected function scopeAlumnoIndex(Request $request, $query): void
    {
        if ($this->userCanWriteAll($request)) return;

        if (!$this->userCanReadAll($request)) {
            abort(403, 'No tenés permiso para ver el padrón de alumnos.');
        }

        $pairs = $this->assignedPairs($request);
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
}
