// Orden de listas de personas (alumnos, profesores, preceptores).
// Criterios en lenguaje de todos los días; el orden elegido se recuerda
// por pantalla para no re-elegirlo cada vez.
export const ORDENES = [
  { value: "apellido-az", label: "Apellido A–Z" },
  { value: "apellido-za", label: "Apellido Z–A" },
  { value: "nuevos", label: "Últimos inscriptos" },
  { value: "viejos", label: "Primeros inscriptos" },
];

export const ORDEN_DEFAULT = "apellido-az";

const comparaApellido = (dir) => (a, b) => {
  const ap = String(a?.apellido || "").localeCompare(String(b?.apellido || ""), "es", { sensitivity: "base" });
  if (ap !== 0) return dir * ap;
  return dir * String(a?.nombre || a?.name || "").localeCompare(String(b?.nombre || b?.name || ""), "es", { sensitivity: "base" });
};

const fechaAlta = (p) => {
  const v = p?.creado_en || p?.created_at || null;
  const t = v ? new Date(v).getTime() : NaN;
  return Number.isNaN(t) ? null : t;
};

// Devuelve una copia nueva ordenada (no muta la lista original).
export function ordenarPersonas(lista, criterio) {
  const arr = Array.isArray(lista) ? [...lista] : [];
  switch (criterio) {
    case "apellido-za":
      return arr.sort(comparaApellido(-1));
    case "nuevos":
      return arr.sort((a, b) => (fechaAlta(b) ?? -1) - (fechaAlta(a) ?? -1));
    case "viejos":
      return arr.sort((a, b) => (fechaAlta(a) ?? Infinity) - (fechaAlta(b) ?? Infinity));
    case "apellido-az":
    default:
      return arr.sort(comparaApellido(1));
  }
}

const KEY = (pantalla) => `orden:${pantalla}`;

export function leerOrden(pantalla) {
  try {
    const v = window.localStorage.getItem(KEY(pantalla));
    if (ORDENES.some((o) => o.value === v)) return v;
  } catch { /* storage no disponible */ }
  return ORDEN_DEFAULT;
}

export function guardarOrden(pantalla, criterio) {
  try {
    window.localStorage.setItem(KEY(pantalla), criterio);
  } catch { /* storage no disponible */ }
}
