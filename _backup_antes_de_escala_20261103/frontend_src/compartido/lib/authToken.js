// Almacen central del token de sesion.
// Unico punto de lectura/escritura del token: mantiene una copia en memoria
// (fuente de verdad durante la sesion) y persiste en localStorage/sessionStorage
// de forma best-effort. Asi funciona aun en navegadores donde el storage
// este bloqueado, restringido o particionado.

let memoryToken = null;
let memoryLoaded = false;

function readLocal() {
  try { return window.localStorage.getItem("token"); } catch { return null; }
}
function readSession() {
  try { return window.sessionStorage.getItem("token_session"); } catch { return null; }
}
function writeLocal(value) {
  try {
    if (value == null) window.localStorage.removeItem("token");
    else window.localStorage.setItem("token", value);
  } catch { /* storage puede estar bloqueado */ }
}
function writeSession(value) {
  try {
    if (value == null) window.sessionStorage.removeItem("token_session");
    else window.sessionStorage.setItem("token_session", value);
  } catch { /* storage puede estar bloqueado */ }
}

export function getToken() {
  if (memoryToken) return memoryToken;
  if (!memoryLoaded) {
    memoryToken = readLocal() || readSession();
    memoryLoaded = true;
  }
  return memoryToken;
}

export function setToken(token) {
  memoryToken = token || null;
  memoryLoaded = true;
  // Nueva sesión: se permite redirigir de nuevo ante el próximo 401.
  redirecting = false;
  writeLocal(memoryToken);
  writeSession(memoryToken);
}

export function clearToken() {
  memoryToken = null;
  memoryLoaded = true;
  writeLocal(null);
  writeSession(null);
}

export function authHeaders() {
  const t = getToken();
  return t ? { Authorization: `Bearer ${t}` } : {};
}

let redirecting = false;

// Manejo global de sesion expirada: limpia el token, notifica al contexto
// de autenticacion y redirige a /login UNA sola vez (evita cascadas y loops).
export function handleUnauthorized() {
  clearToken();
  try {
    if (typeof window !== "undefined") window.dispatchEvent(new Event("auth:expired"));
  } catch { /* dispatch no siempre disponible */ }
  if (redirecting) return;
  redirecting = true;
  try {
    const path = (window.location.pathname || "").replace(/\/+$/, "");
    const alreadyOnLogin = path === "/login" || path === "" || path === "/";
    if (!alreadyOnLogin) {
      const to = path || "/";
      // Parámetro `to`: el mismo que usan los guards y lee Login para volver
      // a la pantalla original después de reingresar.
      window.location.assign(`/login?expired=1&to=${encodeURIComponent(to)}`);
    }
  } catch { /* navegacion fuera de window */ }
}
