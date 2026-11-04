// Búsqueda tolerante: ignora tildes, mayúsculas y puntos/guiones del DNI.
// "garcia" encuentra "García"; "40.000.003" encuentra "40000003".

/** Quita tildes, pasa a minúsculas y recorta espacios. */
export function normalizarTexto(s) {
  return String(s ?? "")
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .trim();
}

/** Deja solo dígitos (para comparar DNIs escritos con puntos o guiones). */
export function soloDigitos(s) {
  return String(s ?? "").replace(/\D/g, "");
}

/**
 * true si `texto` contiene TODAS las palabras de `query`
 * (en cualquier orden), ignorando tildes y mayúsculas.
 */
export function coincideBusqueda(texto, query) {
  const q = normalizarTexto(query);
  if (!q) return true;
  const t = normalizarTexto(texto);
  return q.split(/\s+/).every((palabra) => t.includes(palabra));
}

/**
 * true si el DNI coincide: compara dígitos contra dígitos, así
 * "40.000.003", "40000003" y "40000" encuentran "40000003".
 */
export function coincideDni(dni, query) {
  const qd = soloDigitos(query);
  if (!qd) return false;
  return soloDigitos(dni).includes(qd);
}

/**
 * Si la búsqueda parece un DNI con separadores (tiene dígitos y algún
 * punto/espacio/guion), devuelve solo los dígitos para enviar al servidor
 * (que guarda el DNI sin formato). Si no, devuelve el texto recortado.
 */
export function queryParaServidor(query) {
  const s = String(query ?? "").trim();
  if (/\d/.test(s) && /[.\s-]/.test(s)) {
    const d = soloDigitos(s);
    if (d) return d;
  }
  return s;
}
