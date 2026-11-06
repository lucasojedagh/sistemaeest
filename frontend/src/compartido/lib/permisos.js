// Capa de permisos del frontend.
// /me devuelve:
//  - `permisos`: null para los roles de sistema sin quitar permisos (no se filtra
//    nada) y la lista blanca de claves para los roles personalizados o los roles
//    de sistema a los que se les quitó algo.
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

/** Ruta del módulo que el rol tiene visible, o null si no hay ninguno. */
export const primeraRutaVisible = (user, rutasPorModulo) => {
  if (!Array.isArray(user?.modulos)) return null;
  for (const id of user.modulos) {
    if (rutasPorModulo[id]) return rutasPorModulo[id];
  }
  return null;
};
