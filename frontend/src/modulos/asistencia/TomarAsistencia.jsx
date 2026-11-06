// Pantalla "Pasar lista": marcar la asistencia de una clase de un curso.
// Pensada para alguien sin experiencia: un solo camino, sin valores por defecto
// y no se puede guardar hasta que TODOS los alumnos tengan un estado elegido.
import { useEffect, useMemo, useRef, useState } from "react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import {
  CalendarCheck, CalendarDays, CheckCircle2, ClipboardList, Clock,
  Download, Loader, Printer, Save, Search, AlertCircle,
} from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { ESTADOS_ASISTENCIA, ESTADO_LABELS, fmtPct, cursoLabel, hoyLocal, esCursoEditable } from "../../compartido/lib/asistencia";
import { coincideBusqueda, coincideDni } from "../../compartido/lib/busqueda";
import { mensajeAmable } from "../../compartido/lib/errores";
import CoursePicker from "./CoursePicker";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import { useDeshacerAviso } from "../../compartido/lib/deshacer.jsx";
import { useToast } from "../../compartido/componentes/Common/Toast";
import "./PasarLista.css";

const hoy = () => hoyLocal();
const hhmm = (v) => { const s = String(v || ""); return s.length >= 5 ? s.slice(0, 5) : ""; };

// Estado inicial: NINGUNO. El docente tiene que elegir uno por alumno.
const SIN_ELEGIR = "";

// Estados para los que tiene sentido anotar el motivo.
const CON_NOTA = ["ausente", "tarde", "justificado"];

const aFila = (a) => ({
  alumno_id: a.alumno_id ?? a.id,
  nombre: a.nombre || "",
  apellido: a.apellido || "",
  dni: a.dni || "",
  estado: a.estado || SIN_ELEGIR,
  anotacion: a.anotacion || "",
});

// Listado limpio: los alumnos empiezan sin estado elegido.
const filasNuevas = (alumnos) =>
  alumnos.map((a) => ({ ...aFila(a), estado: SIN_ELEGIR, anotacion: "" }));

export default function TomarAsistencia({ maps, initial, onSaved }) {
  const [curso, setCurso] = useState(() => {
    try {
      const params = new URLSearchParams(window.location.search);
      const qAnio = params.get("anio");
      const qDiv = params.get("division");
      if (qAnio && qDiv) return { anio: String(qAnio), division: String(qDiv) };
    } catch { /* sin URL */ }
    return { anio: "", division: "" };
  });
  const [fecha, setFecha] = useState(hoy());
  const [horaInicio, setHoraInicio] = useState("");
  const [horaFin, setHoraFin] = useState("");
  const [estadoClase, setEstadoClase] = useState("dictada");
  const [comentario, setComentario] = useState("");
  const [rows, setRows] = useState([]);
  const [claseId, setClaseId] = useState(null);
  const [checking, setChecking] = useState(false);
  const [loadingAlumnos, setLoadingAlumnos] = useState(false);
  const [saving, setSaving] = useState(false);
  const [info, setInfo] = useState(null);
  const [filtroAlumno, setFiltroAlumno] = useState("");

  // El backend manda "editables" solo cuando la escritura es por curso asignado;
  // lista vacía significa que la cuenta no puede marcar ninguna asistencia.
  const sinEditables =
    Array.isArray(maps?.editables) && maps.editables.length === 0;

  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();

  const baseRef = useRef([]);
  const fijarBase = (filas) => {
    baseRef.current = (filas || []).map((r) => ({ ...r }));
    setRows(filas);
  };

  // El refresco automatico de la pantalla se fija en esta marca: mientras hay
  // estados elegidos sin guardar no se actualiza nada solo, para no tirar el
  // trabajo a medio hacer (el boton "Actualizar" queda disponible).
  const haySinGuardar = useMemo(() => {
    const base = baseRef.current || [];
    if (base.length !== rows.length) return rows.some((r) => r.estado !== SIN_ELEGIR);
    return rows.some(
      (r, i) => r.estado !== base[i].estado || (r.anotacion || "") !== (base[i].anotacion || "")
    );
  }, [rows]);
  useEffect(() => {
    if (haySinGuardar) document.body.setAttribute("data-cambios-sin-guardar", "1");
    else document.body.removeAttribute("data-cambios-sin-guardar");
    return () => document.body.removeAttribute("data-cambios-sin-guardar");
  }, [haySinGuardar]);

  const cursoOk = Boolean(curso.anio && curso.division && curso.division !== "*");
  const suspendida = estadoClase === "suspendida";
  // Un curso se puede llegar a ver sin estar asignado (el preceptor ve todos);
  // en ese caso la lista se muestra pero el guardado queda bloqueado.
  const puedeMarcar = esCursoEditable(maps?.editables, curso.anio, curso.division);

  // Aviso cuando la clase tiene marcas de alumnos que ya no están en el curso
  // (promovidos o cambiados de división): la planilla se arma con los actuales.
  const notaFueraDeCurso = (detalle) => {
    const fuera = detalle?.marcas_fuera || [];
    if (fuera.length === 0) return "";
    const ejemplos = fuera.slice(0, 3).map((m) => `${m.nombre} (${m.curso_actual})`).join(", ");
    return ` Además hay ${fuera.length} marca${fuera.length === 1 ? "" : "s"} de alumnos que hoy están en otro curso: ${ejemplos}${fuera.length > 3 ? "…" : ""}.`;
  };

  async function cargarClaseExistente(cur, f) {
    // Los datos de la clase arrancan limpios en cada fecha. Si hay una clase
    // guardada para ese dia se completan; si no, quedan vacios. Sin esto, la
    // hora y el comentario de una clase se arrastraban a la siguiente.
    setHoraInicio("");
    setHoraFin("");
    setComentario("");
    setEstadoClase("dictada");

    const cls = await asistenciaApi.clases({ anio: cur.anio, division: cur.division, desde: f, hasta: f });
    if (!cls.clases?.length) { setClaseId(null); return false; }
    const c = cls.clases[0];
    setClaseId(c.id);
    setHoraInicio(hhmm(c.hora_inicio));
    setHoraFin(hhmm(c.hora_fin));
    setEstadoClase(c.estado || "dictada");
    const detalle = await asistenciaApi.clase(c.id);
    setComentario(detalle.clase?.comentario ?? c.comentario ?? "");
    if (detalle.alumnos?.length) fijarBase(detalle.alumnos.map(aFila));
    setInfo({
      type: "info",
      text: `Esta clase del ${fmtFechaBonita(c.fecha)} ya estaba cargada. Podés cambiarla y volver a guardar.${notaFueraDeCurso(detalle)}`,
    });
    return true;
  }

  async function loadAlumnos(cur, withClass = true) {
    setLoadingAlumnos(true);
    setInfo(null);
    try {
      const data = await asistenciaApi.alumnosCurso(cur.anio, cur.division);
      const list = (data?.data || []).filter((a) => a.estado !== "Egresado");
      fijarBase(filasNuevas(list));
      if (withClass && cur.anio && cur.division && fecha) {
        setChecking(true);
        try { await cargarClaseExistente(cur, fecha); } catch { setClaseId(null); } finally { setChecking(false); }
      }
    } catch {
      mostrarAviso({ icon: "error", title: "No se pudo abrir la lista", text: "Volvé a intentar en un momento." });
      setRows([]);
      baseRef.current = [];
    } finally {
      setLoadingAlumnos(false);
    }
  }

  useEffect(() => {
    if (!cursoOk) return;
    loadAlumnos(curso);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [curso?.anio, curso?.division, fecha]);

  // Cuando se edita una clase desde la pestaña Clases.
  // Se aplica una sola vez por pedido (si se cambia de pestaña y se vuelve,
  // no se recarga desde el servidor para no borrar lo que se está marcando).
  const initialAplicado = useRef(null);
  useEffect(() => {
    if (!initial) return;
    const firma = JSON.stringify(initial);
    if (initialAplicado.current === firma) return;
    initialAplicado.current = firma;
    let cancelado = false;
    // La pestaña Clases manda { clase: id } o { curso: {anio, division}, fecha };
    // se aceptan también las formas sueltas por si llega de la URL.
    const clasePedido = initial.clase_id ?? initial.clase;
    if (clasePedido) {
      asistenciaApi.clase(clasePedido).then((detalle) => {
        if (cancelado) return;
        const c = detalle.clase || {};
        // El detalle trae el curso anidado ("curso": {anio, division}), no suelto.
        const cur = c.curso || {};
        setCurso({ anio: String(cur.anio ?? c.anio ?? ""), division: String(cur.division ?? c.division ?? "") });
        setFecha(c.fecha || hoy());
        setHoraInicio(hhmm(c.hora_inicio));
        setHoraFin(hhmm(c.hora_fin));
        setEstadoClase(c.estado || "dictada");
        setComentario(c.comentario || "");
        setClaseId(c.id);
        fijarBase((detalle.alumnos || []).map(aFila));
        setInfo({ type: "info", text: `Estás editando la clase N.°${c.numero_clase} del ${fmtFechaBonita(c.fecha)}.${notaFueraDeCurso(detalle)}` });
      }).catch((e) => {
        if (cancelado) return;
        mostrarAviso({
          icon: "error",
          title: "No se pudo abrir esta clase",
          text: mensajeAmable(e, "Revisá tu conexión e intentá de nuevo."),
        });
      });
    } else {
      const cur = initial.curso || initial;
      if (cur.anio && cur.division) {
        setCurso({ anio: String(cur.anio), division: String(cur.division) });
        if (initial.fecha) setFecha(initial.fecha);
      }
    }
    return () => { cancelado = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initial]);

  const setEstadoAlumno = (alumno_id, estado) =>
    setRows((prev) => prev.map((x) => (x.alumno_id === alumno_id ? { ...x, estado } : x)));
  const marcarTodos = (estado) => setRows((prev) => prev.map((r) => ({ ...r, estado })));

  const filasVisibles = filtroAlumno.trim()
    ? rows.filter((r) =>
        coincideBusqueda(`${r.apellido} ${r.nombre}`, filtroAlumno) ||
        coincideBusqueda(`${r.nombre} ${r.apellido}`, filtroAlumno) ||
        coincideDni(r.dni, filtroAlumno))
    : rows;

  const resumen = useMemo(() => {
    const c = { presente: 0, ausente: 0, justificado: 0, tarde: 0, sinElegir: 0 };
    rows.forEach((r) => { if (c[r.estado] != null) c[r.estado] += 1; else c.sinElegir += 1; });
    return { ...c, total: rows.length, elegidos: rows.length - c.sinElegir };
  }, [rows]);

  // Solo se puede guardar cuando no falta nadie (y la clase no está suspendida).
  const todoElegido = resumen.sinElegir === 0 && resumen.total > 0;
  const faltaUno = resumen.sinElegir === 1;
  const progresoPct = resumen.total ? Math.round((resumen.elegidos / resumen.total) * 100) : 0;

  async function guardar(e) {
    e.preventDefault();
    if (!cursoOk) { mostrarAviso({ icon: "warning", title: "Falta elegir el curso", text: "Elegí año y división." }); return; }
    if (!fecha) { mostrarAviso({ icon: "warning", title: "Falta la fecha", text: "Elegí el día de la clase." }); return; }

    if (!suspendida && !todoElegido) {
      await mostrarAviso({
        icon: "warning",
        title: "Faltan alumnos por marcar",
        text: `${resumen.sinElegir} ${faltaUno ? "alumno todavía no tiene" : "alumnos todavía no tienen"} un estado. Marcá ${faltaUno ? "el que falta" : "todos"} y volvé a guardar.`,
      });
      return;
    }

    const esEdicion = Boolean(claseId);
    const anterior = baseRef.current.map((r) => ({ ...r }));
    const foto = {
      anio: curso.anio, division: curso.division, fecha,
      hora_inicio: hhmm(horaInicio) || null, hora_fin: hhmm(horaFin) || null,
      estado: estadoClase, comentario: comentario.trim() || null,
    };

    let seGuardo = false;
    let entradaDeshacer = null;
    const detalles = await confirmAction(
      esEdicion
        ? "¿Querés guardar los cambios de esta clase?"
        : "¿Guardar la asistencia de esta clase?",
      async (d) => {
        const payload = {
          ...foto,
          alumnos: suspendida
            ? []
            : rows.map(({ alumno_id, estado, anotacion }) => ({ alumno_id, estado, anotacion })),
          _detalles: d,
        };

        setSaving(true);
        try {
          if (esEdicion) {
            const r = await asistenciaApi.editar(claseId, payload);
            seGuardo = true;
            const guardadas = (r.alumnos || []).map(aFila);
            setRows(guardadas);
            baseRef.current = guardadas.map((x) => ({ ...x }));
            entradaDeshacer = {
              etiqueta: `Asistencia del ${foto.fecha}`,
              deshacer: async () => {
                await asistenciaApi.editar(claseId, {
                  ...foto,
                  alumnos: anterior.map(({ alumno_id, estado, anotacion }) => ({ alumno_id, estado, anotacion })),
                  _detalles: "Deshace el último guardado",
                });
                baseRef.current = anterior.map((x) => ({ ...x }));
                setRows(anterior.map((x) => ({ ...x })));
              },
            };
          } else {
            const r = await asistenciaApi.guardar(payload);
            const nuevaId = r.clase?.id || null;
            seGuardo = true;
            setClaseId(nuevaId);
            const guardadas = (r.alumnos || []).map(aFila);
            setRows(guardadas);
            baseRef.current = guardadas.map((x) => ({ ...x }));
            if (nuevaId) {
              entradaDeshacer = {
                etiqueta: `Asistencia del ${foto.fecha}`,
                exigePassword: true,
                deshacer: async () => {
                  await asistenciaApi.eliminar(nuevaId);
                  setClaseId(null);
                  const fresca = filasNuevas(rows);
                  baseRef.current = fresca.map((x) => ({ ...x }));
                  setRows(fresca);
                },
              };
            }
          }
        } catch (err) {
          if (err.status === 409) {
            const cargar = await pedirConfirmacion({
              icon: "warning",
              title: "Esa clase ya existe",
              text: "Querés abrirla para ver lo que tiene cargado?",
              confirmText: "Sí, abrirla",
              cancelText: "No, cancelar",
            });
            if (cargar && err.data?.clase_id) {
              await cargarClaseExistente(curso, fecha);
            }
          } else {
            throw err;
          }
        } finally {
          setSaving(false);
        }
      }
    );

    if (detalles === null || !seGuardo) return;
    const mensajeExito = esEdicion
      ? "Cambios guardados."
      : `Lista guardada: ${resumen.presente + resumen.tarde} presentes de ${resumen.total} alumnos.`;
    if (entradaDeshacer) ofrecerDeshacer(entradaDeshacer, mensajeExito);
    else showToast(mensajeExito, "success");
    onSaved?.();
  }

  // ---- Sin curso elegido todavia ----
  if (!cursoOk && !loadingAlumnos && rows.length === 0) {
    return (
      <>
        <EmptyState
          icon={CalendarCheck}
          title="Pasar lista"
          description={
            sinEditables
              ? "Tu cuenta no tiene ningún curso asignado, así que no hay lista para marcar. Podés revisar las clases en la solapa \"Clases\"."
              : "Elegí el curso para empezar a marcar la asistencia."
          }
          action={
            <div className="pl-emptystate-action">
              <CoursePicker maps={maps} editables={maps?.editables ?? null} value={curso} onChange={setCurso} />
            </div>
          }
        />
        {confirmModal}{avisoModal}
      </>
    );
  }

  const busy = loadingAlumnos || checking;

  return (
    <div className="pl-container">
      {/* ---------- Paso 1: curso y fecha ---------- */}
      <div className="pl-top">
        <div className="pl-top-curso">
          <label className="pl-label">Curso</label>
          <CoursePicker maps={maps} editables={maps?.editables ?? null} value={curso} onChange={setCurso} />
        </div>
        <div className="pl-top-fecha">
          <label className="pl-label">Fecha de la clase</label>
          <input
            type="date"
            value={fecha}
            onChange={(e) => setFecha(e.target.value)}
            className="pl-input"
          />
        </div>
      </div>

      {info && (
        <div className="pl-info">
          <ClipboardList size={16} /> {info.text}
        </div>
      )}

      {busy && (
        <div className="pl-cargando"><Loader size={18} /> Cargando la lista…</div>
      )}

      {!busy && (
        <>
          {/* ---------- Paso 2: cuanto falta ---------- */}
          {!suspendida && rows.length > 0 && (
            <div className={`pl-avance ${todoElegido ? "pl-avance-ok" : ""}`}>
              <div className="pl-avance-texto">
                {todoElegido ? (
                  <>Listo. Ya marcaste a los {rows.length} alumnos.</>
                ) : (
                  <>
                    <b>{resumen.sinElegir}</b> de {rows.length} alumnos sin marcar
                  </>
                )}
              </div>
              <div className="pl-progreso" role="progressbar" aria-valuenow={progresoPct} aria-valuemin={0} aria-valuemax={100}>
                <div className="pl-progreso-fill" style={{ width: `${progresoPct}%` }} />
              </div>
              {!todoElegido && (
                <button type="button" onClick={() => marcarTodos("presente")} className="pl-btn-todos">
                  <CheckCircle2 size={16} /> Marcar todos presentes
                </button>
              )}
            </div>
          )}

          {/* ---------- Datos de la clase (opcional), encima de la lista ---------- */}
          <details className="pl-detalles">
            <summary className="pl-detalles-resumen">Datos de la clase (opcional)</summary>
            <div className="pl-detalles-campos">
              <div>
                <label className="pl-label">Hora de inicio</label>
                <input type="time" value={horaInicio} onChange={(e) => setHoraInicio(e.target.value)} className="pl-input" />
              </div>
              <div>
                <label className="pl-label">Hora de fin</label>
                <input type="time" value={horaFin} onChange={(e) => setHoraFin(e.target.value)} className="pl-input" />
              </div>
              <div>
                <label className="pl-label">La clase se dictó</label>
                <select value={estadoClase} onChange={(e) => setEstadoClase(e.target.value)} className="pl-input">
                  <option value="dictada">Sí, se dictó</option>
                  <option value="suspendida">No, se suspendió</option>
                </select>
              </div>
            </div>
            <div className="pl-detalles-comentario">
              <label className="pl-label">Comentario de la clase</label>
              <textarea
                value={comentario}
                onChange={(e) => setComentario(e.target.value)}
                rows={2}
                maxLength={2000}
                placeholder="Algo que quieras dejar anotado"
                className="pl-textarea"
              />
            </div>
          </details>

          {/* ---------- Paso 3: la lista ---------- */}
          {suspendida ? (
            <div className="pl-suspendida">
              <AlertCircle size={18} />
              <div>
                <b>Clase suspendida.</b> No se registra la asistencia de los alumnos.
                Guardá para dejar constancia.
              </div>
            </div>
          ) : rows.length === 0 ? (
            <div className="pl-vacio">
              Este curso no tiene alumnos inscriptos. Crealos en Alumnos y volvé a pasar lista.
            </div>
          ) : (
            <div className="pl-lista">
              <div className="pl-buscador">
                <Search size={16} />
                <input
                  type="text"
                  value={filtroAlumno}
                  onChange={(e) => setFiltroAlumno(e.target.value)}
                  placeholder="Buscar un alumno…"
                  aria-label="Buscar alumno"
                />
              </div>

              {filasVisibles.map((r, i) => (
                <div key={r.alumno_id} className={`pl-fila ${r.estado ? `pl-fila-${r.estado}` : "pl-fila-sin-elegir"}`}>
                  <span className="pl-fila-nro">{i + 1}</span>
                  <div className="pl-fila-quien">
                    <span className="pl-fila-apellido">{r.apellido}</span>
                    <span className="pl-fila-nombre">{r.nombre}</span>
                    {r.dni ? <span className="pl-fila-dni">{r.dni}</span> : null}
                  </div>

                  <div className="pl-botones" role="group" aria-label={`Asistencia de ${r.apellido}, ${r.nombre}`}>
                    {ESTADOS_ASISTENCIA.map((s) => (
                      <button
                        key={s}
                        type="button"
                        onClick={() => setEstadoAlumno(r.alumno_id, s)}
                        className={`pl-btn pl-btn-${s} ${r.estado === s ? "pl-btn-activo" : ""}`}
                        aria-pressed={r.estado === s}
                      >
                        {ESTADO_LABELS[s]}
                      </button>
                    ))}
                  </div>

                  {CON_NOTA.includes(r.estado) && (
                    <input
                      value={r.anotacion}
                      onChange={(e) => setRows((prev) => prev.map((x) => (x.alumno_id === r.alumno_id ? { ...x, anotacion: e.target.value } : x)))}
                      placeholder="Nota (opcional)"
                      aria-label={`Nota de ${r.apellido}, ${r.nombre}`}
                      className="pl-nota"
                      maxLength={500}
                    />
                  )}
                </div>
              ))}

              {filasVisibles.length === 0 && (
                <div className="pl-vacio">No encontramos a nadie con esa búsqueda.</div>
              )}
            </div>
          )}

          {/* ---------- Guardar ---------- */}
          <div className="pl-pie">
            <div className="pl-pie-info">
              {!puedeMarcar
                ? "Este curso no está asignado a tu cuenta: podés ver la lista pero no guardarla."
                : claseId ? "Vas a modificar una clase ya cargada." : "Todavía no guardaste esta lista."}
            </div>
            <div className="pl-pie-acciones">
              <button
                type="button"
                disabled={saving || busy || !puedeMarcar || (!suspendida && !todoElegido)}
                onClick={guardar}
                title={puedeMarcar ? undefined : "Tu cuenta no tiene asignado este curso."}
                className="pl-btn-guardar"
              >
                {saving ? <><Loader size={18} /> Guardando…</> : <><Save size={18} /> {claseId ? "Guardar cambios" : "Guardar lista"}</>}
              </button>
            </div>
          </div>

          {!suspendida && rows.length > 0 && (
            <div className="pl-resumen-linea">
              <Clock size={14} />
              Asistencia: <b>{fmtPct(resumen.total ? ((resumen.presente + resumen.tarde) / resumen.total) * 100 : null)}</b>
              {" · "}Presentes {resumen.presente} · Tardes {resumen.tarde} · Ausentes {resumen.ausente} · Justificados {resumen.justificado}
              <CalendarDays size={14} style={{ marginLeft: 12 }} />
              {cursoLabel(curso.anio, curso.division)}
            </div>
          )}
        </>
      )}

      {confirmModal}
      {avisoModal}
    </div>
  );
}

// Fecha en criollo, sin depender de la zona horaria del navegador.
function fmtFechaBonita(iso) {
  if (!iso) return "";
  const [a, m, d] = String(iso).slice(0, 10).split("-");
  return `${d}/${m}/${a}`;
}