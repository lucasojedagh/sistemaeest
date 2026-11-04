<?php

namespace App\Dominios\Sistema;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Sello de cambios: dice, sin traer datos, si algo se modifico despues de la
// ultima vez que cada pantalla miro los suyos.
//
// Devuelve un numero por grupo de datos (alumnos, profesores, cursos, etc.).
// Ese numero es el id del ultimo movimiento registrado en ese grupo. El
// frontend lo guarda; si el numero que devuelve el servidor es mayor que el que
// esa pantalla anoto, es que alguien toco esos datos.
//
// Como se mantiene: cada ActivityLogger::log() avisa a registrar() y el contador
// del grupo pasa a ese id. Leer el sello entonces son lecturas de cache, sin
// tocar la base. Antes se calculaba con un GROUP BY sobre TODO el historial: con
// 267 filas no se sentia, pero con un ano de uso de 1200 alumnos era un escaneo
// completo cada dos segundos, y ahora que las pantallas consultan solas cada
// pocos segundos ese costo se multiplicaba por todas las terminales abiertas.
class SelloCambios
{
    // Ventana de filas que se mira para poner los contadores al dia la primera
    // vez (despues de un cache:clear o del primer arranque). Va por el indice
    // del id, asi que cuesta lo mismo aunque el historial tenga 200.000 filas.
    const SEMILLA_FILAS = 500;

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

    // Movimientos que NO son cambios de datos (por ejemplo, iniciar sesion): no
    // deben hacer aparecer el aviso en ninguna pantalla.
    const IGNORADOS = ['user.login', 'user.logout'];

    private static function clave(string $grupo): string
    {
        return 'sello:grupo:' . $grupo;
    }

    /**
     * Estado actual de los datos: ['grupo' => ultimoId, ...].
     *
     * @return array<string,int>
     */
    public static function estado(): array
    {
        $estado    = [];
        $semilla   = null;
        $faltantes = [];

        foreach (self::todosLosGrupos() as $grupo) {
            $valor = Cache::get(self::clave($grupo));
            if ($valor === null) {
                $faltantes[] = $grupo;
                $estado[$grupo] = 0;
                continue;
            }
            $estado[$grupo] = (int) $valor;
        }

        // Solo la primera lectura de cada grupo (despues de vaciar la cache o al
        // subir el sistema por primera vez) cuesta una consulta; el resto de las
        // veces es puro cache.
        if ($faltantes !== []) {
            $semilla = self::semilla();
            foreach ($faltantes as $grupo) {
                $valor = (int) ($semilla[$grupo] ?? 0);
                $estado[$grupo] = $valor;
                Cache::forever(self::clave($grupo), $valor);
            }
        }

        return $estado;
    }

    // Avisa que un movimiento recien guardado toca ciertos grupos. Se llama
    // desde ActivityLogger despues de crear la fila, con el id real del log.
    public static function registrar(string $actionType, int $id): void
    {
        if (in_array($actionType, self::IGNORADOS, true)) return;

        foreach (self::gruposDe($actionType) as $grupo) {
            // El id nunca baja: si llega un log mas viejo que el que ya esta
            // anotado (dos escrituras al mismo tiempo), se conserva el mayor.
            if ($id > (int) (Cache::get(self::clave($grupo)) ?? 0)) {
                Cache::forever(self::clave($grupo), $id);
            }
        }
    }

    /** Ultimo id por grupo dentro de las filas mas nuevas del historial. */
    private static function semilla(): array
    {
        $estado = array_fill_keys(self::todosLosGrupos(), 0);

        $filas = DB::table('activity_logs')
            ->select('action_type', 'id')
            ->orderByDesc('id')
            ->limit(self::SEMILLA_FILAS)
            ->get();

        foreach ($filas as $fila) {
            $tipo = (string) $fila->action_type;
            if (in_array($tipo, self::IGNORADOS, true)) continue;

            $ultimo = (int) $fila->id;
            foreach (self::gruposDe($tipo) as $grupo) {
                if ($ultimo > $estado[$grupo]) $estado[$grupo] = $ultimo;
            }
        }

        return $estado;
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