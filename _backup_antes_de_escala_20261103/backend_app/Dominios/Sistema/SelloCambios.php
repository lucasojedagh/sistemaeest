<?php

namespace App\Dominios\Sistema;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Sello de cambios: dice, sin traer datos, si algo se modifico despues de la
// ultima vez que cada pantalla miro los suyos.
//
// Devuelve un numero por grupo de datos (alumnos, profesores, cursos, etc.).
// Ese numero es el id del ultimo movimiento registrado en ese grupo. El
// frontend lo guarda; si al volver a la pantalla el numero es mayor, es que
// alguien toco esos datos y aparece el boton "Actualizar".
//
// Es barato: una sola consulta que agrupa el historial por tipo de operacion.
class SelloCambios
{
    // Segundos que se guarda la respuesta. Si alguien guarda algo y en menos
    // de este tiempo vuelve a la pantalla, todavia no se le avisa (ya lo ve).
    const CACHE_TTL = 2;

    // Que grupo de datos toca cada tipo de operacion del historial (por
    // prefijo). Lo que no este aca cae en "otros": asi ningun cambio se pierde
    // y siempre se puede avisar.
    const GRUPOS = [
        'alumno.'        => ['alumnos'],
        'calificaciones' => ['calificaciones', 'alumnos'],
        'profesor.'      => ['profesores'],
        'asistencia.'    => ['asistencia'],
        'justificacion.' => ['asistencia'],
        'curso.'         => ['cursos'],
        'horario.'       => ['cursos'],
        'materias.'      => ['cursos'],
        'divisiones.'    => ['cursos'],
        'ciclo.'         => ['cursos', 'alumnos'],
        'documento.'     => ['documentos'],
        'user.'          => ['usuarios'],
    ];

    // Movimientos que NO son cambios de datos (por ejemplo, iniciar sesion):
    // no deben hacer aparecer el boton "Actualizar" en ninguna pantalla.
    const IGNORADOS = ['user.login', 'user.logout'];

    /**
     * Estado actual de los datos: ['grupo' => ultimoId, ...].
     *
     * @return array<string,int>
     */
    public static function estado(): array
    {
        return Cache::remember('sello:datos', self::CACHE_TTL, function () {
            $estado = array_fill_keys(self::todosLosGrupos(), 0);

            $filas = DB::table('activity_logs')
                ->selectRaw('action_type, MAX(id) as ultimo')
                ->groupBy('action_type')
                ->get();

            foreach ($filas as $fila) {
                $tipo = (string) $fila->action_type;
                if (in_array($tipo, self::IGNORADOS, true)) continue;

                $ultimo = (int) $fila->ultimo;
                foreach (self::gruposDe($tipo) as $grupo) {
                    if ($ultimo > $estado[$grupo]) $estado[$grupo] = $ultimo;
                }
            }

            return $estado;
        });
    }

    /** Nombres de todos los grupos conocidos (incluye "otros"). */
    public static function todosLosGrupos(): array
    {
        $todos = ['otros'];
        foreach (self::GRUPOS as $grupos) {
            $todos = array_merge($todos, $grupos);
        }
        return array_values(array_unique($todos));
    }

    /** A que grupo(s) pertenece un tipo de operacion. */
    private static function gruposDe(string $tipo): array
    {
        foreach (self::GRUPOS as $prefijo => $grupos) {
            if (str_starts_with($tipo, $prefijo)) return $grupos;
        }
        return ['otros'];
    }
}