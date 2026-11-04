// Funciones API para autenticacion y gestion de usuarios.
// Todas las llamadas pasan por la capa unica de red (src/lib/api).
import { apiGet, apiPost, apiPut, apiDelete } from "../lib/api";

/* ===== Auth basica ===== */
// Registra un nuevo usuario en el sistema con nombre, credenciales y rol elegido de la lista.
// El rol lo confirma el admin al verificar; apellido y DNI los completa el admin si hacen falta.
export async function apiRegister({ name, email, password, password_confirmation, rol_solicitado }) {
  return apiPost("/register", { name, email, password, password_confirmation, rol_solicitado }); // { message: '...' }
}

// Inicia sesion y devuelve los datos del usuario junto con el token JWT.
export async function apiLogin({ email, password }) {
  return apiPost("/login", { email, password }); // { user:{id,name,email,role}, token }
}

// Obtiene los datos del usuario autenticado a partir del token vigente.
export async function apiMe() {
  return apiGet("/me"); // { id, name, email, role, ... }
}

// Cierra la sesion. Con todos=1 cierra la de todos los equipos.
export async function apiLogout(todos = false) {
  return apiPost(todos ? "/logout?todos=1" : "/logout", {});
}

/* ===== Equipos con sesion abierta ===== */
// Lista los equipos desde los que la persona esta conectada.
export async function apiSesiones() {
  return apiGet("/auth/sesiones"); // { sesiones: [{id, dispositivo, ip_address, ultimo_uso_at, es_esta}] }
}

// Cierra la sesion de los equipos indicados, o de todos menos el actual
// (todos = true). La sesion desde la que se llama nunca se cierra.
export async function apiCerrarSesiones(ids = [], todos = false) {
  return apiPost(todos ? "/auth/sesiones/cerrar?todos=1" : "/auth/sesiones/cerrar", todos ? {} : { ids });
}

// Obtiene el ambito (rol y asignaciones) del usuario autenticado.
export async function apiAmbito() {
  return apiGet("/me/ambito"); // { role, asignaciones: [{anio, division}] | null }
}

// Actualiza los datos del perfil del usuario autenticado.
export async function apiUpdateProfile(payload) {
  return apiPut("/profile", payload); // { id, name, email, role }
}

/* ===== Roles / Admin ===== */
// Obtiene la lista de roles disponibles en el sistema.
export async function apiRoles() {
  return apiGet("/roles"); // ['admin','director','jefe_preceptores','preceptor','profesor']
}

// Obtiene todos los usuarios del sistema (solo admin).
export async function apiAdminUsers() {
  return apiGet("/admin/users");
}

// Obtiene usuarios filtrados por rol con sus asignaciones en una sola consulta.
export async function apiAdminUsersByRole(role) {
  return apiGet(`/admin/users?role=${role}&with_assignments=1&per_page=200`);
}

// Obtiene los usuarios pendientes de verificacion (solo admin).
export async function apiAdminPendingUsers() {
  return apiGet("/admin/users/pending"); // [{id,name,apellido,email,role,status,created_at}]
}

// Aprueba o rechaza un usuario pendiente segun el estado proporcionado.
// Al activar, el admin puede indicar el rol real a asignar (role).
export async function apiAdminVerifyUser(id, status, _detalles, role) {
  const body = { status };
  if (_detalles) body._detalles = _detalles;
  if (role) body.role = role;
  return apiPut(`/admin/users/${id}/verify`, body); // { id, name, apellido, email, role, status }
}

// Obtiene las asignaciones (anio/division) de un usuario especifico.
export async function apiAdminGetAssignments(userId) {
  return apiGet(`/admin/users/${userId}/assignments`); // [{id,user_id,anio,division}]
}

// Guarda las asignaciones (anio/division) para un usuario especifico.
export async function apiAdminSaveAssignments(userId, asignaciones, _detalles) {
  const body = { asignaciones };
  if (_detalles) body._detalles = _detalles;
  return apiPut(`/admin/users/${userId}/assignments`, body); // [{id,user_id,anio,division}]
}

// Crea un usuario via admin con nombre, apellido, DNI, email, password y rol.
export async function apiAdminCreateUser({ name, apellido, email, dni, password, role, _detalles }) {
  const body = { name, apellido, email, dni, password, role };
  if (_detalles) body._detalles = _detalles;
  return apiPost("/admin/users", body); // { id, name, email, role, status, ... }
}

// Actualiza los datos de un usuario existente (solo admin).
export async function apiAdminUpdateUser(id, payload) {
  return apiPut(`/admin/users/${id}`, payload);
}

// Actualiza el rol de un usuario especifico (solo admin).
export async function apiAdminUpdateUserRole(id, role, _detalles) {
  const body = { role };
  if (_detalles) body._detalles = _detalles;
  return apiPut(`/admin/users/${id}/role`, body);
}

// Elimina un usuario del sistema (solo admin).
export async function apiAdminDeleteUser(id, _detalles) {
  const qs = _detalles ? `?_detalles=${encodeURIComponent(_detalles)}` : '';
  return apiDelete(`/admin/users/${id}${qs}`); // { ok: true }
}

/* ===== Confirmacion de contrasena (para acciones sensibles) ===== */
// Verifica la contrasena actual del usuario para autorizar operaciones criticas.
export async function apiConfirmPassword(password) {
  return apiPost("/auth/confirm-password", { password }); // { ok:true, valid_until: ISOString }
}

/* ===== Recuperar / Restablecer contrasena ===== */
/**
 * Acepta tanto un string como un objeto { email } para evitar el error 422.
 * Ejemplos válidos:
 *   apiForgotPassword("user@mail.com")
 *   apiForgotPassword({ email: "user@mail.com" })
 */
export async function apiForgotPassword(arg) {
  const email = (typeof arg === "string" ? arg : arg?.email)?.trim?.();
  return apiPost("/password/forgot", { email }); // { message: '...' }
}

// Restablece la contrasena usando el token recibido por email.
export async function apiResetPassword({ token, email, password, password_confirmation }) {
  return apiPost("/password/reset", {
    token,
    email,
    password,
    password_confirmation,
  }); // { message: '...' }
}
