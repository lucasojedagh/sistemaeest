// ============================================================================
// Módulo central de etiquetas de curso (año + división).
//
// Formato de visualización unificado en todo el sistema:
//   año 1 -> "1ro", 2 -> "2do", 3 -> "3ro", 4 -> "4to", 5 -> "5to",
//   6 -> "6to", 7 -> "7mo"
//   división 1 -> "1ra", 2 -> "2da", 3 -> "3ra", 4 -> "4ta", 5 -> "5ta",
//   6 -> "6ta", 7 -> "7ma", 8 -> "8va", 9 -> "9na"
//
// Ejemplos de etiqueta completa: "1ro 1ra", "2do 3ra", "7mo 9na".
//
// El backend siempre guarda los datos como números/strings:
//   { anio: int (1-7), division: string ("1"-"9") | null }
// Este módulo es la ÚNICA fuente de verdad para convertir entre la
// representación de datos (anio/division) y la etiqueta visible (ordinales).
// ============================================================================

// Año máximo soportado por el sistema escolar.
export const MAX_ANIO = 7;

// Divisiones válidas (1 a 9), según el esquema definido.
export const DIVISIONES_VALIDAS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

// Ordinales de año (índice = año - 1).
const ANIO_ORDINAL = ['1.º', '2.º', '3.º', '4.º', '5.º', '6.º', '7.º'];

// Ordinales de división (índice = división - 1).
const DIVISION_ORDINAL = ['1.ª', '2.ª', '3.ª', '4.ª', '5.ª', '6.ª', '7.ª', '8.ª', '9.ª'];

// Convierte un año numérico (1-7) a su ordinal visible ("3ro").
// Devuelve '' si no es un año válido.
export function anioToOrdinal(anio) {
  const n = Number(anio);
  if (!Number.isInteger(n) || n < 1 || n > ANIO_ORDINAL.length) return '';
  return ANIO_ORDINAL[n - 1];
}

// Convierte una división ("1"-"9", null) a su ordinal visible ("3ra").
// Devuelve '' si la división es nula/vacía o inválida.
export function divisionToOrdinal(division) {
  if (division === null || division === undefined || division === '') return '';
  const n = Number(division);
  if (!Number.isInteger(n) || n < 1 || n > DIVISION_ORDINAL.length) return '';
  return DIVISION_ORDINAL[n - 1];
}

// Normaliza un valor de división a su forma de datos ("3").
// Acepta "3", "3ra", "C", etc. Devuelve null si no hay división.
export function divisionToDato(division) {
  if (division === null || division === undefined || division === '') return null;
  const s = String(division).trim();
  if (!s) return null;
  // Ya es un dígito 1-9.
  if (/^[1-9]$/.test(s)) return s;
  // Viene como ordinal ("3ra") o letra - extrae el primer dígito.
  const m = s.match(/[1-9]/);
  return m ? m[0] : null;
}

// Construye la etiqueta visible de un curso a partir de sus datos.
//   cursoLabel({ anio: 3, division: '2' }) -> "3ro 2da"
//   cursoLabel({ anio: 3 })               -> "3ro"
// También acepta un string ya formateado y lo re-normaliza.
export function cursoLabel(input) {
  if (input == null) return '';

  // Si es un string, parsearlo primero.
  if (typeof input === 'string') {
    const parsed = parseCurso(input);
    return cursoLabel(parsed);
  }

  const anio = Number(input.anio ?? input.anio_num ?? input.grado);
  const division = input.division ?? input.div ?? null;

  const aOrd = anioToOrdinal(anio);
  if (!aOrd) return '';
  const dOrd = divisionToOrdinal(division);
  return dOrd ? `${aOrd} ${dOrd}` : aOrd;
}

// Parsea una etiqueta de curso (en cualquier formato conocido) y devuelve
// la representación de datos { anio: number, division: string|null }.
// Soporta:
//   "3ro 2da", "3ro", "3° A", "3°", "3 A", "3-A", "3"
// También acepta directamente un objeto { anio, division }.
export function parseCurso(val) {
  if (val == null) return { anio: null, division: null };
  if (typeof val === 'object') {
    const anio = Number(val.anio ?? val.anio_num ?? val.grado);
    const division = divisionToDato(val.division ?? val.div);
    return {
      anio: Number.isInteger(anio) && anio >= 1 ? anio : null,
      division,
    };
  }

  const s = String(val).trim();
  if (!s) return { anio: null, division: null };

  // Quitar símbolo de grado, ordinal, puntos y separadores.
  const limpio = s.replace(/[°ºª.]/g, ' ').replace(/\s+/g, ' ').trim();

  // Extraer el primer número (año).
  const anioMatch = limpio.match(/\d+/);
  const anio = anioMatch ? Number(anioMatch[0]) : null;

  // Remover el año del string para buscar división separadamente.
  let resto = anioMatch ? limpio.replace(/\d+/, '').trim() : limpio;

  // Extraer división: un dígito 1-9, o letra A-I (mapeadas), o ordinal.
  let division = null;
  if (resto) {
    const digitMatch = resto.match(/(?:^|\s)([1-9])(?=\s|$)/);
    if (digitMatch) {
      division = digitMatch[1];
    } else {
      const letterMatch = resto.match(/(?:^|\s)([A-Ia-i])(?=\s|$)/);
      if (letterMatch) {
        const map = { A: '1', B: '2', C: '3', D: '4', E: '5', F: '6', G: '7', H: '8', I: '9' };
        division = map[letterMatch[1].toUpperCase()];
      }
    }
  }

  return {
    anio: anio && anio >= 1 && anio <= MAX_ANIO ? anio : null,
    division: division || null,
  };
}

// Normaliza cualquier representación de curso a su etiqueta canónica.
//   normalizeCurso("3° A") -> "3ro 1ra"
export function normalizeCurso(val) {
  return cursoLabel(parseCurso(val));
}

// Compara si un alumno/curso pertenece a una asignación { anio, division }.
// Usado para permisos de preceptor/profesor por ámbito.
//   matchAsignacion({ anio: 3, division: '1' }, { anio: 3, division: '1' }) -> true
//   matchAsignacion({ anio: 3, division: null }, { anio: 3, division: '5' }) -> true (asignación a todo el año)
export function matchAsignacion(curso, asignacion) {
  if (!curso || !asignacion) return false;
  const c = parseCurso(curso);
  const a = parseCurso(asignacion);
  if (!c.anio || !a.anio) return false;
  if (Number(c.anio) !== Number(a.anio)) return false;
  if (!a.division) return true; // asignado a todo el año
  return c.division === a.division;
}

// Devuelve true si el curso está dentro de la lista de asignaciones.
export function cursoEnAsignaciones(curso, asignaciones) {
  if (!Array.isArray(asignaciones) || !asignaciones.length) return false;
  return asignaciones.some((a) => matchAsignacion(curso, a));
}

// Genera la lista de cursos disponibles a partir del mapa de /divisiones.
//   buildCursosDisponiblesFromMap({ "1": ["1","2"], "7": ["1"] }) ->
//     [{ anio: '1ro', anioNum: 1, divisiones: ['1ra','2ra'] }, ...]
export function buildCursosDisponiblesFromMap(mapa) {
  const keys = Object.keys(mapa || {})
    .map((k) => Number(k))
    .filter((n) => Number.isFinite(n) && (mapa[n]?.length ?? 0) > 0)
    .sort((a, b) => a - b);

  return keys.map((n) => ({
    anio: anioToOrdinal(n),
    anioNum: n,
    divisiones: [...(mapa[n] || [])].map(String).map(divisionToOrdinal),
  }));
}

// ============================================================================
// Especialidades técnicas (solo a partir de 4to año).
// Las claves coinciden con el backend (Alumno::ESPECIALIDADES).
// ============================================================================
export const ANIO_ESPECIALIDAD_DESDE = 4;

export const ESPECIALIDADES = [
  { key: 'informatica',     label: 'Técnico en Informática P. y P.' },
  { key: 'mmo',             label: 'Maestro Mayor de Obras' },
  { key: 'electromecanica', label: 'Técnico en Electromecánica' },
  { key: 'quimica',         label: 'Técnico Químico' },
];

// Devuelve la etiqueta legible de una especialidad a partir de su clave.
export function especialidadLabel(key) {
  const found = ESPECIALIDADES.find((e) => e.key === key);
  return found ? found.label : (key || '');
}

