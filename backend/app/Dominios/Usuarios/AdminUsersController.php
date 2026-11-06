<?php

namespace App\Dominios\Usuarios;

use App\Http\Controllers\Controller;

use App\Dominios\Profesores\Profesor;
use App\Dominios\Usuarios\ApiSesion;
use App\Dominios\Usuarios\User;
use App\Dominios\Usuarios\UserAnioDivision;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Hash;
use App\Support\ActivityLogger;
use App\Support\SchoolYear;
use App\Support\RoleHierarchy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// Controlador para la gestion de usuarios del panel de administracion.
// Proporciona endpoints para listar, crear, actualizar, verificar y eliminar usuarios,
// asi como gestionar sus asignaciones a cursos (anio/division) y roles.
class AdminUsersController extends Controller
{
    use \App\Support\AuthorizesScope;

    /**
     * Valida que el actor pueda gestionar (editar/eliminar/cambiar rol de) al usuario objetivo.
     * Reglas de jerarquia:
     *  - Nadie gestiona a un rol superior al propio.
     *  - jefe_preceptores no gestiona a admin ni directivo.
     *  - preceptor solo edita DATOS de profesores asignados a sus cursos (no crea/elimina/cambia rol).
     * Lanza 403 si no tiene permiso.
     */
    protected function assertCanManageUser(Request $request, User $target, bool $isDataEditOnly = false): void
    {
        $actor = $request->user();
        $actorRole = $actor->role;
        $targetRole = $target->role;

        // No puede gestionarse a si mismo en operaciones destructivas de rol/borrado (se controla aparte para self).
        if ($actorRole === RoleHierarchy::PRECEPTOR) {
            // El preceptor solo edita datos de profesores asignados a sus cursos.
            if (!$isDataEditOnly || $targetRole !== RoleHierarchy::PROFESOR) {
                abort(403, 'El preceptor solo puede editar datos de profesores asignados a sus cursos.');
            }
            if (!$this->preceptorManagesProfesor($actor, $target)) {
                abort(403, 'No tenés profesores asignados en los cursos de este usuario.');
            }
            return;
        }

        if (!RoleHierarchy::canManage($actorRole, $targetRole)) {
            abort(403, 'No tenés permiso para gestionar usuarios de un rol superior al tuyo.');
        }
    }

    /**
     * Devuelve true si el preceptor $actor tiene asignado algun curso donde el profesor $target dicta clases.
     * Se vincula por email (User.profesor <-> Profesor.email).
     */
    protected function preceptorManagesProfesor(User $actor, User $target): bool
    {
        $profesor = Profesor::whereRaw('LOWER(TRIM(email)) = LOWER(TRIM(?))', [$target->email])->first();
        if (!$profesor) return false;

        $cursosPreceptor = $actor->anioDivisiones()
            ->get(['anio', 'division'])
            ->map(fn($a) => [$a->anio, $a->division]);

        if ($cursosPreceptor->isEmpty()) return false;

        $asignaciones = $profesor->asignaciones()->get(['anio', 'division']);
        foreach ($asignaciones as $asig) {
            foreach ($cursosPreceptor as $curso) {
                if ((int) $curso[0] === (int) $asig->anio &&
                    (is_null($curso[1]) || $curso[1] === $asig->division)) {
                    return true;
                }
            }
        }
        return false;
    }

    // Devuelve la lista de roles disponibles para asignar a un usuario.
    /** Opciones de roles (útil para el modal del front) */
    public function roles()
    {
        return \App\Support\RoleHierarchy::all();
    }

    // Obtiene el listado completo de usuarios con campos seleccionados, ordenado por ID descendente.
    // Soporta filtro por rol (?role=preceptor) y eager loading de asignaciones (?with_assignments=1).
    /** Listado simple */
    public function index(Request $r)
    {
        // Rangos bajos (preceptor): sin DNI. Solo contacto, rol/estado y asignaciones.
        $cols = ['id','name','apellido','email','dni','role','rol_solicitado','status','debe_cambiar_password','avatar','created_at','updated_at','last_login_at'];
        if (!$this->userCanSeeFullFicha($r)) {
            $cols = array_values(array_diff($cols, ['dni']));
        }
        $query = User::select($cols);

        // ?role=preceptor  o  ?role=jefe_preceptores,prosecretaria (varios roles separados por coma)
        if ($r->has('role')) {
            $roles = array_values(array_filter(array_map('trim', explode(',', (string) $r->query('role')))));
            if (count($roles) === 1) {
                $query->where('role', $roles[0]);
            } elseif (count($roles) > 1) {
                $query->whereIn('role', $roles);
            }
        }

        if ($r->boolean('with_assignments')) {
            $query->with('anioDivisiones');
        }

        $users = $query->orderBy('id','desc')
            ->paginate(\App\Support\Paginacion::porPagina($r, 100, 200));

        if ($r->boolean('with_assignments')) {
            $users->getCollection()->transform(function ($user) {
                $user->asignaciones = $user->anioDivisiones->toArray();
                unset($user->anioDivisiones);
                return $user;
            });
        }

        return $users;
    }

    // Obtiene los usuarios con estado 'pending' (pendientes de verificacion), ordenados por fecha de creacion.
    /** Usuarios pendientes de verificación */
    public function pending(Request $r)
    {
        return User::select('id','name','apellido','email','dni','role','rol_solicitado','status','avatar','created_at')
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->paginate(\App\Support\Paginacion::porPagina($r, 100, 200));
    }

    // Aprueba o rechaza la cuenta de un usuario pendiente de verificacion y registra la actividad.
    /** Verificar (aprobar/rechazar) usuario */
    public function verify(Request $r, $id)
    {
        $data = $r->validate([
            'status' => ['required','in:active,rejected'],
            'role'   => ['sometimes','in:'.implode(',', \App\Support\RoleHierarchy::all())],
        ]);

        $u = User::findOrFail($id);
        $this->assertCanManageUser($r, $u, false);

        $oldStatus = $u->status;
        $oldRole = $u->role;

        // Al activar, el admin puede asignar el rol real (el solicitado por el usuario
        // o el que corresponda). No se puede asignar un rol superior al propio.
        if ($data['status'] === 'active' && !empty($data['role'])) {
            if (!RoleHierarchy::canManage($r->user()->role, $data['role'])) {
                return response()->json(['message' => 'No podés asignar un rol superior al tuyo.'], 403);
            }
            $u->role = $data['role'];
        }

        $u->status = $data['status'];
        $u->save();

        // Si se rechaza la cuenta, se revocan todas sus sesiones abiertas
        // para que pierda el acceso de inmediato.
        if ($data['status'] === 'rejected') {
            if ($u->api_token) {
                \Illuminate\Support\Facades\Cache::forget('auth_token_' . $u->api_token);
            }
            ApiSesion::cerrarTodas($u->id);
            $u->api_token = null;
            $u->save();
        }

        ActivityLogger::log($r, ($data['status'] === 'active' ? 'Aprobó' : 'Rechazó') . ' cuenta de ' . $u->email, 'user.verify', 'User', $u->id, ['status' => $oldStatus, 'role' => $oldRole], ['status' => $data['status'], 'role' => $u->role]);

        return $u->only('id','name','apellido','email','role','rol_solicitado','status','created_at');
    }

    // Obtiene las asignaciones (anio y division) de un usuario especifico.
    /** Obtener asignaciones de un usuario */
    public function assignments($id)
    {
        $u = User::findOrFail($id);
        return $u->anioDivisiones()->orderBy('anio')->orderBy('division')->get();
    }

    // Guarda las asignaciones (anio y division) para un usuario, reemplazando las existentes.
    /** Guardar asignaciones de un usuario */
    public function saveAssignments(Request $r, $id)
    {
        $u = User::findOrFail($id);

        $data = $r->validate([
            'asignaciones' => ['present', 'array'],
            'asignaciones.*.anio'     => ['required','integer','min:1','max:'.\App\Support\SchoolYear::maxYear()],
            'asignaciones.*.division' => ['nullable', 'string', 'max:1'],
            // Materias que ensena en ese curso (solo rol profesor): se guardan en su ficha
            'asignaciones.*.materias'     => ['nullable', 'array'],
            'asignaciones.*.materias.*'   => ['integer', 'min:1'],
        ]);

        // Normaliza a pares {anio, division} ordenados, sin duplicados.
        $nuevas = [];
        foreach ($data['asignaciones'] as $a) {
            $par = [
                'anio'     => (int) $a['anio'],
                'division' => (isset($a['division']) && $a['division'] !== '' && $a['division'] !== null) ? (string) $a['division'] : null,
            ];
            $key = $par['anio'] . '|' . ($par['division'] ?? '');
            if (isset($nuevas[$key])) {
                abort(422, 'Hay asignaciones duplicadas (mismo año y división).');
            }
            $nuevas[$key] = $par;
        }
        ksort($nuevas);
        $nuevas = array_values($nuevas);

        // Materias por curso para el profesor: clave "anio|DIVISION" => [materia_id,...]
        $matsPorCurso = [];
        if ($u->role === 'profesor') {
            foreach ($data['asignaciones'] as $a) {
                $div = (isset($a['division']) && $a['division'] !== '' && $a['division'] !== null)
                    ? strtoupper(trim((string) $a['division'])) : null;
                if ($div === null || $div === '' || empty($a['materias']) || !is_array($a['materias'])) continue;
                $key = ((int) $a['anio']) . '|' . $div;
                $mats = array_values(array_unique(array_map(fn ($m) => (int) $m, $a['materias'])));
                $matsPorCurso[$key] = $mats;
            }
        }

        // Conjunto actual en BD con el mismo formato.
        $actuales = $u->anioDivisiones()
            ->get(['anio', 'division'])
            ->map(fn ($x) => ['anio' => (int) $x->anio, 'division' => $x->division !== null ? (string) $x->division : null])
            ->sortBy(fn ($x) => [$x['anio'], $x['division'] ?? ''])
            ->values()
            ->all();

        // Sincroniza materias en la ficha del profesor (profesor_asignaciones).
        $sync = !empty($matsPorCurso) ? $this->syncProfesorMaterias($u, $matsPorCurso) : null;

        // Nada cambió: no tocar la BD ni registrar en historial.
        if ($actuales == $nuevas && empty($sync['cambios'])) {
            return $u->anioDivisiones()->orderBy('anio')->orderBy('division')->get();
        }

        if ($actuales != $nuevas) {
            UserAnioDivision::where('user_id', $u->id)->delete();
            foreach ($nuevas as $par) {
                UserAnioDivision::create([
                    'user_id'  => $u->id,
                    'anio'     => $par['anio'],
                    'division' => $par['division'],
                ]);
            }
        }

        ActivityLogger::log(
            $r,
            'Actualizó asignaciones de ' . $u->email . (!empty($sync['cambios']) ? ' (incluye materias que enseña)' : ''),
            'user.assignments',
            'User',
            $u->id,
            array_filter(['asignaciones' => $actuales, 'materias_ficha' => $sync['antes'] ?? null]),
            array_filter(['asignaciones' => $nuevas, 'materias_ficha' => $sync['despues'] ?? null])
        );

        return $u->anioDivisiones()->orderBy('anio')->orderBy('division')->get();
    }

    /**
     * Sincroniza las materias que ensena un profesor en los cursos indicados
     * (tabla profesor_asignaciones, vinculada por email con su ficha).
     * Los cursos no incluidos en $matsPorCurso quedan intactos.
     */
    private function syncProfesorMaterias(User $u, array $matsPorCurso): array
    {
        $profesor = \App\Dominios\Profesores\Profesor::where('email', $u->email)->first();
        if (!$profesor) {
            // Si no tiene ficha, la creamos para poder asignarle materias.
            $profesor = \App\Dominios\Profesores\Profesor::create([
                'nombre'   => (string) $u->name,
                'apellido' => (string) $u->apellido,
                'email'    => (string) $u->email,
                'estado'   => 'Activo',
            ]);
        }

        $antes = [];
        $porClave = [];
        foreach (\App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $profesor->id)->get() as $e) {
            $k = (int) $e->anio . '|' . strtoupper(trim((string) $e->division));
            $porClave[$k][] = $e;
            $antes[] = ['curso' => (int) $e->anio . '° ' . trim((string) $e->division), 'materia_id' => (int) $e->materia_id];
        }

        $cambios = false;
        foreach ($matsPorCurso as $key => $matIds) {
            [$anioStr, $divStr] = explode('|', $key);
            $anio = (int) $anioStr;

            $enCurso = $porClave[$key] ?? [];
            $idsActuales = array_map(fn ($e) => (int) $e->materia_id, $enCurso);

            // Quita las materias desmarcadas
            foreach ($enCurso as $e) {
                if (!in_array((int) $e->materia_id, $matIds, true)) {
                    $e->delete();
                    $cambios = true;
                }
            }
            // Agrega las nuevas
            foreach ($matIds as $mid) {
                if (!in_array($mid, $idsActuales, true)) {
                    \App\Dominios\Profesores\ProfesorAsignacion::create([
                        'profesor_id' => $profesor->id,
                        'materia_id'  => $mid,
                        'anio'        => $anio,
                        'division'    => $divStr,
                    ]);
                    $cambios = true;
                }
            }
        }

        $despues = \App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $profesor->id)->get()
            ->map(fn ($e) => ['curso' => (int) $e->anio . '° ' . strtoupper(trim((string) $e->division)), 'materia_id' => (int) $e->materia_id])
            ->values()
            ->all();

        return ['cambios' => $cambios, 'antes' => $antes, 'despues' => $despues];
    }

    // Crea un nuevo usuario con el rol especificado y estado activo.
    /** Crear usuario con rol */
    public function store(Request $r)
    {
        $data = $r->validate([
            'name'     => ['required','string','max:255'],
            'apellido' => ['required','string','max:255'],
            'dni'      => ['nullable','string','max:15','unique:users,dni', 'regex:/^\d{6,8}$/'],
            'email'    => ['required','email','max:255','unique:users,email', new \App\Support\Rules\EmailInstitucional],
            'password' => ['required', Password::min(8)],
            'role'     => ['required','in:'.implode(',', \App\Support\RoleHierarchy::all())],
        ]);

        // No se puede crear un usuario con un rol superior al propio.
        if (!RoleHierarchy::canManage($r->user()->role, $data['role'])) {
            return response()->json(['message' => 'No podés crear usuarios con un rol superior al tuyo.'], 403);
        }

        $u = User::create([
            'name'     => $data['name'],
            'apellido' => $data['apellido'],
            'dni'      => $data['dni'] ?? null,
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => $data['role'],
            'status'   => 'active',
        ]);

        // Si el rol es profesor, crear automaticamente la ficha en la tabla profesores
        // para que aparezca en el modulo de Profesores (ligada por email).
        if ($data['role'] === RoleHierarchy::PROFESOR) {
            Profesor::create([
                'nombre'  => $data['name'],
                'apellido'=> $data['apellido'],
                'email'   => $data['email'],
                'dni'     => $data['dni'] ?? null,
                'estado'  => 'Activo',
            ]);
        }

        ActivityLogger::log($r, 'Creó usuario ' . $u->email . ' con rol ' . $u->role, 'user.store', 'User', $u->id, null, ['email' => $u->email, 'role' => $u->role, 'name' => $u->name]);

        return response()->json(
            $u->only('id','name','apellido','email','dni','role','status','created_at','updated_at','last_login_at'),
            201
        );
    }

    // Actualiza los datos basicos (nombre, email, password) de un usuario existente.
    /** Actualizar datos */
    public function update(Request $r, $id)
    {
        $u = User::findOrFail($id);
        $this->assertCanManageUser($r, $u, true);

        $actorRole = $r->user()->role;
        $isPreceptorEditing = ($actorRole === RoleHierarchy::PRECEPTOR);

        $rules = [
            'name'     => ['required','string','max:255'],
            'apellido' => ['required','string','max:255'],
            'dni'      => ['nullable','string','max:15','unique:users,dni,'.$u->id],
            'email'    => ['required','email','max:255','unique:users,email,'.$u->id, new \App\Support\Rules\EmailInstitucional],
            'password' => ['nullable', Password::min(8)],
        ];
        // El preceptor solo edita datos de profesores asignados; no puede cambiar el email
        // porque eso romperia el vinculo con la ficha de Profesor (ligada por email).
        if ($isPreceptorEditing) {
            unset($rules['email']);
            unset($rules['dni']);
            unset($rules['apellido']);
        }

        $data = $r->validate($rules);

        $old = ['name' => $u->name, 'apellido' => $u->apellido, 'email' => $u->email, 'dni' => $u->dni];
        $u->name     = $data['name'];
        $u->apellido = $data['apellido'] ?? $u->apellido;
        if (!$isPreceptorEditing) {
            $u->email = $data['email'];
            $u->dni   = $data['dni'] ?? $u->dni;
        }
        $cambioPassword = !empty($data['password']);
        if ($cambioPassword) {
            $u->password = Hash::make($data['password']);
        }

        // Detecta los campos que realmente cambiaron (comparación tolerante:
        // ignora mayúsculas, espacios y null vs vacío). Si no cambió nada no
        // se toca la base ni se genera entrada en el historial.
        $cambios = [];
        foreach ($old as $campo => $anterior) {
            $nuevo = $u->$campo;
            if (!\App\Support\ActivityDiff::igual($nuevo, $anterior)) {
                $cambios[$campo] = true;
            }
        }

        // Nada cambió: no guardar ni registrar en historial.
        if (empty($cambios) && !$cambioPassword) {
            return $u->only('id','name','apellido','email','dni','role','status','created_at','updated_at','last_login_at');
        }

        $u->save();

        // Si el cambio de clave lo hizo otra persona (admin/preceptor), se cierran
        // todas las sesiones del usuario: ninguna puede seguir viva con una
        // contraseña que ya no vale. Se borra también users.api_token porque el
        // middleware lo lee como respaldo y reviviría el acceso.
        if ($cambioPassword) {
            if ($u->api_token) {
                \Illuminate\Support\Facades\Cache::forget('auth_token_' . $u->api_token);
            }
            ApiSesion::where('user_id', $u->id)->get()->each(function ($s) {
                \Illuminate\Support\Facades\Cache::forget('auth_token_' . $s->token);
            });
            ApiSesion::cerrarTodas($u->id);
            $u->api_token = null;
            $u->save();
        }

        // La ficha de Profesor está ligada a la cuenta por email, así que cambiar
        // el email sin mover la ficha deja al profesor sin sus cursos.
        if (!$isPreceptorEditing && $old['email'] && $u->email !== $old['email']) {
            $this->sincronizarEmailProfesor((string) $old['email'], (string) $u->email);
        }

        // Se manda la foto completa: el diff centralizado deja solo lo que cambió.
        // La contraseña se marca como texto para no guardar el hash.
        $newVals = ['name' => $u->name, 'apellido' => $u->apellido, 'email' => $u->email, 'dni' => $u->dni];
        if ($cambioPassword) {
            $oldVals = ['password' => '(anterior)'];
            $newVals['password'] = 'actualizada';
        } else {
            $oldVals = $old;
        }

        ActivityLogger::log($r, 'Actualizó usuario ' . $u->email, 'user.update', 'User', $u->id, $oldVals ?: null, $newVals ?: null);

        return $u->only('id','name','apellido','email','dni','role','status','created_at','updated_at','last_login_at');
    }

    // Clave temporal para la ventanilla: la recuperación por email no depende
    // del correo del colegio (que no está configurado), así que dirección o
    // secretaría generan una clave, se la entregan en mano al usuario, y la app
    // lo obliga a poner una propia en el primer ingreso. La clave se muestra
    // una sola vez en la respuesta y nunca se guarda ni se registra.
    /** Generar clave temporal */
    public function claveTemporal(Request $r, $id)
    {
        $u = User::findOrFail($id);
        $this->assertCanManageUser($r, $u, true);

        // Bloqueo explícito: una tecla mal dada dejaría afuera al propio
        // operador, y con la cuenta administrativa no hay quien la recupere.
        if ((int) $u->id === (int) $r->user()->id) {
            abort(422, 'No te podés generar una clave temporal a vos mismo.');
        }

        if ($u->status !== 'active') {
            abort(422, 'Esta cuenta todavía no está aprobada: no tiene clave que reemplazar.');
        }

        $clave = $this->claveTemporalAleatoria();
        $u->password = Hash::make($clave);
        $u->debe_cambiar_password = true;
        $u->save();

        // La clave vieja ya no sirve, así que ningún equipo puede seguir
        // adentro con ella: se cierran todas las sesiones, incluida la propia.
        foreach (ApiSesion::where('user_id', $u->id)->pluck('token') as $token) {
            Cache::forget('auth_token_' . $token);
        }
        ApiSesion::cerrarTodas($u->id);
        if ($u->api_token) {
            Cache::forget('auth_token_' . $u->api_token);
        }
        User::where('id', $u->id)->update(['api_token' => null]);

        // Sin old/new: la clave es un dato sensible que el historial nunca
        // guarda, y el diff central descarta la contraseña siempre. Al no pasar
        // valores, la entrada se registra igual con la acción realizada.
        ActivityLogger::log(
            $r,
            'Generó clave temporal para ' . $u->email,
            'user.clave_temporal',
            'User',
            $u->id
        );

        return [
            'id'     => $u->id,
            'nombre' => trim($u->name . ' ' . $u->apellido),
            'email'  => $u->email,
            'clave'  => $clave,
        ];
    }

    // Cuatro sílabas y cuatro dígitos (≈36 bits): es provisoria y se cambia al
    // entrar, así que prioriza poder dictarse por teléfono o a mano sin errores.
    private function claveTemporalAleatoria(): string
    {
        $silabas = ['ba','be','bi','bo','bu','ca','ce','ci','co','cu','da','de','di','do','du',
            'fa','fe','fi','fo','fu','ga','ge','gi','go','gu','ja','je','ji','jo','ju','ka','ke',
            'la','le','li','lo','lu','ma','me','mi','mo','mu','na','ne','ni','no','nu','pa','pe',
            'pi','po','pu','ra','re','ri','ro','ru','sa','se','si','so','su','ta','te','ti','to',
            'tu','va','ve','vi','vo','vu','za','ze','zi','zo','zu'];
        $pick = fn () => $silabas[random_int(0, count($silabas) - 1)];
        $grupo = fn (int $n) => implode('', array_map(fn () => $pick(), range(1, $n)));

        return $grupo(2) . '-' . $grupo(2) . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    // Mueve la ficha de Profesor que estaba ligada a la cuenta al email nuevo.
    // Si ya existe otra ficha con ese email no se toca nada: es mejor dejar las
    // fichas como están que mezclar los datos de dos personas.
    private function sincronizarEmailProfesor(string $emailViejo, string $emailNuevo): void
    {
        if ($emailNuevo === '' || mb_strtolower(trim($emailViejo)) === mb_strtolower(trim($emailNuevo))) {
            return;
        }

        $profesor = Profesor::whereRaw('LOWER(TRIM(email)) = LOWER(TRIM(?))', [$emailViejo])->first();
        if (!$profesor) {
            return;
        }

        $choque = Profesor::where('id', '!=', $profesor->id)
            ->whereRaw('LOWER(TRIM(email)) = LOWER(TRIM(?))', [$emailNuevo])
            ->exists();
        if ($choque) {
            return;
        }

        $profesor->email = $emailNuevo;
        $profesor->save();
    }

    // Cambia el rol de un usuario existente.
    /** Cambiar rol */
    public function updateRole(Request $r, $id)
    {
        $data = $r->validate([
            'role' => ['required','in:'.implode(',', \App\Support\RoleHierarchy::all())],
        ]);

        $u = User::findOrFail($id);
        $this->assertCanManageUser($r, $u, false);

        // No se puede asignar un rol que el actor no pueda gestionar (evita escalada a admin/directivo).
        if (!RoleHierarchy::canManage($r->user()->role, $data['role'])) {
            return response()->json(['message' => 'No podés asignar un rol superior al tuyo.'], 403);
        }
        // Nadie puede elevarse el rol a sí mismo.
        if ((int) $id === (int) $r->user()->id &&
            RoleHierarchy::level($data['role']) > RoleHierarchy::level($r->user()->role)) {
            return response()->json(['message' => 'No podés elevarte el rol a vos mismo.'], 403);
        }

        // Acción destructiva: exige confirmación de contraseña reciente.
        $this->assertPasswordConfirmed($r);

        $oldRole = $u->role;
        $u->role = $data['role'];
        $u->save();

        // Invalida la caché de los tokens para que el cambio de rol se aplique
        // de inmediato en todos los equipos abiertos.
        if ($u->api_token) {
            \Illuminate\Support\Facades\Cache::forget('auth_token_' . $u->api_token);
        }
        ApiSesion::where('user_id', $u->id)->get()->each(function ($s) {
            \Illuminate\Support\Facades\Cache::forget('auth_token_' . $s->token);
        });

        ActivityLogger::log($r, 'Cambió rol de ' . $u->email . ' de ' . $oldRole . ' a ' . $data['role'], 'user.role', 'User', $u->id, ['role' => $oldRole], ['role' => $data['role']]);

        return $u->only('id','name','apellido','email','role','status','created_at','updated_at','last_login_at');
    }

    // Elimina un usuario del sistema, excepto si es su propia cuenta.
    /** Eliminar usuario */
    public function destroy(Request $request, $id)
    {
        if ((int) $id === $request->user()->id) {
            return response()->json(['message' => 'No podés eliminar tu propia cuenta.'], 422);
        }

        $u = User::findOrFail($id);
        $this->assertCanManageUser($request, $u, false);

        // Acción destructiva: exige confirmación de contraseña reciente.
        $this->assertPasswordConfirmed($request);

        // --- Limpieza de datos vinculados antes de borrar el usuario ---

        // 1. Si es profesor, limpiar referencias en horarios y overrides, y borrar la ficha.
        if ($u->role === RoleHierarchy::PROFESOR) {
            $profesor = Profesor::where('email', $u->email)->first();
            if ($profesor) {
                DB::table('horario_celdas')->where('profesor_id', $profesor->id)->update(['profesor_id' => null]);
                DB::table('alumno_horario_overrides')->where('profesor_id', $profesor->id)->update(['profesor_id' => null]);
                // Borrado definitivo: las asignaciones se borran por FK en cascada.
                $profesor->delete();
            }
        }

        // 2. Nullificar responsable en clases (ya es nullable gracias a la migración).
        DB::table('clases')->where('responsable_id', $u->id)->update(['responsable_id' => null]);

        // 3. Nullificar registrado_por / aprobado_por en justificaciones (ya nullable).
        DB::table('asistencia_justificaciones')->where('registrado_por', $u->id)->update(['registrado_por' => null]);
        DB::table('asistencia_justificaciones')->where('aprobado_por', $u->id)->update(['aprobado_por' => null]);

        // 4. Nullificar user_id en activity_logs.
        DB::table('activity_logs')->where('user_id', $u->id)->update(['user_id' => null]);

        // 5. Nullificar created_by en ventana_nota_final.
        DB::table('ventana_nota_final')->where('created_by', $u->id)->update(['created_by' => null]);

        // 6. Limpiar el token de la caché y cerrar sus sesiones abiertas.
        if ($u->api_token) {
            Cache::forget('auth_token_' . $u->api_token);
        }
        ApiSesion::where('user_id', $u->id)->get()->each(function ($s) {
            Cache::forget('auth_token_' . $s->token);
        });
        ApiSesion::cerrarTodas($u->id);

        // 7. Borrar archivos físicos de sus documentos (las filas cascan por FK
        // pero los ficheros quedarían huérfanos en disco).
        $rutas = DB::table('documentos')->where('user_id', $u->id)->pluck('ruta')->filter()->values()->all();
        foreach ($rutas as $ruta) {
            try {
                if (Storage::disk('local')->exists($ruta)) {
                    Storage::disk('local')->delete($ruta);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // 7b. Su foto de perfil: el directorio entero, por si quedó más de una.
        try {
            Storage::disk('local')->deleteDirectory('avatars/' . (int) $u->id);
        } catch (\Throwable $e) {
            report($e);
        }

        // 8. Borrar el usuario (casca a user_anio_division y documentos).
        $u->delete();

        ActivityLogger::log($request, 'Eliminó usuario ' . $u->email, 'user.destroy', 'User', $u->id, ['email' => $u->email, 'role' => $u->role], null);

        return response()->json(['ok' => true]);
    }
}
