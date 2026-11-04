// Archivo que implementa un gate de API: limita la concurrencia de peticiones
// y evita duplicados para la misma combinacion de URL, metodo y cuerpo.
import { getToken, handleUnauthorized } from "./authToken";

export function createApiGate(baseURL, { maxConcurrent = 2 } = {}) {
  const queue = [];
  let running = 0;
  const inflight = new Map(); // key -> Promise

  // Construye los encabezados HTTP estandar incluyendo autenticacion si existe token.
  const JSON_HEADERS = () => ({
    Accept: "application/json",
    "Content-Type": "application/json",
    "X-Requested-With": "XMLHttpRequest",
    ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {}),
  });

  // Genera una clave unica para identificar y deduplicar peticiones identicas.
  const keyOf = (method, url, body) =>
    `${method}|${url}|${body ? JSON.stringify(body) : ""}`;

  // Ejecuta la siguiente peticion en cola si no se ha superado el limite de concurrencia.
  const runNext = () => {
    if (running >= maxConcurrent) return;
    const next = queue.shift();
    if (!next) return;
    running++;
    next().finally(() => {
      running--;
      runNext();
    });
  };

  // Agrega una funcion a la cola de ejecucion y la procesa cuando haya cupo disponible.
  const schedule = (fn) =>
    new Promise((resolve, reject) => {
      queue.push(() => fn().then(resolve, reject));
      runNext();
    });

  // Realiza una peticion HTTP con control de concurrencia y deduplicacion en GET.
  async function request(method, path, { signal, body, headers } = {}) {
    const url = `${baseURL.replace(/\/+$/,"")}/${String(path).replace(/^\/+/,"")}`;
    const k = keyOf(method, url, body);

    // Deduplica peticiones GET: si la misma solicitud ya esta en vuelo, reusa la promesa existente.
    if (method === "GET" && inflight.has(k)) return inflight.get(k);

    const exec = async () => {
      try {
        const res = await fetch(url, {
          method,
          signal,
          headers: { ...JSON_HEADERS(), ...(headers || {}) },
          body: body ? JSON.stringify(body) : undefined,
          redirect: "follow",
        });

        const ctype = (res.headers.get("content-type") || "").toLowerCase();
        // Redirección a /login (sesión caída en una pestaña vieja): se trata
        // como 401 (limpia token y redirige una sola vez).
        if (res.redirected || /\/login/i.test(res.url)) {
          handleUnauthorized();
          const err = new Error("Tu sesión expiró. Volvé a ingresar.");
          err.status = 401;
          throw err;
        }
        // 200 no-JSON inesperado: error genérico en criollo (sin método/URL).
        if (res.ok && !ctype.includes("application/json")) {
          const err = new Error("Respuesta inesperada del servidor. Probá de nuevo.");
          err.status = res.status;
          throw err;
        }

        if (!res.ok) {
          if (res.status === 401) handleUnauthorized();
          let data = null;
          try { data = ctype.includes("json") ? await res.json() : await res.text(); } catch { /* cuerpo no legible: conservar mensaje por defecto */ }
          const err = new Error((data && data.message) || `${method} ${url} ${res.status}`);
          err.status = res.status;
          err.data = data;
          throw err;
        }
        return ctype.includes("json") ? res.json() : res.text();
      } finally {
        inflight.delete(k);
      }
    };

    const p = schedule(exec);
    if (method === "GET") inflight.set(k, p);
    return p;
  }

  return {
    get: (p, o) => request("GET", p, o || {}),
    post: (p, b, o) => request("POST", p, { ...(o || {}), body: b }),
    put: (p, b, o) => request("PUT", p, { ...(o || {}), body: b }),
    patch: (p, b, o) => request("PATCH", p, { ...(o || {}), body: b }),
    del: (p, o) => request("DELETE", p, o || {}),
  };
}
