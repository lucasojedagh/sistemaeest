// Jerarquia de roles, debe coincidir con App\Support\RoleHierarchy del backend.
// admin(5) > directivo(4) > jefe_preceptores(3) > preceptor/prosecretaria(2) > profesor(1)

export const ROLE_LEVEL = {
  profesor: 1,
  preceptor: 2,
  prosecretaria: 2,
  jefe_preceptores: 3,
  directivo: 4,
  admin: 5,
};

export const roleLevel = (role) => ROLE_LEVEL[role] ?? 0;

// El actor puede gestionar (editar/eliminar/cambiar rol de) al objetivo?
// admin/directivo gestionan a cualquiera salvo a si mismos en autodestruccion.
// jefe_preceptores gestiona a preceptor y profesor (no a admin/directivo).
// preceptor NO gestiona usuarios (solo edita datos de profesores asignados, validado en back).
export const canManageRole = (actorRole, targetRole) => {
  const a = roleLevel(actorRole);
  const t = roleLevel(targetRole);
  if (a <= 0 || t <= 0) return false;
  if (a >= 4) return t < 4 || actorRole === "admin";
  if (a === 3) return t < 3;
  return false;
};
