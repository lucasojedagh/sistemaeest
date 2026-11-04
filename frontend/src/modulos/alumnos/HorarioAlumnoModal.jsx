// ===============================
// FILE: src/components/Alumnos/HorarioAlumnoModal.jsx
// ===============================
import React, { useEffect, useMemo, useState, useRef } from "react";
import { apiGet, apiPut } from "../../compartido/lib/api";
import { motion, AnimatePresence } from "framer-motion";
import { Pencil, Eraser, BookOpen, X, RotateCcw, AlertTriangle, ChevronDown, ChevronUp } from "lucide-react";
import { useAuth } from "../../modulos/auth/AuthContext"; // ⬅️ NUEVO
import { cursoLabel, parseCurso } from "../../compartido/lib/cursos";
import { useDeshacerAviso } from "../../compartido/lib/deshacer.jsx";
import useAviso from "../../compartido/componentes/modals/useAviso";
import "./HorarioAlumnoModal.css";

/*
 * Componente: HorarioAlumnoModal
 * Props:
 *   visible   - Booleano que controla la apertura del modal
 *   alumno    - Objeto del alumno para el cual se gestiona el horario
 *   onClose   - Callback al cerrar el modal
 *   onGuardar - Callback al guardar cambios en el horario
 *
 * Modal de edicion del horario semanal de un alumno. Incluye dos paneles:
 * "Resumen" con promedios y listado de materias, y "Cronograma" con
 * una grilla interactiva para asignar materias a cada bloque horario.
 * Soporta superposiciones de materias recursantes e intensificacion.
 * Los profesores tienen solo lectura.
 */

/* ---------- Helpers ---------- */
const DIAS = ["Lun", "Mar", "Mié", "Jue", "Vie"];

/* Bloques visibles en el orden real (mañana → mediodía → tarde) */
const BLOQUES = [
  "07:30 – 08:30",
  "08:40 – 09:40",
  "09:50 – 10:50",
  "11:00 – 12:00", // NUEVO (h8)
  "12:00 – 12:50", // NUEVO (h9)
  "13:05 – 14:05",
  "14:10 – 15:10",
  "15:15 – 16:15",
  "16:20 – 17:20",
];

/* Mapeos entre índice de fila <-> slot_key del back (coincidir con el cronograma de cursos y la búsqueda) */
const SLOT_ORDER = ["h1", "h2", "h3", "h8", "h9", "h4", "h5", "h6", "h7"]; // orden visual
const idxForKey = (slotKey) => {
  const k = String(slotKey || "");
  const m = /^h(\d+)$/i.exec(k);
  if (m) {
    const norm = `h${parseInt(m[1], 10)}`;
    const i = SLOT_ORDER.indexOf(norm);
    if (i >= 0) return i;
  }
  const n = parseInt(k.replace(/\D+/g, ""), 10);
  return Number.isFinite(n) ? Math.max(0, n - 1) : null;
};
const keyForIdx = (idx) => SLOT_ORDER[idx] || `h${(Number(idx) || 0) + 1}`;

const diaNum = (d) => (typeof d === "number" ? d : (DIAS.indexOf(String(d)) + 1 || null));
const diaStr = (n) => DIAS[(Number(n) || 1) - 1] || "Lun";

/* ←— IMPORTANTE: ahora esto respeta el orden visual usando SLOT_ORDER —→ */
const slotIdx = (r) => {
  if (r == null) return null;
  if (typeof r.bloque === "number") return r.bloque;           // ya viene en índice visual
  if (typeof r.slot === "number") return r.slot;               // compat
  if (typeof r.slot_key === "string") return idxForKey(r.slot_key);
  return null;
};
/* Usado para enviar al back (PUT): de índice visual a slot_key real */
const slotKey = (idx) => keyForIdx(Number(idx));

const formatearCurso = (s) => (!s ? "Sin curso" : cursoLabel(s));
const toGridMap = (rows=[]) => { const m = new Map(); (rows || []).forEach(e => m.set(`${e.dia}-${e.bloque}`, e)); return m; };
const fromGridMap = (map) => {
  const out = [];
  for (const [key, val] of map.entries()) {
    const [dia, b] = key.split("-");
    out.push({
      dia,
      bloque: Number(b),
      materia: val.materia,
      profesor: val.profesor || "",
      profesor_id: val.profesor_id ?? null,
      mark: val.mark || null,
      origen: val.origen || null,
      label: val.label || null,
    });
  }
  const orderDia = DIAS;
  out.sort((a,b)=>orderDia.indexOf(a.dia)-orderDia.indexOf(b.dia)||a.bloque-b.bloque);
  return out;
};
const normalizeMark = (r) => {
  const mk = (r?.marcado || r?.mark || "").toString().toLowerCase();
  if (mk.includes("rec")) return "rec";
  if (mk.includes("int")) return "int";
  return null;
};
const toFrontEntries = (rows = []) =>
  (rows || [])
    .map((r) => {
      const d = diaStr(r.dia);
      const i = slotIdx(r);
      const materia = (r?.materia?.nombre ?? r?.materia_name ?? r?.materia ?? r?.materia_nombre ?? "")
        .toString()
        .trim();
      const profesor_id = r?.profesor_id ?? null;
      const profesor = (r?.profesor?.nombre ?? r?.profesor_name ?? r?.profesor ?? r?.profesor_nombre ?? "")
        .toString()
        .trim();
      const origen = r?.origen || null;
      const mark = normalizeMark(r);
      const conflict = !!r?.conflict;
      const label = cursoLabel({ anio: r?.anio, division: r?.division });
      if (!materia) return null;
      if (d && i != null) return { dia: d, bloque: i, materia, profesor, profesor_id, origen, mark, label: label.trim() || null, conflict };
      return null;
    })
    .filter(Boolean);

/* --- normalizadores --- */
const normalize = (s) => (s||"").toString().normalize("NFD").replace(/\p{Diacritic}/gu,"").toLowerCase().trim();
const eqMat = (a,b) => normalize(a) === normalize(b);

/* ========= Segmented Tabs ========= */
/* Componente de pestanas con indicador deslizante para alternar entre "Resumen" y "Cronograma" */
function SegmentedTabs({ value, onChange }) {
  const options = [
    { value: "resumen", label: "Resumen" },
    { value: "cronograma", label: "Cronograma" },
  ];
  const idx = Math.max(0, options.findIndex(o => o.value === value));
  const pct = 100 / options.length;
  return (
    <div className="horario-tabs">
      <motion.div
        className="horario-tabs-indicator"
        initial={false}
        animate={{ left: `${idx * pct}%`, width: `${pct}%` }}
        transition={{ type: "spring", stiffness: 380, damping: 28 }}
      />
      {options.map((opt) => (
        <button
          key={opt.value}
          type="button"
          onClick={() => onChange(opt.value)}
          className={`horario-tabs-btn ${value === opt.value ? "horario-tabs-btn-active" : "horario-tabs-btn-inactive"}`}
        >
          {opt.label}
        </button>
      ))}
    </div>
  );
}

/* ============================ Componente principal ============================ */
/* Modal de horario del alumno con paneles de resumen y cronograma editable */
export default function HorarioAlumnoModal({ visible, alumno, onClose, onGuardar }) {
  // Rol (solo lectura si es profesor)
  const { user } = useAuth();            // ⬅️ NUEVO
  const isProfesor = user?.role === "profesor"; // ⬅️ NUEVO
  const { ofrecerDeshacer } = useDeshacerAviso();
  const [mostrarAviso, pedirConfirmacion, avisoModal, mostrarToast] = useAviso();

  // --- State ---
  const [panel, setPanel] = useState("resumen");
  const [entradas, setEntradas] = useState([]);
  const [baseEntradas, setBaseEntradas] = useState([]);
  const [_overrides, setOverrides] = useState([]);
  const [overlayRec, setOverlayRec] = useState([]);
  const [overlayInt, setOverlayInt] = useState([]);

  const [editMode, setEditMode] = useState(false);
  const [materiaActiva, setMateriaActiva] = useState("");
  const [listaTrabajo, setListaTrabajo] = useState([]);
  const [tagByMateria, setTagByMateria] = useState(new Map());

  const [promedioGeneral, setPromedioGeneral] = useState(null);
  const [countNotas, setCountNotas] = useState(0);

  const [prevAlumno, setPrevAlumno] = useState(null);
  const alumnoSafe = alumno || prevAlumno;

  // Cierre con animacion: marcamos "cerrando" (dispara la animacion CSS de
  // salida) y recien avisamos al padre cuando termina.
  const [cerrando, setCerrando] = useState(false);
  const cerrandoRef = useRef(false);
  const cerrarTimerRef = useRef(null);

  // Al abrir, volvemos a "Resumen" y salimos del modo edición ANTES del primer
  // pintado. Hacerlo dentro del useEffect de carga mostraba durante un instante
  // el panel/estado de la vez anterior y después el cambio (el parpadeo).
  const [visiblePrev, setVisiblePrev] = useState(visible);
  if (visible !== visiblePrev) {
    setVisiblePrev(visible);
    if (visible) {
      if (panel !== "resumen") setPanel("resumen");
      if (editMode) setEditMode(false);
      if (cerrando) setCerrando(false);
      // Si quedo un cierre pendiente, lo cancelamos al reabrir.
      window.clearTimeout(cerrarTimerRef.current);
      cerrandoRef.current = false;
    }
  }

  // memo maps
  const gridMap       = useMemo(()=>toGridMap(entradas),[entradas]);
  const baseMap       = useMemo(()=>toGridMap(baseEntradas),[baseEntradas]);
  const overlayRecMap = useMemo(()=>toGridMultiMap(overlayRec),[overlayRec]);
  const overlayIntMap = useMemo(()=>toGridMultiMap(overlayInt),[overlayInt]);
  const { rec: recLblByMateria, int: intLblByMateria } = useMemo(
    () => buildCursoLabelMaps(alumnoSafe || {}),
    [alumnoSafe]
  );

  // cierre con confirmación
  const originalRef = useRef([]);
  const sameEntries = (a, b) => JSON.stringify(a ?? []) === JSON.stringify(b ?? []);
  const hasUnsaved = () => !sameEntries(entradas, originalRef.current);
  const askDiscard = async () => {
    const ok = await pedirConfirmacion({
      title: "¿Descartar cambios?",
      text: "Tenés cambios sin guardar. Si cerrás ahora, se perderán.",
      icon: "warning",
      confirmText: "Descartar",
      cancelText: "Seguir editando",
    });
    return ok;
  };

  // Bloquear el scroll del body mientras el modal está visible
  useEffect(() => {
    if (visible) {
      const prev = document.body.style.overflow;
      document.body.style.overflow = "hidden";
      return () => { document.body.style.overflow = prev || ""; };
    }
  }, [visible]);

  // deshacer
  const editSnapshotRef = useRef(null);
  const startEditMode = () => {                      // ⬅️ mod: respeta solo lectura
    if (isProfesor) return;
    editSnapshotRef.current = entradas;
    setEditMode(true);
  };
  const stopEditModeToggle = () => {                // ⬅️ mod: respeta solo lectura
    if (isProfesor) return;
    setEditMode((v) => !v);
  };
  const undoEdits = () => {
    if (isProfesor) return;                         // ⬅️ mod: respeta solo lectura
    const base = editSnapshotRef.current ?? originalRef.current ?? [];
    setEntradas(base);
  };
  const canUndo = editMode && editSnapshotRef.current && !sameEntries(entradas, editSnapshotRef.current);

  // combos
  const matsPlan = useMemo(() => {
    const s = new Set();
    (baseEntradas || []).forEach(x => { if (x?.materia) s.add(x.materia); });
    if (s.size === 0) (entradas || []).forEach(x => { if (x?.materia && x?.origen === "base") s.add(x.materia); });
    return Array.from(s).sort();
  }, [baseEntradas, entradas]);
  const matsRec  = Array.from(tagByMateria.entries()).filter(([,t])=>t==="rec").map(([m])=>m).sort();
  const matsInt  = Array.from(tagByMateria.entries()).filter(([,t])=>t==="int").map(([m])=>m).sort();

  const parseMateriaValue = (val) => {
    if (!val) return { name: "", mark: null };
    const parts = String(val).split("::");
    if (parts.length === 2) {
      const kind = parts[0];
      const name = parts[1];
      if (kind === "rec") return { name, mark: "rec" };
      if (kind === "int") return { name, mark: "int" };
      return { name, mark: null };
    }
    return { name: val, mark: null };
  };

  // Cierra con la animacion CSS de salida y avisa al padre al terminar.
  const cerrarConAnimacion = () => {
    if (cerrandoRef.current) return;
    cerrandoRef.current = true;
    setCerrando(true);
    window.clearTimeout(cerrarTimerRef.current);
    cerrarTimerRef.current = window.setTimeout(() => {
      cerrandoRef.current = false;
      onClose?.();
    }, 190);
  };

  useEffect(() => () => window.clearTimeout(cerrarTimerRef.current), []);

  const handleRequestClose = async () => {
    if (hasUnsaved()) {
      const ok = await askDiscard();
      if (!ok) return;
      editSnapshotRef.current = null;
    }
    cerrarConAnimacion();
  };
  useEffect(() => {
    const onEsc = async (e) => { if (e.key === "Escape" && visible) await handleRequestClose(); };
    document.addEventListener("keydown", onEsc);
    return () => document.removeEventListener("keydown", onEsc);
  }, [visible]); // eslint-disable-line

  /* ===== Carga ===== */
  useEffect(() => {
    let cancel = false;
    async function cargar() {
      if (!visible || !alumno) return;
      setPrevAlumno(alumno);
      setMateriaActiva("");
      editSnapshotRef.current = null;

      const { materias, tagByMateria: tags0 } = buildListaTrabajo(alumno);
      if (!cancel) { setListaTrabajo(materias); setTagByMateria(new Map(tags0)); }

      try {
        const resp = await apiGet(`/alumnos/${alumno.id}/horario?with_base=1`);
        const eff = toFrontEntries(Array.isArray(resp)?resp:(resp.effective||[]));
        const base= toFrontEntries(Array.isArray(resp)?[]:(resp.base||[]));
        const ov  = toFrontEntries(Array.isArray(resp)?[]:(resp.overrides||[]));
        const rec = toFrontEntries(Array.isArray(resp)?[]:(resp.recursando_overlay||[]));
        const intl= toFrontEntries(Array.isArray(resp)?[]:(resp.intensificacion_overlay||[]));

        const nextEntradas = eff.length?eff:base;

        if (!cancel) {
          setEntradas(nextEntradas);
          setBaseEntradas(base);
          setOverrides(ov);
          setOverlayRec(rec);
          setOverlayInt(intl);
          setListaTrabajo(Array.from(new Set([...(materias||[]), ...base.map(x=>x.materia)])));
          const tags  = new Map(tags0);
          base.forEach(m=>{ if(!tags.has(m.materia)) tags.set(m.materia,null); });
          setTagByMateria(tags);
          originalRef.current = nextEntradas;
        }
      } catch {
        if (!cancel) {
          setBaseEntradas([]); setEntradas([]); setOverrides([]); setOverlayRec([]); setOverlayInt([]);
          originalRef.current = [];
        }
      }

      // promedio
      try {
        const data = await apiGet(`/alumnos/${alumno.id}/calificaciones`);
        let notasTotal=0, proms=[];
        if (data?.notas_por_materia && typeof data.notas_por_materia === "object") {
          Object.values(data.notas_por_materia).forEach(arr=>{
            const nums=(arr||[]).map(n=>Number(n.valor)).filter(Number.isFinite);
            if(nums.length){ notasTotal+=nums.length; proms.push(nums.reduce((a,b)=>a+b,0)/nums.length); }
          });
        } else if (Array.isArray(data)) {
          data.forEach(e=>{
            const nums=(e.notas||[]).map(n=>Number(n.valor)).filter(Number.isFinite);
            if(nums.length){ notasTotal+=nums.length; proms.push(nums.reduce((a,b)=>a+b,0)/nums.length); }
          });
        }
        if (!cancel) {
          setPromedioGeneral(proms.length ? +(proms.reduce((a,b)=>a+b,0)/proms.length).toFixed(2) : null);
          setCountNotas(notasTotal);
        }
      } catch {
        if (!cancel) { setPromedioGeneral(null); setCountNotas(0); }
      }
    }
    cargar();
    return () => { cancel = true; };
  }, [visible, alumno]);

  /* ===== Edicion del cronograma ===== */
  /* Asigna o remueve una materia en una celda especifica (dia y bloque horario) */
  const handleCellClick = (dia, bloque) => {
    if (!editMode || isProfesor) return; // ⬅️ NUEVO: bloquear edición a profesor
    const { name, mark } = parseMateriaValue(materiaActiva);
    const key = `${dia}-${bloque}`;

    let profesor = "";
    if (mark === "rec") {
      const arr = overlayRecMap.get(key) || [];
      const hit = arr.find(r => eqMat(r.materia, name));
      if (hit && (hit.profesor || "").trim()) profesor = (hit.profesor || "").trim();
    } else if (mark === "int") {
      const arr = overlayIntMap.get(key) || [];
      const hit = arr.find(r => eqMat(r.materia, name));
      if (hit && (hit.profesor || "").trim()) profesor = (hit.profesor || "").trim();
    } else {
      const base = baseMap.get(key);
      if (base && eqMat(base.materia, name) && (base.profesor || "").trim()) {
        profesor = (base.profesor || "").trim();
      }
    }

    setEntradas(prev=>{
      const map = toGridMap(prev);
      if (name) map.set(key,{ dia, bloque, materia: name, profesor, origen:"override", mark });
      else map.delete(key);
      return fromGridMap(map);
    });
  };

  const clearDay = (dia) => {
    if (!editMode || isProfesor) return; // ⬅️ NUEVO
    setEntradas(prev=>{
      const map = toGridMap(prev);
      for (let i=0;i<BLOQUES.length;i++) map.delete(`${dia}-${i}`);
      return fromGridMap(map);
    });
  };
  const clearCell = (dia,bloque) => {
    if (!editMode || isProfesor) return; // ⬅️ NUEVO
    const key = `${dia}-${bloque}`;
    setEntradas(prev=>{
      const map = toGridMap(prev);
      map.delete(key);
      return fromGridMap(map);
    });
  };

  /* Persiste los cambios del cronograma mediante API PUT y refresca los datos */
  const guardarCronograma = async (entriesOverride=null) => {
    if (isProfesor) return; // ⬅️ NUEVO: no guardar si es profesor
    try {
      // Snapshot previo para poder deshacer (revertir las celdas que cambiaron).
      const antes = (originalRef.current || []).map(x => ({ ...x }));
      const prevEffM = toGridMap(originalRef.current || []);
      const currM    = toGridMap(entriesOverride || entradas);

      const keys = new Set([...prevEffM.keys(), ...currM.keys()]);
      const diffs = [];
      const revertir = [];

      keys.forEach((k) => {
        const [d,b] = k.split("-");
        const A = prevEffM.get(k) || null;
        const B = currM.get(k)  || null;

        const aMateria = A ? (A.materia || null) : null;
        const aProf    = A ? ((A.profesor || "").trim() || null) : null;
        const bMateria = B ? ((B.materia || "").trim() || null) : null;
        const bProf    = B ? ((B.profesor || "").trim() || null) : null;
        const bMark    = B ? (B.mark || null) : null;

        const changed = (aMateria !== bMateria) || (aProf !== bProf) || (normalizeMark(A) !== bMark);
        if (!changed) return;

        let marcado = "normal";
        if (bMark === "rec") marcado = "recursando";
        else if (bMark === "int") marcado = "intensificacion";
        else if (bMateria) {
          const isPlan = matsPlan.includes(bMateria);
          if (!isPlan) {
            const tag = tagByMateria.get(bMateria);
            if (tag === "rec") marcado = "recursando";
            else if (tag === "int") marcado = "intensificacion";
          }
        }

        diffs.push({
          dia: diaNum(d),
          slot_key: slotKey(Number(b)), // ← usa el orden visual para mapear al h*
          materia: bMateria,
          profesor: bProf,
          marcado
        });

        // Contracara: vuelve la celda a su estado previo.
        let marcadoAntes = "normal";
        if (A?.mark === "rec") marcadoAntes = "recursando";
        else if (A?.mark === "int") marcadoAntes = "intensificacion";
        else if (aMateria) {
          const esPlanA = matsPlan.includes(aMateria);
          if (!esPlanA) {
            const tagA = tagByMateria.get(aMateria);
            if (tagA === "rec") marcadoAntes = "recursando";
            else if (tagA === "int") marcadoAntes = "intensificacion";
          }
        }
        revertir.push({
          dia: diaNum(d),
          slot_key: slotKey(Number(b)),
          materia: aMateria,
          profesor: aProf,
          marcado: marcadoAntes
        });
      });

      await apiPut(`/alumnos/${alumnoSafe.id}/horario`, { entries: diffs });
      // Deshacer: revierte esas mismas celdas al estado anterior.
      ofrecerDeshacer(
        {
          etiqueta: `Cronograma de ${alumnoSafe?.nombre || ''} ${alumnoSafe?.apellido || ''}`.trim(),
          deshacer: async () => {
            await apiPut(`/alumnos/${alumnoSafe.id}/horario`, { entries: revertir });
            setEntradas(antes);
            originalRef.current = antes.map(x => ({ ...x }));
            setEditMode(false);
          },
        },
        'Cambios guardados correctamente'
      );

      // refresco
      try {
        const resp = await apiGet(`/alumnos/${alumnoSafe.id}/horario?with_base=1`);
        const eff = toFrontEntries(Array.isArray(resp)?resp:(resp.effective||[]));
        const base= toFrontEntries(Array.isArray(resp)?[]:(resp.base||[]));
        const ov  = toFrontEntries(Array.isArray(resp)?[]:(resp.overrides||[]));
        const rec = toFrontEntries(Array.isArray(resp)?[]:(resp.recursando_overlay||[]));
        const intl= toFrontEntries(Array.isArray(resp)?[]:(resp.intensificacion_overlay||[]));
        const next = eff.length?eff:base;

        setEntradas(next);
        setBaseEntradas(base);
        setOverrides(ov);
        setOverlayRec(rec);
        setOverlayInt(intl);
        originalRef.current = next;

        if (typeof onGuardar === "function") onGuardar(alumnoSafe.id, next);
      } catch {
        await mostrarAviso({ icon: "warning", title: "Se guardó el horario", text: "No se pudieron recargar los datos actualizados. Recargá la página para ver el cambio." });
      }

      setEditMode(false);
      editSnapshotRef.current = null;

    } catch (e) {
      await mostrarAviso({ icon:"error", title:"No se pudo guardar", text: e?.message || "Error al guardar el horario." });
    }
  };

  // Restablecer al plan (no guarda, solo carga en edición)
  const restablecerAlPlan = async () => {
    if (isProfesor) return; // ⬅️ NUEVO
    const ok = await pedirConfirmacion({
      icon: "question",
      title: "Restablecer al plan",
      text: "Se cargará el horario base del curso en el editor. Tenés que guardar para aplicar.",
      confirmText: "Cargar plan",
      cancelText: "Cancelar",
    });
    if (!ok) return;

    setEntradas(baseEntradas);
    setEditMode(true);
    editSnapshotRef.current = baseEntradas;

    mostrarToast({ icon: "info", title: "Plan cargado en edición. Presioná Guardar para aplicar.", ms: 1600 });
  };

  /* --- Render --- */
  if (!visible && !alumnoSafe) return null;

  return (
    <>
      {visible && (
        <div className={`horario-backdrop${cerrando ? ' is-closing' : ''}`}>
          <div className={`horario-container${cerrando ? ' is-closing' : ''}`}>
            {/* Halos ambientales */}
            <div className="horario-halo horario-halo-emerald" />
            <div className="horario-halo horario-halo-teal" />

            {/* Cerrar */}
            <button
              onClick={handleRequestClose}
              aria-label="Cerrar"
              className="horario-close"
            >
              <X size={20} />
            </button>

            {/* Header + Tabs (avatar removido) */}
            <div className="horario-header">
              <div className="horario-header-center">
                <h2
                  className="horario-title"
                  title={alumnoSafe ? `${alumnoSafe.nombre} ${alumnoSafe.apellido}` : "Alumno"}
                >
                  {alumnoSafe ? `${alumnoSafe.nombre} ${alumnoSafe.apellido}` : "Alumno"}
                </h2>
                <div className="horario-curso">
                  <span className="horario-curso-chip">
                    {formatearCurso(alumnoSafe?.anio) || "Sin curso"}
                  </span>
                  {alumnoSafe?.grupo && (
                    <span className="horario-curso-chip" title="Grupo del alumno (la base mostrada respeta este grupo)">
                      Grupo {alumnoSafe.grupo}
                    </span>
                  )}
                </div>
              </div>
              <div className="horario-tabs-center">
                <SegmentedTabs value={panel} onChange={setPanel} />
              </div>
            </div>

            {/* Área de paneles con animación discreta */}
            <div className="horario-body">
              <div className="horario-card">
              <AnimatePresence mode="wait" initial={false}>
                {panel === "resumen" && (
                  <motion.div
                    key="panel-resumen"
                    initial={{ y: 8 }}
                    animate={{ y: 0 }}
                    exit={{ opacity: 0, y: -8 }}
                    transition={{ duration: 0.18, ease: "easeOut" }}
                    className="horario-panel"
                  >
                    <ResumenPanel
                      entradas={entradas}
                      alumnoSafe={alumnoSafe}
                      promedioGeneral={promedioGeneral}
                      countNotas={countNotas}
                      overlayRec={overlayRec}
                      overlayInt={overlayInt}
                    />
                  </motion.div>
                )}
                {panel === "cronograma" && (
                  <motion.div
                    key="panel-cronograma"
                    initial={{ y: 8 }}
                    animate={{ y: 0 }}
                    exit={{ opacity: 0, y: -8 }}
                    transition={{ duration: 0.18, ease: "easeOut" }}
                    className="horario-panel"
                  >
                    <CronogramaPanel
                      gridMap={gridMap}
                      baseMap={baseMap}
                      overlayRecMap={overlayRecMap}
                      editMode={editMode}
                      setEditMode={setEditMode}
                      materiaActiva={materiaActiva}
                      setMateriaActiva={setMateriaActiva}
                      listaTrabajo={listaTrabajo}
                      matsPlan={matsPlan}
                      matsRec={matsRec}
                      matsInt={matsInt}
                      handleCellClick={handleCellClick}
                      clearDay={clearDay}
                      clearCell={clearCell}
                      tagByMateria={tagByMateria}
                      startEditMode={startEditMode}
                      stopEditModeToggle={stopEditModeToggle}
                      undoEdits={undoEdits}
                      canUndo={canUndo}
                      recLblByMateria={recLblByMateria}
                      intLblByMateria={intLblByMateria}
                      restablecerAlPlan={restablecerAlPlan}
                      readOnly={isProfesor} // ⬅️ NUEVO
                    />
                  </motion.div>
                )}
              </AnimatePresence>
            </div>
            </div>

            {/* Footer: oculto para profesor */}
            {panel === "cronograma" && !isProfesor && (
              <div className="horario-footer">
                <button
                  onClick={restablecerAlPlan}
                  className="horario-footer-btn-plan"
                  title="Cargar el horario base en edición (no guarda hasta confirmar)"
                >
                  Restablecer al plan
                </button>
                <button
                  onClick={()=>guardarCronograma()}
                  className="horario-footer-btn-save"
                >
                  Guardar horario
                </button>
              </div>
            )}
          </div>
        </div>
      )}
      {avisoModal}
    </>
  );
}

/* ---------- Panel Resumen ---------- */
/* Panel de resumen con tarjetas de promedio general, cantidad de materias recursando e intensificacion */
function ResumenPanel({ entradas, alumnoSafe, promedioGeneral, countNotas, overlayRec=[], overlayInt=[] }) {
  const normalizeMaterias = (val, anioStr) => {
    const p = parseCurso(anioStr);
    const anio = p.anio ? cursoLabel({ anio: p.anio, division: null }) : "";
    const division = p.division || "";
    if (Array.isArray(val)) {
      if (val.length > 0 && typeof val[0] === "string") {
        return val.filter(Boolean).map(s => ({ anio, division, materia: String(s).trim() }));
      }
      return val;
    }
    if (typeof val === "string" && val.trim()) {
      return val.split(",").map(s => ({ anio, division, materia: s.trim() })).filter(m => m.materia);
    }
    return [];
  };
  const recList = alumnoSafe ? normalizeMaterias(alumnoSafe.recursando, alumnoSafe.anio) : [];
  const intList = alumnoSafe ? normalizeMaterias(alumnoSafe.intensificacion, alumnoSafe.anio) : [];

  const profDeOverlay = (materia, overlayArr) => {
    const hit = (overlayArr||[]).find(
      r => normalize(r?.materia) === normalize(materia) && (r?.profesor || r?.profesor_nombre)
    );
    return (hit?.profesor || hit?.profesor_nombre || "").toString().trim();
  };
  const profDeEntradas = (materia) => {
    const hit = (entradas||[]).find(
      e => normalize(e.materia) === normalize(materia) && (e.profesor||"").trim()
    );
    return hit?.profesor?.trim() || "";
  };
  const findProfesorRec = (materia) => profDeOverlay(materia, overlayRec) || profDeEntradas(materia) || "";
  const findProfesorInt = (materia) => profDeOverlay(materia, overlayInt) || profDeEntradas(materia) || "";

  return (
    <div className="horario-resumen">
      <div className="horario-stats">
        <div className="horario-stat horario-stat-blue">
          <div className="horario-stat-label horario-stat-label-blue">Promedio general</div>
          <div className="horario-stat-value horario-stat-value-blue">{promedioGeneral == null ? "—" : promedioGeneral}</div>
          <div className="horario-stat-count horario-stat-count-blue">{countNotas} nota{countNotas === 1 ? "" : "s"} registradas</div>
        </div>
        <div className="horario-stat horario-stat-orange">
          <div className="horario-stat-label horario-stat-label-orange">Recursando</div>
          <div className="horario-stat-value horario-stat-value-orange">{recList.length}</div>
          <div className="horario-stat-count horario-stat-count-orange">materia{recList.length !== 1 ? "s" : ""}</div>
        </div>
        <div className="horario-stat horario-stat-green">
          <div className="horario-stat-label horario-stat-label-green">Intensificación</div>
          <div className="horario-stat-value horario-stat-value-green">{intList.length}</div>
          <div className="horario-stat-count horario-stat-count-green">materia{intList.length !== 1 ? "s" : ""}</div>
        </div>
      </div>

      <div className="horario-listados">
        <Listado
          titulo="Materias que recursa"
          colorHeader="horario-listado-header-orange"
          vacio="No tiene materias en recursado"
          items={recList}
          findProfesor={(m)=>findProfesorRec(m)}
        />
        <Listado
          titulo="Materias de intensificación"
          colorHeader="horario-listado-header-green"
          vacio="No tiene materias en intensificación"
          items={intList}
          findProfesor={(m)=>findProfesorInt(m)}
        />
      </div>
    </div>
  );
}

/* ---------- Panel de Cronograma ---------- */
/* Grilla interactiva de horario semanal con edicion de materias por celda */
function CronogramaPanel({
  gridMap, baseMap, overlayRecMap,
  editMode, materiaActiva, setMateriaActiva,
  matsPlan, matsRec, matsInt,
  handleCellClick, clearDay, clearCell,
  tagByMateria, startEditMode, stopEditModeToggle, undoEdits, canUndo,
  recLblByMateria,
  readOnly = false, // ⬅️ NUEVO
}) {
  const matsPlanSet = useMemo(()=> new Set(matsPlan || []), [matsPlan]);

  // Superposiciones detalladas
  const recConflicts = useMemo(() => {
    const map = new Map();
    overlayRecMap.forEach((arr, key) => {
      if (!baseMap.has(key)) return;
      const base = baseMap.get(key);
      arr.forEach(r => {
        const label = r?.label || recLblByMateria.get(r.materia) || "";
        const k = `${normalize(r.materia)}|${label}|${normalize(base.materia)}`;
        if (!map.has(k)) map.set(k, { rec: r.materia, label, base: base.materia, count: 0 });
        map.get(k).count += 1;
      });
    });
    return Array.from(map.values());
  }, [overlayRecMap, baseMap, recLblByMateria]);

  const [showRecPanel, setShowRecPanel] = useState(false);
  useEffect(() => { if (recConflicts.length === 0) setShowRecPanel(false); }, [recConflicts.length]);

  return (
    <div className="horario-crono">
      {/* Banner superposiciones rec – colapsable */}
      {recConflicts.length > 0 && (
        <div className="horario-banner">
          <button
            type="button"
            onClick={() => setShowRecPanel(v=>!v)}
            className="horario-banner-toggle"
          >
            <span className="horario-banner-label">
              <AlertTriangle size={16} />
              Superposiciones con el plan (recursando)
            </span>
            <span className="horario-banner-chevron">
              {showRecPanel ? <ChevronUp size={18}/> : <ChevronDown size={18}/>}
            </span>
          </button>

          <AnimatePresence initial={false}>
            {showRecPanel && (
              <motion.div
                key="rec-content"
                initial={{ height: 0, opacity: 0 }}
                animate={{ height: "auto", opacity: 1 }}
                exit={{ height: 0, opacity: 0 }}
                transition={{ type: "tween", duration: 0.18 }}
                className="horario-banner-body"
              >
                <div className="horario-banner-inner">
                  <div className="horario-banner-scroll">
                    <div className="horario-banner-chips">
                      {recConflicts.map((o, i) => (
                        <span
                          key={i}
                          className="horario-banner-chip"
                          title={`Se superpone con ${o.base}`}
                        >
                          Rec • {o.rec}{o.label ? ` — ${o.label}` : ""} ⟷ {o.base} • ×{o.count}
                        </span>
                      ))}
                    </div>
                  </div>
                </div>
              </motion.div>
            )}
          </AnimatePresence>
        </div>
      )}

      {/* Toolbar (oculta en readOnly) */}
      <div className="horario-toolbar">
        {!readOnly && (
          <>
            <div className="horario-toolbar-left">
              <label className="horario-toolbar-label">Materia activa</label>
              <div className="horario-select-wrap">
                <select
                  value={materiaActiva}
                  onChange={(e) => setMateriaActiva(e.target.value)}
                  className="horario-select"
                >
                  <option value="">(sin materia)</option>
                  {matsPlan.length>0 && (
                    <optgroup label="Plan del curso">
                      {matsPlan.map(m => <option key={`p-${m}`} value={`plan::${m}`}>{m}</option>)}
                    </optgroup>
                  )}
                  {matsRec.length>0  && (
                    <optgroup label="Recursando">
                      {matsRec.map(m => <option key={`r-${m}`} value={`rec::${m}`}>{m} • Rec</option>)}
                    </optgroup>
                  )}
                  {matsInt.length>0  && (
                    <optgroup label="Intensificación">
                      {matsInt.map(m => <option key={`i-${m}`} value={`int::${m}`}>{m} • Int</option>)}
                    </optgroup>
                  )}
                </select>
                <BookOpen size={16} className="horario-select-icon" />
                <svg className="horario-select-chevron" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M6 8l4 4 4-4" />
                </svg>
              </div>
            </div>

            <div className="horario-toolbar-right">
              {!editMode ? (
                <button
                  onClick={startEditMode}
                  className="horario-btn-tool horario-btn-edit"
                >
                  <Pencil size={16} />
                  Editar horario
                </button>
              ) : (
                <button
                  onClick={stopEditModeToggle}
                  className="horario-btn-tool horario-btn-editing"
                >
                  <Pencil size={16} />
                  Editando
                </button>
              )}

              {editMode && (
                <button
                  onClick={undoEdits}
                  disabled={!canUndo}
                  className={`horario-btn-tool ${canUndo ? "horario-btn-undo-ok" : "horario-btn-undo-disabled"}`}
                  title="Revertir cambios hechos desde que activaste la edición"
                >
                  <RotateCcw size={16} />
                  Deshacer cambios
                </button>
              )}
            </div>
          </>
        )}
      </div>

      {/* Tabla */}
      <div className="horario-table-wrap">
        <table className="horario-table">
          <thead className="horario-thead">
            <tr className="horario-tr-head">
              <th className="horario-th-time">Horario</th>
              {DIAS.map(d => (
                <th key={d} className="horario-th-day">
                  <div className="horario-th-day-inner">
                    <span>{d}</span>
                    {editMode && (
                      <button
                        onClick={() => clearDay(d)}
                        className="horario-btn-clear-day"
                        title={`Limpiar ${d}`}
                      >
                        <Eraser size={14} />
                      </button>
                    )}
                  </div>
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="horario-tbody">
            {BLOQUES.map((bloqueHora, i) => (
              <tr key={i} className={`${i % 2 ? "horario-tr-odd" : "horario-tr-even"}`}>
                <td className="horario-td-time">{bloqueHora}</td>
                {DIAS.map((dia) => {
                  const key = `${dia}-${i}`;
                  const eff   = gridMap.get(key);
                  const base  = baseMap.get(key);
                  const rawRecList = overlayRecMap.get(key) || [];

                  const materia  = (eff?.materia || "").trim();
                  const profesorFull = (eff?.profesor || "").trim();
                  const mark     = eff?.mark || null;

                  const hasEff  = materia.length > 0;
                  const hasBase = !!base;

                  const isOverride = hasEff && (
                    !hasBase ||
                    materia !== (base?.materia || "") ||
                    profesorFull !== (base?.profesor || "")
                  );

                  const occupantType =
                    hasEff && mark ? (mark) :
                    hasEff && (matsPlanSet.has(materia)) ? "plan" :
                    hasEff && (tagByMateria.get(materia) || null) === "rec" ? "rec" :
                    hasEff ? "custom" : "empty";

                  const recList = rawRecList.filter(r => !(hasEff && occupantType==="rec" && eqMat(materia, r.materia)));
                  const recOverlapWithBase = hasBase && recList.length > 0;
                  const conflict =
                    (hasBase && !hasEff && rawRecList.length > 0) ||
                    (hasBase && hasEff && occupantType === "rec" && materia !== base.materia);

                  let borderCls = "horario-cell-border-empty";
                  let bgTint = "";

                  if (conflict) {
                    borderCls = "horario-cell-border-conflict";
                  } else if (hasEff && occupantType === "rec") {
                    borderCls = "horario-cell-border-rec";
                    bgTint = "horario-cell-bg-rec";
                  } else if (isOverride) {
                    borderCls = "horario-cell-border-override";
                    bgTint = "horario-cell-bg-override";
                  } else if (hasBase) {
                    borderCls = "horario-cell-border-base";
                  }

                  const recLbl = recLblByMateria.get(materia) || "";

                  return (
                    <td key={key} className="horario-td-cell">
                      <div
                        onClick={() => handleCellClick(dia, i)}
                        className={`horario-cell ${borderCls} ${bgTint} ${editMode && !readOnly ? "horario-cell-editable" : "horario-cell-static"}`}
                        title={
                          hasEff
                            ? `${materia}${profesorFull ? ` — ${profesorFull}` : ""}`
                            : (editMode && !readOnly ? "Click para asignar" : "")
                        }
                      >
                        {/* Bandita lateral para Rec */}
                        {hasEff && occupantType === "rec" && (
                          <span className="horario-cell-band" />
                        )}

                        {/* Chip conflicto con tooltip */}
                        {recOverlapWithBase && (
                          <div className="horario-cell-tip-wrap">
                            <span className="horario-cell-tip">
                              Rec ×{recList.length}
                            </span>
                            <div className="horario-cell-tip-pop">
                              <div className="horario-cell-tip-head">
                                Plan: <span className="horario-cell-tip-normal">{base?.materia || "—"}</span>
                              </div>
                              <div className="horario-cell-tip-sub">Se superpone con:</div>
                              <ul className="horario-cell-tip-list">
                                {recList.map((r, idx) => {
                                  const lbl = r?.label || recLblByMateria.get(r.materia) || "";
                                  return <li key={idx} className="horario-cell-tip-li">• {r.materia}{lbl?` — ${lbl}`:""}</li>;
                                })}
                              </ul>
                            </div>
                          </div>
                        )}

                        {/* Contenido */}
                        {hasEff ? (
                          <div className="horario-cell-content">
                            <div className="horario-cell-mat-row">
                              <div className="horario-cell-mat" title={materia}>{materia}</div>
                              {occupantType === "rec" && (
                                <span className="horario-cell-rec">
                                  Rec{recLbl ? ` • ${recLbl}` : ""}
                                </span>
                              )}
                            </div>
                            {profesorFull && (
                              <div className="horario-cell-prof" title={profesorFull}>
                                {profesorFull}
                              </div>
                            )}
                          </div>
                        ) : (
                          <span className="horario-cell-empty">—</span>
                        )}

                        {/* base fantasma */}
                        {!hasEff && hasBase && (
                          <div className="horario-cell-ghost">
                            Base: {base.materia}
                          </div>
                        )}

                        {/* borrar personalización (oculto si readOnly) */}
                        {editMode && !readOnly && isOverride && (
                          <button
                            onClick={(e)=>{ e.stopPropagation(); clearCell(dia,i); }}
                            className="horario-cell-clear"
                            title="Quitar personalización"
                          >
                            <Eraser size={14} />
                          </button>
                        )}

                        {/* Trama visual de conflicto */}
                        {conflict && (
                          <div className="horario-cell-pattern" />
                        )}
                      </div>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/* ---------- Subcomponentes ---------- */
function Listado({ items, colorHeader, vacio, findProfesor, titulo }) {
  return (
    <div className="horario-listado">
      {!!titulo && (
        <div className="horario-listado-title">{titulo}</div>
      )}
      <div className={`horario-listado-header ${colorHeader}`}>
        <div className="horario-listado-header-cell">Materia</div>
        <div className="horario-listado-header-cell">Año</div>
        <div className="horario-listado-header-cell">División</div>
        <div className="horario-listado-header-cell">Profesor</div>
      </div>
      <div className="horario-listado-rows">
        {items.length === 0 ? (
          <div className="horario-listado-empty">{vacio}</div>
        ) : items.map((m, idx) => {
          const profFull = (typeof findProfesor === "function" ? findProfesor(m.materia) : "") || "—";
          return (
            <div key={idx} className="horario-listado-row">
              <div className="horario-listado-row-cell">{m.materia}</div>
              <div className="horario-listado-row-cell">{cursoLabel({ anio: m.anio, division: null }) || "—"}</div>
              <div className="horario-listado-row-cell">{cursoLabel({ anio: null, division: m.division }) || "—"}</div>
              <div className="horario-listado-row-cell horario-listado-prof" title={profFull}>{profFull}</div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

/* ----- utils para mapas de overlays ----- */
function buildCursoLabelMaps(alumnoSafe) {
  const toMap = (arr=[]) => {
    const m = new Map();
    (Array.isArray(arr)?arr:[]).forEach(x=>{
      const nombre = (x?.materia || x || "").toString().trim();
      if(!nombre) return;
      const lbl = cursoLabel({ anio: x?.anio, division: x?.division });
      if(!m.has(nombre)) m.set(nombre, new Set());
      if(lbl) m.get(nombre).add(lbl);
    });
    const out = new Map();
    m.forEach((set,name)=> out.set(name, Array.from(set).join(" / ")));
    return out;
  };
  return {
    rec: toMap(alumnoSafe?.recursando),
    int: toMap(alumnoSafe?.intensificacion),
  };
}
function toGridMultiMap(rows = []) {
  const m = new Map();
  (rows || []).forEach(e => {
    const k = `${e.dia}-${slotIdx(e)}`;
    if (!m.has(k)) m.set(k, []);
    m.get(k).push(e);
  });
  return m;
}
function buildListaTrabajo(alumno) {
  const rec=(Array.isArray(alumno.recursando)?alumno.recursando:[]).map(m=>(m.materia||m||"").toString().trim()).filter(Boolean);
  const inte=(Array.isArray(alumno.intensificacion)?alumno.intensificacion:[]).map(m=>(m.materia||m||"").toString().trim()).filter(Boolean);
  const set=new Map(); rec.forEach(m=>set.set(m,"rec")); inte.forEach(m=>{ if(!set.has(m)) set.set(m,"int"); });
  return { materias:Array.from(set.keys()), tagByMateria:set };
}
