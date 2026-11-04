<?php

namespace App\Dominios\Cursos;

use App\Http\Controllers\Controller;

use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\ParametroDivision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthorizesScope;

// Controlador para sincronizar los cursos activos con las divisiones configuradas.
// Se encarga de activar o desactivar cursos segun el estado de parametro_divisiones.
class CursoSyncController extends Controller
{
    use AuthorizesScope;
    // POST /api/cursos/sync
    // Sincroniza los cursos activos con las divisiones configuradas en parametro_divisiones.
    // Activa cursos para divisiones activas y desactiva aquellos cuyas divisiones ya no estan activas.
    public function sync(Request $request): JsonResponse
    {
        // Activa/desactiva cursos en masa: exige contraseña reciente.
        $this->assertPasswordConfirmed($request);

        $movimientos = 0;

        DB::transaction(function () use (&$movimientos) {
            // 1) Activar/crear cursos para divisiones activas
            $divs = ParametroDivision::where('activo', true)->get(['anio','division']);
            foreach ($divs as $d) {
                // crea si no existe; si existe, lo marca activo
                $curso = Curso::firstOrCreate(
                    ['anio' => $d->anio, 'division' => $d->division],
                    ['activo' => true]
                );
                if (!$curso->wasRecentlyCreated) {
                    if (!$curso->activo) {
                        $curso->update(['activo' => true]);
                        $movimientos++;
                    }
                } else {
                    $movimientos++;
                }
            }

            // 2) Desactivar cursos cuya división ya no esté activa
            // (no los borramos para conservar historial)
            $activos = ParametroDivision::where('activo', true)
                ->select('anio','division')
                ->get()
                ->map(fn($r) => $r->anio.'-'.$r->division)
                ->toArray();

            Curso::query()->get()->each(function ($curso) use ($activos, &$movimientos) {
                $clave = $curso->anio.'-'.$curso->division;
                if (!in_array($clave, $activos, true) && $curso->activo) {
                    $curso->update(['activo' => false]);
                    $movimientos++;
                }
            });
        });

        // La caché de divisiones puede venir de la tabla cursos (cuando
        // parametro_divisiones está vacío), así que se descarta acá también.
        \Illuminate\Support\Facades\Cache::forget('divisiones_index');

        // Sin movimiento no hay nada que anunciar; con movimiento, el sello de
        // "cursos" sube y las pantallas abiertas se actualizan solas.
        if ($movimientos > 0) {
            \App\Support\ActivityLogger::log(
                $request,
                'Sincronizó los cursos con las divisiones (' . $movimientos . ' movimientos)',
                'curso.sync',
                'Curso',
                null,
                null,
                ['cursos tocados' => $movimientos]
            );
        }

        // Devolver estado actual
        $out = Curso::orderBy('anio')->orderBy('division')
            ->get(['id','anio','division','turno','cupo','activo']);

        return response()->json($out);
    }
}

