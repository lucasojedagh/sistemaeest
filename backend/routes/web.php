<?php

// Rutas web de la aplicacion. Por defecto carga la vista de bienvenida.

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

use App\Dominios\Alumnos\AlumnoController;
use App\Dominios\Asistencia\AsistenciaController;
use App\Dominios\Calificaciones\AlumnoCalificacionesController;
use App\Dominios\Cursos\HorarioController;

Route::get('/', function () {
    return view('welcome');
});

// Imprimibles: requieren login (Bearer o ?token=, ver AuthToken).
// Cada controlador además verifica alcance al curso (403 si no corresponde).
Route::middleware('auth.token')->group(function () {
    Route::get('/calificaciones/planilla', [AlumnoCalificacionesController::class, 'printPlanilla']);
    // Descarga el mismo diseño en Excel (texto real, se imprime sin pixelarse).
    Route::get('/calificaciones/planilla/excel', [AlumnoCalificacionesController::class, 'descargarPlanillaExcel']);
    // Guardar desde la planilla es la única escritura por esta via: lleva su
    // propio limite, porque esta excluida del CSRF (viaja con token, no con
    // cookies) y sin esto se podria repetir sin costo.
    Route::post('/calificaciones/planilla/guardar', [AlumnoCalificacionesController::class, 'guardarPlanilla'])
        ->middleware('throttle:60,1');

    Route::get('/horarios/planilla', [HorarioController::class, 'printHorario']);

    Route::get('/print/alumnos', [AlumnoController::class, 'printListado']);
    Route::get('/print/asistencia', [AsistenciaController::class, 'printPlanillaDia']);
});