// Pestaña "Clases": qué se cargó en cada clase del curso.
// Tarjetas con palabras claras (no siglas) para que se entienda sin explicación.
import { useEffect, useRef, useState } from "react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { CalendarDays, Loader, Pencil, Plus, Trash2, Clock, AlertCircle } from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { ESTADO_CLASE_LABELS, fmtFecha, fmtPct, hoyLocal, esCursoEditable } from "../../compartido/lib/asistencia";
import CoursePicker from "./CoursePicker";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import { mensajeAmable } from "../../compartido/lib/errores";
import { useToast } from "../../compartido/componentes/Common/Toast";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import { esGestion } from "../auth/roles";
import { accionHabilitada, alcanceEditar } from "../../compartido/lib/permisos";
import "./ClasesAsistencia.css";

const mesActual = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
};

// La lista que se abre desde el botón "Pasar la lista": si el mes elegido es el
// de hoy se abre hoy (lo habitual); si es otro mes, su último día. Antes caía
// siempre el día 1, que casi nunca es fecha de clase.
const fechaUtilesDelMes = (mes) => {
  const hoy = hoyLocal();
  if (hoy.slice(0, 7) === mes) return hoy;
  const [anio, numMes] = String(mes).split("-").map(Number);
  if (!anio || !numMes) return hoy;
  const ultimo = new Date(anio, numMes, 0).getDate();
  return `${mes}-${String(ultimo).padStart(2, "0")}`;
};

// "28 presentes · 2 ausentes" en vez de "28 P · 2 A".
const resumenEnPalabras = (c) => {
  if (c.estado === "suspendida") return null;
  if (!c.tomada) return null;
  const r = c.resumen || {};
  const partes = [];
  if (r.presente) partes.push(`${r.presente} presente${r.presente === 1 ? "" : "s"}`);
  if (r.tarde) partes.push(`${r.tarde} tarde${r.tarde === 1 ? "" : "s"}`);
  if (r.ausente) partes.push(`${r.ausente} ausente${r.ausente === 1 ? "" : "s"}`);
  if (r.justificado) partes.push(`${r.justificado} justificado${r.justificado === 1 ? "" : "s"}`);
  return partes.length ? partes.join(" · ") : "Nadie marcado";
};

// % de asistencia de la clase: tarde computa como presencia (AsistenciaReglas).
const porcentajeClase = (c) => {
  const r = c.resumen || {};
  const total = (r.presente || 0) + (r.ausente || 0) + (r.justificado || 0) + (r.tarde || 0);
  if (!total) return null;
  return ((r.presente + r.tarde) / total) * 100;
};

// Promedio del período. Si el servidor ya trajo el aggregate (resumen.presentes /
// total_asistencias) se usa ese, porque la lista de clases está topada y sumar
// sólo lo que se ve dejaba el porcentaje calculado sobre una parte.
const asistenciaDelPeriodo = (data) => {
  const r = data?.resumen || {};
  if (r.total_asistencias) {
    return ((r.presentes || 0) + (r.tardanzas || 0)) / r.total_asistencias * 100;
  }
  let presentes = 0;
  let marcados = 0;
  (data?.clases || []).forEach((c) => {
    const s = c.resumen || {};
    presentes += (s.presente || 0) + (s.tarde || 0);
    marcados += (s.presente || 0) + (s.ausente || 0) + (s.justificado || 0) + (s.tarde || 0);
  });
  return marcados ? (presentes / marcados) * 100 : null;
};

export default function ClasesAsistencia({ maps, user, onEdit, refreshKey = 0 }) {
  const showToast = useToast();
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  // Sin división todavía no hay curso que mostrar: arrancar en "1.º" suelto
  // dejaba el selector con un valor a medias y la lista vacía sin explicación.
  const [curso, setCurso] = useState({ anio: "", division: "" });
  const [mes, setMes] = useState(mesActual());
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const cursoOk = curso.anio && curso.division && curso.division !== "*";
  // Borrar una clase cargada es supervisión: el backend exige el permiso
  // "Tomar y corregir asistencia" y alcance de escritura en "Todos".
  const puedeEliminar = accionHabilitada(user, 'asistencia.editar', esGestion(user?.role))
    && alcanceEditar(user, 'asistencia') === 'todos';
  const puedeMarcar = esCursoEditable(maps?.editables, curso.anio, curso.division);

  // Cada carga se numera: si se cambió de curso o de mes mientras la respuesta
  // estaba en vuelo, la copia vieja se descarta en lugar de pintar el curso
  // anterior encima del que se acaba de elegir.
  const version = useRef(0);

  useEffect(() => {
    if (!cursoOk) return;
    const mia = ++version.current;
    setLoading(true);
    setError(null);
    asistenciaApi
      .clases({ anio: curso.anio, division: curso.division, mes })
      .then((d) => { if (mia === version.current) setData(d); })
      .catch((e) => { if (mia === version.current) setError(mensajeAmable(e, "No se pudieron cargar las clases. Revisá tu conexión.")); })
      .finally(() => { if (mia === version.current) setLoading(false); });
  }, [curso?.anio, curso?.division, mes, cursoOk, refreshKey]);

  async function eliminar(clase) {
    const ok = await pedirConfirmacion({
      icon: "warning",
      title: "Eliminar clase",
      text: `¿Eliminar la clase N.°${clase.numero_clase} del ${fmtFecha(clase.fecha)}? Se borrarán las asistencias registradas.`,
      confirmText: "Sí, eliminar",
      cancelText: "Cancelar",
      danger: true,
    });
    if (!ok) return;
    if (!(await confirmarContrasena())) return;
    try {
      await asistenciaApi.eliminar(clase.id);
      setData((prev) => ({
        ...prev,
        clases: (prev?.clases || []).filter((c) => c.id !== clase.id),
      }));
      showToast("Clase eliminada.", "success");
    } catch (err) {
      // Si el servidor bloqueó el borrado (la clase tiene justificaciones),
      // su mensaje explica qué hay que hacer en lugar del aviso genérico.
      const texto = err?.status === 422 && err?.data?.message
        ? err.data.message
        : mensajeAmable(err, "Intentá de nuevo.");
      mostrarAviso({ icon: "error", title: "No se pudo borrar", text: texto });
    }
  }

  const clases = data?.clases || [];
  const asistenciaMes = fmtPct(asistenciaDelPeriodo(data));

  return (
    <div className="clases-container">
      <div className="clases-filters-card">
        {/* Solapa de lectura: se eligen cursos aunque no se puedan marcar. */}
        <CoursePicker maps={maps} value={curso} onChange={setCurso} />
        <div>
          <label className="clases-label">Mes</label>
          <input type="month" value={mes} onChange={(e) => setMes(e.target.value)} className="clases-month-input" />
        </div>
        {cursoOk && esCursoEditable(maps?.editables, curso.anio, curso.division) && (
          <button
            type="button"
            onClick={() => onEdit && onEdit({ curso, fecha: fechaUtilesDelMes(mes) })}
            className="clases-btn-nueva"
          >
            <Plus className="clases-btn-icon" />
            {mes === mesActual() ? "Pasar la lista de hoy" : `Pasar la lista del ${fmtFecha(fechaUtilesDelMes(mes))}`}
          </button>
        )}
      </div>

      {loading && <div className="clases-loading"><Loader className="clases-icon-spin" /> Cargando…</div>}
      {error && !loading && <div className="clases-error">{error}</div>}

      {!loading && !error && !cursoOk && (
        <EmptyState
          icon={CalendarDays}
          title="Elegí el curso para ver sus clases"
          description="Se muestran las clases del año y la división que elijas, del mes que indiques al lado."
        />
      )}

      {!loading && !error && data && (
        <>
          {clases.length === 0 ? (
            <EmptyState
              icon={CalendarDays}
              title="Todavía no hay clases en este mes"
              description="Cuando pases lista, la clase queda guardada acá con el detalle de quién estuvo."
            />
          ) : (
            <div className="clases-cards">
              {clases.map((c) => {
                const resumen = resumenEnPalabras(c);
                const suspendida = c.estado === "suspendida";
                const pct = porcentajeClase(c);
                return (
                  <div key={c.id} className={`clase-card ${suspendida ? "clase-card-suspendida" : ""}`}>
                    <div className="clase-card-izq">
                      <span className="clase-card-num">Clase N.°{c.numero_clase}</span>
                      <span className="clase-card-fecha">{fmtFecha(c.fecha)}</span>
                      <span className="clase-card-hora">
                        {c.hora_inicio && c.hora_fin
                          ? <><Clock size={13} /> {String(c.hora_inicio).slice(0, 5)} – {String(c.hora_fin).slice(0, 5)}</>
                          : null}
                      </span>
                      {c.responsable?.nombre ? <span className="clase-card-responsable">{c.responsable.nombre}</span> : null}
                    </div>

                    <div className="clase-card-centro">
                      {suspendida ? (
                        <span className="clase-card-dato clase-card-dato-suspendida">
                          <AlertCircle size={14} /> Clase suspendida, no se pasó lista
                        </span>
                      ) : resumen ? (
                        <>
                          <span className="clase-card-dato">{resumen}</span>
                          {/* La cantidad ya está dicha en las palabras de arriba
                              (presentes + tardanzas + ausentes + justificados),
                              así que acá solo va el porcentaje. */}
                          {pct != null && (
                            <span className="clase-card-extra">{fmtPct(pct)} de asistencia</span>
                          )}
                        </>
                      ) : (
                        <span className="clase-card-dato clase-card-dato-pendiente">
                          Todavía no se pasó lista
                        </span>
                      )}
                      {c.comentario && <span className="clase-card-nota">{c.comentario}</span>}
                    </div>

                    <div className="clase-card-acciones">
                      <button
                        type="button"
                        onClick={() => onEdit && onEdit({ clase: c.id })}
                        className="clase-btn-ver"
                      >
                        <Pencil size={15} /> {puedeMarcar ? "Ver y editar" : "Ver"}
                      </button>
                      {puedeEliminar && (
                        <button
                          type="button"
                          onClick={() => eliminar(c)}
                          className="clase-btn-borrar"
                          title="Eliminar la clase"
                          aria-label={`Eliminar la clase ${c.numero_clase}`}
                        >
                          <Trash2 size={15} />
                        </button>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          {data.recortado && (
            <div className="asist-nota">
              {`Este mes tiene ${data.total_clases} clases y se muestran las ${clases.length} más recientes. Bajar de mes para ver el resto.`}
            </div>
          )}

          <div className="clases-resumen-grid">
            {[
              ["Clases dictadas", data.resumen?.dictadas],
              ["Suspendidas", data.resumen?.suspendidas],
              // Suma las marcas de todas las clases del mes, no alumnos
              // distintos: un alumno que estuvo presente 10 veces conta 10.
              ["Marcas de asistencia", data.resumen?.total_asistencias],
              ["Asistencia del mes", asistenciaMes],
            ].map(([label, val]) => (
              <div key={label} className="clases-resumen-card">
                <div className="clases-resumen-label">{label}</div>
                <div className="clases-resumen-value">{val ?? 0}</div>
              </div>
            ))}
          </div>
        </>
      )}

      {avisoModal}
      {modalPwd}
    </div>
  );
}