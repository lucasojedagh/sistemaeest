<?php

namespace App\Dominios\Usuarios;

use App\Http\Controllers\Controller;

use App\Dominios\Usuarios\ApiSesion;
use App\Dominios\Usuarios\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Cache;
use App\Support\ActivityLogger;

// Controlador de autenticacion de la API.
// Gestiona el registro, inicio de sesion, cierre de sesion, perfil del usuario
// autenticado, ambito de visibilidad segun rol y confirmacion de contrasena.
class AuthApiController extends Controller
{
    // Registra un nuevo usuario sin rol (sin_rol) y estado 'pending'. El usuario elige
    // su rol de una lista fija; un administrador lo confirma al verificar la cuenta.
    /** Registro público */
    public function register(Request $r)
    {
        $data = $r->validate([
            'name'            => ['required','string','max:255'],
            'apellido'        => ['nullable','string','max:255'],
            'dni'             => ['nullable','string','max:15','unique:users,dni', 'regex:/^\d{6,8}$/'],
            'email'           => ['required','email','max:255','unique:users,email', new \App\Support\Rules\EmailInstitucional],
            'password'        => ['required', 'confirmed', Password::min(8)],
            // El registro publico solo ofrece roles operativos. "directivo" se
            // asigna exclusivamente desde la administracion de usuarios: un
            // autoalta no puede pedirse un rol con acceso global de escritura.
            'rol_solicitado'  => ['nullable','string','in:preceptor,profesor'],
        ]);

        try {
            $user = User::create([
                'name'           => $data['name'],
                'apellido'       => $data['apellido'] ?? null,
                'dni'            => $data['dni'] ?? null,
                'email'          => $data['email'],
                'password'       => Hash::make($data['password']),
                'role'           => \App\Support\RoleHierarchy::SIN_ROL,
                'rol_solicitado' => $data['rol_solicitado'] ?? null,
                'status'         => 'pending',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                // Dice cuál dato está duplicado para no hacerlo probar dos veces.
                $msg = $e->getMessage();
                if (str_contains($msg, 'users_email_unique')) {
                    return response()->json(['message' => 'Ese correo ya está registrado. Probá iniciar sesión o recuperar tu contraseña.'], 422);
                }
                if (str_contains($msg, 'users_dni_unique')) {
                    return response()->json(['message' => 'Ese DNI ya está registrado. Probá iniciar sesión.'], 422);
                }
                return response()->json(['message' => 'El email o DNI ya están registrados.'], 422);
            }
            throw $e;
        }

        $solicitado = $data['rol_solicitado'] ?? 'sin especificar';
        ActivityLogger::log($r, 'Se registró (rol solicitado: ' . $solicitado . '): ' . $user->email, 'user.register', 'User', $user->id);

        return response()->json([
            'message' => 'Registro exitoso. Tu cuenta está pendiente de verificación por un administrador.',
        ], 201);
    }

    // Inicia sesion con email y contrasena. Verifica que la cuenta este activa y
    // registra el ultimo acceso. NO rota el token: si ya hay uno vigente se
    // reusa, asi entrar desde otra pestana o desde el celular no tira abajo
    // la sesion que ya estaba abierta (era el motivo de los cierres inesperados).
    // El token no vence por tiempo de uso: vence si el equipo pasa mas de
    // ApiSesion::INACTIVIDAD_DIAS sin pedir nada (ver AuthToken), o antes si se
    // cierra la sesion, se cambia la contrasena o se elimina la cuenta.
    /** Login (público) */
    public function login(Request $r)
    {
        $data = $r->validate([
            'email'    => ['required','email'],
            'password' => ['required','string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 422);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Tu cuenta está pendiente de verificación. Esperá a que un administrador la active.'], 403);
        }

        // Límite de equipos por persona: 10. Al superarlo se cierran las más
        // antiguas, para que la tabla no crezca sin control.
        ApiSesion::where('user_id', $user->id)
            ->orderByDesc('ultimo_uso_at')
            ->skip(9)->take(50)->get()
            ->each->delete();

        $sesion = ApiSesion::abrir($user->id, $r);

        // El login no pasa por auth.token todavia, asi que se le pasa el usuario
        // a mano para que la entrada quede con nombre e id (si no, saldria
        // "desconocido"). Queda registrado como "Inició sesión".
        $r->setUserResolver(fn () => $user);
        ActivityLogger::log($r, 'Inició sesión', 'user.login', 'User', $user->id);

        // Solo se actualiza last_login_at: tocar updated_at haria parecer que
        // el usuario fue editado. users.api_token NO se usa como espejo porque
        // el middleware lo lee como respaldo y eso reviviria las sesiones que
        // se cerraron (quedaria solo con ese token valido).
        User::where('id', $user->id)->update(['last_login_at' => now()]);

        $user->api_token     = $sesion->token;
        $user->last_login_at = now();

        return response()->json([
            'user'  => [
                'id'            => $user->id,
                'name'          => $user->name,
                'apellido'      => $user->apellido,
                'email'         => $user->email,
                'role'          => $user->role,
                'status'        => $user->status,
                'created_at'    => $user->created_at,
                'updated_at'    => $user->updated_at,   // solo cambia cuando se edita el usuario
                'last_login_at' => $user->last_login_at, // cambia en cada login
            ],
            'token' => $sesion->token,
        ]);
    }

    // Lista los equipos con sesion abierta, para poder cerrar uno solo.
    /** Sesiones abiertas del usuario (protegida) */
    public function sesiones(Request $r)
    {
        $u = $r->user();
        if (!$u) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tokenActual = (string) $r->bearerToken();

        $filas = ApiSesion::where('user_id', $u->id)
            ->orderByDesc('ultimo_uso_at')
            ->get()
            // Las vencidas por inactividad ya no sirven: no tiene sentido
            // mostrarlas como equipos abiertos.
            ->filter(fn (ApiSesion $s) => $s->estaViva())
            ->map(fn (ApiSesion $s) => [
                'id'            => $s->id,
                'dispositivo'   => $s->dispositivo ?: 'Equipo desconocido',
                'ip_address'    => $s->ip_address,
                'ultimo_uso_at' => $s->ultimo_uso_at?->toIso8601String(),
                'es_esta'       => $s->token === $tokenActual,
            ])
            ->values();

        return ['sesiones' => $filas];
    }

    // Cierra sesiones. Con ?todos=1 cierra todas menos la actual; sin eso,
    // cierra solo las indicadas en "ids".
    /** Cerrar sesiones (protegida) */
    public function cerrarSesiones(Request $r)
    {
        $u = $r->user();
        if (!$u) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tokenActual   = (string) $r->bearerToken();
        $cerrarTodas   = $r->boolean('todos') || $r->boolean('all');

        $query = ApiSesion::where('user_id', $u->id)
            ->where('token', '!=', $tokenActual);

        if (!$cerrarTodas) {
            $ids = array_filter((array) $r->input('ids', []));
            if (empty($ids)) {
                return response()->json(['message' => 'No indicaste qué sesión cerrar.'], 422);
            }
            $query->whereIn('id', $ids);
        }

        $cerradas = $query->get();
        foreach ($cerradas as $s) {
            \Illuminate\Support\Facades\Cache::forget('auth_token_' . $s->token);
        }
        $cerradas->each->delete();

        // Si el token cerrado era el que quedo en users.api_token (sesiones
        // abiertas antes de la migracion), se limpia tambien: si no, el
        // respaldo del middleware lo seguiria dando por valido.
        $tokensCerrados = $cerradas->pluck('token')->all();
        if ($tokensCerrados) {
            User::where('id', $u->id)
                ->whereIn('api_token', $tokensCerrados)
                ->update(['api_token' => null]);
        }

        return ['ok' => true, 'cerradas' => $cerradas->count()];
    }

    // Obtiene los datos del usuario autenticado a partir del token de API.
    /** Datos del usuario autenticado (protegida) */
    public function me(Request $r)
    {
        $u = $r->user();
        if (!$u) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return [
            'id'            => $u->id,
            'name'          => $u->name,
            'apellido'      => $u->apellido,
            'email'         => $u->email,
            'role'          => $u->role,
            'status'        => $u->status,
            'created_at'    => $u->created_at,
            'updated_at'    => $u->updated_at,
            'last_login_at' => $u->last_login_at,
        ];
    }

    // Devuelve el ambito de visibilidad del usuario segun su rol.
    // Admin y jefe_preceptores ven todo (asignaciones = null); los demas ven solo sus anios y divisiones asignados.
    /** Ámbito de visibilidad del usuario según su rol */
    public function ambito(Request $r)
    {
        $u = $r->user();
        if (!$u) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // admin, directivo y jefe_preceptores ven todo → null indica "sin restricción"
        if (in_array($u->role, [
            \App\Support\RoleHierarchy::ADMIN,
            \App\Support\RoleHierarchy::DIRECTIVO,
            \App\Support\RoleHierarchy::JEFE_PRECEPTORES,
        ], true)) {
            return ['role' => $u->role, 'asignaciones' => null];
        }

        $asignaciones = $u->anioDivisiones()
            ->orderBy('anio')
            ->orderBy('division')
            ->get(['anio', 'division']);

        // Para profesores: materias que efectivamente ensena (profesor_asignaciones,
        // vinculado por email con su ficha). Usado por Calificaciones para mostrar
        // solo las materias que dicta, no todas las del curso.
        // Se suman las que cubre como suplente, marcadas para que la pantalla
        // pueda distinguirlas ("suplente de ...").
        $materiasEnsenadas = [];
        if ($u->role === 'profesor') {
            $prof = \App\Dominios\Profesores\Profesor::where('email', $u->email)->first();
            if ($prof) {
                $materiasEnsenadas = \App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $prof->id)
                    ->with('materia:id,nombre,anio')
                    ->get()
                    ->map(fn ($a) => [
                        'anio'       => (int) $a->anio,
                        'division'   => strtoupper(trim((string) $a->division)),
                        'materia_id' => (int) $a->materia_id,
                        'materia'    => optional($a->materia)->nombre,
                    ])
                    ->values()
                    ->all();

                // Los cursos de la ficha tambien habilitan visibilidad de cursos
                // (union con las asignaciones a nivel usuario, sin duplicados).
                $fichaCursos = \App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $prof->id)
                    ->get(['anio', 'division'])
                    ->map(fn ($a) => [
                        'anio'     => (int) $a->anio,
                        'division' => strtoupper(trim((string) $a->division)),
                    ]);

                // Coberturas como suplente: mismos cursos y materias, marcados.
                $coberturas = \App\Support\Suplencias::coberturasDe($prof->id);
                $titulares = [];
                $materiasSuplidas = [];
                if ($coberturas) {
                    $titIds = array_values(array_unique(array_column($coberturas, 'titular_id')));
                    $titulares = \App\Dominios\Profesores\Profesor::whereIn('id', $titIds)
                        ->get(['id', 'nombre', 'apellido'])
                        ->mapWithKeys(fn ($p) => [$p->id => trim($p->apellido . ', ' . $p->nombre, ', ')])
                        ->all();
                    $mats = \App\Dominios\Cursos\Materia::whereIn(
                            'id',
                            array_values(array_unique(array_column($coberturas, 'materia_id')))
                        )->pluck('nombre', 'id')->all();
                    foreach ($coberturas as $cob) {
                        $materiasSuplidas[] = [
                            'anio'          => $cob['anio'],
                            'division'      => $cob['division'],
                            'materia_id'    => $cob['materia_id'],
                            'materia'       => $mats[$cob['materia_id']] ?? null,
                            'suplencia'     => true,
                            'titular'       => $titulares[$cob['titular_id']] ?? null,
                        ];
                    }
                    $materiasEnsenadas = array_merge($materiasEnsenadas, $materiasSuplidas);
                    $fichaCursos = $fichaCursos->concat(collect($coberturas)->map(fn ($cob) => [
                        'anio'     => $cob['anio'],
                        'division' => $cob['division'],
                    ]));
                }

                $asignaciones = collect($asignaciones)
                    ->map(fn ($a) => [
                        'anio'     => (int) ($a['anio'] ?? 0),
                        'division' => strtoupper(trim((string) ($a['division'] ?? ''))),
                    ])
                    ->concat($fichaCursos)
                    ->unique(fn ($x) => $x['anio'].'|'.$x['division'])
                    ->sortBy(fn ($x) => $x['anio'].'|'.$x['division'])
                    ->values();
            }
        }

        return [
            'role'         => $u->role,
            'asignaciones' => $asignaciones,
            'materias'     => $materiasEnsenadas,
        ];
    }

    // Actualiza el nombre, email y/o contrasena del usuario autenticado.
    /** Actualizar perfil (protegida) */
    public function updateProfile(Request $r)
    {
        $u = $r->user();
        if (!$u) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $r->validate([
            'name'     => ['required','string','max:255'],
            'email'    => ['required','email','max:255','unique:users,email,'.$u->id, new \App\Support\Rules\EmailInstitucional],
            'password' => ['nullable', Password::min(8)],
        ]);

        $u->name  = $data['name'];
        $u->email = $data['email'];

        $cambioPassword = !empty($data['password']);
        if ($cambioPassword) {
            $u->password = Hash::make($data['password']);
        }

        $u->save(); // aquí sí queremos que updated_at cambie

        // La clave nueva cierra las sesiones de los DEMÁS equipos: si la vieja
        // se filtró, quien la tenga ya no entra. La sesión con la que se hizo el
        // cambio queda abierta para no expulsar al usuario a media edición.
        if ($cambioPassword) {
            $tokenActual = (string) $r->bearerToken();
            $otras = ApiSesion::where('user_id', $u->id)
                ->when($tokenActual !== '', fn ($q) => $q->where('token', '!=', $tokenActual))
                ->get();
            foreach ($otras as $s) {
                Cache::forget('auth_token_' . $s->token);
            }
            ApiSesion::where('user_id', $u->id)
                ->when($tokenActual !== '', fn ($q) => $q->where('token', '!=', $tokenActual))
                ->delete();
            if ($u->api_token && $u->api_token !== $tokenActual) {
                Cache::forget('auth_token_' . $u->api_token);
                ApiSesion::where('token', $u->api_token)->delete();
                User::where('id', $u->id)->update(['api_token' => null]);
            }
        }

        return [
            'id'            => $u->id,
            'name'          => $u->name,
            'apellido'      => $u->apellido,
            'email'         => $u->email,
            'role'          => $u->role,
            'status'        => $u->status,
            'created_at'    => $u->created_at,
            'updated_at'    => $u->updated_at,
            'last_login_at' => $u->last_login_at,
        ];
    }

    // Cierra la sesion del usuario invalidando su token de API.
    /** Logout (protegida) */
    public function logout(Request $r)
    {
        $u = $r->user();
        if ($u) {
            $token = $r->bearerToken();
            if ($token) {
                \Illuminate\Support\Facades\Cache::forget('auth_token_' . $token);
                // Cierra SOLO este equipo. Con ?todos=1 cierra todos los
                // equipos: es el "salir de todos lados".
                if ($r->boolean('todos') || $r->boolean('all')) {
                    ApiSesion::cerrarTodas($u->id);
                    User::where('id', $u->id)->update(['api_token' => null]);
                } else {
                    ApiSesion::where('user_id', $u->id)->where('token', $token)->delete();
                    // El respaldo del middleware usa users.api_token: si queda
                    // apuntando a este token, la sesion "cerrada" seguiria viva.
                    User::where('id', $u->id)->where('api_token', $token)->update(['api_token' => null]);
                }
            }
            // Evitamos tocar updated_at al cerrar sesión
        }

        return ['ok' => true];
    }

    // Verifica la contrasena del usuario autenticado (solo admin o jefe_preceptores).
    // Devuelve un TTL de 5 minutos para que el frontend pueda realizar operaciones sensibles.
    public function confirmPassword(Request $r)
{
    $u = $r->user();
        if (!$u || !in_array($u->role, [
            \App\Support\RoleHierarchy::ADMIN,
            \App\Support\RoleHierarchy::DIRECTIVO,
            \App\Support\RoleHierarchy::JEFE_PRECEPTORES,
        ], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

    $data = $r->validate([
        'password' => ['required','string'],
    ]);

    if (!\Illuminate\Support\Facades\Hash::check($data['password'], $u->password)) {
        return response()->json(['message' => 'Contraseña incorrecta'], 422);
    }

    // Confirmación server-side: vale 5 minutos para acciones destructivas
    // (la verifican con assertPasswordConfirmed). El valid_until sigue
    // informándose al frontend por compatibilidad.
    Cache::put('pwdconfirm_' . $u->id, true, 300);

    $until = now()->addMinutes(5); // TTL sugerido para el front
    return [
        'ok'          => true,
        'valid_until' => $until->toIso8601String(),
    ];
}

    // Emite un ticket de impresión (60s, reutilizable) para abrir vistas
    // imprimibles en iframes/ventanas que no pueden mandar header Authorization.
    // El frontend lo pide con Bearer y abre la URL con ?ticket=.
    // El api_token real nunca viaja en la URL (no queda en logs/historial).
    /** Ticket de impresión (protegida) */
    public function printTicket(Request $r)
    {
        $u = $r->user();
        if (!$u) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $ticket = Str::random(40);
        Cache::put('print_ticket_' . $ticket, $u->id, 60);

        return ['ticket' => $ticket];
    }

}

