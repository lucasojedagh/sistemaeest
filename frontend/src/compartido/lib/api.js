// Capa unica de red del frontend.
// TODAS las peticiones al backend pasan por aca: apiGet / apiPost / apiPut / apiDelete.
// - Agrega automaticamente el token (lib/authToken).
// - Ante error lanza Error con el `message` que devuelve la API (fallback por metodo),
//   y adjunta `status` y `data` para quien necesite mas detalle.
// - Todas aceptan como ultimo argumento un AbortSignal suelto o { signal } para cancelar.

import { authHeaders, handleUnauthorized } from "./authToken";
import { clearCache, clearPrefijo } from "./pageCache";
import { marcarCambioPropio } from "./dataStamp";

// Qué copias guardar hay que tirar cuando una escritura tiene éxito, y qué
// grupos de datos tocó (para que el refresco automático no recargue por un
// cambio propio que la pantalla ya está mostrando).
// Clave: comienzo de la ruta (sin query). Orden: lo más específico primero.
// El historial registra casi todo, así que se invalida en cualquier escritura.
const INVALIDACION_POR_RUTA = [
  // Operaciones masivas: cambian años, alumnos y cursos a la vez.
  [["/ciclo/", "/alumnos/promocionar", "/admin/years/enable"], () => clearCache(), ["cursos", "alumnos"]],
  // Un profesor touchado cambia la lista, sus cursos (ambito), los cursos que
  // aparecen para pasar lista y los numeros del tablero.
  [["/profesores"], () => {
    clearCache("profesores/activos");
    clearCache("ambito");
    clearCache("asistencia:cursos");
    clearCache("dashboard/data");
  }, ["profesores"]],
  [["/alumnos"], () => { clearCache("alumnos/lista"); clearCache("dashboard/data"); clearCache("asistencia:cursos"); }, ["alumnos"]],
  [["/admin/users", "/admin/roles"], () => {
    clearCache("adminUsers");
    clearCache("ambito");
    clearCache("profesores/activos");
    clearPrefijo("preceptores/");
    clearCache("dashboard/data");
  }, ["usuarios"]],
  [["/anios", "/materias", "/divisiones", "/cursos", "/horarios"], () => {
    clearCache("materias/todas");
    clearCache("divisiones");
    clearCache("profesores/divisiones");
    clearCache("preceptores/divisiones");
    clearPrefijo("materias/curso/");
    clearCache("ambito");
    clearCache("dashboard/data");
    clearCache("asistencia:cursos");
  }, ["cursos"]],
  [["/asistencia"], () => { clearCache("asistencia:cursos"); clearCache("dashboard/data"); }, ["asistencia"]],
  [["/calificaciones"], () => { clearCache("dashboard/data"); }, ["calificaciones"]],
  [["/documentos"], () => {}, ["documentos"]],
];

// Tras una escritura exitosa se invalidan las copias relacionadas (y se avisa
// a las otras pestañas abiertas). Así la próxima pantalla que se abra, o la
// actual al volver a ella, pide datos frescos en vez de mostrar lo guardado.
function invalidarTrasEscritura(path) {
  try {
    const ruta = `/${String(path || "").split("?")[0]}`.replace(/\/+/g, "/");
    for (const [prefijos, limpiar, grupos] of INVALIDACION_POR_RUTA) {
      const toca = prefijos.some((p) => {
        const base = `/${p}`.replace(/\/+/g, "/").replace(/\/$/, "");
        return ruta === base || ruta.startsWith(base + "/");
      });
      if (toca) {
        limpiar();
        // Este cambio es nuestro: la pantalla que guardó ya se actualizó sola,
        // así que no tiene por qué avisarnos "hay novedades".
        marcarCambioPropio(grupos);
        break;
      }
    }
    // El historial registra la actividad de casi todo: siempre se invalida.
    clearCache("historial");
  } catch { /* invalidar nunca debe romper un guardado */ }
}

// ---- Origen de la API ----------------------------------------------------
// Tres modos soportados (ver frontend/.env.example):
//   1. Sin VITE_API_BASE -> en dev http://localhost:8000/api, en build "/api"
//   2. VITE_API_BASE=/api  (mismo origen; nginx/ruta relativa) -> "/api"
//   3. VITE_API_BASE=https://api.ejemplo.edu/api (otro host)  -> absoluto, tal cual
// Una base RELATIVA (empieza con "/") se CONSERVA: el navegador la resuelve
// contra el origen que está sirviendo la página, así que el mismo build funciona
// en localhost, en la IP/dominio del colegio o detrás de un proxy sin recompilar.
// El default cuando NO hay variable depende de cómo se corre el código:
//   - `npm run dev`  -> el `php artisan serve` de la misma máquina.
//   - `npm run build` -> mismo origen: el dist copiado al server habla con el
//     "/api" que le sirve la página. Quemar localhost en el bundle haría que
//     cada PC de la escuela buscara la API en su propio equipo y el login no
//     abriera ni desde el server.
const DEV_API_BASE = "http://localhost:8000/api";

function resolverApiBase(valor) {
  const crudo = String(valor ?? "").trim();
  if (!crudo) return import.meta.env.DEV ? DEV_API_BASE : "/api";
  // URL absoluta http/https: se respeta, solo se le quita la barra final.
  if (/^https?:\/\//i.test(crudo)) return crudo.replace(/\/+$/, "");
  // Relativa: se normaliza a raiz-de-origen ("/api") para que no dependa de la
  // ruta donde está el SPA (/alumnos, /login, ...). Quien escriba "api" sin
  // barra también termina en "/api".
  const conBarra = crudo.startsWith("/") ? crudo : `/${crudo}`;
  return conBarra.replace(/\/+$/, "") || "/";
}

// Forma ESTÁTICA (sin `?.` entre env y la clave): es lo que Vite reemplaza en
// el build. Con `import.meta?.env?.X` la sustitución no ocurre y el bundle
// lee un undefined en el navegador, cayendo siempre al default de localhost.
export const API_BASE = resolverApiBase(import.meta.env.VITE_API_BASE);

function joinUrl(base, path) {
  if (/^https?:\/\//i.test(path)) return path;
  return `${base}/${String(path).replace(/^\/+/, "")}`;
}

// URL lista para usar a partir de una ruta de la API ("alumnos/1" o "/alumnos/1").
// Exportada porque se necesita también fuera de fetch: <img src>, <a href> y
// window.open. Con API_BASE relativa devuelve "/api/..." (el navegador le pone
// el origen); con API_BASE absoluta devuelve la URL completa.
export function apiUrl(path) {
  return joinUrl(API_BASE, path);
}

// Origen del backend SIN el prefijo /api, para lo que no pasa por la API:
// imprimibles servidos por PHP (/print/..., /calificaciones/planilla, /horarios/planilla).
// Con API_BASE relativa queda "": el navegador usa el origen de la pagina, que
// es el mismo server que hace de proxy hacia PHP.
export const BACKEND_BASE = /^https?:\/\//i.test(API_BASE)
  ? API_BASE.replace(/\/api$/i, "").replace(/\/+$/, "")
  : "";

// Origen absoluto del backend (lo necesita postMessage, que trabaja con origen
// y no con ruta). Si la base es relativa, el iframe se sirve desde esta pagina.
export const BACKEND_ORIGIN = (() => {
  try {
    return new URL(BACKEND_BASE || (typeof window !== "undefined" ? window.location.href : "")).origin;
  } catch {
    return typeof window !== "undefined" ? window.location.origin : "";
  }
})();

// Lee el cuerpo de una respuesta sin romperse si viene vacio o no es JSON.
async function readSafely(res) {
  try {
    const ct = res.headers.get("content-type") || "";
    if (/json/i.test(ct)) return await res.json();
    const text = await res.text();
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
}

// Normaliza el argumento opcional: acepta AbortSignal directo o { signal }.
function normOpts(opts) {
  if (!opts) return {};
  return opts instanceof AbortSignal ? { signal: opts } : opts;
}

async function apiRequest(method, path, body, opts) {
  const { signal } = normOpts(opts);
  const url = apiUrl(path);
  const res = await fetch(url, {
    method,
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
      ...authHeaders(),
    },
    body: body !== undefined ? JSON.stringify(body ?? {}) : undefined,
    signal,
  });

  const data = await readSafely(res);

  // Sesión inválida/expirada: limpia el token y redirige a /login una sola vez.
  // (No aplica a rutas públicas como /login, que nunca devuelven 401.)
  if (res.status === 401) {
    handleUnauthorized();
  }

  if (!res.ok) {
    // Mensaje en criollo: nunca mostrar método/URL/códigos al usuario final.
    const err = new Error(
      (data && data.message) || "No se pudo completar la acción. Revisá tu conexión e intentá de nuevo."
    );
    err.status = res.status;
    err.data = data;
    throw err;
  }

  // Escritura exitosa: se invalidan las copias guardadas relacionadas para
  // que lo próximo que se abra pida datos frescos.
  if (method !== "GET" && method !== "HEAD") {
    invalidarTrasEscritura(path);
  }

  return typeof data === "object" && data !== null ? data : data ?? {};
}

export function apiGet(path, opts) {
  return apiRequest("GET", path, undefined, opts);
}

export function apiPost(path, body, opts) {
  return apiRequest("POST", path, body ?? {}, opts);
}

export function apiPut(path, body, opts) {
  return apiRequest("PUT", path, body, opts);
}

export async function apiDelete(path, opts) {
  const data = await apiRequest("DELETE", path, undefined, opts);
  return data ?? { ok: true };
}

// Subida de archivos (multipart/form-data). El Content-Type lo pone el navegador
// con el boundary correcto: NO debe setearse manualmente.
export async function apiUpload(path, formData, opts) {
  const { signal } = normOpts(opts);
  const url = apiUrl(path);
  const res = await fetch(url, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...authHeaders(),
    },
    body: formData,
    signal,
  });
  const data = await readSafely(res);
  if (res.status === 401) {
    handleUnauthorized();
  }
  if (!res.ok) {
    // Mensaje en criollo: nunca mostrar método/URL/códigos al usuario final.
    const err = new Error((data && data.message) || "No se pudo subir el archivo. Revisá tu conexión e intentá de nuevo.");
    err.status = res.status;
    err.data = data;
    throw err;
  }
  invalidarTrasEscritura(path);
  return data ?? {};
}

// Descarga de archivos binarios (PDF, XLS, etc.). Devuelve un Blob listo para
// object URLs / descargas / impresión.
export async function apiBlob(path, opts) {
  const { signal } = normOpts(opts);
  const url = apiUrl(path);
  const res = await fetch(url, { headers: { ...authHeaders() }, signal });
  if (res.status === 401) {
    handleUnauthorized();
  }
  if (!res.ok) {
    const data = await readSafely(res);
    // Mensaje en criollo: nunca mostrar método/URL/códigos al usuario final.
    const err = new Error((data && data.message) || "No se pudo descargar el archivo. Revisá tu conexión e intentá de nuevo.");
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return res.blob();
}

// Subida de un archivo que devuelve otro archivo (por ejemplo el reporte de
// filas rechazadas de una importación). Devuelve Blob, no JSON.
export async function apiUploadBlob(path, formData) {
  const url = apiUrl(path);
  const res = await fetch(url, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...authHeaders(),
    },
    body: formData,
  });
  if (res.status === 401) {
    handleUnauthorized();
  }
  if (!res.ok) {
    const data = await readSafely(res);
    const err = new Error((data && data.message) || "No se pudo generar el archivo. Revisá tu conexión e intentá de nuevo.");
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return res.blob();
}
