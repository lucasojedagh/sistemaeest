// Sello de datos: remember en que momento cada pantalla vio la informacion.
//
// El servidor devuelve un numero por grupo de datos (alumnos, profesores,
// cursos, asistencia, calificaciones, usuarios, documentos). Ese numero es el
// id del ultimo cambio registrado. Si al volver a una pantalla el numero es
// mayor que el que esa pantalla guardo, es que alguien toco esos datos: se
// muestra el boton "Actualizar" en vez de recargar solo.
//
// El sello se guarda POR PANTALLA: asi el tablero (que mira todo) no se come
// la novedad que corresponde a la pantalla de Alumnos.
//
// Este archivo solo maneja la copia local (sin red) para poder usarse desde
// api.js sin generar una dependencia circular.

const PREFIJO = 'datos:sello:';
const CLAVE_PROPIO = 'datos:miCambio';

// Ventana en la que un cambio hecho por esta misma cuenta no se reporta:
// la pantalla que guardo ya se actualizo sola, no hace falta avisarle.
const MS_PROPIO = 20000;

function leerJson(clave, porDefecto) {
  try {
    const raw = localStorage.getItem(clave);
    if (!raw) return porDefecto;
    const v = JSON.parse(raw);
    return v && typeof v === 'object' ? v : porDefecto;
  } catch { return porDefecto; }
}

function escribirJson(clave, valor) {
  try { localStorage.setItem(clave, JSON.stringify(valor)); } catch { /* storage no disponible */ }
}

/** El sello de la ultima vez que esta pantalla miro la informacion. */
export function leerSello(pantalla) {
  return leerJson(PREFIJO + pantalla, {});
}

/**
 * Guarda el sello de una pantalla: a partir de aqui no hay novedades para ella.
 * Solo guarda las categorias que mira, para no pisar lo de otras pantallas.
 */
export function guardarSello(pantalla, sello, categorias) {
  if (!pantalla || !sello || typeof sello !== 'object') return;
  const lista = (Array.isArray(categorias) && categorias.length)
    ? categorias
    : Object.keys(sello);
  const previo = leerSello(pantalla);
  const nuevo = { ...previo };
  let guardoTodo = true;
  lista.forEach((c) => {
    if (c in sello) nuevo[c] = Number(sello[c]);
    else guardoTodo = false;
  });
  escribirJson(PREFIJO + pantalla, nuevo);
  // El aviso de "yo guarde esto" solo se descarta cuando la pantalla que
  // comproboro es justamente de los datos que se guardaron.
  if (guardoTodo || cambioPropioCubierto(lista)) limpiarCambioPropio();
}

/**
 * True si alguno de los grupos que mira la pantalla cambio desde la ultima
 * vez que esa pantalla guardo su sello.
 */
export function hayCambios(pantalla, selloServidor, categorias) {
  if (!pantalla || !selloServidor || typeof selloServidor !== 'object') return false;
  const guardado = leerSello(pantalla);
  // Sin sello previo no hay con que comparar: la pantalla recien se abre y
  // los datos que se ven ya son los del servidor.
  if (!Object.keys(guardado).length) return false;
  const lista = (Array.isArray(categorias) && categorias.length)
    ? categorias
    : Object.keys(selloServidor);
  return lista.some((c) => {
    // Grupo sin registro previo: se esta mirando por primera vez, no es
    // una novedad (no hay nada con que comparar).
    if (!(c in guardado)) return false;
    return Number(selloServidor[c] ?? 0) > Number(guardado[c]);
  });
}

/**
 * Marca que esta cuenta acaba de guardar algo en estos grupos, para que el
 * boton "Actualizar" no aparezca por un cambio propio que la pantalla ya
 * mostro (llamado desde api.js tras cada escritura exitosa).
 */
export function marcarCambioPropio(categorias) {
  try {
    const lista = Array.isArray(categorias) && categorias.length ? categorias : ['otros'];
    escribirJson(CLAVE_PROPIO, { categorias: lista, ts: Date.now() });
  } catch { /* storage no disponible */ }
}

/** True si el cambio fue hecho por esta misma cuenta hace nada. */
export function cambioPropioVigente(categorias) {
  const info = leerJson(CLAVE_PROPIO, null);
  if (!info || !Array.isArray(info.categorias)) return false;
  if (Date.now() - Number(info.ts || 0) > MS_PROPIO) return false;
  const lista = Array.isArray(categorias) && categorias.length ? categorias : [];
  if (!lista.length) return false;
  return info.categorias.some((c) => lista.includes(c) || c === 'otros');
}

/** True si el cambio propio reciente cae en estas categorias. */
function cambioPropioCubierto(categorias) {
  const info = leerJson(CLAVE_PROPIO, null);
  if (!info || !Array.isArray(info.categorias)) return false;
  return info.categorias.every((c) => categorias.includes(c));
}

export function limpiarCambioPropio() {
  try { localStorage.removeItem(CLAVE_PROPIO); } catch { /* storage no disponible */ }
}

/** Borra los sellos de todas las pantallas (al cerrar sesion, por ejemplo). */
export function borrarSellos() {
  try {
    Object.keys(localStorage)
      .filter((k) => k.startsWith(PREFIJO))
      .forEach((k) => localStorage.removeItem(k));
    limpiarCambioPropio();
  } catch { /* storage no disponible */ }
}