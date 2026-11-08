// Capa de permisos del frontend.
// /me devuelve:
//  - `permisos`: null para los roles de sistema que nadie tocó todavía (entran
//    por su rango) y la lista completa de claves para cualquier rol ya guardado
//    en "Roles y permisos", sea del sistema o personalizado.
//  - `alcance`: mapa área -> {ver, editar} con 'todos' | 'asignados' | 'ninguno'.
//  - `modulos`: null cuando el rol usa la lista de siempre, o los módulos del
//    lateral que debe ver.
// Permiso y alcance son filtros en serie: el alcance nunca habilita una acción
// bloqueada, solo restringe sobre qué datos se aplica.

/** ¿El usuario puede hacer lo que describe `clave`? */
export function puede(user, clave) {
  if (!clave) return true;
  const lista = user?.permisos;
  if (!Array.isArray(lista)) return true;
  return lista.includes(clave);
}

/** ¿El backend le está filtrando los permisos a este usuario? (rol personalizado o de sistema con quitas) */
export const estaFiltrado = (user) => Array.isArray(user?.permisos);

/**
 * ¿Este botón/acción está habilitada para el usuario?
 * Es la misma regla que aplica el backend en EnsureRole: si el rol tiene su lista
 * guardada en "Roles y permisos" (rol personalizado, o uno de sistema editado) la
 * casilla decide sola; si no tiene lista, decide la regla de siempre del rango,
 * que es el `segunRango` que pasa la pantalla.
 * Así desmarcar una casilla deja de ser decorativo, y marcarla en un rol
 * personalizado habilita la acción aunque el rango del rol no la cubra.
 */
export function accionHabilitada(user, clave, segunRango = false) {
  if (!clave) return true;
  if (estaFiltrado(user)) return puede(user, clave);
  return !!segunRango;
}

/** ¿El rol muestra este módulo del menú? Sin configuración, se usa la lista de siempre. */
export function moduloVisible(user, id) {
  const lista = user?.modulos;
  if (!Array.isArray(lista)) return true;
  // Sin id no hay módulo que consultar: el guard se guía solo por el permiso.
  if (!id) return true;
  return lista.includes(id);
}

/** Alcance de lectura de un área ('todos' | 'asignados' | 'ninguno'). */
export const alcanceVer = (user, area) => user?.alcance?.[area]?.ver ?? 'todos';

/** Alcance de escritura de un área. */
export const alcanceEditar = (user, area) => user?.alcance?.[area]?.editar ?? 'todos';

/** Ve todo: no hay que filtrar por curso. */
export const veTodo = (user, area) => alcanceVer(user, area) === 'todos';

/** No ve nada del área: la pantalla va vacía u oculta. */
export const noVe = (user, area) => alcanceVer(user, area) === 'ninguno';

/**
 * ¿El alcance de escritura del rol alcanza este curso concreto?
 * 'todos' no recorta; 'asignados' mira la lista que devuelve /me/ambito;
 * 'ninguno' corta todo. Es el mismo orden del backend: primero la acción,
 * después el alcance.
 */
export function cursoEnAlcance(user, area, anio, division, asignaciones) {
  const alcance = alcanceEditar(user, area);
  if (alcance === 'ninguno') return false;
  if (alcance === 'todos') return true;
  if (!asignaciones?.length) return false;
  return asignaciones.some((a) =>
    Number(a.anio) === Number(anio) &&
    (a.division || '').toUpperCase() === (division || '').toUpperCase()
  );
}

/**
 * Botón/acción que recae sobre un curso concreto: la casilla del catálogo decide
 * si existe la acción y el alcance de escritura decide sobre qué cursos.
 * `segunRango` es la regla de siempre del módulo, para los roles sin lista guardada.
 */
export function accionEnCurso(user, clave, segunRango, area, anio, division, asignaciones) {
  return !!user
    && accionHabilitada(user, clave, segunRango)
    && cursoEnAlcance(user, area, anio, division, asignaciones);
}

/** Ruta del módulo que el rol tiene visible, o null si no hay ninguno. */
export const primeraRutaVisible = (user, rutasPorModulo) => {
  if (!Array.isArray(user?.modulos)) return null;
  for (const id of user.modulos) {
    if (rutasPorModulo[id]) return rutasPorModulo[id];
  }
  return null;
};
