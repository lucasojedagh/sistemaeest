<?php

namespace App\Support;

use App\Dominios\Sistema\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogger
{
    public static function log(
        Request $request,
        string $action,
        string $actionType,
        ?string $auditableType = null,
        ?int $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): ActivityLog {
        $user = $request->user();

        // Diff centralizado: se guarda SOLO lo que cambió de verdad, para que el
        // historial no muestre los datos intactos. Los formatos especializados
        // (materias, planilla, asignaciones) se respetan completos.
        [$oldValues, $newValues] = ActivityDiff::cambios($oldValues, $newValues);

        // Edición que no cambió nada: no genera entrada (el historial es para
        // ver qué se tocó, no para llenar de ruido). Las altas (new) y las
        // bajas (old) siempre se registran.
        if (is_array($oldValues) && is_array($newValues)
            && $oldValues === [] && $newValues === []) {
            return new ActivityLog();
        }

        // Si la peticion trae "_detalles" (un motivo, vacio o "sin motivo"), se
        // guarda; si no trae el campo, es que la accion no pide motivo
        // (inicio de sesion, descarga, impresion) y queda vacio en vez de
        // ensuciar la tarjeta con "Motivo: sin motivo".
        $detalles = $request->exists('_detalles')
            ? self::normalizarDetalles($request->input('_detalles'))
            : null;

        $log = ActivityLog::create([
            'user_id'        => $user?->id,
            'user_email'     => $user?->email ?? 'desconocido',
            'user_role'      => $user?->role ?? 'desconocido',
            'action'         => $action,
            'action_type'    => $actionType,
            'auditable_type' => $auditableType,
            'auditable_id'   => $auditableId,
            'old_values'     => $oldValues,
            'new_values'     => $newValues,
            'detalles'       => $detalles,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'created_at'     => now(),
        ]);

        // Invalidamos la caché del historial para que la proxima carga refleje el nuevo registro.
        try {
            \App\Dominios\Sistema\HistorialController::clearCache();
            // Y movemos el sello del grupo de datos que toca este movimiento: es
            // lo que hace que las pantallas abiertas se enteren del cambio.
            \App\Dominios\Sistema\SelloCambios::registrar($actionType, (int) $log->id);
        } catch (\Throwable $e) {
            // Si falla la invalidación no debe romper el registro de la actividad.
        }

        return $log;
    }

    // Si no se indicó motivo (botón Omitir o campo vacío), queda registrado así.
    private static function normalizarDetalles(mixed $detalles): string
    {
        $texto = trim((string) ($detalles ?? ''));
        return $texto === '' ? 'sin motivo' : $texto;
    }
}
