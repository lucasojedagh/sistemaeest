const PREFIX = 'pageCache:';
const SCHEMA_VERSION = 4; // Incrementar si cambia la forma de los datos cacheados
const memoryCache = new Map(); // key -> { data }
const MAX_JSON_BYTES = 120_000; // ~120KB: listas grandes solo en memoria, evita lag de stringify/parse + localStorage

// La copia de localStorage sólo sirve durante una jornada. Sin este tope, el
// dato de ayer se leía como si fuera el de hoy: si la API no respondía, la
// pantalla mostraba un padrón viejo sin decir nada. En memoria no aplica: la
// pestaña abierta se revalida sola al recuperar el foco.
const MAX_ANTIGUEDAD_MS = 12 * 60 * 60 * 1000;

/* Invalida todo el cache si la versión de esquema cambió */
function ensureSchemaVersion() {
  try {
    const stored = localStorage.getItem(PREFIX + '_schemaVersion');
    if (stored !== String(SCHEMA_VERSION)) {
      const keys = Object.keys(localStorage).filter(k => k.startsWith(PREFIX));
      keys.forEach(k => { if (k !== PREFIX + '_schemaVersion') localStorage.removeItem(k); });
      memoryCache.clear();
      localStorage.setItem(PREFIX + '_schemaVersion', String(SCHEMA_VERSION));
    }
  } catch { /* storage no disponible */ }
}

export function getCached(key) {
  ensureSchemaVersion();
  // Memory cache first (instant, survives SPA navigation)
  const hit = memoryCache.get(key);
  if (hit) return hit.data;
  // Fallback to localStorage on cold start
  try {
    const raw = localStorage.getItem(PREFIX + key);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed._ts !== 'number' || Date.now() - parsed._ts > MAX_ANTIGUEDAD_MS) {
      localStorage.removeItem(PREFIX + key);
      return null;
    }
    memoryCache.set(key, { data: parsed._data });
    return parsed._data;
  } catch { return null; }
}

export function setCache(key, data) {
  ensureSchemaVersion();
  memoryCache.set(key, { data });
  // Las listas grandes (padron completo) no entran en localStorage ni conviene
  // medirlas con JSON.stringify: serializar 1200 alumnos cuesta 50-200 ms de
  // hilo principal en cada guardado. Se quedan solo en memoria.
  if (Array.isArray(data) && data.length > 300) return;
  try {
    const json = JSON.stringify({ _data: data, _ts: Date.now() });
    if (json.length > MAX_JSON_BYTES) {
      try { localStorage.removeItem(PREFIX + key); } catch { /* storage no disponible */ }
      return;
    }
    localStorage.setItem(PREFIX + key, json);
  } catch { /* storage no disponible */ }
}

export function clearCache(key) {
  if (key) {
    memoryCache.delete(key);
    try { localStorage.removeItem(PREFIX + key); } catch { /* storage no disponible */ }
    avisarOtrasPestanas([key]);
  } else {
    memoryCache.clear();
    try {
      const keys = Object.keys(localStorage).filter(k => k.startsWith(PREFIX));
      keys.forEach(k => localStorage.removeItem(k));
    } catch { /* storage no disponible */ }
    avisarOtrasPestanas(null);
  }
}

/* Borra todas las claves que empiezan con un prefijo (ej: 'materias/curso/').
   Sirve para cachés con claves dinámicas por id. */
export function clearPrefijo(prefijo) {
  const borradas = [];
  memoryCache.forEach((_, k) => { if (k.startsWith(prefijo)) borradas.push(k); });
  borradas.forEach(k => memoryCache.delete(k));
  try {
    Object.keys(localStorage)
      .filter(k => k.startsWith(PREFIX + prefijo))
      .forEach(k => localStorage.removeItem(k));
  } catch { /* storage no disponible */ }
  if (borradas.length) avisarOtrasPestanas(borradas);
}

/* ---------- Sincronización entre pestañas ----------
   El localStorage es compartido: cuando una pestaña invalida claves, las
   demás se enteran por el evento 'storage' y descartan su copia en memoria.
   La pantalla visible se actualiza sola al volver a ella (cada lista
   revalida al recuperar el foco). */
const SYNC_KEY = PREFIX + '_sync';

function avisarOtrasPestanas(claves) {
  try {
    localStorage.setItem(SYNC_KEY, JSON.stringify({ ts: Date.now(), claves }));
  } catch { /* storage no disponible */ }
}

let sincronizacionActiva = false;

/* Llamar una sola vez al arrancar (main.jsx). Devuelve función para detener. */
export function activarSincronizacionEntrePestanas(alCambiar) {
  if (sincronizacionActiva) return () => {};
  sincronizacionActiva = true;
  const alCambiarStorage = (e) => {
    if (!e || e.key !== SYNC_KEY || !e.newValue) return;
    let claves = null;
    try { claves = JSON.parse(e.newValue).claves; } catch { return; }
    if (claves === null) {
      memoryCache.clear();
    } else if (Array.isArray(claves)) {
      claves.forEach(k => memoryCache.delete(k));
    }
    try { alCambiar?.(); } catch { /* callback opcional */ }
  };
  window.addEventListener('storage', alCambiarStorage);
  return () => window.removeEventListener('storage', alCambiarStorage);
}
