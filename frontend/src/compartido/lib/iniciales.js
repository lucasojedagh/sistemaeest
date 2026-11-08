// Las dos letras del avatar, cuando la cuenta no tiene foto.
// El orden es APELLIDO + NOMBRE, que es como la ficha de cada persona siempre las
// mostró. Estaba roto el nombre completo escrito en un solo campo
// ("Carolina Beatriz Gómez"): se tomaba la segunda palabra como apellido y salía
// "BC" en vez de "GC".
const PARTICULAS = ["de", "del", "la", "las", "los", "y"];

const palabras = (texto) => String(texto ?? "").trim().split(/\s+/).filter(Boolean);

const esParticula = (p) => PARTICULAS.includes(p.toLowerCase());

// Primera palabra que realmente es un apellido: "de Preceptores" es P, no D.
const letra = (lista) => {
  for (const p of lista) if (!esParticula(p)) return p[0] || "";
  return "";
};

// En español el apellido va al final, así que la última palabra fuerte lo es.
const ultimaPalabraFuerte = (lista) => {
  for (let i = lista.length - 1; i >= 1; i--) if (!esParticula(lista[i])) return lista[i];
  return lista[lista.length - 1];
};

/**
 * Acepta los campos separados (nombre + apellido) o el nombre completo en el
 * primero. Devuelve "", y el llamador decide su respaldo, si no hay nada.
 */
export function iniciales(nombre, apellido) {
  const dados = palabras(nombre);
  const familia = palabras(apellido);

  if (familia.length) return `${letra(familia)}${letra(dados)}`.toUpperCase();
  if (dados.length < 2) return letra(dados).toUpperCase();

  return `${ultimaPalabraFuerte(dados)[0]}${dados[0][0]}`.toUpperCase();
}

export default iniciales;
