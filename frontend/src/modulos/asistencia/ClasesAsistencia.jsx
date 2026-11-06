// Pestaña "Clases": qué se cargó en cada clase del curso.
// Tarjetas con palabras claras (no siglas) para que se entienda sin explicación.
import { useEffect, useState } from "react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { CalendarDays, Loader, Pencil, Plus, Trash2, Clock, AlertCircle } from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { ESTADO_CLASE_LABELS, fmtFecha, esCursoEditable } from "../../compartido/lib/asistencia";
import CoursePicker from "./CoursePicker";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import { mensajeAmable } from "../../compartido/lib/errores";
import { useToast } from "../../compartido/componentes/Common/Toast";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import { esGestion } from "../auth/roles";
import "./ClasesAsistencia.css";

const mesActual = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
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
  return partes.length ? partes.join(" · ") : "SinNadie marcado";
};

export default function ClasesAsistencia({ maps, user, onEdit, refreshKey = 0 }) {
  const showToast = useToast();
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const [curso, setCurso] = useState({ anio: 1, division: "" });
  const [mes, setMes] = useState(mesActual());
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const cursoOk = curso.anio && curso.division && curso.division !== "*";
  const puedeEliminar = esGestion(user?.role);

  useEffect(() => {
    if (!cursoOk) return;
    setLoading(true);
    setError(null);
    asistenciaApi
      .clases({ anio: curso.anio, division: curso.division, mes })
      .then((d) => setData(d))
      .catch((e) => setError(mensajeAmable(e, "No se pudieron cargar las clases. Revisá tu conexión.")))
      .finally(() => setLoading(false));
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
          <button type="button" onClick={() => onEdit && onEdit({ curso, fecha: `${mes}-01` })} className="clases-btn-nueva">
            <Plus className="clases-btn-icon" /> Pasar lista de este mes
          </button>
        )}
      </div>

      {loading && <div className="clases-loading"><Loader className="clases-icon-spin" /> Cargando…</div>}
      {error && !loading && <div className="clases-error">{error}</div>}

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
                    </div>

                    <div className="clase-card-centro">
                      {suspendida ? (
                        <span className="clase-card-dato clase-card-dato-suspendida">
                          <AlertCircle size={14} /> Clase suspendida, no se pasó lista
                        </span>
                      ) : resumen ? (
                        <span className="clase-card-dato">{resumen}</span>
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
                        <Pencil size={15} /> Ver y editar
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

          <div className="clases-resumen-grid">
            {[
              ["Clases dictadas", data.resumen?.dictadas],
              ["Suspendidas", data.resumen?.suspendidas],
              ["Alumnos marcados", data.resumen?.total_asistencias],
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