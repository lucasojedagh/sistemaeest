import { apiGet, apiPost, apiPut, apiDelete } from "../lib/api";

function qs(params) {
  if (!params) return "";
  const p = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== null && v !== undefined && v !== "") p.set(k, v);
  });
  const s = p.toString();
  return s ? `?${s}` : "";
}

// Todos los metodos devuelven el JSON parseado y lanzan Error con .status/.data
// ante fallo (comportamiento provisto por lib/api).
export const asistenciaApi = {
  cursos: () => apiGet("/asistencia/cursos"),
  alumnosCurso: (anio, division) => apiGet(`/alumnos?anio=${anio}&division=${encodeURIComponent(division)}&per_page=500`),
  clases: (params = {}) => apiGet(`/asistencia/clases${qs(params)}`),
  clase: (id) => apiGet(`/asistencia/clases/${id}`),
  guardar: (payload) => apiPost("/asistencia/clases", payload),
  editar: (id, payload) => apiPut(`/asistencia/clases/${id}`, payload),
  eliminar: (id) => apiDelete(`/asistencia/clases/${id}`),
  historial: (params = {}) => apiGet(`/asistencia/historial${qs(params)}`),
  inasistencias: (params = {}) => apiGet(`/asistencia/inasistencias${qs(params)}`),
  justificaciones: (params = {}) => apiGet(`/asistencia/justificaciones${qs(params)}`),
  crearJustificacion: (payload) => apiPost("/asistencia/justificaciones", payload),
  resolverJustificacion: (id, estado) => apiPut(`/asistencia/justificaciones/${id}`, { estado }),
  estadisticas: (params = {}) => apiGet(`/asistencia/estadisticas${qs(params)}`),
};