<?php

namespace App\Support;

use App\Dominios\Sistema\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogger
{
    /** Cuánto admite cada columna de activity_logs: un valor más largo abortaba
     *  el insert y el pedido entero, dejando el cambio ya guardado sin testigo. */
    private const LIMITES = [
        'user_email'     => 255,
        'user_role'      => 50,
        'action'         => 500,
        'action_type'    => 100,
        'auditable_type' => 255,
        'ip_address'     => 45,
        'user_agent'     => 500,
    ];

    public static function log(
        Request $request,
        string $action,
        string $actionType,
        ?string $auditableType = null,
        ?int $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?object $actor = null
    ): ActivityLog {
        // En acciones sin sesión (restablecer la clave desde el link del correo)
        // no hay user() que leer: se pasa la cuenta afectada como actor para que
        // el historial diga quién es y no "Usuario del sistema".
        $user = $actor ?? $request->user();

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

        $datos = [
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
        ];

        // Los textos más largos que la columna se recortan: un rol creado con un
        // nombre de 60 caracteres entraba en users.role pero reventaba el insert
        // del historial (varchar 50) y el usuario recibía un error 500 con su
        // cambio ya guardado.
        foreach (self::LIMITES as $campo => $max) {
            if (is_string($datos[$campo] ?? null)) {
                $datos[$campo] = mb_substr($datos[$campo], 0, $max);
            }
        }

        try {
            $log = ActivityLog::create($datos);
        } catch (\Throwable $e) {
            // El historial es un testigo, no la operación: si no se puede escribir
            // (columna corta, base sin permiso, JSON inválido) el trabajo del
            // usuario no se descarría. Queda en el log del servidor para reconstruir.
            \Illuminate\Support\Facades\Log::error('No se pudo registrar la actividad', [
                'action_type' => $actionType,
                'action'      => $action,
                'error'       => $e->getMessage(),
            ]);
            return new ActivityLog();
        }

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
