<?php

// Rutas de la API del sistema escuela. Define endpoints publicos y protegidos con autenticacion y roles.

use Illuminate\Support\Facades\Route;

use App\Dominios\Alumnos\AlumnoController;
use App\Dominios\Alumnos\AlumnoHorarioController;
use App\Dominios\Asistencia\AsistenciaController;
use App\Dominios\Calificaciones\AlumnoCalificacionesController;
use App\Dominios\Calificaciones\AlumnoPromedioController;
use App\Dominios\Calificaciones\CalificacionesResumenController;
use App\Dominios\Calificaciones\VentanaNotaFinalController;
use App\Dominios\Cursos\CicloLectivoController;
use App\Dominios\Cursos\CursoController;
use App\Dominios\Cursos\CursoSyncController;
use App\Dominios\Cursos\DivisionController;
use App\Dominios\Cursos\HorarioController;
use App\Dominios\Cursos\MateriaController;
use App\Dominios\Cursos\PromotionController;
use App\Dominios\Documentos\DocumentoController;
use App\Dominios\Profesores\ProfesorController;
use App\Dominios\Profesores\ProfesorSuplenciaController;
use App\Dominios\Sistema\BackupController;
use App\Dominios\Sistema\DashboardController;
use App\Dominios\Sistema\HistorialController;
use App\Dominios\Usuarios\AdminUsersController;
use App\Dominios\Usuarios\AuthApiController;
use App\Dominios\Usuarios\PasswordResetController;

// ====== PÚBLICAS ======
Route::get('/ping', fn() => ['ok' => true]);
Route::post('/password/forgot', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password');
Route::post('/password/reset',  [PasswordResetController::class, 'reset'])->middleware('throttle:password');
Route::post('/login', [AuthApiController::class, 'login'])->middleware('throttle:login');
// Registro: 10 intentos cada 10 minutos por IP. El limite anterior (2 por
// HORA) bloqueaba a toda la escuela cuando varios se registraban el mismo
// dia detras de la misma IP. Las cuentas quedan pendientes hasta que un
// admin las verifique, asi que el riesgo de abuso es bajo.
Route::post('/register', [AuthApiController::class, 'register'])->middleware('throttle:10,10');

// ====== PROTEGIDAS (autenticación requerida) ======
Route::middleware('auth.token')->group(function () {

    // Perfil
    Route::get('/me',        [AuthApiController::class, 'me']);
    Route::get('/me/ambito', [AuthApiController::class, 'ambito']);
    Route::post('/logout',   [AuthApiController::class, 'logout']);
    Route::put('/profile',   [AuthApiController::class, 'updateProfile']);

    // Ticket de impresión de un solo uso (60s) para iframes/ventanas.
    Route::post('/print/ticket', [AuthApiController::class, 'printTicket']);

    // Confirmación de contraseña (admin, directivo, jefe preceptores)
    // Throttle dedicado: es un chequeo de contraseña, no debe permitir fuerza bruta.
    // 15/10min da margen para varias acciones destructivas seguidas sin bloquear al admin.
    Route::middleware(['role:admin,directivo,jefe_preceptores', 'throttle:15,10'])
        ->post('/auth/confirm-password', [AuthApiController::class, 'confirmPassword']);

    // Equipos con sesión abierta: permite cerrar uno solo sin perder los demás.
    Route::get('/auth/sesiones', [AuthApiController::class, 'sesiones']);
    Route::post('/auth/sesiones/cerrar', [AuthApiController::class, 'cerrarSesiones']);

    // Catálogo de roles
    Route::get('/roles', [AdminUsersController::class, 'roles']);

    // ===== ADMINISTRACIÓN DE USUARIOS =====

    // Lectura de usuarios y asignaciones: admin, directivo, jefe_preceptores y preceptor
    Route::middleware('role:admin,directivo,jefe_preceptores,preceptor')->prefix('admin')->group(function () {
        Route::get('/users',                    [AdminUsersController::class, 'index']);
        Route::get('/users/{id}/assignments',   [AdminUsersController::class, 'assignments']);
    });

    // Escritura de asignaciones: solo admin, directivo y jefe_preceptores
    Route::middleware('role:admin,directivo,jefe_preceptores')->prefix('admin')->group(function () {
        Route::put('/users/{id}/assignments',   [AdminUsersController::class, 'saveAssignments']);
    });

    // Solo admin y directivo: verificar cuentas, crear, cambiar rol y eliminar usuarios.
    // jefe_preceptores tambien puede (casi todo), pero NO tocar a admin/directivo (se valida en controlador).
    Route::middleware('role:admin,directivo,jefe_preceptores')->prefix('admin')->group(function () {
        Route::get('/users/pending',      [AdminUsersController::class, 'pending']);
        Route::put('/users/{id}/verify',  [AdminUsersController::class, 'verify']);
        Route::post('/users',             [AdminUsersController::class, 'store']);
        Route::put('/users/{id}/role',    [AdminUsersController::class, 'updateRole']);
        Route::delete('/users/{id}',      [AdminUsersController::class, 'destroy']);
    });

    // Edicion de datos basicos de usuarios: admin, directivo, jefe_preceptores y preceptor.
    // - preceptor solo puede editar datos de profesores asignados a sus cursos (validado en controlador).
    // - nadie puede editar a un rol superior al propio (validado en controlador).
    Route::middleware('role:admin,directivo,jefe_preceptores,preceptor')
        ->put('/admin/users/{id}', [AdminUsersController::class, 'update']);

    // ===== LECTURA (todos los autenticados) =====
    Route::get('/alumnos',                    [AlumnoController::class, 'index']);
    // Padrón en Excel. Estática antes que la parametrizada {alumno}.
    Route::get('/alumnos/padron/excel',       [AlumnoController::class, 'exportarPadron']);
    Route::get('/alumnos/{alumno}',           [AlumnoController::class, 'show'])->whereNumber('alumno');
    Route::get('/alumnos/{alumno}/calificaciones',  [AlumnoCalificacionesController::class, 'show'])->whereNumber('alumno');
    Route::get('/alumnos/{alumno}/anios-con-notas', [AlumnoCalificacionesController::class, 'aniosConNotas'])->whereNumber('alumno');
    Route::get('/alumnos/{alumno}/promedios', [AlumnoPromedioController::class, 'index'])->whereNumber('alumno');
    Route::get('/alumnos/{alumno}/horario',   [AlumnoHorarioController::class, 'show'])->whereNumber('alumno');
    Route::get('/calificaciones/resumen',          [CalificacionesResumenController::class, 'index']);
    Route::get('/calificaciones/por-curso-materia', [AlumnoCalificacionesController::class, 'porCursoMateria']);
    Route::get('/calificaciones/ventana', [VentanaNotaFinalController::class, 'show']);
    Route::get('/profesores',                 [ProfesorController::class, 'index']);
    // Estática antes que la parametrizada: aunque {profesor} exige número,
    // el orden explícito evita depender de esa sutileza.
    Route::get('/profesores/mapping',         [ProfesorController::class, 'mapping']);
    Route::get('/profesores/en-horarios',     [ProfesorController::class, 'enHorarios']);
    Route::get('/profesores/{profesor}',      [ProfesorController::class, 'show'])->whereNumber('profesor');
    // Cadena de suplencias: quién reemplaza a quién y en qué materias.
    Route::get('/profesores/{profesor}/suplencias', [ProfesorSuplenciaController::class, 'index'])->whereNumber('profesor');
    Route::get('/divisiones',                 [DivisionController::class, 'index']);
    // Canonical: el frontend usa /horarios/... La variante /cursos/.../horario
    // existe por compatibilidad y resuelve al mismo controlador.
    Route::get('/horarios/{anio}/{division}', [HorarioController::class, 'show'])->whereNumber('anio')->where('division', '[a-zA-Z0-9]+');
    Route::get('/cursos/{anio}/{division}/horario', [HorarioController::class, 'show'])->whereNumber('anio')->where('division', '[a-zA-Z0-9]+');
    Route::get('/cursos',                     [CursoController::class, 'index']);
    Route::get('/materias/todas',             [MateriaController::class, 'todas']);
    Route::get('/anios/{anio}/materias',      [MateriaController::class, 'indexByAnio'])->whereNumber('anio');
    // Diagnóstico de backups: expone host/BD y rutas, solo roles de gestión (como el resto de backups).
    Route::middleware('role:admin,directivo,jefe_preceptores')
        ->get('/backups/sql/check',          [BackupController::class, 'check']);
    Route::get('/dashboard/top-performers',   [DashboardController::class, 'topPerformers']);
    // Números de las tarjetas del panel, agregados en el servidor.
    Route::get('/dashboard/resumen',          [DashboardController::class, 'resumen']);
    // Cantidad de preceptores (todos los roles pueden verlo: es un dato del panel).
    Route::get('/dashboard/preceptores',     [DashboardController::class, 'preceptores']);
    Route::middleware('role:admin,directivo,jefe_preceptores')
        ->get('/historial', [HistorialController::class, 'index']);
    Route::middleware('role:admin,directivo,jefe_preceptores')
        ->post('/historial/refresh', [HistorialController::class, 'refresh']);
    // Sello de cambios: numero por tipo de dato, para avisar "hay novedades".
    // Lo pueden consultar todos los roles (solo devuelve numeros, sin datos).
    Route::get('/datos/sello', [HistorialController::class, 'sello']);

    // ===== DOCUMENTOS =====
    Route::get('/documentos', [DocumentoController::class, 'index']);
    Route::get('/documentos/{id}/download', [DocumentoController::class, 'download'])->whereNumber('id');
    Route::get('/documentos/{id}/print', [DocumentoController::class, 'print'])->whereNumber('id');
    Route::middleware('role:admin,directivo,jefe_preceptores,preceptor')->group(function () {
        Route::post('/documentos', [DocumentoController::class, 'store']);
        Route::put('/documentos/{id}', [DocumentoController::class, 'update'])->whereNumber('id');
        Route::delete('/documentos/{id}', [DocumentoController::class, 'destroy'])->whereNumber('id');
    });

    // ===== ESCRITURA (solo admin, directivo, jefe preceptores) =====
    Route::middleware('role:admin,directivo,jefe_preceptores')->group(function () {
        Route::post('/alumnos',                     [AlumnoController::class, 'store']);
        Route::delete('/alumnos/{alumno}',          [AlumnoController::class, 'destroy'])->whereNumber('alumno');
        Route::post('/alumnos/{alumno}/egresar',    [AlumnoController::class, 'egresar'])->whereNumber('alumno');
        Route::post('/alumnos/promocionar/preview', [PromotionController::class, 'preview']);
        Route::post('/alumnos/promocionar/aplicar', [PromotionController::class, 'aplicar']);
        // Alias histórico: el frontend usa /aplicar; se mantiene por compatibilidad.
        Route::post('/alumnos/promocionar',         [PromotionController::class, 'aplicar']);

        Route::post('/profesores',                  [ProfesorController::class, 'store']);
        Route::post('/profesores/from-users',        [ProfesorController::class, 'storeFromUsers']);
        Route::put('/profesores/{profesor}',        [ProfesorController::class, 'update'])->whereNumber('profesor');
        // Eslabones de suplencia (agregar / quitar reemplazos por materia).
        Route::post('/profesores/{profesor}/suplencias', [ProfesorSuplenciaController::class, 'store'])->whereNumber('profesor');
        Route::delete('/profesores/suplencias/{id}', [ProfesorSuplenciaController::class, 'destroy'])->whereNumber('id');
        // Baja definitiva completa (sin papelera).
        Route::delete('/profesores/{id}/force',     [ProfesorController::class, 'forceDelete'])->whereNumber('id');

        Route::put('/divisiones',                   [DivisionController::class, 'update']);
        Route::put('/divisiones/bulk',              [DivisionController::class, 'bulkUpdate']);

        Route::post('/cursos',                      [CursoController::class, 'store']);
        Route::patch('/cursos/{curso}',             [CursoController::class, 'update'])->whereNumber('curso');
        Route::delete('/cursos/{curso}',            [CursoController::class, 'destroy'])->whereNumber('curso');
        Route::post('/cursos/sync',                 [CursoSyncController::class, 'sync']);

        Route::post('/ciclo/promocion',             [CicloLectivoController::class, 'promote']);
        Route::post('/admin/years/enable',          [CicloLectivoController::class, 'enableYears']);
        // Backups: operaciones pesadas/destructivas con throttle dedicado (el uso legítimo es esporádico).
        Route::middleware('throttle:20,60')->group(function () {
            Route::post('/backups/sql/email',       [BackupController::class, 'emailBackup']);
            Route::get('/backups/sql/download',     [BackupController::class, 'download']);
            Route::post('/backups/sql/import',      [BackupController::class, 'import']);
        });
    });

    // ===== ESCRITURA (admin, directivo, jefe_preceptores Y preceptor en su ámbito) =====
    Route::put('/alumnos/{alumno}',           [AlumnoController::class, 'update'])->whereNumber('alumno');
    Route::put('/alumnos/{alumno}/horario',   [AlumnoHorarioController::class, 'update'])->whereNumber('alumno');
    Route::put('/horarios/{anio}/{division}', [HorarioController::class, 'update'])->whereNumber('anio')->where('division', '[a-zA-Z0-9]+');
    // Alias por compatibilidad (misma acción que la ruta canónica de arriba).
    Route::put('/cursos/{anio}/{division}/horario', [HorarioController::class, 'update'])->whereNumber('anio')->where('division', '[a-zA-Z0-9]+');
    Route::put('/anios/{anio}/materias',      [MateriaController::class, 'replaceByAnio'])->whereNumber('anio');
    Route::put('/materias/{materia}',         [MateriaController::class, 'update'])->whereNumber('materia');
    Route::delete('/materias/{materia}',      [MateriaController::class, 'destroy'])->whereNumber('materia');

    // ===== CALIFICACIONES (admin, directivo, jefe_preceptores, profesor en su ámbito) =====
    Route::put('/alumnos/{alumno}/calificaciones', [AlumnoCalificacionesController::class, 'update'])
        ->whereNumber('alumno');
    // Lote: varios alumnos en una peticion, una sola entrada de historial.
    Route::put('/calificaciones/batch', [AlumnoCalificacionesController::class, 'updateBatch']);
    // Configuracion de la ventana de nota final (admin, directivo y jefe:
    // el middleware es jerárquico, role:admin,jefe_preceptores exige nivel >= 3).
    Route::post('/calificaciones/ventana', [VentanaNotaFinalController::class, 'update'])
        ->middleware('role:admin,jefe_preceptores');
    // Cierre inmediato de la ventana (mismo rol que configurarla).
    Route::post('/calificaciones/ventana/cerrar', [VentanaNotaFinalController::class, 'cerrar'])
        ->middleware('role:admin,jefe_preceptores');

    // ===== ASISTENCIA (todos los roles docentes; permisos por ámbito en el controlador) =====
    Route::get('/asistencia/cursos',             [AsistenciaController::class, 'cursos']);
    Route::get('/asistencia/clases',             [AsistenciaController::class, 'index']);
    Route::post('/asistencia/clases',            [AsistenciaController::class, 'store']);
    Route::get('/asistencia/clases/{clase}',     [AsistenciaController::class, 'show'])->whereNumber('clase');
    Route::put('/asistencia/clases/{clase}',     [AsistenciaController::class, 'update'])->whereNumber('clase');
    Route::delete('/asistencia/clases/{clase}',  [AsistenciaController::class, 'destroy'])->whereNumber('clase');
    Route::get('/asistencia/historial',          [AsistenciaController::class, 'historial']);
    Route::get('/asistencia/inasistencias',      [AsistenciaController::class, 'inasistencias']);
    Route::get('/asistencia/justificaciones',    [AsistenciaController::class, 'justificaciones']);
    Route::post('/asistencia/justificaciones',   [AsistenciaController::class, 'storeJustificacion']);
    Route::put('/asistencia/justificaciones/{id}', [AsistenciaController::class, 'updateJustificacion'])->whereNumber('id');
    Route::get('/asistencia/estadisticas',       [AsistenciaController::class, 'estadisticas']);
});
