// ===============================
// FILE: src/components/Curso/ModalBuscarProfesor.jsx
// ===============================
import React, { useEffect, useMemo, useRef, useState } from "react";
import { apiGet } from "../../compartido/lib/api";
import { AnimatePresence, motion } from "framer-motion";
import { Search, X, ArrowRight, StopCircle, ChevronDown } from "lucide-react";
import { cursoLabel } from "../../compartido/lib/cursos";
import "./ModalBuscarProfesor.css";

const yrLabel = (y) => cursoLabel({ anio: Number(y), division: null });

// Componente: ModalBuscarProfesor
// Proposito: Modal de busqueda de profesores que escanea los horarios de todos los cursos
//            para encontrar coincidencias por nombre o ID. Soporta filtro por anos,
//            restauracion de estado previo y snapshot para recordar la busqueda al cerrar.
// Props:
//   visible      - Booleano que controla la visibilidad del modal.
//   onClose      - Funcion para cerrar el modal.
//   onPick       - Callback al seleccionar un profesor (recibe el match encontrado).
//   initialState - Estado opcional para restaurar una busqueda previa.
//   onSnapshot   - Callback para entregar el estado actual al padre antes de cerrar.

/* ====== Constantes horarias ====== */
const DIAS = ["Lun", "Mar", "Mié", "Jue", "Vie"];
const DIA_FULL = { Lun: "Lunes", Mar: "Martes", "Mié": "Miércoles", Jue: "Jueves", Vie: "Viernes" };

/* 
   IMPORTANTE:
   Agregamos h8 y h9 para los nuevos bloques de 11:00–12:00 y 12:00–12:50.
   También definimos un orden visual para listados (coincide con el modal de horarios de curso).
*/
const SLOT_LABEL = {
  h1: "07:30 – 08:30",
  h2: "08:40 – 09:40",
  h3: "09:50 – 10:50",
  h8: "11:00 – 12:00", // NUEVO
  h9: "12:00 – 12:50", // NUEVO
  h4: "13:05 – 14:05",
  h5: "14:10 – 15:10",
  h6: "15:15 – 16:15",
  h7: "16:20 – 17:20",
};
// orden para ordenar el detalle por franja horaria (mañana → mediodía → tarde)
const SLOT_ORDER = ["h1", "h2", "h3", "h8", "h9", "h4", "h5", "h6", "h7"];
const slotOrderIndex = (k) => {
  const i = SLOT_ORDER.indexOf(String(k));
  if (i >= 0) return i;
  const n = parseInt(String(k).replace(/\D+/g, ""), 10);
  return Number.isFinite(n) ? n : 999;
};

const diaKeyForBackend = (d) => (d === "Mié" ? "Mie" : d);

/* ====== Estilos por día (borde + chip) ====== */
const DAY_STYLES = {
  Lun: { border: "mbp-day-border-sky", chipBg: "mbp-chip-sky", chipText: "mbp-chip-sky", chipBorder: "mbp-chip-sky" },
  Mar: { border: "mbp-day-border-emerald", chipBg: "mbp-chip-emerald", chipText: "mbp-chip-emerald", chipBorder: "mbp-chip-emerald" },
  "Mié": { border: "mbp-day-border-amber", chipBg: "mbp-chip-amber", chipText: "mbp-chip-amber", chipBorder: "mbp-chip-amber" },
  Jue: { border: "mbp-day-border-violet", chipBg: "mbp-chip-violet", chipText: "mbp-chip-violet", chipBorder: "mbp-chip-violet" },
  Vie: { border: "mbp-day-border-rose", chipBg: "mbp-chip-rose", chipText: "mbp-chip-rose", chipBorder: "mbp-chip-rose" },
};

/* ====== Colores por curso (consistentes) ====== */
const COURSE_PALETTES = [
  { bg: "mbp-pal-indigo", text: "mbp-pal-indigo", border: "mbp-pal-indigo" },
  { bg: "mbp-pal-teal", text: "mbp-pal-teal", border: "mbp-pal-teal" },
  { bg: "mbp-pal-cyan", text: "mbp-pal-cyan", border: "mbp-pal-cyan" },
  { bg: "mbp-pal-fuchsia", text: "mbp-pal-fuchsia", border: "mbp-pal-fuchsia" },
  { bg: "mbp-pal-lime", text: "mbp-pal-lime", border: "mbp-pal-lime" },
  { bg: "mbp-pal-orange", text: "mbp-pal-orange", border: "mbp-pal-orange" },
  { bg: "mbp-pal-blue", text: "mbp-pal-blue", border: "mbp-pal-blue" },
  { bg: "mbp-pal-emerald", text: "mbp-pal-emerald", border: "mbp-pal-emerald" },
  { bg: "mbp-pal-amber", text: "mbp-pal-amber", border: "mbp-pal-amber" },
  { bg: "mbp-pal-rose", text: "mbp-pal-rose", border: "mbp-pal-rose" },
];
function coursePalette(anio, division) {
  const s = `${anio}-${division}`;
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
  return COURSE_PALETTES[h % COURSE_PALETTES.length];
}

/* ====== Utils ====== */
const normalize = (s) =>
  String(s || "")
    .normalize("NFD")
    .replace(/\p{Diacritic}+/gu, "")
    .toLowerCase();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function buildIdToName(mapMateriasProfes) {
  const out = new Map();
  if (!mapMateriasProfes || typeof mapMateriasProfes !== "object") return out;
  Object.values(mapMateriasProfes).forEach((arr) => {
    (arr || []).forEach((p) => {
      const k = String(p.id);
      if (!out.has(k)) out.set(k, p.nombre);
    });
  });
  return out;
}
function readCell(horario, slotKey, diaUi) {
  const k = diaKeyForBackend(diaUi);
  const raw = horario?.[slotKey]?.[k] ?? horario?.[slotKey]?.[diaUi];
  if (!raw) return { materia: "", profesor_id: "" };
  if (typeof raw === "string") return { materia: raw, profesor_id: "" };
  const pid = raw.profesor_id ?? raw.profesorId ?? raw.profesor ?? "";
  return { materia: raw.materia ?? "", profesor_id: pid };
}

/* ====== Anti-429 ====== */
const SCAN_DELAY_MS = 420;
const BACKOFF_429_MS = 1800;
const MAX_RETRY_429 = 2;
const MIN_GAP_BETWEEN_REQ_MS = 280; // gap global entre requests
const cooldownRefGlobal = { until: 0 }; // cooldown global ante 429

/* Checkbox que soporta estado intermedio (indeterminate) para seleccion multiple */
function IndeterminateCheckbox({ checked, indeterminate, onChange, disabled }) {
  const ref = useRef(null);
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = Boolean(indeterminate) && !checked;
  }, [indeterminate, checked]);
  return (
    <input
      ref={ref}
      type="checkbox"
      className="mbp-checkbox"
      checked={checked}
      disabled={disabled}
      onChange={(e) => onChange?.(e.target.checked)}
    />
  );
}

/* Selector multiple de anos con casillas de verificacion y animaciones */
function MultiYearSelect({
  selected = [],              // Array de strings: ["1","3",...]
  onChange,
  years = [],                 // Array de numeros o strings
  width = 180,
  placeholder = "Todos los anos",
  disabled = false,
}) {
  const [open, setOpen] = useState(false);
  const btnRef = useRef(null);
  const menuRef = useRef(null);

  // Normaliza los valores a strings para comparacion
  const all = years.map((y) => String(y));
  const selSet = new Set(selected.map(String));

  // Determina si estan todos seleccionados o solo algunos (estado indeterminado)
  const allSelected = selSet.size > 0 && selSet.size === all.length;
  const someSelected = selSet.size > 0 && selSet.size < all.length;

  // Construye la etiqueta resumida para el boton (ej: "1°, 2° +3")
  const label = (() => {
    if (selSet.size === 0 || allSelected) return placeholder;
    const shown = all.filter((y) => selSet.has(y)).slice(0, 2).map((y) => yrLabel(y)).join(", ");
    const extra = selSet.size - 2;
    return selSet.size <= 2 ? shown : `${shown} +${extra}`;
  })();

  // Cierra el menu al hacer clic fuera o presionar Escape
  useEffect(() => {
    const onDocClick = (e) => {
      if (!btnRef.current || !menuRef.current) return;
      if (!btnRef.current.contains(e.target) && !menuRef.current.contains(e.target)) setOpen(false);
    };
    const onEsc = (e) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", onDocClick);
    document.addEventListener("keydown", onEsc);
    return () => {
      document.removeEventListener("mousedown", onDocClick);
      document.removeEventListener("keydown", onEsc);
    };
  }, []);

  // Agrega o quita un anio del conjunto de filtros seleccionados
  const toggleYear = (y) => {
    const v = String(y);
    const next = new Set(selSet);
    if (next.has(v)) next.delete(v);
    else next.add(v);
    onChange?.(Array.from(next));
  };

  // Selecciona o deselecciona todos los anos disponibles
  const setAll = (check) => {
    if (check) onChange?.([...all]);
    else onChange?.([]);
  };

  return (
    <div className="mbp-multiselect" style={{ width }}>
      <button
        ref={btnRef}
        type="button"
        disabled={disabled}
        onClick={() => !disabled && setOpen((o) => !o)}
        className={`mbp-mys-btn
                    ${disabled ? "mbp-mys-btn--disabled" : "mbp-mys-btn--enabled"}`}
        aria-haspopup="listbox"
        aria-expanded={open}
      >
        {label}
        <motion.span
          className="mbp-mys-chevron"
          animate={{ rotate: open ? 180 : 0 }}
          transition={{ duration: 0.26 }} // ⬅️ más duración
        >
          <ChevronDown size={16} />
        </motion.span>
      </button>

      <AnimatePresence>
        {open && !disabled && (
          <motion.div
            ref={menuRef}
            initial={{ opacity: 0, y: -4 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -4 }}
            transition={{ duration: 0.26, ease: "easeOut" }} // ⬅️ más duración
            className="mbp-mys-menu"
            role="listbox"
          >
            <div className="mbp-mys-list">
              {/* Opción TODOS */}
              <label className="mbp-mys-option">
                <motion.span
                  className="mbp-mys-option-hl"
                  initial={false}
                  animate={{ backgroundColor: allSelected ? "rgba(59,130,246,0.08)" : "rgba(0,0,0,0)" }}
                  transition={{ duration: 0.28 }}
                />
                <IndeterminateCheckbox
                  checked={allSelected}
                  indeterminate={someSelected}
                  onChange={(checked) => setAll(checked)}
                />
                <span className="mbp-mys-option-label">Todos</span>
              </label>

              <div className="mbp-mys-divider" />

              {/* Años individuales */}
              {all.map((y) => {
                const checked = selSet.has(y);
                return (
                  <label
                    key={y}
                    className={`mbp-mys-option ${
                      checked ? "mbp-mys-option--checked" : ""
                    }`}
                    onMouseDown={(e) => e.preventDefault()} // evita perder foco y que se cierre
                  >
                    {/* Highlight animado detrás de la fila */}
                    <motion.span
                      className="mbp-mys-option-hl"
                      initial={false}
                      animate={{ opacity: checked ? 1 : 0 }}
                      transition={{ duration: 0.32, ease: "easeOut" }}
                      style={{ backgroundColor: "rgba(59,130,246,0.10)" }}
                    />

                    {/* Contenedor animado del checkbox (pulso sutil al chequear) */}
                    <motion.span
                      className="mbp-mys-checkbox-wrap"
                      initial={false}
                      animate={{ scale: checked ? 1.06 : 1 }}
                      transition={{ duration: 0.24, ease: "easeOut" }}
                    >
                      <input
                        type="checkbox"
                        className="mbp-mys-checkbox"
                        checked={checked}
                        onChange={() => toggleYear(y)}
                      />
                      {/* Pulso animado al marcar */}
                      <AnimatePresence>
                        {checked && (
                          <motion.span
                            key="pulse"
                            className="mbp-mys-pulse"
                            initial={{ opacity: 0, scale: 0.8 }}
                            animate={{ opacity: 1, scale: 1.15 }}
                            exit={{ opacity: 0, scale: 0.9 }}
                            transition={{ duration: 0.45, ease: "easeOut" }}
                          />
                        )}
                      </AnimatePresence>
                    </motion.span>

                    <span className="mbp-mys-year-label">{yrLabel(y)}</span>
                  </label>
                );
              })}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

/* ============================================================
   ModalBuscarProfesor
   - Soporta restaurar estado (q, yearFilters, resultados, seleccion)
   - Emite un snapshot al padre para recordar la busqueda al salir
   ============================================================ */
export default function ModalBuscarProfesor({
  visible,
  onClose,
  onPick,
  /** Estado inicial opcional para restaurar búsqueda previa */
  initialState, // { q, anioFilter?, anioFilters?, results?, selectedProfesorId?, hasSearched? }
  /** Callback opcional para entregar snapshot al padre antes de cerrar o navegar */
  onSnapshot,   // (state) => void
}) {
  const [q, setQ] = useState("");
  const [hasFocus, setHasFocus] = useState(false);
  // NUEVO: multiselección de años
  const [yearFilters, setYearFilters] = useState([]); // array de strings, vacío = todos
  const [divisionesMapa, setDivisionesMapa] = useState({});

  const [loading, setLoading] = useState(false);
  const [scanningIdx, setScanningIdx] = useState(0);
  const [totalCursos, setTotalCursos] = useState(0);
  const [results, setResults] = useState([]);
  const [error, setError] = useState("");

  const [selectedProfesorId, setSelectedProfesorId] = useState(null);
  const [showProgress, setShowProgress] = useState(false);
  const [hasSearched, setHasSearched] = useState(false);

  const cacheRef = useRef({});
  const abortRef = useRef(null);
  const lastReqRef = useRef(0); // throttle global
  const hydratedRef = useRef(false); // evita rehidratar más de una vez por apertura

  /* Genera un snapshot del estado actual para restaurarlo luego */
  const getSnapshot = () => ({
    q,
    anioFilters: yearFilters,  // ← nuevo
    // compat opcional: si alguien aún mira anioFilter, enviamos el primero o ""
    anioFilter: yearFilters.length === 1 ? yearFilters[0] : "",
    results,
    selectedProfesorId,
    hasSearched,
  });

  /* Carga el mapa de divisiones desde el backend al abrir el modal */
  useEffect(() => {
    if (!visible) return;
    let cancelled = false;
    (async () => {
      try {
        const mapa = await apiGet("/divisiones");
        if (!cancelled) setDivisionesMapa(mapa || {});
      } catch {
        if (!cancelled) setDivisionesMapa({});
      }
    })();
    return () => { cancelled = true; };
  }, [visible]);

  /* Restaura el estado inicial (busqueda previa) al abrir, una sola vez por apertura */
  useEffect(() => {
    if (visible && initialState && !hydratedRef.current) {
      setQ(initialState.q ?? "");
      // compat: anioFilters (nuevo) o anioFilter (viejo)
      const initYears = Array.isArray(initialState.anioFilters)
        ? initialState.anioFilters.map(String)
        : (initialState.anioFilter ? [String(initialState.anioFilter)] : []);
      setYearFilters(initYears);
      if (Array.isArray(initialState.results)) setResults(initialState.results);
      if (initialState.selectedProfesorId != null) setSelectedProfesorId(String(initialState.selectedProfesorId));
      setHasSearched(Boolean(initialState.hasSearched));
      hydratedRef.current = true;
    }
    if (!visible) {
      hydratedRef.current = false; // listo para próxima apertura
    }
  }, [visible, initialState]);

  /* Resetea el estado al cerrar (el snapshot se mantiene en el padre si corresponde) */
  useEffect(() => {
    if (!visible) {
      setQ(""); setResults([]); setError("");
      setScanningIdx(0); setTotalCursos(0);
      setSelectedProfesorId(null);
      setShowProgress(false);
      setHasSearched(false);
      setYearFilters([]);
      abortRef.current?.abort?.(); abortRef.current = null;
    }
  }, [visible]);

  /* Auto-selecciona el primer profesor de los resultados */
  useEffect(() => {
    if (results.length && !selectedProfesorId) {
      setSelectedProfesorId(String(results[0].profesorId));
    } else if (!results.length) {
      setSelectedProfesorId(null);
    }
  }, [results, selectedProfesorId]);

  /* Aplica un throttle global entre peticiones y cooldown ante errores 429 */
  async function throttleAndCooldown() {
    const now = Date.now();
    const waitGap = Math.max(0, MIN_GAP_BETWEEN_REQ_MS - (now - lastReqRef.current));
    const waitCd = Math.max(0, cooldownRefGlobal.until - now);
    const wait = Math.max(waitGap, waitCd);
    if (wait > 0) await sleep(wait + Math.floor(Math.random() * 120));
    lastReqRef.current = Date.now();
  }

  /* GET con throttling para evitar saturar el backend */
  async function safeGet(path, signal) {
    await throttleAndCooldown();
    return apiGet(path, signal);
  }

  /* Ejecuta la busqueda escaneando todos los cursos y sus horarios */
  async function buscar() {
    if (!q || q.trim().length < 2) {
      setError("Ingresá al menos 2 letras o un ID.");
      return;
    }

    abortRef.current?.abort?.();
    abortRef.current = new AbortController();

    setLoading(true);
    setShowProgress(true);
    setHasSearched(true);
    setError("");
    setResults([]);
    setScanningIdx(0);
    setTotalCursos(0);

    try {
      // 1) cursos a escanear
      const allowedYears = new Set(yearFilters.map(String)); // vacío = todos
      const cursos = [];
      Object.entries(divisionesMapa || {}).forEach(([anioStr, divs]) => {
        const anio = Number(anioStr);
        if (allowedYears.size && !allowedYears.has(String(anio))) return;
        (divs || []).forEach((division) => cursos.push({ anio, division }));
      });
      setTotalCursos(cursos.length);

      const qNorm = normalize(q.trim());

      // 2) escaneo con pausas/backoff y cooldown global
      for (let i = 0; i < cursos.length; i++) {
        if (abortRef.current?.signal?.aborted) break;
        const { anio, division } = cursos[i];
        const key = `${anio}-${division}`;

        let entry = cacheRef.current[key];
        let retries = 0;

        while (!entry) {
          try {
            const horario = await safeGet(`/horarios/${anio}/${division}`, abortRef.current.signal).catch(() => ({}));
            const mapping = await safeGet(`/profesores/mapping?anio=${anio}&division=${division}&estado=Activo`, abortRef.current.signal).catch(() => ({}));
            entry = { horario: horario || {}, idToName: buildIdToName(mapping) };
            cacheRef.current[key] = entry;
          } catch (e) {
            if ((e?.status === 429) && retries < MAX_RETRY_429 && !abortRef.current?.signal?.aborted) {
              retries += 1;
              cooldownRefGlobal.until = Date.now() + BACKOFF_429_MS + Math.floor(Math.random() * 400);
              await sleep(BACKOFF_429_MS);
              continue;
            }
            entry = { horario: {}, idToName: new Map() };
            break;
          }
        }

        const { horario, idToName } = entry;
        const matches = [];

        Object.keys(horario || {}).forEach((slotKey) => {
          // Solo consideramos slots conocidos (incluye h8/h9 ahora)
          if (!SLOT_LABEL[slotKey]) return;
          for (const diaUi of DIAS) {
            const cell = readCell(horario, slotKey, diaUi);
            const pid = String(cell.profesor_id || "");
            if (!pid) continue;

            const nombre = idToName.get(String(pid)) || "";
            const ok =
              (nombre && normalize(nombre).includes(qNorm)) ||
              (/^\d+$/.test(qNorm) && pid === qNorm);

            if (ok) {
              matches.push({
                anio, division, dia: diaUi,
                slot_key: slotKey, slot_label: SLOT_LABEL[slotKey] || slotKey,
                materiaNombre: cell.materia || "",
                profesorId: Number(pid), profesorNombre: nombre || `ID ${pid}`,
              });
            }
          }
        });

        if (matches.length) {
          setResults((prev) => [...prev, ...matches]);
        }
        setScanningIdx(i + 1);
        if (i + 1 < cursos.length) await sleep(SCAN_DELAY_MS);
      }
    } catch (e) {
      if (e?.name !== "AbortError") {
        console.error("[BuscarProfesor] error:", e);
        setError(
          e?.status === 429
            ? "Se alcanzó el límite de peticiones. Aguardá unos segundos y probá de nuevo."
            : "No se pudo completar la búsqueda."
        );
      }
    } finally {
      setLoading(false);
      setTimeout(() => setShowProgress(false), 260);
    }
  }

  /* Agrupa los resultados por profesor: nombre, cantidad de coincidencias, cursos */
  const profesores = useMemo(() => {
    const map = new Map();
    for (const r of results) {
      const id = String(r.profesorId);
      if (!map.has(id)) map.set(id, { id, nombre: r.profesorNombre, count: 0, cursos: new Set() });
      const ent = map.get(id);
      ent.count += 1;
      ent.cursos.add(cursoLabel({ anio: r.anio, division: r.division }));
    }
    return Array.from(map.values()).sort((a, b) => a.nombre.localeCompare(b.nombre));
  }, [results]);

  /* Filtra los detalles del profesor seleccionado, ordenados por dia y franja horaria */
  const detallesSeleccionado = useMemo(() => {
    if (!selectedProfesorId) return [];
    return results
      .filter((r) => String(r.profesorId) === String(selectedProfesorId))
      .sort((a, b) => {
        const d = DIAS.indexOf(a.dia) - DIAS.indexOf(b.dia);
        return d !== 0 ? d : (slotOrderIndex(a.slot_key) - slotOrderIndex(b.slot_key));
      });
  }, [results, selectedProfesorId]);

  const progress = totalCursos > 0 ? Math.min(100, Math.round((scanningIdx / totalCursos) * 100)) : 0;
  const springy = { type: "spring", stiffness: 300, damping: 24 };

  /* Maneja el clic del boton principal: Buscar o Cancelar segun el estado */
  const onPrimaryClick = () => {
    if (loading) {
      // Si esta cargando, el mismo boton funciona como "Cancelar"
      abortRef.current?.abort?.();
      setLoading(false);
      setScanningIdx(0);
      setTotalCursos(0);
      setShowProgress(false);
      setError("");
    } else {
      buscar();
    }
  };

  /* Envia el snapshot al padre y cierra el modal */
  const closeWithSnapshot = () => {
    onSnapshot?.(getSnapshot());
    onClose?.();
  };

  // Lista de anos disponibles ordenados numericamente
  const availableYears = useMemo(
    () =>
      Object.keys(divisionesMapa || {})
        .map((n) => Number(n))
        .filter((n) => Number.isFinite(n))
        .sort((a, b) => a - b),
    [divisionesMapa]
  );

  return (
    <AnimatePresence>
      {visible && (
        <motion.div
          className="mbp-overlay"
          initial={false}
          exit={{ opacity: 0 }}
          onMouseDown={(e) => {
            if (e.currentTarget === e.target) closeWithSnapshot();
          }}
        >
          <motion.div
            className="mbp-dialog"
            role="dialog"
            aria-modal="true"
            initial={{ y: -8, scale: 0.98 }}
            animate={{ y: 0, scale: 1, transition: springy }}
            exit={{ y: -8, opacity: 0, scale: 0.98 }}
          >
            {/* Header */}
            <div className="mbp-header">
              <div className="mbp-header-left">
                <h3 className="mbp-title">Buscar profesor</h3>

                {/* PROGRESO EN HEADER (compacto) */}
                <AnimatePresence initial={false}>
                  {showProgress && totalCursos > 0 && (
                    <motion.div
                      key="hdr-progress"
                      className="mbp-progress"
                      initial={{ opacity: 0 }}
                      animate={{ opacity: 1 }}
                      exit={{ opacity: 0 }}
                      transition={{ duration: 0.18 }}
                      aria-live="polite"
                    >
                      <div className="mbp-progress-track">
                        <motion.div
                          className="mbp-progress-bar"
                          initial={{ width: "0%" }}
                          animate={{ width: `${Math.max(2, progress)}%` }}
                          transition={{ duration: 0.35, ease: "easeOut" }}
                        />
                      </div>
                      <span className="mbp-progress-text">
                        {scanningIdx}/{totalCursos} ({progress}%)
                      </span>
                    </motion.div>
                  )}
                </AnimatePresence>
              </div>

              <button
                className="mbp-close"
                onClick={closeWithSnapshot}
                aria-label="Cerrar"
              >
                <X size={18} />
              </button>
            </div>

            {/* Controles */}
            <div className="mbp-controls">
              {/* Grid de controles: Input | Select | Botón */}
              <div
                className="mbp-controls-grid"
                style={{ gridTemplateColumns: `1fr 180px 180px` }}
              >
                {/* Input */}
                <div className="mbp-input-wrap">
                  <Search className="mbp-input-icon" size={18} />
                  <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") onPrimaryClick();
                    }}
                    onFocus={() => setHasFocus(true)}
                    onBlur={() => setHasFocus(false)}
                    className={`mbp-input ${hasFocus ? "mbp-input--focus" : ""}`}
                    placeholder="Escribí nombre o apellido…"
                  />

                  {/* Limpiar suave (sin spinner de carga) */}
                  <AnimatePresence>
                    {q ? (
                      <motion.button
                        type="button"
                        initial={{ opacity: 0, x: 6, scale: 0.98 }}
                        animate={{ opacity: 1, x: 0, scale: 1 }}
                        exit={{ opacity: 0, x: 6, scale: 0.98 }}
                        transition={{ duration: 0.18, ease: "easeOut" }}
                        onClick={() => setQ("")}
                        title="Limpiar"
                        aria-label="Limpiar búsqueda"
                        className="mbp-input-clear"
                      >
                        <X size={14} />
                        <span className="mbp-input-clear-label">Limpiar</span>
                      </motion.button>
                    ) : null}
                  </AnimatePresence>
                </div>

                {/* Multi-select de años */}
                <div className="mbp-multiselect-wrap">
                  <MultiYearSelect
                    selected={yearFilters}
                    onChange={setYearFilters}
                    years={availableYears}
                    width={180}
                    placeholder="Todos los años"
                  />
                </div>

                {/* Botón principal: Buscar / Cancelar */}
                <motion.button
                  whileTap={{ scale: 0.98 }}
                  onClick={onPrimaryClick}
                  disabled={!loading && q.trim().length < 2}
                  className={`mbp-primary
                    ${loading
                      ? "mbp-primary--loading"
                      : q.trim().length < 2
                        ? "mbp-primary--disabled"
                        : "mbp-primary--ready"}`}
                  title={loading ? "Cancelar escaneo" : "Buscar"}
                >
                  {loading ? <StopCircle size={16} /> : <Search size={16} />}
                  {loading ? "Cancelar" : "Buscar"}
                </motion.button>
              </div>

              {/* Error real (429 u otros) */}
              {error ? (
                <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="mbp-error">
                  {error}
                </motion.div>
              ) : null}
            </div>

            {/* Contenido */}
            <motion.div
              className="mbp-content"
              animate={{ y: showProgress ? 2 : 0 }}
              transition={{ type: "spring", stiffness: 250, damping: 22 }}
            >
              {/* Izquierda: lista de profesores */}
              <div className="mbp-list-col">
                <h4 className="mbp-list-title">
                  Profesores {profesores.length ? `(${profesores.length})` : ""}
                </h4>

                <div className="mbp-list-items">
                  <AnimatePresence initial={false}>
                    {profesores.length === 0 ? (
                      <motion.div key="empty-left" initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="mbp-list-empty">
                        Escribí y presioná <b>Buscar</b> para ver resultados.
                      </motion.div>
                    ) : (
                      profesores.map((p) => {
                        const selected = String(p.id) === String(selectedProfesorId);
                        const cursosCount = p.cursos.size;
                        return (
                          <motion.button
                            key={p.id}
                            layout
                            initial={{ opacity: 0, y: 6 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -6 }}
                            onClick={() => setSelectedProfesorId(String(p.id))}
                            className={[
                              "mbp-prof-card",
                              selected ? "mbp-prof-card--selected" : "mbp-prof-card--idle",
                            ].join(" ")}
                            whileTap={{ scale: 0.98 }}
                          >
                            <div className="mbp-prof-card-name">{p.nombre}</div>

                            <div className="mbp-prof-card-meta">
                              <span className="mbp-chip mbp-chip--gray">
                                Cursos: {cursosCount}
                              </span>
                              <span className="mbp-chip mbp-chip--blue">
                                Coincidencias: {p.count}
                              </span>
                            </div>

                            <div className="mbp-prof-card-cursos">{Array.from(p.cursos).join(" · ")}</div>
                          </motion.button>
                        );
                      })
                    )}
                  </AnimatePresence>
                </div>
              </div>

              {/* Derecha: detalle */}
              <div className="mbp-detail-col">
                <h4 className="mbp-detail-title">
                  {selectedProfesorId ? `Horarios de ${profesores.find((p) => String(p.id) === String(selectedProfesorId))?.nombre || "—"}` : "Seleccioná un profesor para ver sus horarios"}
                </h4>

                {/* “No se encontraron coincidencias” aquí (derecha) */}
                {!loading && hasSearched && profesores.length === 0 ? (
                  <motion.div
                    initial={{ opacity: 0, y: -6 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -6 }}
                    className="mbp-noresults"
                  >
                    <div className="mbp-noresults-title">No se encontraron coincidencias</div>
                    <div className="mbp-noresults-text">
                      Probá ajustar el nombre o el filtro de año. También podés intentar con el ID del profesor.
                    </div>
                  </motion.div>
                ) : null}

                <div className="mbp-detalles">
                  <AnimatePresence initial={false}>
                    {detallesSeleccionado.map((m, i) => {
                      const daySty = DAY_STYLES[m.dia] || {};
                      const crsSty = coursePalette(m.anio, m.division);
                      return (
                        <motion.div
                          key={`${m.profesorId}-${m.anio}-${m.division}-${m.dia}-${m.slot_key}-${i}`}
                          layout
                          initial={{ opacity: 0, y: 8, scale: 0.98 }}
                          animate={{ opacity: 1, y: 0, scale: 1 }}
                          exit={{ opacity: 0, y: -8, scale: 0.98 }}
                          className={[
                            "mbp-detalle-card",
                            daySty.border || "mbp-day-border-fallback",
                          ].join(" ")}
                        >
                          <div className="mbp-detalle-info">
                            <div className="mbp-detalle-materia">{m.materiaNombre || "—"}</div>

                            <div className="mbp-detalle-tags">
                              {/* Curso con color por curso */}
                              <span
                                className={`mbp-chip-base ${crsSty.bg} ${crsSty.text} ${crsSty.border}`}
                                title="Curso"
                              >
                                {cursoLabel({ anio: m.anio, division: m.division })}
                              </span>

                              {/* Día con color por día */}
                              <span
                                className={`mbp-chip-base ${daySty.chipBg || "mbp-chip-fallback"} ${daySty.chipText || "mbp-chip-fallback"} ${daySty.chipBorder || "mbp-chip-fallback"}`}
                                title="Día"
                              >
                                {DIA_FULL[m.dia] || m.dia}
                              </span>

                              {/* Franja horaria */}
                              <span className="mbp-chip-base mbp-chip--gray" title="Horario">
                                {m.slot_label}
                              </span>
                            </div>
                          </div>

                          <motion.button
                            whileTap={{ scale: 0.97 }}
                            className="mbp-btn-ver"
                            onClick={() => {
                              // entregar snapshot al padre antes de navegar al modal de horarios
                              onSnapshot?.(getSnapshot());
                              onPick?.(m);
                              onClose?.();
                            }}
                            title={`Ver en ${cursoLabel({ anio: m.anio, division: m.division })}`}
                          >
                            Ver en {cursoLabel({ anio: m.anio, division: m.division })}
                            <ArrowRight size={16} />
                          </motion.button>
                        </motion.div>
                      );
                    })}
                  </AnimatePresence>

                  {selectedProfesorId && !detallesSeleccionado.length && (
                    <div className="mbp-sin-coincidencias">Sin coincidencias para este profesor.</div>
                  )}
                </div>
              </div>
            </motion.div>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
