// Pantalla "Pasar lista": marcar la asistencia de una clase de un curso.
// Pensada para alguien sin experiencia: un solo camino, sin valores por defecto
// y no se puede guardar hasta que TODOS los alumnos tengan un estado elegido.
import { useEffect, useMemo, useRef, useState } from "react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import {
  CalendarCheck, CalendarDays, CheckCircle2, CircleDashed, ClipboardList, Clock,
  Download, Keyboard, Loader, Lock, Pencil, Printer, Save, Search, AlertCircle, Undo2, X,
} from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { ESTADOS_ASISTENCIA, ESTADO_LABELS, fmtPct, cursoLabel, hoyLocal, esCursoEditable } from "../../compartido/lib/asistencia";
import { coincideBusqueda, coincideDni } from "../../compartido/lib/busqueda";
import { estaFueraDeCursada } from "../../compartido/lib/estadosAlumno";
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

// Atajo de teclado por estado: se usa con la fila activa seleccionada.
const ATAJO_ESTADO = { p: "presente", a: "ausente", t: "tarde", j: "justificado" };

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
  // Una vez guardada, la planilla se bloquea (opaca y sin botones activos) para
  // que no se toque por accidente. "Editar asistencia" la vuelve a habilitar.
  const [editando, setEditando] = useState(false);
  const [checking, setChecking] = useState(false);
  const [loadingAlumnos, setLoadingAlumnos] = useState(false);
  const [saving, setSaving] = useState(false);
  const [info, setInfo] = useState(null);
  const [filtroAlumno, setFiltroAlumno] = useState("");
  // Fila "activa": la que responde a las teclas. Es la que sigue sin marcar,
  // así se puede pasar lista entero sin levantar las manos del teclado.
  const [filaActiva, setFilaActiva] = useState(null);

  // El backend manda "editables" solo cuando la escritura es por curso asignado;
  // lista vacía significa que la cuenta no puede marcar ninguna asistencia.
  const sinEditables =
    Array.isArray(maps?.editables) && maps.editables.length === 0;
  // El backend distingue al rol que no toma asistencia (puede_marcar=false) de la
  // cuenta que sí la toma pero no tiene ningún curso asignado (lista vacía).
  const rolNoToma = maps?.puede_marcar === false;

  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();

  const baseRef = useRef([]);
  // Cada carga de lista se numera. Si mientras se esperaba la respuesta se pidió
  // otra cosa (se cambió de curso, o se entró a editar una clase puntual desde
  // la pestaña Clases), la respuesta vieja se descarta en vez de pisar la
  // pantalla con datos del curso anterior.
  const versionLista = useRef(0);
  // "Ver y editar" ya trae la clase completa; la marca de curso+fecha que cubre
  // ese pedido evita que el efecto de abajo vuelva a cargarla y apague el modo
  // edición que se acaba de pedir.
  const cargaCubierta = useRef(null);
  // Cuántas marcas tiene lo que quedó guardado (lo que trajo el servidor o lo
  // que se acaba de guardar). Va en estado, no se deduce de `rows`: de lo
  // contrario, marcar al primer alumno ya cerraría la planilla.
  const [baseMarcas, setBaseMarcas] = useState(0);
  const fijarBase = (filas) => {
    const f = filas || [];
    baseRef.current = f.map((r) => ({ ...r }));
    setBaseMarcas(f.filter((r) => r.estado && r.estado !== SIN_ELEGIR).length);
    setRows(f);
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
  const tituloSinMarcado = rolNoToma
    ? "Tu rol no toma asistencia: la dirección lo habilita en Usuarios › Roles y permisos."
    : "Este curso no está asignado a tu cuenta.";

  // Aviso cuando la clase tiene marcas de alumnos que ya no están en el curso
  // (promovidos o cambiados de división): la planilla se arma con los actuales.
  const notaFueraDeCurso = (detalle) => {
    const fuera = detalle?.marcas_fuera || [];
    if (fuera.length === 0) return "";
    const ejemplos = fuera.slice(0, 3).map((m) => `${m.nombre} (${m.curso_actual})`).join(", ");
    return `Hay ${fuera.length} marca${fuera.length === 1 ? "" : "s"} de alumnos que hoy están en otro curso: ${ejemplos}${fuera.length > 3 ? "…" : ""}.`;
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
    // Que la clase ya estaba cargada lo dice la etiqueta de estado de arriba,
    // que es la que se ve de un vistazo; acá solo queda lo que esa etiqueta
    // no puede mostrar: las marcas de alumnos que se fueron del curso.
    const fuera = notaFueraDeCurso(detalle);
    setInfo(fuera ? { type: "info", text: fuera } : null);
    return true;
  }

  async function loadAlumnos(cur, withClass = true) {
    const mia = ++versionLista.current;
    const vigente = () => mia === versionLista.current;
    setLoadingAlumnos(true);
    setInfo(null);
    // Curso o fecha nuevos: se vuelve al modo bloqueado si ese día ya tiene lista.
    setEditando(false);
    try {
      const data = await asistenciaApi.alumnosCurso(cur.anio, cur.division);
      if (!vigente()) return;
      const list = (data?.data || []).filter((a) => !estaFueraDeCursada(a));
      fijarBase(filasNuevas(list));
      if (withClass && cur.anio && cur.division && fecha) {
        setChecking(true);
        try {
          await cargarClaseExistente(cur, fecha);
          if (!vigente()) return;
        } catch {
          if (vigente()) setClaseId(null);
        } finally {
          if (vigente()) setChecking(false);
        }
      }
    } catch {
      if (!vigente()) return;
      mostrarAviso({ icon: "error", title: "No se pudo abrir la lista", text: "Volvé a intentar en un momento." });
      fijarBase([]);
    } finally {
      if (vigente()) setLoadingAlumnos(false);
    }
  }

  useEffect(() => {
    if (!cursoOk) return;
    if (cargaCubierta.current === `${curso.anio}|${curso.division}|${fecha}`) {
      cargaCubierta.current = null;
      return;
    }
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
        const anio = String(cur.anio ?? c.anio ?? "");
        const division = String(cur.division ?? c.division ?? "");
        const dia = c.fecha || hoy();
        // Cualquier carga de lista que esté en vuelo se descarta: esta respuesta
        // ya trae el curso y las marcas de la clase que se pidió editar.
        versionLista.current += 1;
        // Solo se marca si el cambio de curso/fecha va a disparar el efecto de
        // abajo; si ya estaba en ese curso y fecha, el efecto no corre y la
        // marca quedaría pendiente para una carga posterior legítima.
        const vaOcambiar = anio !== curso.anio || division !== curso.division || dia !== fecha;
        cargaCubierta.current = vaOcambiar ? `${anio}|${division}|${dia}` : null;
        setCurso({ anio, division });
        setFecha(dia);
        setHoraInicio(hhmm(c.hora_inicio));
        setHoraFin(hhmm(c.hora_fin));
        setEstadoClase(c.estado || "dictada");
        setComentario(c.comentario || "");
        setClaseId(c.id);
        // Se llegó acá desde "Ver y editar" en la pestaña Clases: la intención
        // explícita es corregir, así que la lista abre habilitada.
        setEditando(true);
        fijarBase((detalle.alumnos || []).map(aFila));
        setInfo({
          type: "info",
          text: [`Estás editando la clase N.°${c.numero_clase} del ${fmtFechaBonita(c.fecha)}.`, notaFueraDeCurso(detalle)]
            .filter(Boolean).join(" "),
        });
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

  // "Marcar todos" actúa sobre lo que se está viendo: si se filtró un alumno o
  // una letra, no tiene que tocar al resto del curso por detrás de la búsqueda.
  const marcarVisibles = (estado) => {
    const ids = new Set(filasVisibles.map((r) => r.alumno_id));
    setRows((prev) => prev.map((r) => (ids.has(r.alumno_id) ? { ...r, estado } : r)));
    setFilaActiva(null);
  };

  const filasVisibles = useMemo(() => {
    const q = filtroAlumno.trim();
    if (!q) return rows;
    return rows.filter((r) =>
      coincideBusqueda(`${r.apellido} ${r.nombre}`, q) ||
      coincideBusqueda(`${r.nombre} ${r.apellido}`, q) ||
      coincideDni(r.dni, q));
  }, [rows, filtroAlumno]);

  const resumen = useMemo(() => {
    const c = { presente: 0, ausente: 0, justificado: 0, tarde: 0, sinElegir: 0 };
    rows.forEach((r) => { if (c[r.estado] != null) c[r.estado] += 1; else c.sinElegir += 1; });
    return { ...c, total: rows.length, elegidos: rows.length - c.sinElegir };
  }, [rows]);

  // Solo se puede guardar cuando no falta nadie (y la clase no está suspendida).
  const todoElegido = resumen.sinElegir === 0 && resumen.total > 0;
  const faltaUno = resumen.sinElegir === 1;
  const progresoPct = resumen.total ? Math.round((resumen.elegidos / resumen.total) * 100) : 0;

  // "Cargada" = el sistema ya tiene marcas guardadas de este día. Se lee de la
  // base, no de lo marcado en pantalla: si no, al marcar al primer alumno la
  // planilla se cerraría sola. Una clase creada pero sin ninguna marca no
  // cuenta, porque hay que poder marcarla.
  const cargada = Boolean(claseId) && (suspendida || baseMarcas > 0);
  // Guardada = planilla cerrada. "Editar asistencia" la vuelve a abrir.
  const bloqueada = cargada && !editando && puedeMarcar;
  // Espejo de la anterior: una lista guardada que se abrió para corregir.
  // Es lo que habilita el botón "Cancelar edición".
  const enEdicion = cargada && editando && puedeMarcar;

  // La etiqueta de arriba responde "¿esta clase ya tiene asistencia tomada?"
  // sin tener que bajar al pie: cargada es lo que hay guardado y haySinGuardar
  // lo que se tocó después.
  const estadoLista = !cargada
    ? (resumen.elegidos
      ? { tono: "pendiente", titulo: "Sin asistencia cargada", detalle: `${resumen.elegidos} de ${resumen.total} marcados · falta guardar` }
      : {
          tono: "pendiente",
          titulo: "Sin asistencia cargada",
          detalle: claseId
            ? "La clase existe pero nadie está marcado: elegí el estado de cada alumno"
            : "Todavía no se pasó la lista de este día",
        })
    : haySinGuardar
      ? { tono: "cambios", titulo: "Asistencia tomada", detalle: "Hay cambios sin guardar" }
      : suspendida
        ? { tono: "tomada", titulo: "Clase suspendida cargada", detalle: "Sin marcas de alumnos" }
        : {
            tono: "tomada",
            titulo: "Asistencia tomada",
            detalle: baseMarcas === resumen.total
              ? `${resumen.total} ${resumen.total === 1 ? "alumno marcado" : "alumnos marcados"}`
              : `${baseMarcas} de ${resumen.total} alumnos marcados`,
          };

  // ---------- Fila activa + atajos de teclado ----------
  // Pasar 30 alumnos a clicks es lento. Con P/A/T/J y las flechas se marca
  // seguidos sin soltar el teclado; al marcar, la fila activa salta sola al
  // próximo que todavía no tiene estado.
  const idsVisibles = useMemo(() => filasVisibles.map((r) => r.alumno_id), [filasVisibles]);
  const filaRefs = useRef(new Map());
  const contenedorRef = useRef(null);

  const avanzarDesde = (indice) => {
    if (!idsVisibles.length) return;
    for (let k = 1; k <= idsVisibles.length; k += 1) {
      const i = (indice + k) % idsVisibles.length;
      if (filasVisibles[i].estado === SIN_ELEGIR) { setFilaActiva(filasVisibles[i].alumno_id); return; }
    }
    // Ya no falta nadie: se deja la fila siguiente igual, por si hay que corregir.
    setFilaActiva(idsVisibles[(indice + 1) % idsVisibles.length]);
  };

  const marcarYAvanzar = (alumno_id, estado) => {
    setEstadoAlumno(alumno_id, estado);
    const i = idsVisibles.indexOf(alumno_id);
    if (i >= 0) avanzarDesde(i);
  };

  // Si cambia el filtro o la lista, la fila activa se reubica en el primero sin marcar.
  useEffect(() => {
    if (!filasVisibles.length) { setFilaActiva(null); return; }
    setFilaActiva((actual) => {
      if (actual != null && idsVisibles.includes(actual)) return actual;
      const primero = filasVisibles.find((r) => r.estado === SIN_ELEGIR) || filasVisibles[0];
      return primero ? primero.alumno_id : null;
    });
  }, [filasVisibles, idsVisibles]);

  // La fila activa siempre se ve en pantalla (el curso puede tener 50 líneas).
  useEffect(() => {
    if (filaActiva == null) return;
    filaRefs.current.get(filaActiva)?.scrollIntoView({ block: "nearest" });
  }, [filaActiva]);

  const sePuedeMarcarConTeclado = puedeMarcar && !bloqueada && !suspendida && !loadingAlumnos && !checking && idsVisibles.length > 0;

  useEffect(() => {
    function onKey(e) {
      if (e.ctrlKey || e.metaKey || e.altKey || !sePuedeMarcarConTeclado) return;
      // Esta pestaña puede estar montada pero oculta (el usuario está en otra
      // solapa): sin esta guarda las teclas marcarían lista en silencio.
      const panel = contenedorRef.current;
      if (!panel || panel.offsetParent === null) return;
      const t = e.target;
      const tag = t?.tagName;
      // Cualquier campo le gana la tecla: mientras se escribe (nombre, DNI,
      // nota, fecha, hora) las letras y las flechas son para ese campo.
      if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || t?.isContentEditable) return;

      const moverse = e.key === "ArrowDown" || e.key === "ArrowUp" || (e.key === "Enter" && tag !== "BUTTON");
      if (moverse) {
        e.preventDefault();
        const i = idsVisibles.indexOf(filaActiva);
        const delta = e.key === "ArrowUp" ? -1 : 1;
        setFilaActiva(idsVisibles[(i + delta + idsVisibles.length) % idsVisibles.length]);
        return;
      }

      const estado = ATAJO_ESTADO[(e.key || "").toLowerCase()];
      if (!estado || filaActiva == null) return;
      e.preventDefault();
      marcarYAvanzar(filaActiva, estado);
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
    // marcarYAvanzar se recrea en cada render; el listener se vuelve a colgar
    // igual cuando cambian la lista o la fila activa, que es lo que importa.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sePuedeMarcarConTeclado, idsVisibles, filaActiva]);

  // Cambios sin guardar: se pueden tirar abajo con un clic.
  function descartarCambios() {
    const base = (baseRef.current || []).map((x) => ({ ...x }));
    setRows(base);
    setFilaActiva(null);
    // Descartar devuelve a la versión guardada, así que la planilla se
    // vuelve a cerrar. Sin clase guardada se queda abierta para marcar.
    if (claseId) setEditando(false);
  }

  // "Editar asistencia": único camino para volver a tocar una lista guardada.
  // Deja la fila activa en el primero de la lista para seguir con el teclado.
  const activarEdicion = () => {
    setEditando(true);
    if (filasVisibles[0]) setFilaActiva(filasVisibles[0].alumno_id);
  };

  // "Cancelar edición": el camino de vuelta de «Editar asistencia». Cierra la
  // planilla dejando lo que ya estaba guardado. Si se tocó algo, se avisa antes
  // de tirarlo abajo, porque sino un clic en el botón borra la corrección.
  async function cancelarEdicion() {
    if (haySinGuardar) {
      const seguro = await pedirConfirmacion({
        icon: "warning",
        title: "¿Cancelar la edición?",
        text: "Todavía no guardaste lo que corregiste. Al cancelar vuelve la asistencia tal como estaba guardada.",
        confirmText: "Sí, cancelar edición",
        cancelText: "No, seguir editando",
      });
      if (!seguro) return;
    }
    descartarCambios();
  }

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
            fijarBase((r.alumnos || []).map(aFila));
            entradaDeshacer = {
              etiqueta: `Asistencia del ${foto.fecha}`,
              deshacer: async () => {
                await asistenciaApi.editar(claseId, {
                  ...foto,
                  alumnos: anterior.map(({ alumno_id, estado, anotacion }) => ({ alumno_id, estado, anotacion })),
                  _detalles: "Deshace el último guardado",
                });
                fijarBase(anterior);
              },
            };
          } else {
            const r = await asistenciaApi.guardar(payload);
            const nuevaId = r.clase?.id || null;
            seGuardo = true;
            setClaseId(nuevaId);
            fijarBase((r.alumnos || []).map(aFila));
            if (nuevaId) {
              entradaDeshacer = {
                etiqueta: `Asistencia del ${foto.fecha}`,
                exigePassword: true,
                deshacer: async () => {
                  await asistenciaApi.eliminar(nuevaId);
                  setClaseId(null);
                  fijarBase(filasNuevas(rows));
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
    // Aviso que se queda en la pantalla (el toast dura poco y el resto de la
    // planilla no cambia): así se ve que lo marcado ya está en el sistema.
    const hora = new Date().toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit", hour12: false });
    setInfo({
      type: "ok",
      text: suspendida
        ? `Clase suspendida guardada a las ${hora}.`
        : `Asistencia guardada a las ${hora} · ${resumen.presente + resumen.tarde} de ${resumen.total} ${resumen.total === 1 ? "alumno" : "alumnos"} presente${resumen.presente + resumen.tarde === 1 ? "" : "s"}.`,
    });
    // Guardado = cerrado otra vez: lo que se ve es exactamente lo que quedó
    // en el sistema, y para cambiar algo hay que pedir "Editar asistencia".
    setEditando(false);
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
            rolNoToma
              ? "Tu rol no toma asistencia, pero podés verla. En la solapa \"Clases\" están las clases pasadas de tus cursos."
              : sinEditables
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
    <div className="pl-container" ref={contenedorRef}>
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

      {busy && (
        <div className="pl-cargando"><Loader size={18} /> Cargando la lista…</div>
      )}

      {!busy && (
        <>
          {/* ---------- Estado de la clase, arriba y a la vista ---------- */}
          <div className={`pl-estado pl-estado-${estadoLista.tono}`}>
            {estadoLista.tono === "tomada"
              ? <CheckCircle2 size={16} />
              : <CircleDashed size={16} />}
            <b>{estadoLista.titulo}</b>
            <span className="pl-estado-det">{estadoLista.detalle}</span>
            {bloqueada && (
              <button type="button" onClick={activarEdicion} className="pl-btn-editar">
                <Pencil size={15} /> Editar asistencia
              </button>
            )}
          </div>

          {info && (
            <div className={`pl-info ${info.type === "ok" ? "pl-info-ok" : ""}`}>
              {info.type === "ok" ? <CheckCircle2 size={16} /> : <ClipboardList size={16} />} {info.text}
            </div>
          )}

          {/* ---------- Paso 2: cuanto falta ---------- */}
          {!suspendida && rows.length > 0 && (
            <div className={`pl-avance ${todoElegido ? "pl-avance-ok" : ""}`}>
              <div className="pl-avance-texto">
                {todoElegido ? (
                  <>
                    {cargada && !haySinGuardar
                      ? `Guardado. Están los ${rows.length} ${rows.length === 1 ? "alumno marcado" : "alumnos marcados"}.`
                      : `Listo. Ya marcaste a los ${rows.length} ${rows.length === 1 ? "alumno" : "alumnos"}.`}
                  </>
                ) : (
                  <>
                    <b>{resumen.sinElegir}</b> de {rows.length} alumnos sin marcar
                  </>
                )}
              </div>
              <div className="pl-progreso" role="progressbar" aria-valuenow={progresoPct} aria-valuemin={0} aria-valuemax={100}>
                <div className="pl-progreso-fill" style={{ width: `${progresoPct}%` }} />
              </div>
              {!todoElegido && puedeMarcar && !bloqueada && (
                <button type="button" onClick={() => marcarVisibles("presente")} className="pl-btn-todos">
                  <CheckCircle2 size={16} />
                  {filtroAlumno.trim()
                    ? `Marcar ${filasVisibles.length} ${filasVisibles.length === 1 ? "alumno visible" : "alumnos visibles"}`
                    : "Marcar todos presentes"}
                </button>
              )}
            </div>
          )}

          {puedeMarcar && !suspendida && rows.length > 0 && bloqueada && (
            <div className="pl-bloqueo-nota">
              <Lock size={14} /> La lista quedó guardada y bloqueada para que no se toque de más.
              Tocá <b>Editar asistencia</b> (arriba a la derecha) para corregirla.
            </div>
          )}

          {puedeMarcar && !suspendida && !bloqueada && rows.length > 0 && (
            <div className="pl-atajos">
              <Keyboard size={14} />
              <span><b>P</b> presente</span>
              <span><b>A</b> ausente</span>
              <span><b>T</b> tarde</span>
              <span><b>J</b> justificado</span>
              <span><b>↑ ↓</b> cambiar de alumno</span>
              <span className="pl-atajos-nota">Se marca sobre el alumno resaltado y salta solo al que falta.</span>
            </div>
          )}

          {/* ---------- Datos de la clase (opcional), encima de la lista ---------- */}
          <details className="pl-detalles">
            <summary className="pl-detalles-resumen">Datos de la clase (opcional)</summary>
            <div className="pl-detalles-campos">
              <div>
                <label className="pl-label">Hora de inicio</label>
                <input type="time" value={horaInicio} onChange={(e) => setHoraInicio(e.target.value)} disabled={!puedeMarcar || bloqueada} className="pl-input" />
              </div>
              <div>
                <label className="pl-label">Hora de fin</label>
                <input type="time" value={horaFin} onChange={(e) => setHoraFin(e.target.value)} disabled={!puedeMarcar || bloqueada} className="pl-input" />
              </div>
              <div>
                <label className="pl-label">La clase se dictó</label>
                <select value={estadoClase} onChange={(e) => setEstadoClase(e.target.value)} disabled={!puedeMarcar || bloqueada} className="pl-input">
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
                disabled={!puedeMarcar || bloqueada}
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
            <div className={`pl-lista ${bloqueada ? "pl-lista-bloqueada" : ""}`}>
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
                <div
                  key={r.alumno_id}
                  ref={(el) => {
                    if (el) filaRefs.current.set(r.alumno_id, el);
                    else filaRefs.current.delete(r.alumno_id);
                  }}
                  onClick={() => { if (!bloqueada) setFilaActiva(r.alumno_id); }}
                  className={[
                    "pl-fila",
                    r.estado ? `pl-fila-${r.estado}` : "pl-fila-sin-elegir",
                    filaActiva === r.alumno_id ? "pl-fila-activa" : "",
                  ].join(" ")}
                >
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
                        disabled={!puedeMarcar || bloqueada}
                        title={bloqueada
                          ? "La lista ya está guardada. Tocá «Editar asistencia» para corregirla."
                          : puedeMarcar ? `Marcar como ${ESTADO_LABELS[s].toLowerCase()} (tecla ${s[0].toUpperCase()})` : tituloSinMarcado}
                        onClick={() => marcarYAvanzar(r.alumno_id, s)}
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
                      disabled={!puedeMarcar || bloqueada}
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
                ? rolNoToma
                  ? "Tu rol no toma asistencia: podés ver la lista pero no guardarla. La dirección lo habilita en Usuarios › Roles y permisos."
                  : "Este curso no está asignado a tu cuenta: podés ver la lista pero no guardarla."
                : bloqueada
                  ? "La lista está guardada y bloqueada. Se edita con «Editar asistencia», arriba."
                  : enEdicion
                    ? "Estás corrigiendo una clase guardada. Con «Cancelar edición» vuelve a quedar como estaba."
                    : claseId ? "Vas a modificar una clase ya cargada." : "Todavía no guardaste esta lista."}
            </div>
            <div className="pl-pie-acciones">
              {/* Cancelar la edición: el pie está pegado abajo, así que el botón
                  se ve sin importar en qué fila se esté. Reemplaza a
                  «Descartar cambios» porque hacen lo mismo y dos botones
                  iguales confunden más que uno. */}
              {enEdicion && (
                <button
                  type="button"
                  disabled={saving}
                  onClick={cancelarEdicion}
                  title="Cerrar la edición y dejar la asistencia como estaba guardada"
                  className="pl-btn-cancelar"
                >
                  <X size={16} /> Cancelar edición
                </button>
              )}
              {!bloqueada && !enEdicion && haySinGuardar && puedeMarcar && (
                <button
                  type="button"
                  disabled={saving}
                  onClick={descartarCambios}
                  title="Volver a como estaba la lista antes de tocarla"
                  className="pl-btn-sec"
                >
                  <Undo2 size={16} /> Descartar cambios
                </button>
              )}
              {!bloqueada && (
                <button
                  type="button"
                  disabled={saving || busy || !puedeMarcar || (!suspendida && !todoElegido)}
                  onClick={guardar}
                  title={puedeMarcar ? undefined : tituloSinMarcado}
                  className="pl-btn-guardar"
                >
                  {saving ? <><Loader size={18} /> Guardando…</> : <><Save size={18} /> {claseId ? "Guardar cambios" : "Guardar lista"}</>}
                </button>
              )}
            </div>
          </div>

          {!suspendida && rows.length > 0 && (
            <div className="pl-resumen-linea">
              <Clock size={14} />
              Asistencia: <b>{fmtPct(resumen.total ? ((resumen.presente + resumen.tarde) / resumen.total) * 100 : null)}</b>
              {" · "}Presentes {resumen.presente} · Tardes {resumen.tarde} · Ausentes {resumen.ausente} · Justificados {resumen.justificado}
              <CalendarDays size={14} style={{ marginLeft: 12 }} />
              {cursoLabel(curso.anio, curso.division)}
              <div className="pl-criterio">
                Cómo se calcula: la tarde cuenta como presencia (el alumno llegó) y el
                justificado cuenta como falta aunque tenga motivo. Por eso el porcentaje
                suma presentes y tardes.
              </div>
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