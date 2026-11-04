<?php

namespace App\Dominios\Calificaciones;

use App\Http\Controllers\Controller;

use App\Dominios\Calificaciones\VentanaNotaFinal;
use App\Support\RoleHierarchy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Controla la "ventana" global durante la cual se puede cargar la nota final del anio.
// Solo administradores y jefe de preceptores pueden configurarla.
class VentanaNotaFinalController extends Controller
{
    // Devuelve el estado actual de la ventana para que el frontend habilite/deshabilite la carga.
    public function show(Request $req)
    {
        // Solo roles docentes (profesor o superior); sin_rol recibe 403.
        if (!RoleHierarchy::isAtLeast($req->user()->role ?? null, RoleHierarchy::PROFESOR)) {
            abort(403, 'No tenés permiso para ver este recurso.');
        }

        $v = VentanaNotaFinal::actual();

        return response()->json([
            'abierta'       => $v ? $v->estaAbierta() : false,
            'alcance'       => $v ? $v->alcanceNormalizado() : 'ambos',
            'abierta_p1'    => $v ? $v->permitePeriodo(1) : false,
            'abierta_p2'    => $v ? $v->permitePeriodo(2) : false,
            'fecha_inicio'  => $v?->fecha_inicio?->toDateTimeString(),
            'fecha_fin'     => $v?->fecha_fin?->toDateTimeString(),
        ]);
    }

    // Configura (reemplaza) la ventana. Rol: admin, jefe_preceptores.
    public function update(Request $req)
    {
        $d = $req->validate([
            'fecha_inicio'  => ['required', 'date'],
            'hora_inicio'   => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'fecha_fin'     => ['required', 'date'],
            'hora_fin'      => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'ciclo_lectivo' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'alcance'       => ['nullable', 'string', 'in:p1,p2,ambos'],
        ]);

        $inicio = Carbon::parse($d['fecha_inicio'] . ' ' . $d['hora_inicio']);
        $fin    = Carbon::parse($d['fecha_fin'] . ' ' . $d['hora_fin']);

        if ($fin->lte($inicio)) {
            return response()->json(
                ['ok' => false, 'message' => 'La fecha y hora de fin debe ser posterior a la de inicio.'],
                422
            );
        }

        // Semantica de fila unica: se reemplaza la configuracion previa.
        VentanaNotaFinal::query()->delete();
        $v = VentanaNotaFinal::create([
            'fecha_inicio'  => $inicio,
            'fecha_fin'     => $fin,
            'ciclo_lectivo' => $d['ciclo_lectivo'] ?? now()->year,
            'alcance'       => $d['alcance'] ?? 'ambos',
            'created_by'    => $req->user()->id,
        ]);

        return response()->json([
            'ok'            => true,
            'abierta'       => $v->estaAbierta(),
            'alcance'       => $v->alcanceNormalizado(),
            'abierta_p1'    => $v->permitePeriodo(1),
            'abierta_p2'    => $v->permitePeriodo(2),
            'fecha_inicio'  => $v->fecha_inicio->toDateTimeString(),
            'fecha_fin'     => $v->fecha_fin->toDateTimeString(),
        ]);
    }

    // Cierra la ventana de inmediato (fin = ahora). Rol: admin, jefe_preceptores.
    // Se conserva la fila para que quede constancia de cuando se cerro.
    public function cerrar(Request $req)
    {
        $v = VentanaNotaFinal::actual();
        if (!$v || !$v->estaAbierta()) {
            return response()->json(
                ['ok' => false, 'message' => 'La ventana ya está cerrada.'],
                422
            );
        }

        $v->fecha_fin = now();
        $v->save();

        \App\Support\ActivityLogger::log(
            $req,
            'Cerró la ventana de nota final',
            'ventana.cerrar',
            'VentanaNotaFinal',
            $v->id
        );

        return response()->json([
            'ok'           => true,
            'abierta'      => false,
            'alcance'      => $v->alcanceNormalizado(),
            'abierta_p1'   => false,
            'abierta_p2'   => false,
            'fecha_inicio' => $v->fecha_inicio->toDateTimeString(),
            'fecha_fin'    => $v->fecha_fin->toDateTimeString(),
        ]);
    }
}

