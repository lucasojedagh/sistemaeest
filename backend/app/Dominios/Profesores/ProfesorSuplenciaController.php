<?php

namespace App\Dominios\Profesores;

use App\Http\Controllers\Controller;
use App\Dominios\Cursos\Materia;
use App\Support\ActivityLogger;
use App\Support\SchoolYear;
use App\Support\Suplencias;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Gestión de la cadena de suplencias entre profesores.
// Cada eslabón dice quién reemplaza a quién, en qué materia de qué curso.
// Ver App\Support\Suplencias para las reglas (sin ciclos, máximo 4 niveles).
class ProfesorSuplenciaController extends Controller
{
    // GET /api/profesores/{profesor}/suplencias
    // Lo que este profesor cubre (otorga) y por quién es cubierto (recibe),
    // más la cadena completa hacia abajo para dibujarla en pantalla.
    public function index(Request $req, Profesor $profesor)
    {
        $otorga = ProfesorSuplencia::with(['suplente:id,nombre,apellido,estado', 'materia:id,nombre,anio'])
            ->where('titular_id', $profesor->id)
            ->orderBy('anio')->orderBy('division')->orderBy('id')
            ->get()
            ->map(fn (ProfesorSuplencia $s) => $this->presentar($s, 'suplente'))
            ->values();

        $recibe = ProfesorSuplencia::with(['titular:id,nombre,apellido,estado', 'materia:id,nombre,anio'])
            ->where('suplente_id', $profesor->id)
            ->orderBy('anio')->orderBy('division')->orderBy('id')
            ->get()
            ->map(fn (ProfesorSuplencia $s) => $this->presentar($s, 'titular'))
            ->values();

        return response()->json([
            'otorga'  => $otorga,
            'recibe'  => $recibe,
            'cadena'  => $this->cadenaConNombres($profesor->id),
        ]);
    }

    // POST /api/profesores/{profesor}/suplencias
    // Agrega un eslabón: este profesor será reemplazado por otro en una materia.
    public function store(Request $req, Profesor $profesor)
    {
        $data = $req->validate([
            'suplente_id' => ['required', 'integer', 'exists:profesores,id'],
            'anio'        => ['required', 'integer', 'min:1', 'max:' . SchoolYear::maxYear()],
            'division'    => ['nullable', 'string', 'max:1'],
            'materia_id'  => ['required', 'integer', 'exists:materias,id'],
            'motivo'      => ['nullable', 'string', 'max:250'],
        ]);

        $suplenteId = (int) $data['suplente_id'];
        $anio       = (int) $data['anio'];
        $division   = isset($data['division']) && trim((string) $data['division']) !== ''
            ? strtoupper(trim((string) $data['division']))
            : null;
        $materiaId  = (int) $data['materia_id'];

        $materia = Materia::find($materiaId);
        if (!$materia || !$materia->activo) {
            return response()->json(['message' => 'La materia no existe o no está activa.'], 422);
        }

        // Reglas de cadena (duplicado, auto-reemplazo, ciclos, máximo 4).
        $filas = ProfesorSuplencia::all(['titular_id', 'suplente_id'])
            ->map(fn ($s) => ['titular_id' => (int) $s->titular_id, 'suplente_id' => (int) $s->suplente_id])
            ->all();
        if ($error = Suplencias::validarEslabon($filas, $profesor->id, $suplenteId)) {
            return response()->json(['message' => $error], 422);
        }

        // La materia tiene que ser una que el titular raíz enseña: el suplente
        // cubre materias del titular, no inventa nuevas. En cadena se mira la
        // raíz (el primer titular), porque los eslabones intermedios no tienen
        // por qué tener la materia en su propia ficha.
        $raizId = $this->raizDe($profesor->id);
        $raizCubre = ProfesorAsignacion::where('profesor_id', $raizId)
            ->where('anio', $anio)
            ->where('materia_id', $materiaId)
            ->when($division !== null,
                fn ($q) => $q->where('division', $division),
                fn ($q) => $q->whereNull('division'))
            ->exists();
        if (!$raizCubre) {
            $raiz = Profesor::find($raizId);
            $quien = $raiz ? ($raiz->apellido . ', ' . $raiz->nombre) : 'el titular';
            return response()->json(
                ['message' => $quien . ' no enseña esa materia en ese curso. Revisá sus asignaciones.'],
                422
            );
        }

        $suplencia = ProfesorSuplencia::create([
            'titular_id'  => $profesor->id,
            'suplente_id' => $suplenteId,
            'anio'        => $anio,
            'division'    => $division,
            'materia_id'  => $materiaId,
            'motivo'      => isset($data['motivo']) ? trim((string) $data['motivo']) : null,
        ]);

        $sup = Profesor::find($suplenteId);
        ActivityLogger::log(
            $req,
            'Asignó suplente a ' . $profesor->apellido . ', ' . $profesor->nombre .
            ' (' . $materia->nombre . '): ' . $sup->apellido . ', ' . $sup->nombre,
            'profesor.suplencia.store',
            'Profesor',
            $profesor->id
        );

        return response()->json($this->presentar(
            $suplencia->load(['suplente:id,nombre,apellido,estado', 'titular:id,nombre,apellido,estado', 'materia:id,nombre,anio']),
            'suplente'
        ), 201);
    }

    // DELETE /api/profesores/suplencias/{id}
    // Quita un eslabón: el suplente deja de cubrir esa materia (pierde el acceso).
    public function destroy(Request $req, $id)
    {
        $suplencia = ProfesorSuplencia::with([
            'titular:id,nombre,apellido',
            'suplente:id,nombre,apellido',
            'materia:id,nombre',
        ])->find($id);

        if (!$suplencia) {
            return response()->json(['message' => 'La suplencia no existe.'], 404);
        }

        ActivityLogger::log(
            $req,
            'Quitó suplente a ' . $suplencia->titular->apellido . ', ' . $suplencia->titular->nombre .
            ' (' . ($suplencia->materia->nombre ?? 'materia') . '): ' .
            $suplencia->suplente->apellido . ', ' . $suplencia->suplente->nombre,
            'profesor.suplencia.destroy',
            'Profesor',
            $suplencia->titular_id
        );

        $suplencia->delete();

        return response()->json(['ok' => true]);
    }

    // Presenta un eslabón con nombres legibles para la pantalla.
    private function presentar(ProfesorSuplencia $s, string $lado): array
    {
        $otro = $lado === 'suplente' ? $s->suplente : $s->titular;

        return [
            'id'          => $s->id,
            'titular_id'  => (int) $s->titular_id,
            'suplente_id' => (int) $s->suplente_id,
            'otro_id'     => $otro ? (int) $otro->id : null,
            'otro_nombre' => $otro ? (trim($otro->apellido . ', ' . $otro->nombre, ', ')) : '—',
            'otro_estado' => $otro->estado ?? null,
            'anio'        => (int) $s->anio,
            'division'    => $s->division,
            'materia_id'  => (int) $s->materia_id,
            'materia'     => $s->materia?->nombre ?? '',
            'motivo'      => $s->motivo,
        ];
    }

    // Cadena completa hacia abajo con nombres, para dibujarla en pantalla.
    private function cadenaConNombres(int $titularId): array
    {
        $filas = ProfesorSuplencia::all(['titular_id', 'suplente_id'])
            ->map(fn ($s) => ['titular_id' => (int) $s->titular_id, 'suplente_id' => (int) $s->suplente_id])
            ->all();

        $niveles = Suplencias::nivelesDesde($filas, $titularId);
        if (!$niveles) return [];

        $ids = array_values(array_unique(array_merge(...$niveles)));
        $nombres = Profesor::whereIn('id', $ids)->get(['id', 'nombre', 'apellido'])
            ->mapWithKeys(fn ($p) => [$p->id => trim($p->apellido . ', ' . $p->nombre, ', ')])
            ->all();

        return array_map(
            fn ($nivel) => array_map(
                fn ($id) => ['id' => $id, 'nombre' => $nombres[$id] ?? ('#' . $id)],
                $nivel
            ),
            $niveles
        );
    }

    // Primer titular de la cadena (subiendo por quienes reemplazan).
    private function raizDe(int $titularId): int
    {
        $vistos = [$titularId => true];
        $actual = $titularId;
        for ($i = 0; $i < 10; $i++) {
            $prev = ProfesorSuplencia::where('suplente_id', $actual)->value('titular_id');
            if (!$prev || isset($vistos[(int) $prev])) break;
            $actual = (int) $prev;
            $vistos[$actual] = true;
        }

        return $actual;
    }
}
