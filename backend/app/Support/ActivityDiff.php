<?php

namespace App\Support;

/**
 * Diff centralizado de la actividad.
 *
 * Un solo lugar decide qué campos "cambiaron" de verdad, para que el historial
 * muestre solo lo que la persona tocó y no el registro completo.
 *
 * Se usa desde dos lados y ambos comparten las mismas reglas:
 *  - ActivityLogger: al guardar, para NO almacenar lo que no cambió.
 *  - HistorialController: al presentar, para describir el cambio.
 *
 * Hay formatos de diff especializados (materias, planillas, asignaciones,
 * divisiones) que necesitan la foto completa para poder detectar altas y
 * bajas. Esos se respetan tal cual: filtrarlos rompe el resumen.
 */
class ActivityDiff
{
    /** Campos internos que nunca se muestran como cambio. */
    const CAMPOS_OCULTOS = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'api_token', 'email_verified_at', 'remember_token', 'password',
    ];

    /**
     * Reduce old/new a los campos que realmente cambiaron.
     * Devuelve [old, new]. Si el formato no es un objeto simple (lista o
     * formato especializado), devuelve el par original sin tocar.
     */
    public static function cambios($old, $new): array
    {
        // Formatos especializados: necesitan el par completo.
        if (!self::esObjetoSimple($old) || !self::esObjetoSimple($new)) {
            return [$old, $new];
        }
        if (self::esMaterias($old, $new) || self::esAsignaciones($new)) {
            return [$old, $new];
        }

        $soloOld = [];
        $soloNew = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $campo) {
            if (in_array($campo, self::CAMPOS_OCULTOS, true)) continue;
            $a = $old[$campo] ?? null;
            $b = $new[$campo] ?? null;
            if (self::igual($a, $b)) continue;
            $soloOld[$campo] = $a;
            $soloNew[$campo] = $b;
        }

        return [$soloOld, $soloNew];
    }

    /**
     * Comparación tolerante: evita "falsos cambios" por diferencias de formato
     * (null vs vacío, 0 vs false, 7.50 vs 7.5, espacios o mayúsculas de más).
     */
    public static function igual($a, $b): bool
    {
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b)) return false;
            if (count($a) !== count($b)) return false;
            $ka = array_keys($a); $kb = array_keys($b);
            sort($ka); sort($kb);
            if ($ka !== $kb) return false;
            foreach ($a as $k => $v) {
                if (!self::igual($v, $b[$k])) return false;
            }
            return true;
        }

        $na = self::normalizar($a);
        $nb = self::normalizar($b);
        if ($na === $nb) return true;
        if (is_numeric($na) && is_numeric($nb)) return (float) $na === (float) $nb;
        return false;
    }

    /** Reduce un valor a una forma comparable (trim + sin acentos ni mayúsculas). */
    private static function normalizar($v): string
    {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_object($v)) return json_encode($v);
        if (is_array($v)) return (string) json_encode($v);
        $s = trim((string) $v);
        // Sin acentos ni mayúsculas: "Activo" y "activo " son el mismo dato.
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        if (function_exists('iconv')) {
            $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        }
        return trim((string) $s);
    }

    /** Un mapa clave => valor (no una lista ni null/vacío). */
    public static function esObjetoSimple($v): bool
    {
        return is_array($v) && $v !== [] && !array_is_list($v);
    }

    /** Formato de materias.replace: claves '#id', 'Nueva: X' o 'Eliminada: X'. */
    public static function esMaterias($old, $new): bool
    {
        foreach ([$old, $new] as $lado) {
            if (!is_array($lado) || array_is_list($lado)) continue;
            foreach (array_keys($lado) as $k) {
                $k = (string) $k;
                if (str_starts_with($k, 'Nueva: ') || str_starts_with($k, 'Eliminada: ')
                    || preg_match('/^#\d+$/', $k)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Formato de asignaciones de usuario: hay una clave 'asignaciones' con lista. */
    public static function esAsignaciones($new): bool
    {
        return is_array($new) && isset($new['asignaciones']) && is_array($new['asignaciones']);
    }

    /**
     * Mapa por alumno: cada clave es un alumno y su valor un mapa de campos.
     * Es el formato de la planilla de calificaciones. El mapa vacío cuenta: es
     * el alumno que todavía no tiene ninguna nota, que es justo el caso más
     * frecuente la primera vez que se carga un curso.
     */
    public static function esPlanilla($arr): bool
    {
        if (!is_array($arr) || $arr === [] || array_is_list($arr)) return false;
        foreach ($arr as $v) {
            if ($v === []) continue;
            if (!is_array($v) || array_is_list($v)) return false;
        }
        return true;
    }

    /**
     * Nombre legible de un campo, en criollo. Un solo diccionario para que el
     * historial no muestre "p1_notas" ni "Tiene ppi" a quien no usa el sistema.
     */
    const ETIQUETAS = [
        // Personas
        'name' => 'Nombre', 'apellido' => 'Apellido', 'email' => 'Correo',
        'dni' => 'DNI', 'genero' => 'Género', 'fecha_nacimiento' => 'Fecha de nacimiento',
        'telefono' => 'Teléfono', 'direccion' => 'Dirección', 'parentesco' => 'Parentesco',
        // Alumnos
        'estado' => 'Estado', 'especialidad' => 'Especialidad', 'observaciones' => 'Observaciones',
        'tiene_ppi' => 'Planilla PPI', 'tiene_ae' => 'Adecuación curricular',
        'recursando' => 'Materias que recursa', 'intensificacion' => 'Intensificación',
        // Cursos y materias
        'anio' => 'Año', 'division' => 'División', 'grupo' => 'Grupo', 'turno' => 'Turno',
        'cupo' => 'Cupo', 'activo' => 'Activo', 'tipo' => 'Tipo', 'curso' => 'Curso',
        // Roles
        'rol' => 'Rol', 'role' => 'Rol', 'rol_solicitado' => 'Rol solicitado',
        'status' => 'Estado de la cuenta', 'password' => 'Contraseña',
        'suplente' => 'Suplente', 'roles' => 'Quiénes lo pueden ver',
        // Calificaciones
        'p1_notas' => 'Notas 1.° cuatrimestre', 'p2_notas' => 'Notas 2.° cuatrimestre',
        'p1_preliminar' => 'Preliminar 1.° cuatrimestre', 'p2_preliminar' => 'Preliminar 2.° cuatrimestre',
        'p1_preliminar_notas' => 'Preliminar 1.° cuatrimestre', 'p2_preliminar_notas' => 'Preliminar 2.° cuatrimestre',
        'p1_final_cuatri' => 'Nota final 1.° cuatrimestre', 'p2_final_cuatri' => 'Nota final 2.° cuatrimestre',
        'final_anio' => 'Nota final del año',
        'int_1cuatri' => 'Intensificación 1.° cuatrimestre', 'int_dic' => 'Intensificación diciembre',
        'int_feb' => 'Intensificación febrero', 'int_mar_abr' => 'Intensificación marzo-abril',
        // Asistencia y horario
        'hora_inicio' => 'Hora de inicio', 'hora_fin' => 'Hora de fin',
        'comentario' => 'Comentario', 'alumnos' => 'Alumnos',
    ];

    /** Etiqueta legible de un campo; si no está en el diccionario, la derivamos. */
    public static function etiqueta(string $campo): string
    {
        if (isset(self::ETIQUETAS[$campo])) return self::ETIQUETAS[$campo];
        // Si la clave ya viene rotulada (ej: "Año 3", "García, Benjamín") se
        // respeta tal cual; si no, se deriva de los guiones bajos.
        if (str_contains($campo, ' ')) return $campo;
        $txt = str_replace('_', ' ', $campo);
        $txt = function_exists('mb_convert_case')
            ? mb_convert_case($txt, MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower($txt));
        return $txt;
    }
}
