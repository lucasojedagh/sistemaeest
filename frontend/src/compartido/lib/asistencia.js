import { cursoLabel as cursosCursoLabel } from "./cursos";
import { formatearFecha, hoyLocal } from "./fechas";

export { hoyLocal };

// Orden en que se muestran los botones: de más habitual a menos.
// El mismo orden se usa en toda la app para que se lea siempre igual.
export const ESTADOS_ASISTENCIA = ["presente", "ausente", "tarde", "justificado"];

export const ESTADO_LABELS = {
  presente: "Presente",
  ausente: "Ausente",
  justificado: "Justificado",
  tarde: "Tarde",
};

export const ESTADO_STYLES = {
  presente: "bg-green-100 text-green-700 border-green-200",
  ausente: "bg-red-100 text-red-700 border-red-200",
  justificado: "bg-amber-100 text-amber-700 border-amber-200",
  tarde: "bg-blue-100 text-blue-700 border-blue-200",
};

export const RESOLUCION_LABELS = {
  presente: "Presente",
  ausente: "Ausente",
  tardanza: "Tarde",
};

export const J_ESTADOS = ["pendiente", "aprobada", "rechazada"];

export const J_LABELS = {
  pendiente: "Pendiente",
  aprobada: "Aprobada",
  rechazada: "Rechazada",
};

export const J_STYLES = {
  pendiente: "bg-slate-100 text-slate-700 border-slate-200",
  aprobada: "bg-green-100 text-green-700 border-green-200",
  rechazada: "bg-red-100 text-red-700 border-red-200",
};

export const ESTADO_CLASE_LABELS = {
  dictada: "Dictada",
  suspendida: "Suspendida",
};

export const MESES = [
  "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio",
  "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre",
];

export const SELECCIONAR_COURSE = { anio: "", division: "" };

export const fmtPct = (v) =>
  v == null ? "—" : `${Number(v).toFixed(2).replace(".", ",")} %`;

export const fmtFecha = formatearFecha;

export const cursoLabel = (anio, division) =>
  anio ? cursosCursoLabel({ anio, division }) : "—";

// "editables" sólo viene del backend cuando la escritura es por curso asignado.
// Sin lista, todos los cursos del mapa se pueden marcar.
export function esCursoEditable(editables, anio, division) {
  if (!Array.isArray(editables)) return true;
  if (division === "*") return true;
  return editables.some(
    ([a, d]) => Number(a) === Number(anio) && (d === null || String(d) === String(division))
  );
}