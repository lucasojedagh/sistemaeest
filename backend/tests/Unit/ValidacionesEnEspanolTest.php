<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Tests\TestCase;

/**
 * Todas las reglas de validación que usa el backend tienen que tener su frase
 * en lang/es/validation.php.
 *
 * Cuando falta, Laravel no inventa nada: muestra la clave cruda y el usuario
 * lee "validation.present" dentro del formulario. Pasó con present, between y
 * dimensions; esta prueba recorre el código en vez de esperar a que alguien se
 * tope con el mensaje feo.
 */
class ValidacionesEnEspanolTest extends TestCase
{
    /** Reglas que por diseño no devuelven mensaje (acompañan a otras). */
    private const SIN_MENSAJE = ['nullable', 'sometimes'];

    /**
     * Frases que el validador usa sin ser un método validateX: 'uploaded' la
     * dispara el código UPLOAD_ERR_* de PHP. Sirven para no marcarlas como
     * huérfanas en la segunda prueba.
     */
    private const REGLAS_ESPECIALES = ['uploaded'];

    /** @return array<string, string> regla => dónde se usa (archivo:línea) */
    private function reglasUsadas(): array
    {
        $reglas = [];

        foreach (glob(app_path('**/**/*.php')) ?: [] as $archivo) {
            $lineas = file($archivo);
            $src = implode('', $lineas);

            // Sólo lo que está dentro de un validate([...]) o Validator::make([...]):
            // afuera hay strings iguales que son casts de modelo, no reglas.
            foreach ($this->bloquesValidacion($src) as [$bloque, $desde]) {
                $hasta = substr_count(substr($src, 0, $desde), "\n");
                preg_match_all('/["\']([a-z_]+)(?::[^"\']*)?["\']/', $bloque, $m);

                foreach ($m[1] as $regla) {
                    $reglas[$regla] ??= basename($archivo) . ':' . ($hasta + 1);
                }
            }
        }

        return $reglas;
    }

    /**
     * Extrae el texto de cada arreglo de reglas, buscando el corchete que abre
     * después de validate( y cerrándolo por conteo (los anidados importan).
     *
     * @return array<int, array{0:string,1:int}> [contenido, offset inicial]
     */
    private function bloquesValidacion(string $src): array
    {
        $bloques = [];

        foreach (['->validate(', 'Validator::make(', '::validate('] as $marcador) {
            $desde = 0;
            while (($pos = strpos($src, $marcador, $desde)) !== false) {
                $apertura = strpos($src, '[', $pos);
                $desde = $pos + strlen($marcador);
                if ($apertura === false) {
                    continue;
                }

                $profundidad = 0;
                for ($i = $apertura; $i < strlen($src); $i++) {
                    if ($src[$i] === '[') $profundidad++;
                    elseif ($src[$i] === ']') {
                        $profundidad--;
                        if ($profundidad === 0) {
                            $bloques[] = [substr($src, $apertura, $i - $apertura + 1), $apertura];
                            break;
                        }
                    }
                }
            }
        }

        return $bloques;
    }

    public function test_toda_regla_de_validacion_tiene_su_mensaje_en_espanol(): void
    {
        app()->setLocale('es');

        $usadas = $this->reglasUsadas();
        $this->assertNotEmpty($usadas, 'la lectura de reglas no encontró nada: revisar el parser');

        $faltan = [];
        foreach ($usadas as $regla => $donde) {
            if (in_array($regla, self::SIN_MENSAJE, true)) {
                continue;
            }
            // Es un token del bloque, pero puede ser una opción de 'in:' o un
            // nombre de campo; sólo interesa lo que el framework sepa validar.
            if (!method_exists(Validator::class, 'validate' . Str::studly($regla))) {
                continue;
            }
            if (!Lang::has('validation.' . $regla)) {
                $faltan[$regla] = $donde;
            }
        }

        $descripción = [];
        foreach ($faltan as $regla => $donde) {
            $descripción[] = "validation.{$regla} (se usa en {$donde})";
        }

        $this->assertSame([], $descripción, "reglas sin frase en lang/es/validation.php — en pantalla se lee la clave cruda:\n"
            . implode("\n", $descripción));
    }

    public function test_las_claves_traducidas_no_sobrescriben_una_regra_inexistente(): void
    {
        // Un typo en la clave ('reqired') deja la frase huérfana y el mensaje
        // real sigue saliendo en inglés: se avisa acá, en silencio en la UI.
        $llave = require base_path('lang/es/validation.php');
        unset($llave['attributes']);

        $huerfanas = [];
        foreach (array_keys($llave) as $clave) {
            if (in_array($clave, self::REGLAS_ESPECIALES, true)) {
                continue;
            }
            if (!method_exists(Validator::class, 'validate' . Str::studly($clave))) {
                $huerfanas[] = $clave;
            }
        }

        $this->assertSame([], $huerfanas, 'claves en lang/es/validation.php que no son una regla del framework: '
            . implode(', ', $huerfanas));
    }
}
