// ===============================
// FILE: src/components/Curso/ModalHorario.jsx
// ===============================
import React, { useEffect, useRef, useState } from "react";
import { apiGet, apiPut } from "../../compartido/lib/api";
import { X, Pencil, Search, BookOpen, Layers, Printer, FileDown } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { useAuth } from "../../modulos/auth/AuthContext";
import { cursoLabel } from "../../compartido/lib/cursos";
import { useToast } from "../../compartido/componentes/Common/Toast";
import { useDeshacerAviso } from "../../compartido/lib/deshacer.jsx";
import { pedirTicketImpresion } from "../../compartido/lib/imprimir";
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import "./ModalHorario.css";

const RAW_API_BASE = import.meta?.env?.VITE_API_BASE || "http://localhost:8000/api";
const BACKEND_BASE = RAW_API_BASE.replace(/\/api\/?$/, "");

// Componente: ModalHorario
// Proposito: Modal que muestra y permite editar la grilla horaria de un curso (anio/division).
//            Soporta pestanas de "General" y "Taller/Especialidad" con subgrupos A/B.
//            Permite seleccionar materia y profesor por cada celda (dia x franja horaria).
// Props:
//   visible              - Booleano que controla la visibilidad del modal.
//   onClose              - Funcion para cerrar el modal.
//   curso                - Objeto con anio y division del curso.
//   horario              - Objeto con los datos del horario actual.
//   setHorario           - Funcion para actualizar el horario en el padre.
//   materiasDisponibles  - Lista de materias disponibles para el anio.
//   profesoresIndex      - Indice de profesores por materia (mapping).
//   highlightTarget      - Objeto opcional para resaltar un profesor especifico.
//   canEdit              - Booleano que indica si el usuario puede editar.
//   onBackToSearch       - Funcion para volver al modal de busqueda.

const DIAS = ["Lun", "Mar", "Mié", "Jue", "Vie"];
// Nota: agregamos los dos bloques que faltaban como h8 y h9
// y los ubicamos visualmente entre mañana y tarde para no romper
// las claves existentes (h4..h7 ya guardadas en BD).
const SLOTS = [
  { key: "h1", label: "07:30 – 08:30" },
  { key: "h2", label: "08:40 – 09:40" },
  { key: "h3", label: "09:50 – 10:50" },
  { key: "h8", label: "11:00 – 12:00" }, // NUEVO
  { key: "h9", label: "12:00 – 12:50" }, // NUEVO
  { key: "h4", label: "13:05 – 14:05" },
  { key: "h5", label: "14:10 – 15:10" },
  { key: "h6", label: "15:15 – 16:15" },
  { key: "h7", label: "16:20 – 17:20" },
];

/* Normaliza el nombre del dia para el backend (Mie sin acento) */
const diaKeyForBackend = (dia) => (dia === "Mié" ? "Mie" : dia);

export default function ModalHorario({
  visible,
  onClose,
  curso,
  horario = {},
  materiasDisponibles = [],
  profesoresIndex = {},
  highlightTarget = null,
  canEdit: canEditProp,
  onBackToSearch,
  loading = false,
}) {
  const { user } = useAuth();
  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();
  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();

  // Determina si el usuario puede editar segun la prop o su rol
  const canEdit = canEditProp !== undefined ? canEditProp : (user?.role !== 'profesor');

  const [modoEdicion, setModoEdicion] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [tab, setTab] = useState('general'); // 'general' | 'taller'
  const [subGrupo, setSubGrupo] = useState('A');
  const grupo = tab === 'general' ? null : subGrupo;
  // Controla que los tiempos se inicialicen UNA vez por grupo con el horario real ya cargado
  const initDoneRef = useRef({});
  // Snapshot del payload de la grilla al momento de entrar a edición: se usa
  // para deshacer el último guardado.
  const payloadOriginalRef = useRef(null);

  /* Convierte una etiqueta "07:30 – 08:30" en {inicio, fin} */
  const parseRango = (label) => {
    const m = String(label || '').match(/(\d{1,2}:\d{2})\s*–\s*(\d{1,2}:\d{2})/);
    if (m) return { inicio: m[1], fin: m[2] };
    return { inicio: '', fin: '' };
  };

  /* Inicializa los tiempos por slot desde la grilla cargada o los defaults */
  const initTiempos = (grid) => {
    const next = {};
    for (const slot of SLOTS) {
      const def = parseRango(slot.label);
      let ini = def.inicio;
      let fin = def.fin;
      const fila = (grid || {})[slot.key] || {};
      for (const dia of DIAS) {
        const k = diaKeyForBackend(dia);
        const raw = fila[k] ?? fila[dia];
        if (raw && typeof raw === 'object') {
          if (raw.horaInicio) ini = raw.horaInicio;
          if (raw.horaFin) fin = raw.horaFin;
        }
      }
      next[slot.key] = { inicio: ini, fin };
    }
    setTiempos(next);
  };

  /* Actualiza el tiempo manual de un slot y marca como sucio */
  const handleTiempoChange = (slotKey, campo, valor) => {
    setTiempos((prev) => ({
      ...prev,
      [slotKey]: { ...(prev[slotKey] || { inicio: '', fin: '' }), [campo]: valor },
    }));
    setDirty(true);
  };

  // Estados locales de carga para materias y profesores (fallback si el padre no los provee)
  const [localMaterias, setLocalMaterias] = useState([]);
  const [localProfesIdx, setLocalProfesIdx] = useState({});
  const [loadingMaterias, setLoadingMaterias] = useState(false);
  const [loadingProfes, setLoadingProfes] = useState(false);

  // Tiempos manuales por slot (modulo horario). Se inicializan desde la grilla
  // cargada o desde los rangos por defecto de SLOTS.
  const [tiempos, setTiempos] = useState({});

  // Grilla por grupo, para que General / Taller A / Taller B NO se pisen entre sí.
  // Claves: 'general' | 'A' | 'B'. null = aún no cargado.
  const [grids, setGrids] = useState({ general: null, A: null, B: null });
  // Error de carga por grupo (404 curso inexistente, 403 sin permiso, red...).
  // Antes se tragaba en silencio y la grilla quedaba vacía ("no aparecen").
  const [errores, setErrores] = useState({});
  const [reintento, setReintento] = useState(0);
  const grupoKey = grupo || 'general';

  // Celda en edición vía popup: { slotKey, dia } | null. Las celdas en modo
  // edición son compactas (igual altura que lectura) y abren este editor.
  const [celdaActiva, setCeldaActiva] = useState(null);

  const materias = materiasDisponibles.length ? materiasDisponibles : localMaterias;
  const profIdx = Object.keys(profesoresIndex || {}).length ? profesoresIndex : localProfesIdx;

  /* Bloquea el scroll del body cuando el modal esta abierto y resetea estado al abrir */
  useEffect(() => {
    document.body.style.overflow = visible ? "hidden" : "auto";
    if (visible) {
      setModoEdicion(false);
      setDirty(false);
      setTab('general');
      setSubGrupo('A');
      initDoneRef.current = {};
      setGrids({ general: null, A: null, B: null });
      setErrores({});
      setCeldaActiva(null);
    }
    return () => { document.body.style.overflow = "auto"; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible]);

  /* Carga materias y profesores del backend cuando cambia anio/division */
  useEffect(() => {
    if (!visible) return;
    const anio = curso?.anio;
    const division = curso?.division;
    if (!anio || !division) return;

    // Solo carga si el padre no provee las listas
    if (materiasDisponibles.length === 0 && localMaterias.length === 0) {
      setLoadingMaterias(true);
      apiGet(`/anios/${anio}/materias`)
        .then(list => setLocalMaterias(list || []))
        .catch(() => {})
        .finally(() => setLoadingMaterias(false));
    }

    if (Object.keys(profesoresIndex || {}).length === 0 && Object.keys(localProfesIdx).length === 0) {
      setLoadingProfes(true);
      apiGet(`/profesores/mapping?anio=${anio}&division=${division}&estado=Activo`)
        .then(map => setLocalProfesIdx(map || {}))
        .catch(() => {})
        .finally(() => setLoadingProfes(false));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible, curso?.anio, curso?.division]);

    /* Carga el horario del backend POR GRUPO (órden de pestaña), cacheándolo en
       grids[grupoKey] para que al cambiar de General a Taller A/B las materias
       ya editadas NO se pisen entre sí. */
    useEffect(() => {
      if (!visible) return;
      const anio = curso?.anio;
      const division = curso?.division;
      if (!anio || !division) return;

      // General: sembramos desde el prop del padre si viene con datos, sino fetcheamos.
      if (grupoKey === 'general' && grids.general === null) {
        if (horario && typeof horario === 'object' && Object.keys(horario).length > 0) {
          setGrids(prev => ({ ...prev, general: horario }));
          return;
        }
      }

      // Ya está cargado este grupo: no volver a pisarlo.
      if (grids[grupoKey] !== null) return;

      const ctrl = new AbortController();
      const url = `/horarios/${anio}/${division}${grupo ? '?grupo=' + encodeURIComponent(grupo) : ''}`;
      apiGet(url, ctrl.signal).then(data => {
        if (data && typeof data === 'object') {
          setGrids(prev => ({ ...prev, [grupoKey]: data }));
          setErrores(prev => ({ ...prev, [grupoKey]: null }));
        }
      }).catch(err => {
        if (err?.name === "AbortError") return;
        const msg = err?.data?.message || err?.message || "";
        // Mensaje claro según causa: curso inexistente (404), sin permiso (403) o red.
        const amable = err?.status === 404 && msg
          ? msg
          : err?.status === 403
            ? "No tenés permiso para ver el horario de este curso."
            : "No se pudo cargar el horario. Revisá tu conexión e intentá de nuevo.";
        setErrores(prev => ({ ...prev, [grupoKey]: amable }));
      });
      return () => ctrl.abort();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [visible, curso?.anio, curso?.division, grupo, horario, reintento]);

    /* Inicializa los tiempos manuales desde la grilla del grupo activo, UNA vez
       por grupo cargado. Se omite durante la edición para no pisar cambios. */
    const activeGrid = grids[grupoKey];
    useEffect(() => {
      if (!visible) return;
      if (modoEdicion) return;
      if (!activeGrid || typeof activeGrid !== 'object' || !Object.keys(activeGrid).length) return;
      const key = grupoKey;
      if (typeof initDoneRef.current !== 'object' || initDoneRef.current === null) {
        initDoneRef.current = {};
      }
      if (initDoneRef.current[key]) return;
      initTiempos(activeGrid);
      initDoneRef.current[key] = true;
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [visible, activeGrid, modoEdicion, grupo]);

  /* Cierra el popup editor al salir de edición o cambiar de pestaña/grupo */
  useEffect(() => {
    setCeldaActiva(null);
  }, [modoEdicion, tab, subGrupo]);

  /* Cierra el popup editor con Escape */
  useEffect(() => {
    if (!celdaActiva) return;
    const onKey = (e) => { if (e.key === "Escape") setCeldaActiva(null); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [celdaActiva]);

  /* Lee una celda del horario del grupo activo, soportando claves profesor_id / profesorId compatibles con versiones anteriores */
  const readCell = (slotKey, dia, grid) => {
    const g = grid ?? grids[grupoKey] ?? {};
    const k = diaKeyForBackend(dia);
    const raw = (g?.[slotKey]?.[k] ?? g?.[slotKey]?.[dia]);
    if (!raw) return { materia: "", profesor_id: "" };
    if (typeof raw === "string") return { materia: raw, profesor_id: "" };
    const pid = raw.profesor_id ?? raw.profesorId ?? raw.profesor ?? "";
    return { materia: raw.materia ?? "", profesor_id: pid };
  };

  /* Escribe una celda del horario del grupo activo y marca el estado como sucio (con cambios sin guardar) */
  const writeCell = (slotKey, dia, valueObj) => {
    const key = diaKeyForBackend(dia);
    const prev = grids[grupoKey] || {};
    const fila = prev[slotKey] || {};
    const materia = valueObj.materia ?? "";
    const profesor_id = valueObj.profesor_id ?? valueObj.profesorId ?? "";
    const next = { ...prev, [slotKey]: { ...fila, [key]: { materia, profesor_id } } };
    setGrids((prevGrids) => ({ ...prevGrids, [grupoKey]: next }));
    setDirty(true);
  };

  /* Al cambiar la materia, auto-asigna el primer profesor disponible */
  const handleMateriaChange = (slotKey, dia, materia) => {
    const profesores = (profIdx[materia] || []);
    const profesor_id = profesores.length ? profesores[0].id : "";
    writeCell(slotKey, dia, { materia, profesor_id });
  };

  /* Actualiza el profesor de una celda manteniendo la materia actual */
  const handleProfesorChange = (slotKey, dia, profesor_id) => {
    const current = readCell(slotKey, dia);
    writeCell(slotKey, dia, { materia: current.materia, profesor_id });
  };

  /* Limpia el contenido de una celda (materia y profesor) */
  const limpiarCelda = (slotKey, dia) => {
    writeCell(slotKey, dia, { materia: "", profesor_id: "" });
  };

  /* Cierra el modal con confirmacion si hay cambios sin guardar */
  const cerrarConConfirm = async () => {
    if (dirty) {
      const ok = await pedirConfirmacion({
        title: "Descartar cambios",
        text: "Tenés cambios sin guardar. Si cerrás ahora, se perderán.",
        icon: "warning",
        confirmText: "Descartar",
        cancelText: "Seguir editando",
      });
      if (!ok) return;
    }
    onClose?.();
    setModoEdicion(false);
    setDirty(false);
  };

  /* Vuelve al modal de busqueda con confirmacion si hay cambios sin guardar */
  const irABuscar = async () => {
    if (!onBackToSearch) return;
    if (dirty) {
      const ok = await pedirConfirmacion({
        title: "¿Descartar cambios?",
        text: "Vas a volver a la búsqueda. Los cambios sin guardar se perderán.",
        icon: "warning",
        confirmText: "Descartar",
        cancelText: "Seguir editando",
      });
      if (!ok) return;
    }
    setModoEdicion(false);
    setDirty(false);
    onBackToSearch();
  };

  /* Pide justificación con el modal y envía los horarios al backend via PUT */
  const armarPayload = () => {
    const toIntOrNull = (v) => {
      if (v === undefined || v === null || v === "") return null;
      const n = Number(v);
      return Number.isFinite(n) ? n : null;
    };

    const payload = {};
    for (const slot of SLOTS) {
      const fila = {};
      for (const dia of DIAS) {
        const k = diaKeyForBackend(dia);
        const { materia, profesor_id } = readCell(slot.key, dia);
        if (!materia || String(materia).trim() === "") {
          fila[k] = null;
        } else {
          const t = tiempos[slot.key] || { inicio: '', fin: '' };
          fila[k] = {
            materia: String(materia).trim(),
            profesor_id: toIntOrNull(profesor_id),
            horaInicio: t.inicio || null,
            horaFin: t.fin || null,
          };
        }
      }
      payload[slot.key] = fila;
    }
    return payload;
  };

  const guardarHorarios = async () => {
    if (!curso?.anio || !curso?.division || saving) return;
    const payload = armarPayload();

    const url = `/horarios/${curso.anio}/${curso.division}${grupo ? '?grupo='+encodeURIComponent(grupo) : ''}`;
    setSaving(true);
    try {
      const detalles = await confirmAction(
        `¿Qué cambios estás haciendo en el horario de ${cursoLabel({ anio: curso?.anio, division: curso?.division })}${grupo ? ` (Grupo ${grupo})` : ""}?`,
        async (d) => {
          await apiPut(url, { ...payload, _detalles: d });
        }
      );
      if (detalles === null) return;
      setModoEdicion(false);
      setDirty(false);
      ofrecerDeshacer(
        {
          etiqueta: `Horario ${cursoLabel({ anio: curso?.anio, division: curso?.division })}`.trim(),
          deshacer: async () => {
            const revert = payloadOriginalRef.current;
            if (!revert) return;
            await apiPut(url, { ...revert, _detalles: 'Deshace el último guardado' });
            setGrids((prev) => ({ ...prev, [grupoKey]: revert }));
            if (initDoneRef.current && typeof initDoneRef.current[grupoKey] !== 'undefined') {
              initDoneRef.current[grupoKey] = false;
            }
            setModoEdicion(false);
            setDirty(false);
          },
        },
        'Cambios guardados correctamente'
      );
    } catch (e) {
      console.error(e);
      await mostrarAviso({
        icon: "error",
        title: "No se pudo guardar",
        text: "El servidor rechazó el formato. Enviamos SLOTS×DIAS con profesor_id numérico o null.",
      });
    } finally {
      setSaving(false);
    }
  };

  const paramsHorario = async (conDescarga) => {
    const anio = curso?.anio;
    const division = curso?.division;
    if (!anio || !division) return null;
    const ticket = await pedirTicketImpresion();
    if (!ticket) {
      showToast("No se pudo autorizar la impresión. Probá de nuevo.", "error");
      return null;
    }
    const params = new URLSearchParams({
      anio,
      division,
      ticket,
    });
    const ciclo = curso?.ciclo_lectivo ?? curso?.ciclo;
    if (ciclo) params.set("ciclo", ciclo);
    if (grupo) params.set("grupo", grupo);
    if (conDescarga) params.set("download", "1");
    return { url: `${BACKEND_BASE}/horarios/planilla?${params.toString()}`, anio, division };
  };

  /* Imprime sin ventanas emergentes: usa un iframe oculto (no lo bloquea
     el navegador) y llama a imprimir cuando carga. */
  const imprimirHorario = async () => {
    const p = await paramsHorario(false);
    if (!p) return;
    showToast("Preparando el horario para imprimir...", "info");
    const iframe = document.createElement("iframe");
    iframe.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0;";
    iframe.onload = () => {
      try {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
      } catch {
        showToast("No se pudo abrir la impresión. Probá con Descargar PDF.", "error");
      }
      setTimeout(() => iframe.remove(), 60000);
    };
    iframe.onerror = () => {
      iframe.remove();
      showToast("No se pudo cargar el horario. Revisá tu conexión.", "error");
    };
    iframe.src = p.url;
    document.body.appendChild(iframe);
  };

  /* Descarga el horario como PDF real (generado por el backend con dompdf).
     Descarga directa, sin ventanas emergentes: queda en tu carpeta Descargas. */
  const exportarPdf = async () => {
    const p = await paramsHorario(true);
    if (!p) return;
    const nombre = `horario-${p.anio}-${p.division}.pdf`;
    const a = document.createElement("a");
    a.href = p.url;
    a.setAttribute("download", nombre);
    document.body.appendChild(a);
    a.click();
    a.remove();
    showToast(`Se descargó "${nombre}". Buscalo en tu carpeta Descargas.`, "success");
  };

  const materiasCargando = loadingMaterias && materias.length === 0;
  const profesCargando = loadingProfes && Object.keys(profIdx).length === 0;

  const hlColor = highlightTarget?.color || "rgba(59,130,246,.14)";

  return (
    <AnimatePresence>
      {visible && (
        <motion.div
          className="mhor-overlay"
          initial={false}
          exit={{ opacity: 0 }}
        >
          <motion.div
            className="mhor-dialog"
            role="dialog"
            aria-modal="true"
            initial={{ y: -10, scale: 0.98 }}
            animate={{ y: 0, scale: 1, transition: { type: "spring", stiffness: 260, damping: 22 } }}
            exit={{ opacity: 0, y: -10, scale: 0.98, transition: { duration: 0.2 } }}
          >
            {/* Header */}
            <div className="mhor-header">
              <div className="mhor-header-left">
                <h2 className="mhor-title">
                  Horario de {cursoLabel({ anio: curso?.anio, division: curso?.division })}
                </h2>

                {/* Tabs: General | Taller/Especialidad */}
                <div className="mhor-tabs">
                  <button
                    onClick={() => { setTab('general'); setModoEdicion(false); }}
                    className={`mhor-tab ${tab === 'general' ? 'mhor-tab--active' : 'mhor-tab--idle'}`}
                  >
                    <BookOpen size={14} />
                    General
                  </button>
                  <button
                    onClick={() => { setTab('taller'); setModoEdicion(false); }}
                    className={`mhor-tab ${tab === 'taller' ? 'mhor-tab--active' : 'mhor-tab--idle'}`}
                  >
                    <Layers size={14} />
                    Taller/Especialidad
                  </button>
                </div>

                {/* Sub-tabs Grupo A / Grupo B (solo cuando está en Taller) */}
                {tab === 'taller' && (
                  <div className="mhor-subtabs">
                    {['A', 'B'].map(g => (
                      <button key={g}
                        onClick={() => { setSubGrupo(g); setModoEdicion(false); }}
                        className={`mhor-subtab ${subGrupo === g ? 'mhor-subtab--active' : 'mhor-subtab--idle'}`}
                      >
                        Grupo {g}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              <div className="mhor-header-actions">
                {onBackToSearch && (
                  <motion.button
                    whileTap={{ scale: 0.98 }}
                    onClick={irABuscar}
                    className="mhor-btn-back"
                    title="Volver a búsqueda"
                  >
                    <Search size={16} />
                    Volver a búsqueda
                  </motion.button>
                )}

                <button
                  onClick={cerrarConConfirm}
                  className="mhor-close"
                  aria-label="Cerrar"
                >
                  <X size={18} />
                </button>
              </div>
            </div>

            {/* Tabla */}
            {errores[grupoKey] && grids[grupoKey] === null && (
              <div className="mhor-error" role="alert">
                <span>{errores[grupoKey]}</span>
                <button
                  type="button"
                  className="mhor-btn-back"
                  onClick={() => { setErrores(prev => ({ ...prev, [grupoKey]: null })); setReintento(k => k + 1); }}
                >
                  Reintentar
                </button>
              </div>
            )}
            <motion.div
              key={modoEdicion ? "editable" : "read-only"}
              initial={{ opacity: 0, y: 6 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: 6 }}
              transition={{ duration: 0.25 }}
              className="mhor-table-wrap"
            >
              {loading && (
                <div className="mhor-loading-overlay">
                  <span className="mhor-loading-text">
                    <motion.span
                      className="mhor-spinner"
                      animate={{ rotate: 360 }}
                      transition={{ duration: 0.8, repeat: Infinity, ease: "linear" }}
                    />
                    Cargando horario…
                  </span>
                </div>
              )}
              <table className="mhor-table">
                <thead className="mhor-thead">
                  <tr>
                    <th className="mhor-th-corner">
                      Horario
                    </th>
                    {DIAS.map((dia) => (
                      <th key={dia} className="mhor-th">{dia}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                   {SLOTS.map((slot) => (
                     <tr key={slot.key} className="mhor-tr">
                       <td className="mhor-td-time">
                         {modoEdicion ? (
                           <div className="mhor-time-edit">
                             <input
                               type="time"
                               className="mhor-time-input"
                               value={tiempos[slot.key]?.inicio || ''}
                               onChange={(e) => handleTiempoChange(slot.key, 'inicio', e.target.value)}
                               aria-label={`Hora inicio ${slot.label}`}
                             />
                             <span className="mhor-time-sep">–</span>
                             <input
                               type="time"
                               className="mhor-time-input"
                               value={tiempos[slot.key]?.fin || ''}
                               onChange={(e) => handleTiempoChange(slot.key, 'fin', e.target.value)}
                               aria-label={`Hora fin ${slot.label}`}
                             />
                           </div>
                         ) : (
                           <span className="mhor-time-label">
                             {tiempos[slot.key]?.inicio
                               ? `${tiempos[slot.key].inicio} – ${tiempos[slot.key].fin || ''}`
                               : slot.label}
                           </span>
                         )}
                       </td>

                      {DIAS.map((dia) => {
                        const cell = readCell(slot.key, dia);
                        const profesores = (profIdx[cell.materia] || []);
                        const listo = Boolean(cell.materia && cell.profesor_id);

                        const isHL = highlightTarget?.profesorId
                          ? String(cell.profesor_id || "") === String(highlightTarget.profesorId)
                          : false;

                        return (
                          <td key={dia} className="mhor-td">
                            {modoEdicion ? (
                              <div
                                className={[
                                  "mhor-cell-editcompact",
                                  listo ? "mhor-cell-editcompact--listo" : "mhor-cell-editcompact--vacio"
                                ].join(" ")}
                                style={isHL ? { background: hlColor, boxShadow: "inset 0 0 0 2px rgba(59,130,246,.35)" } : undefined}
                                title={isHL ? "Coincide con el profesor buscado" : `Editar ${dia} ${slot.label}`}
                                onClick={() => setCeldaActiva({ slotKey: slot.key, dia })}
                                onKeyDown={(e) => {
                                  if (e.key === "Enter" || e.key === " ") {
                                    e.preventDefault();
                                    setCeldaActiva({ slotKey: slot.key, dia });
                                  }
                                }}
                                tabIndex={0}
                                role="button"
                                aria-label={`Editar celda ${dia} ${slot.label}`}
                              >
                                {cell.materia ? (
                                  <>
                                    <span className="mhor-cell-materia">{cell.materia}</span>
                                    <span className="mhor-cell-profesor">
                                      {profesores.find(p => String(p.id) === String(cell.profesor_id))?.nombre || "—"}
                                    </span>
                                  </>
                                ) : (
                                  <span className="mhor-cell-vacio">+ Agregar</span>
                                )}
                              </div>
                            ) : (
                              <div
                                className="mhor-cell-read"
                                style={isHL ? { background: hlColor, boxShadow: "inset 0 0 0 2px rgba(59,130,246,.35)" } : undefined}
                                title={isHL ? "Coincide con el profesor buscado" : undefined}
                              >
                                {cell.materia ? (
                                  <>
                                    <span className="mhor-cell-materia">{cell.materia}</span>
                                    <span className="mhor-cell-profesor">
                                      {profIdx[cell.materia]?.find(p => String(p.id) === String(cell.profesor_id))?.nombre || "—"}
                                    </span>
                                  </>
                                ) : (
                                  <span className="mhor-cell-vacio">—</span>
                                )}
                              </div>
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </motion.div>

            {/* Popup editor de celda (solo en modo edición) */}
            {modoEdicion && celdaActiva && (() => {
              const popCell = readCell(celdaActiva.slotKey, celdaActiva.dia);
              const popProfes = (profIdx[popCell.materia] || []);
              const popSlotLabel = (SLOTS.find(s => s.key === celdaActiva.slotKey) || {}).label || celdaActiva.slotKey;
              return (
                <div className="mhor-pop-overlay" onClick={() => setCeldaActiva(null)}>
                  <div
                    className="mhor-pop"
                    role="dialog"
                    aria-modal="true"
                    aria-label={`Editar ${celdaActiva.dia} ${popSlotLabel}`}
                    onClick={(e) => e.stopPropagation()}
                  >
                    <div className="mhor-pop-head">
                      <div>
                        <div className="mhor-pop-title">{celdaActiva.dia} · {popSlotLabel}</div>
                        <div className="mhor-pop-sub">
                          {cursoLabel({ anio: curso?.anio, division: curso?.division })}
                          {grupo ? ` · Grupo ${grupo}` : ""}
                        </div>
                      </div>
                      <button
                        onClick={() => setCeldaActiva(null)}
                        className="mhor-close"
                        aria-label="Cerrar editor"
                      >
                        <X size={16} />
                      </button>
                    </div>

                    <label className="mhor-pop-field">
                      <span className="mhor-pop-label">Materia</span>
                      <select
                        className="mhor-select"
                        value={popCell.materia}
                        onChange={(e) => handleMateriaChange(celdaActiva.slotKey, celdaActiva.dia, e.target.value)}
                        disabled={materiasCargando}
                        autoFocus
                      >
                        {materiasCargando ? (
                          <option value="">Cargando...</option>
                        ) : (
                          <>
                            <option value="">— Materia —</option>
                            {materias.map((m) => (
                              <option key={m.id} value={m.nombre}>{m.nombre}</option>
                            ))}
                          </>
                        )}
                      </select>
                    </label>

                    <label className="mhor-pop-field">
                      <span className="mhor-pop-label">Profesor</span>
                      <select
                        className="mhor-select"
                        value={popCell.profesor_id || ""}
                        onChange={(e) => handleProfesorChange(celdaActiva.slotKey, celdaActiva.dia, e.target.value)}
                        disabled={!popCell.materia || profesCargando || (popProfes.length === 0)}
                        title={
                          !popCell.materia
                            ? "Primero elegí una materia"
                            : (profesCargando ? "Cargando profesores..." : (popProfes.length ? "Profesor" : "Sin profesores para esta materia"))
                        }
                      >
                        {profesCargando ? (
                          <option value="">Cargando...</option>
                        ) : (
                          <option value="">
                            {popProfes.length ? "— Profesor —" : "Sin profesores"}
                          </option>
                        )}
                        {!profesCargando && popProfes.map((p) => (
                          <option key={p.id} value={p.id}>{p.nombre}</option>
                        ))}
                      </select>
                    </label>

                    <div className="mhor-pop-foot">
                      <button
                        onClick={() => limpiarCelda(celdaActiva.slotKey, celdaActiva.dia)}
                        className="mhor-btn-limpiar"
                        disabled={!popCell.materia && !popCell.profesor_id}
                      >
                        Limpiar celda
                      </button>
                      <button
                        onClick={() => setCeldaActiva(null)}
                        className="mhor-btn-guardar mhor-pop-listo"
                      >
                        Listo
                      </button>
                    </div>
                  </div>
                </div>
              );
            })()}

            {/* Modal de justificación de cambios */}
            {confirmModal}
            {avisoModal}

            {/* Controles (footer) */}
            <div className="mhor-footer">
              <button
                onClick={imprimirHorario}
                className="mhor-btn-imprimir"
                title="Imprimir o exportar a PDF"
              >
                <Printer size={18} />
                Imprimir
              </button>

              <button
                onClick={exportarPdf}
                className="mhor-btn-pdf"
                title="Descargar como PDF"
              >
                <FileDown size={18} />
                Exportar PDF
              </button>

              {canEdit && (
                <button
                  onClick={() => {
                    if (modoEdicion) { setModoEdicion(false); return; }
                    payloadOriginalRef.current = armarPayload();
                    setModoEdicion(true);
                  }}
                  className="mhor-btn-editar"
                >
                  <Pencil size={18} />
                  {modoEdicion ? "Cancelar edición" : "Editar horario"}
                </button>
              )}

              {canEdit && modoEdicion && (
                <button
                  onClick={guardarHorarios}
                  className="mhor-btn-guardar"
                  disabled={saving}
                >
                  {saving ? (
                    <>
                      <span className="mhor-btn-spinner" aria-hidden="true" />
                      Guardando…
                    </>
                  ) : (
                    "Guardar horario"
                  )}
                </button>
              )}
            </div>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
