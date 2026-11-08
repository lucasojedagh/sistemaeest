// Funciones API del apartado "Roles y permisos" del módulo Usuarios.
import { apiGet, apiPost, apiPut, apiDelete } from "../lib/api";

// Etiquetas y rangos de todos los roles (sistema y personalizados).
export async function apiRolDetalle() {
  return apiGet("/roles/detalle"); // { rol: { nombre, nivel, tipo } }
}

// Un bloque por módulo (acciones + área de datos + entradas del menú) + estado
// de cada rol, para pintar la pestaña.
export async function apiRolesConfig() {
  return apiGet("/admin/roles"); // { bloques, etiquetas_alcance, roles, plantillas, mi_nivel }
}

// Crea un rol personalizado. Puede arrancar desde una plantilla.
export async function apiCrearRol(payload) {
  return apiPost("/admin/roles", payload);
}

// Edita un rol: permisos y rango (personalizado) o quitas (rol de sistema).
export async function apiEditarRol(rol, payload) {
  return apiPut(`/admin/roles/${encodeURIComponent(rol)}`, payload);
}

// Elimina un rol personalizado (falla si todavía hay cuentas que lo usan).
export async function apiEliminarRol(rol) {
  return apiDelete(`/admin/roles/${encodeURIComponent(rol)}`);
}
