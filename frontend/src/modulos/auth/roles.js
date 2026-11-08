// Jerarquia de roles, debe coincidir con App\Support\RoleHierarchy del backend.
// admin(5) > directivo(4) > jefe_preceptores(3) = prosecretaria(3) > preceptor(2) > profesor(1)
// La prosecretaria tiene los mismos permisos operativos que el jefe de preceptores, pero un
// rango más abajo en la escala de cuentas: no crea ni edita cuentas de prosecretaria ni de jefe.
//
// Los roles personalizados (pestaña "Roles y permisos") no están acá: su rango y
// su nombre vienen de /roles/detalle y se cargan en `detalle` al iniciar la sesión.

import { apiRolDetalle } from "../../compartido/api/roles";

export const ROLE_LEVEL = {
  profesor: 1,
  preceptor: 2,
  prosecretaria: 3,
  jefe_preceptores: 3,
  directivo: 4,
  admin: 5,
};

export const ROLE_LABELS = {
  profesor: "Profesor",
  preceptor: "Preceptor",
  prosecretaria: "Prosecretaria",
  jefe_preceptores: "Jefe de Preceptores",
  directivo: "Directivo",
  admin: "Admin",
  sin_rol: "Sin rol",
};

// { rol: { nombre, nivel, tipo } } — null mientras no se cargó.
let detalle = null;

export const setRoleDetalle = (d) => {
  detalle = d && Object.keys(d).length ? d : null;
};

// Descarga las etiquetas y rangos de todos los roles (sistema + personalizados).
// Falls silent: sin esta lista la interfaz usa las etiquetas de siempre.
export async function cargarRoleDetalle() {
  try {
    setRoleDetalle(await apiRolDetalle());
  } catch {
    /* sigue con ROLE_LEVEL / ROLE_LABELS */
  }
  return detalle;
}

// El propio /me trae nombre y rango: con eso se pinta la sesión aunque la lista
// de roles todavía no haya llegado.
export const semillarRol = (user) => {
  if (!user?.role || ROLE_LEVEL[user.role]) return;
  detalle = {
    ...(detalle || {}),
    [user.role]: { nombre: user.rol_nombre || user.role, nivel: user.nivel ?? 0, tipo: "personalizado" },
  };
};

// Rango del rol: el de la lista cargada si es personalizado, si no el de sistema.
export const roleLevel = (role) => ROLE_LEVEL[role] ?? detalle?.[role]?.nivel ?? 0;

// Nombre visible del rol (respeta el apodo que se le puso en "Roles y permisos").
export const rolLabel = (role) => detalle?.[role]?.nombre ?? ROLE_LABELS[role] ?? role ?? "";

export const esRolPersonalizado = (role) => !ROLE_LEVEL[role] && detalle?.[role]?.tipo === "personalizado";

// El rango de Administrador es unico: existe una sola cuenta con ese rango (la
// que crea el seeder) y no se reparte. Espejo de RoleHierarchy::esAsignable del
// backend, que rechaza la asignacion aunque la pida el propio admin.
export const ROL_ADMIN_UNICO = "admin";
export const esRolAsignable = (role) => role !== ROL_ADMIN_UNICO;

// El actor puede gestionar (editar/eliminar/cambiar rol de) al objetivo.
// espejo de RoleHierarchy::canManage del backend.
// Los roles del MISMO nivel se gestionan entre si; hacia arriba nadie puede.
// El admin es la cima: solo otro admin toca una cuenta admin.
// Nivel 3, 4 y 5 gestionan su nivel y todo lo de abajo; preceptor y profesor no gestionan cuentas.
// Un rol personalizado gestiona personalizados de su nivel o de abajo, nunca roles de sistema.
export const canManageRole = (actorRole, targetRole) => {
  const a = roleLevel(actorRole);
  const t = roleLevel(targetRole);
  if (a <= 0 || t <= 0) return false;

  if (targetRole === "admin") return actorRole === "admin";

  const actorSistema = Boolean(ROLE_LEVEL[actorRole]);
  if (!actorSistema) return !ROLE_LEVEL[targetRole] && t <= a;

  if (a < 3) return false;
  return a >= t;
};

// Permisos de gestión de cuentas (módulo Usuarios, historial): jefe y prosecretaria igual.
// Los respaldos de la base NO entran acá: son solo del rol Admin.
export const esGestion = (role) => roleLevel(role) >= ROLE_LEVEL.jefe_preceptores;
