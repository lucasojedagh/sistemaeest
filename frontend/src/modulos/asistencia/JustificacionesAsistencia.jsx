import { useCallback, useEffect, useRef, useState } from "react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { Check, ClipboardCheck, FileText, Loader, Plus, X } from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { J_LABELS, fmtFecha, cursoLabel } from "../../compartido/lib/asistencia";
import CoursePicker from "./CoursePicker";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import { useToast } from "../../compartido/componentes/Common/Toast";
import { mensajeAmable } from "../../compartido/lib/errores";
import { accionHabilitada } from "../../compartido/lib/permisos";
import { estaFueraDeCursada } from "../../compartido/lib/estadosAlumno";
import { ROLE_LEVEL, roleLevel } from "../auth/roles";
import "./JustificacionesAsistencia.css";

const JUST_CHIP_CLASSES = {
  pendiente: "just-chip just-chip-pendiente",
  aprobada: "just-chip just-chip-aprobada",
  rechazada: "just-chip just-chip-rechazada",
};

// Títulos del estado vacío, en plural y con el artículo correcto.
const TITULOS_VACIO = {
  pendiente: "Sin justificaciones pendientes",
  aprobada: "Sin justificaciones aprobadas",
  rechazada: "Sin justificaciones rechazadas",
  "": "Sin justificaciones cargadas",
};

// Motivos que se usan casi siempre; se pueden escribir otros igualmente.
const MOTIVOS_SUGERIDOS = [
  "Certificado médico",
  "Enfermedad",
  "Turno médico",
  "Trámite personal o familiar",
  "Duelo",
  "Actividad institucional de la escuela",
  "Problemas de transporte",
];

export default function JustificacionesAsistencia({ maps, user }) {
  const showToast = useToast();
  const [filtro, setFiltro] = useState("pendiente");
  const [curso, setCurso] = useState({ anio: "", division: "" });
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [showForm, setShowForm] = useState(false);
  // Selector en 3 pasos (curso propio del formulario + alumno + inasistencia).
  // El id de asistencia queda oculto como valor interno.
  const [cursoForm, setCursoForm] = useState({ anio: "", division: "" });
  const [alumnosSel, setAlumnosSel] = useState([]);
  const [cargandoAlumnos, setCargandoAlumnos] = useState(false);
  // Un pedido que falla no es "el curso no tiene alumnos": se avisa aparte.
  const [errorAlumnos, setErrorAlumnos] = useState(null);
  const [alumnoSel, setAlumnoSel] = useState("");
  const [faltasSel, setFaltasSel] = useState([]);
  const [cargandoFaltas, setCargandoFaltas] = useState(false);
  const [errorFaltas, setErrorFaltas] = useState(null);
  const [faltaSel, setFaltaSel] = useState("");
  const [motivo, setMotivo] = useState("");
  const [observacion, setObservacion] = useState("");
  const [saving, setSaving] = useState(false);
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();

  // "Justificar inasistencias" es una casilla del catálogo. Sin lista guardada
  // manda el rango de siempre (preceptor para arriba), igual que el backend.
  const puedeJustificar = accionHabilitada(user, 'asistencia.justificar', roleLevel(user?.role) >= ROLE_LEVEL.preceptor);
  const puedeResolver = puedeJustificar;
  // El alta usa su propio curso (el del formulario), no el filtro de arriba: así
  // se puede justificar aunque se esté mirando "Todos los cursos".
  const cursoFormOk = cursoForm.anio && cursoForm.division && cursoForm.division !== "*";

  // Cada pantalla numera sus cargas: la respuesta de un curso o filtro anterior
  // se descarta en vez de mezclar datos viejos con la elección nueva.
  const versionLista = useRef(0);
  const versionAlumnos = useRef(0);
  const versionFaltas = useRef(0);

  const cargarLista = useCallback(() => {
    const mia = ++versionLista.current;
    setLoading(true);
    setError(null);
    return asistenciaApi
      .justificaciones({
        estado: filtro,
        anio: curso.anio,
        division: curso.division !== "*" ? curso.division : "",
      })
      .then((d) => { if (mia === versionLista.current) setData(d); })
      .catch((e) => {
        // Sin esto la pantalla se quedaba en "Sin justificaciones pendientes"
        // aunque el pedido haya fallado: el vacío se leía como "no hay".
        if (mia !== versionLista.current) return;
        setData(null);
        setError(mensajeAmable(e, "No se pudieron cargar las justificaciones. Revisá tu conexión e intentá de nuevo."));
      })
      .finally(() => { if (mia === versionLista.current) setLoading(false); });
  }, [filtro, curso.anio, curso.division]);

  useEffect(() => {
    cargarLista();
  }, [cargarLista]);

  // Al abrir el formulario: traer alumnos del curso elegido en el formulario.
  useEffect(() => {
    const mia = ++versionAlumnos.current;
    if (!showForm || !cursoFormOk) { setAlumnosSel([]); setErrorAlumnos(null); return; }
    setCargandoAlumnos(true);
    setErrorAlumnos(null);
    asistenciaApi
      .alumnosCurso(cursoForm.anio, cursoForm.division)
      .then((d) => { if (mia === versionAlumnos.current) setAlumnosSel((d?.data || []).filter((a) => !estaFueraDeCursada(a))); })
      .catch((e) => {
        if (mia !== versionAlumnos.current) return;
        setAlumnosSel([]);
        setErrorAlumnos(mensajeAmable(e, "No se pudieron cargar los alumnos del curso."));
      })
      .finally(() => { if (mia === versionAlumnos.current) setCargandoAlumnos(false); });
  }, [showForm, cursoFormOk, cursoForm.anio, cursoForm.division]);

  // Al elegir alumno: traer sus inasistencias sin justificar (estado ausente).
  useEffect(() => {
    const mia = ++versionFaltas.current;
    setFaltaSel("");
    if (!showForm || !alumnoSel) { setFaltasSel([]); setErrorFaltas(null); return; }
    setCargandoFaltas(true);
    setErrorFaltas(null);
    asistenciaApi
      .historial({ alumno_id: alumnoSel })
      .then((d) => { if (mia === versionFaltas.current) setFaltasSel((d?.detalle || []).filter((r) => r.estado === "ausente")); })
      .catch((e) => {
        if (mia !== versionFaltas.current) return;
        setFaltasSel([]);
        setErrorFaltas(mensajeAmable(e, "No se pudieron cargar las faltas del alumno."));
      })
      .finally(() => { if (mia === versionFaltas.current) setCargandoFaltas(false); });
  }, [showForm, alumnoSel]);

  function abrirForm() {
    // Arranca con el curso que se está filtrando, si ya se eligió uno concreto.
    setCursoForm(curso.anio && curso.division && curso.division !== "*" ? { ...curso } : { anio: "", division: "" });
    setAlumnoSel("");
    setFaltaSel("");
    setFaltasSel([]);
    setMotivo("");
    setObservacion("");
    setShowForm(true);
  }

  // Cambiar el curso del formulario deja sin sentido el alumno y la falta elegidos.
  useEffect(() => {
    setAlumnoSel("");
    setFaltaSel("");
    setFaltasSel([]);
  }, [cursoForm.anio, cursoForm.division]);

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
      // La fila no se esconde: cambia de estado delante del usuario. Si
      // desapareciera al aprobarla, parecería que se borró la justificación.
      setData((prev) => ({
        ...prev,
        justificaciones: (prev?.justificaciones || []).map((x) =>
          x.id === j.id ? { ...x, estado } : x
        ),
      }));
      showToast(
        estado === "aprobada"
          ? "Justificación aprobada: el alumno quedó marcado como justificado."
          : "Justificación rechazada: el alumno sigue marcado como ausente.",
        "success",
      );
    } catch (e) {
      mostrarAviso({ icon: "error", title: "No se pudo resolver", text: mensajeAmable(e, "No se pudo resolver la justificación.") });
    }
  }

  async function crear() {
    if (!cursoFormOk) { mostrarAviso({ icon: "warning", title: "Falta el curso", text: "Elegí año y división en el formulario para justificar." }); return; }
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
        {puedeJustificar && (
          <button
            type="button"
            onClick={abrirForm}
            className="just-btn-registrar"
          >
            <Plus className="just-btn-icon" /> Registrar justificación
          </button>
        )}
      </div>

      {showForm && (
        <div className="just-form-card">
          <div className="just-form-heading">
            <ClipboardCheck className="just-form-icon" /> Registrar justificación
          </div>
          <p className="just-form-pasos">
            Tres pasos: el curso, el alumno y la falta que se quiere justificar. La lista
            muestra sólo ausencias que todavía no tienen justificativo.
          </p>
          <div className="just-form-grid">
            <div>
              <label className="just-label">1 · Curso</label>
              <CoursePicker maps={maps} value={cursoForm} onChange={setCursoForm} />
            </div>
            <div>
              <label className="just-label">2 · Alumno</label>
              <select
                value={alumnoSel}
                onChange={(e) => setAlumnoSel(e.target.value)}
                className="just-input"
                disabled={!cursoFormOk || cargandoAlumnos}
              >
                <option value="">
                  {!cursoFormOk ? "— Primero elegí el curso —" : cargandoAlumnos ? "Cargando alumnos…" : errorAlumnos ? "No se pudieron cargar los alumnos" : alumnosSel.length ? "— Elegir alumno —" : "Este curso no tiene alumnos"}
                </option>
                {alumnosSel.map((a) => (
                  <option key={a.alumno_id ?? a.id} value={a.alumno_id ?? a.id}>
                    {a.apellido}, {a.nombre}{a.dni ? ` · DNI ${a.dni}` : ""}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <div className="just-form-grid">
            <div>
              <label className="just-label">3 · Falta a justificar</label>
              <select
                value={faltaSel}
                onChange={(e) => setFaltaSel(e.target.value)}
                className="just-input"
                disabled={!alumnoSel || cargandoFaltas}
              >
                <option value="">
                  {!alumnoSel ? "— Primero elegí el alumno —" : cargandoFaltas ? "Buscando faltas…" : errorFaltas ? "No se pudieron cargar las faltas" : faltasSel.length ? "— Elegir fecha —" : "Este alumno no tiene ausencias sin justificar"}
                </option>
                {faltasSel.map((r) => (
                  <option key={r.asistencia_id} value={r.asistencia_id}>
                    {fmtFecha(r.fecha)} · Clase N.°{r.numero_clase ?? "—"} · {cursoLabel(r.anio, r.division)}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="just-label">Motivo (hasta 255 letras)</label>
              <input
                value={motivo}
                onChange={(e) => setMotivo(e.target.value)}
                placeholder="Ej: Certificado médico"
                list="just-motivos-sugeridos"
                maxLength={255}
                className="just-input"
              />
              <datalist id="just-motivos-sugeridos">
                {MOTIVOS_SUGERIDOS.map((m) => <option key={m} value={m} />)}
              </datalist>
            </div>
          </div>
          <div>
            <label className="just-label">Observación (opcional)</label>
            <textarea
              value={observacion}
              onChange={(e) => setObservacion(e.target.value)}
              rows={2}
              maxLength={1000}
              placeholder="Detalle del justificativo, fecha del certificado, quién lo presenta…"
              className="just-textarea"
            />
          </div>
          <div className="just-form-actions">
            <button
              type="button"
              disabled={saving || !cursoFormOk}
              onClick={crear}
              className="just-btn-save"
            >
              {saving ? "Guardando…" : "Guardar justificación"}
            </button>
            <button
              type="button"
              onClick={() => setShowForm(false)}
              className="just-btn-cancel"
            >
              Cancelar
            </button>
            <span className="just-form-nota">
              Al guardar, la falta queda "pendiente" hasta que alguien la apruebe o rechace.
            </span>
          </div>
        </div>
      )}

      {loading && (
        <div className="just-loading">
          <Loader className="just-loading-icon" /> Cargando…
        </div>
      )}

      {error && !loading && <div className="asist-error">{error}</div>}

      {!loading && !error && data?.recortado && (
        <div className="asist-nota">
          {`Se muestran las ${data.justificaciones.length} justificaciones más recientes de ${data.total_justificaciones} que hay con ese filtro. Acotá el curso o el período para ver el resto.`}
        </div>
      )}

      {!loading && !error && data && (data.justificaciones || []).length === 0 && (
        <EmptyState
          icon={FileText}
          title={TITULOS_VACIO[filtro] || TITULOS_VACIO[""]}
          description={
            filtro === "pendiente"
              ? "No hay faltas esperando aprobación. Registrate una con el botón de arriba o revisá el estado \"Todas\"."
              : "No hay solicitudes con ese estado para los cursos que estás viendo."
          }
        />
      )}

      {!loading && !error && data && (data.justificaciones || []).length > 0 && (
        <div className="just-table-card">
          <div className="just-table-wrap">
            <table className="just-table">
              <thead>
                <tr className="just-thead-row">
                  <th className="just-th">Fecha de la falta</th>
                  <th className="just-th">N° de clase</th>
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
                              title="Aprobar el justificativo: la falta pasa a “justificado”"
                              className="just-btn-aprobar"
                            >
                              <Check className="just-btn-icon" /> Aprobar
                            </button>
                            <button
                              type="button"
                              onClick={() => resolver(j, "rechazada")}
                              title="Rechazar el justificativo: la falta sigue registrada como ausente"
                              className="just-btn-rechazar"
                            >
                              <X className="just-btn-icon" /> Rechazar
                            </button>
                          </>
                        )}
                        {j.estado !== "pendiente" && (
                          <span className="just-sin-resolucion">
                            {j.estado === "aprobada" ? "Ya aprobada" : "Ya rechazada"}
                          </span>
                        )}
                        {j.estado === "pendiente" && !puedeResolver && (
                          <span className="just-sin-resolucion">—</span>
                        )}
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