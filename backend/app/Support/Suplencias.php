<?php

namespace App\Support;

use App\Dominios\Profesores\ProfesorSuplencia;

/**
 * Reglas de la cadena de suplencias entre profesores.
 *
 * Cada fila es un eslabón (titular → suplente) para una materia de un curso.
 * La cadena se arma sola: si B cubre a A y C cubre a B, queda A → B → C.
 *
 * - Profundidad máxima: 4 eslabones en cadena.
 * - Sin ciclos: nadie puede terminar cubriéndose a sí mismo.
 * - Sin auto-reemplazo: titular y suplente distintos.
 * - Sin duplicados: mismo par en la misma materia y curso una sola vez.
 *
 * Las funciones que reciben filas planas ([titular_id, suplente_id, ...]) son
 * puras y testeables sin base de datos; las que leen la tabla delegan en ellas.
 */
class Suplencias
{
    /** Eslabones máximos en cadena. */
    public const MAX_PROFUNDIDAD = 4;

    /**
     * Adyacencias del grafo: quién reemplaza a quién.
     *
     * @param array $filas Filas planas con titular_id y suplente_id.
     * @return array ['sig' => [titular => [suplentes]], 'prev' => [suplente => [titulares]]]
     */
    public static function adyacencias(array $filas): array
    {
        $sig = [];
        $prev = [];
        foreach ($filas as $f) {
            $t = (int) $f['titular_id'];
            $s = (int) $f['suplente_id'];
            if (!in_array($s, $sig[$t] ?? [], true)) {
                $sig[$t][] = $s;
            }
            if (!in_array($t, $prev[$s] ?? [], true)) {
                $prev[$s][] = $t;
            }
        }

        return ['sig' => $sig, 'prev' => $prev];
    }

    /**
     * ¿Hay camino siguiendo eslabones desde $desde hasta $hasta?
     * Acotado para no colgarse ante datos inesperados.
     */
    public static function hayCamino(array $sig, int $desde, int $hasta): bool
    {
        if ($desde === $hasta) return true;

        $vistos = [$desde => true];
        $cola = [$desde];
        $pasos = 0;
        while ($cola && $pasos < 1000) {
            $pasos++;
            $actual = array_shift($cola);
            foreach ($sig[$actual] ?? [] as $prox) {
                if ($prox === $hasta) return true;
                if (!isset($vistos[$prox])) {
                    $vistos[$prox] = true;
                    $cola[] = $prox;
                }
            }
        }

        return false;
    }

    /**
     * Eslabones del camino más largo desde alguna raíz hasta $nodo.
     * 0 si nadie lo reemplaza (es raíz o está suelto).
     */
    public static function profundidadMaxima(array $sig, array $prev, int $nodo): int
    {
        $memo = [];
        $visitando = [];

        $prof = function (int $n) use (&$prof, &$memo, &$visitando, $prev): int {
            if (isset($memo[$n])) return $memo[$n];
            if (isset($visitando[$n])) return 0; // ciclo inesperado: no suma
            $visitando[$n] = true;

            $max = 0;
            foreach ($prev[$n] ?? [] as $ant) {
                $max = max($max, $prof($ant) + 1);
            }

            unset($visitando[$n]);
            $memo[$n] = $max;

            return $max;
        };

        return $prof($nodo);
    }

    /**
     * ¿Se puede agregar el eslabón titular → suplente?
     * Devuelve null si está bien, o el mensaje de error en criollo.
     *
     * @param array $filas Filas existentes con titular_id y suplente_id.
     */
    public static function validarEslabon(array $filas, int $titularId, int $suplenteId): ?string
    {
        if ($titularId === $suplenteId) {
            return 'El suplente tiene que ser otra persona.';
        }

        foreach ($filas as $f) {
            if ((int) $f['titular_id'] === $titularId && (int) $f['suplente_id'] === $suplenteId) {
                return 'Esa suplencia ya está cargada.';
            }
        }

        ['sig' => $sig, 'prev' => $prev] = self::adyacencias($filas);

        // Ciclo: si el suplente ya llega al titular por la cadena, al agregar
        // titular → suplente se cerraría un círculo.
        if (self::hayCamino($sig, $suplenteId, $titularId)) {
            return 'Esa persona ya está en la cadena: se formaría un círculo.';
        }

        // Profundidad: eslabones desde la raíz hasta el nuevo suplente.
        $profundidad = self::profundidadMaxima($sig, $prev, $titularId) + 1;
        if ($profundidad > self::MAX_PROFUNDIDAD) {
            return 'La cadena ya tiene 4 suplentes, que es el máximo.';
        }

        return null;
    }

    /**
     * Subárbol visible desde un titular (para mostrar la cadena en pantalla).
     * Devuelve niveles: [[ids nivel 1], [ids nivel 2], ...].
     */
    public static function nivelesDesde(array $filas, int $titularId): array
    {
        ['sig' => $sig] = self::adyacencias($filas);

        $niveles = [];
        $vistos = [$titularId => true];
        $actual = [$titularId];
        for ($i = 0; $i < self::MAX_PROFUNDIDAD + 1 && $actual; $i++) {
            $prox = [];
            foreach ($actual as $nodo) {
                foreach ($sig[$nodo] ?? [] as $s) {
                    if (!isset($vistos[$s])) {
                        $vistos[$s] = true;
                        $prox[] = $s;
                    }
                }
            }
            if (!$prox) break;
            $niveles[] = array_values(array_unique($prox));
            $actual = $prox;
        }

        return $niveles;
    }

    /**
     * Coberturas vigentes de un profesor: qué materias de qué cursos cubre
     * como suplente (para heredar accesos).
     *
     * @return array Filas [titular_id, suplente_id, anio, division, materia_id]
     */
    public static function coberturasDe(int $profesorId): array
    {
        return ProfesorSuplencia::where('suplente_id', $profesorId)
            ->get(['titular_id', 'suplente_id', 'anio', 'division', 'materia_id'])
            ->map(fn ($s) => [
                'titular_id'  => (int) $s->titular_id,
                'suplente_id' => (int) $s->suplente_id,
                'anio'        => (int) $s->anio,
                'division'    => $s->division !== null && $s->division !== '' ? strtoupper(trim((string) $s->division)) : null,
                'materia_id'  => (int) $s->materia_id,
            ])
            ->values()
            ->all();
    }

    /** ¿Este profesor cubre esa materia de ese curso como suplente? */
    public static function cubreMateria(int $profesorId, int $materiaId, int $anio, ?string $division): bool
    {
        $division = $division !== null && $division !== '' ? strtoupper(trim($division)) : null;

        return ProfesorSuplencia::where('suplente_id', $profesorId)
            ->where('materia_id', $materiaId)
            ->where('anio', $anio)
            ->where(function ($q) use ($division) {
                $q->where('division', $division)->orWhereNull('division');
            })
            ->exists();
    }

    /** ¿Este profesor cubre ese curso como suplente (en alguna materia)? */
    public static function cubreCurso(int $profesorId, int $anio, ?string $division): bool
    {
        $division = $division !== null && $division !== '' ? strtoupper(trim($division)) : null;

        return ProfesorSuplencia::where('suplente_id', $profesorId)
            ->where('anio', $anio)
            ->where(function ($q) use ($division) {
                $q->where('division', $division)->orWhereNull('division');
            })
            ->exists();
    }

    /**
     * Resumen para etiquetas: a quién cubre cada profesor y por quién es
     * cubierto (nombres distintos, sin repetir).
     *
     * @return array ['otorga' => [profId => [nombres]], 'recibe' => [...]]
     */
    public static function resumenPara(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $otorga = [];
        $recibe = [];
        if (!$ids) return ['otorga' => $otorga, 'recibe' => $recibe];

        $nombre = fn ($p) => $p ? trim($p->apellido . ', ' . $p->nombre, ', ') : null;

        $filas = ProfesorSuplencia::with([
                'titular:id,nombre,apellido',
                'suplente:id,nombre,apellido',
            ])
            ->where(function ($q) use ($ids) {
                $q->whereIn('titular_id', $ids)->orWhereIn('suplente_id', $ids);
            })
            ->get();

        foreach ($filas as $s) {
            $tid = (int) $s->titular_id;
            $sid = (int) $s->suplente_id;
            if (in_array($tid, $ids, true) && ($n = $nombre($s->suplente))) {
                $otorga[$tid] ??= [];
                if (!in_array($n, $otorga[$tid], true)) $otorga[$tid][] = $n;
            }
            if (in_array($sid, $ids, true) && ($n = $nombre($s->titular))) {
                $recibe[$sid] ??= [];
                if (!in_array($n, $recibe[$sid], true)) $recibe[$sid][] = $n;
            }
        }

        return ['otorga' => $otorga, 'recibe' => $recibe];
    }

    /**
     * Materias que cada profesor cubre como suplente, con detalle para
     * etiquetas: materia, curso y de quién la cubre.
     *
     * @return array [suplente_id => [{titular_nombre, anio, division, materia_id, materia, motivo}]]
     */
    public static function materiasCubiertasPara(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $map = [];
        if (!$ids) return $map;

        $filas = ProfesorSuplencia::with([
                'titular:id,nombre,apellido',
                'materia:id,nombre',
            ])
            ->whereIn('suplente_id', $ids)
            ->orderBy('anio')->orderBy('division')->orderBy('id')
            ->get();

        foreach ($filas as $s) {
            $sid = (int) $s->suplente_id;
            $map[$sid] ??= [];
            $anio = (int) $s->anio;
            $division = $s->division;
            $map[$sid][] = [
                'titular_id'     => (int) $s->titular_id,
                'titular_nombre' => $s->titular
                    ? trim($s->titular->apellido . ', ' . $s->titular->nombre, ', ')
                    : null,
                'anio'       => $anio,
                'division'   => $division,
                'curso'      => \App\Support\CourseLabel::label($anio, $division),
                'materia_id' => (int) $s->materia_id,
                'materia'    => $s->materia?->nombre ?? '',
                'motivo'     => $s->motivo,
            ];
        }

        return $map;
    }
}
