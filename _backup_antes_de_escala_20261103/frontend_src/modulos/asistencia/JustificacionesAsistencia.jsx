import { useCallback, useEffect, useState } from "react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { Check, ClipboardCheck, FileText, Loader, Plus, X } from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { J_LABELS, fmtFecha, cursoLabel } from "../../compartido/lib/asistencia";
import CoursePicker from "./CoursePicker";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import { useToast } from "../../compartido/componentes/Common/Toast";
import { mensajeAmable } from "../../compartido/lib/errores";
import "./JustificacionesAsistencia.css";

const JUST_CHIP_CLASSES = {
  pendiente: "just-chip just-chip-pendiente",
  aprobada: "just-chip just-chip-aprobada",
  rechazada: "just-chip just-chip-rechazada",
};

export default function JustificacionesAsistencia({ maps, user }) {
  const showToast = useToast();
  const [filtro, setFiltro] = useState("pendiente");
  const [curso, setCurso] = useState({ anio: "", division: "" });
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [showForm, setShowForm] = useState(false);
  // Selector en 3 pasos (curso del filtro + alumno + inasistencia).
  // El id de asistencia queda oculto como valor interno.
  const [alumnosSel, setAlumnosSel] = useState([]);
  const [cargandoAlumnos, setCargandoAlumnos] = useState(false);
  const [alumnoSel, setAlumnoSel] = useState("");
  const [faltasSel, setFaltasSel] = useState([]);
  const [cargandoFaltas, setCargandoFaltas] = useState(false);
  const [faltaSel, setFaltaSel] = useState("");
  const [motivo, setMotivo] = useState("");
  const [observacion, setObservacion] = useState("");
  const [saving, setSaving] = useState(false);
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();

  const puedeResolver = ["admin", "directivo", "jefe_preceptores", "preceptor", "prosecretaria"].includes(user?.role);
  const cursoFiltroOk = curso.anio && curso.division && curso.division !== "*";

  const cargarLista = useCallback(() => {
    setLoading(true);
    return asistenciaApi
      .justificaciones({
        estado: filtro,
        anio: curso.anio,
        division: curso.division !== "*" ? curso.division : "",
      })
      .then(setData)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [filtro, curso.anio, curso.division]);

  useEffect(() => {
    cargarLista();
  }, [cargarLista]);

  // Al abrir el formulario: traer alumnos del curso del filtro.
  useEffect(() => {
    if (!showForm || !cursoFiltroOk) { setAlumnosSel([]); return; }
    setCargandoAlumnos(true);
    asistenciaApi
      .alumnosCurso(curso.anio, curso.division)
      .then((d) => setAlumnosSel((d?.data || []).filter((a) => a.estado !== "Egresado")))
      .catch(() => setAlumnosSel([]))
      .finally(() => setCargandoAlumnos(false));
  }, [showForm, cursoFiltroOk, curso.anio, curso.division]);

  // Al elegir alumno: traer sus inasistencias sin justificar (estado ausente).
  useEffect(() => {
    setFaltaSel("");
    if (!showForm || !alumnoSel) { setFaltasSel([]); return; }
    setCargandoFaltas(true);
    asistenciaApi
      .historial({ alumno_id: alumnoSel })
      .then((d) => setFaltasSel((d?.detalle || []).filter((r) => r.estado === "ausente")))
      .catch(() => setFaltasSel([]))
      .finally(() => setCargandoFaltas(false));
  }, [showForm, alumnoSel]);

  function abrirForm() {
    setAlumnoSel("");
    setFaltaSel("");
    setFaltasSel([]);
    setMotivo("");
    setObservacion("");
    setShowForm(true);
  }

  async function resolver(j, estado) {
    const ok = await pedirConfirmacion({
      icon: "question",
      title: estado === "aprobada" ? "Aprobar justificación" : "Rechazar justificación",
      text:
        estado === "aprobada"
          ? `La asistencia del alumno ${j.alumno?.apellido}, ${j.alumno?.nombre} pasará a "justificado".`
          : "El alumno quedará registrado como ausente.",
      confirmText: estado === "aprobada" ? "Sí, aprobar" : "Sí, rechazar",
      cancelText: "Cancelar",
      danger: estado !== "aprobada",
    });
    if (!ok) return;
    try {
      await asistenciaApi.resolverJustificacion(j.id, estado);
      setData((prev) => ({
        ...prev,
        justificaciones: (prev?.justificaciones || []).filter((x) => x.id !== j.id),
      }));
      showToast(estado === "aprobada" ? 'Justificación aprobada' : 'Justificación rechazada', 'success');
    } catch (e) {
      mostrarAviso({ icon: "error", title: "No se pudo resolver", text: mensajeAmable(e, "No se pudo resolver la justificación.") });
    }
  }

  async function crear() {
    if (!cursoFiltroOk) { mostrarAviso({ icon: "warning", title: "Falta el curso", text: "Elegí año y división en el filtro para justificar." }); return; }
    if (!alumnoSel) { mostrarAviso({ icon: "warning", title: "Faltan datos", text: "Elegí el alumno." }); return; }
    if (!faltaSel) { mostrarAviso({ icon: "warning", title: "Faltan datos", text: "Elegí la inasistencia a justificar." }); return; }
    if (!motivo.trim()) { mostrarAviso({ icon: "warning", title: "Faltan datos", text: "Ingresá el motivo de la justificación." }); return; }
    setSaving(true);
    try {
      await asistenciaApi.crearJustificacion({
        asistencia_id: Number(faltaSel),
        motivo: motivo.trim(),
        observacion: observacion.trim() || null,
      });
      setShowForm(false);
      setAlumnoSel("");
      setFaltaSel("");
      setMotivo("");
      setObservacion("");
      setFiltro("pendiente");
      await cargarLista();
      showToast('Justificación registrada', 'success');
    } catch (e) {
      mostrarAviso({ icon: "error", title: "No se pudo registrar", text: mensajeAmable(e, "No se pudo registrar la justificación.") });
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="just-container">
      <div className="just-filters-card">
        <CoursePicker maps={maps} value={curso} onChange={setCurso} allowEmpty />
        <div className="just-segmented">
          {["pendiente", "aprobada", "rechazada", ""].map((s) => (
            <button
              key={s}
              type="button"
              onClick={() => setFiltro(s)}
              className={`just-seg-btn ${filtro === s ? "just-seg-btn-active" : "just-seg-btn-inactive"}`}
            >
              {s === "" ? "Todas" : J_LABELS[s]}
            </button>
          ))}
        </div>
        <button
          type="button"
          onClick={abrirForm}
          className="just-btn-registrar"
        >
          <Plus className="just-btn-icon" /> Registrar justificación
        </button>
      </div>

      {showForm && (
        <div className="just-form-card">
          <div className="just-form-heading">
            <ClipboardCheck className="just-form-icon" /> Registrar justificación
          </div>
          {!cursoFiltroOk ? (
            <p className="just-form-aviso">Elegí año y división en el filtro para empezar.</p>
          ) : (
          <>
          <div className="just-form-grid">
            <div>
              <label className="just-label">Alumno</label>
              <select
                value={alumnoSel}
                onChange={(e) => setAlumnoSel(e.target.value)}
                className="just-input"
                disabled={cargandoAlumnos}
              >
                <option value="">{cargandoAlumnos ? "Cargando alumnos…" : "— Elegir alumno —"}</option>
                {alumnosSel.map((a) => (
                  <option key={a.alumno_id ?? a.id} value={a.alumno_id ?? a.id}>
                    {a.apellido}, {a.nombre}{a.dni ? ` · DNI ${a.dni}` : ""}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="just-label">Inasistencia a justificar</label>
              <select
                value={faltaSel}
                onChange={(e) => setFaltaSel(e.target.value)}
                className="just-input"
                disabled={!alumnoSel || cargandoFaltas}
              >
                <option value="">
                  {!alumnoSel ? "— Primero elegí el alumno —" : cargandoFaltas ? "Buscando faltas…" : faltasSel.length ? "— Elegir fecha —" : "Sin ausencias para justificar"}
                </option>
                {faltasSel.map((r) => (
                  <option key={r.asistencia_id} value={r.asistencia_id}>
                    {fmtFecha(r.fecha)} · Clase N.°{r.numero_clase ?? "—"} · {cursoLabel(r.anio, r.division)}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <div className="just-form-grid">
            <div>
              <label className="just-label">Motivo</label>
              <input
                value={motivo}
                onChange={(e) => setMotivo(e.target.value)}
                placeholder="Ej: Certificado médico"
                className="just-input"
              />
            </div>
          </div>
          </> 
          )}
          <div>
            <label className="just-label">Observación</label>
            <textarea
              value={observacion}
              onChange={(e) => setObservacion(e.target.value)}
              rows={2}
              className="just-textarea"
            />
          </div>
          <div className="just-form-actions">
            <button
              type="button"
              disabled={saving || !cursoFiltroOk}
              onClick={crear}
              className="just-btn-save"
            >
              Guardar
            </button>
            <button
              type="button"
              onClick={() => setShowForm(false)}
              className="just-btn-cancel"
            >
              Cancelar
            </button>
          </div>
        </div>
      )}

      {loading && (
        <div className="just-loading">
          <Loader className="just-loading-icon" /> Cargando…
        </div>
      )}

      {!loading && data && (data.justificaciones || []).length === 0 && (
        <EmptyState
          icon={FileText}
          title={filtro ? `Sin justificaciones ${filtro === "pendiente" ? "pendientes" : J_LABELS[filtro]}` : "Sin justificaciones"}
          description="No hay solicitudes para mostrar."
        />
      )}

      {!loading && data && (data.justificaciones || []).length > 0 && (
        <div className="just-table-card">
          <div className="just-table-wrap">
            <table className="just-table">
              <thead>
                <tr className="just-thead-row">
                  <th className="just-th">Fecha</th>
                  <th className="just-th">N°</th>
                  <th className="just-th">Curso</th>
                  <th className="just-th">Alumno</th>
                  <th className="just-th">Motivo</th>
                  <th className="just-th">Observación</th>
                  <th className="just-th">Estado</th>
                  <th className="just-th">Registrada por</th>
                  <th className="just-th just-th-right">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {(data.justificaciones || []).map((j) => (
                  <tr key={j.id} className="just-row">
                    <td className="just-td">{fmtFecha(j.fecha)}</td>
                    <td className="just-td just-td-muted">{j.numero_clase ?? "—"}</td>
                    <td className="just-td">{j.curso || "—"}</td>
                    <td className="just-td just-td-medium">
                      {j.alumno ? `${j.alumno.apellido}, ${j.alumno.nombre}` : "—"}
                    </td>
                    <td className="just-td">{j.motivo}</td>
                    <td className="just-td just-td-truncate">{j.observacion || "—"}</td>
                    <td className="just-td">
                      <span className={JUST_CHIP_CLASSES[j.estado] || "just-chip just-chip-default"}>
                        {J_LABELS[j.estado] || j.estado}
                      </span>
                    </td>
                    <td className="just-td just-td-muted">{j.registrado_por || "—"}</td>
                    <td className="just-td">
                      <div className="just-acciones">
                        {j.estado === "pendiente" && puedeResolver && (
                          <>
                            <button
                              type="button"
                              onClick={() => resolver(j, "aprobada")}
                              title="Aprobar"
                              className="just-btn-aprobar"
                            >
                              <Check className="just-btn-icon" />
                            </button>
                            <button
                              type="button"
                              onClick={() => resolver(j, "rechazada")}
                              title="Rechazar"
                              className="just-btn-rechazar"
                            >
                              <X className="just-btn-icon" />
                            </button>
                          </>
                        )}
                        {!puedeResolver && <span className="just-sin-resolucion">—</span>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
      {avisoModal}
    </div>
  );
}