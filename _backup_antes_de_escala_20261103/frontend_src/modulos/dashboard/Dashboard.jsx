// ===============================
// Panel principal con resumen de datos: KPIs, graficos de alumnos por año,
// distribucion por genero, estado de profesores y top de rendimiento.
// ===============================
import React, { useEffect, useMemo, useState, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { apiGet, apiPost, apiBlob, apiUpload } from "../../compartido/lib/api";
import { motion, AnimatePresence } from "framer-motion";
import {
  Users2, GraduationCap, BookOpen, Layers3,
  PieChart as PieIcon, BarChart3, AlertTriangle, Sparkles,
  CheckCircle2, PauseCircle, UserCheck, UserPlus, RefreshCw, Send,
  Download, Upload, ShieldCheck
} from "lucide-react";
import TareasFrecuentes from "./TareasFrecuentes";
import AvisoNotasFinales from "./AvisoNotasFinales";
import {
  ResponsiveContainer, BarChart, Bar, XAxis, YAxis, Tooltip,
  PieChart, Pie, Cell, Legend, CartesianGrid
} from "recharts";
import { useAuth } from "../auth/AuthContext";
import useAviso from "../../compartido/componentes/modals/useAviso";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import { getCached, setCache as pgSetCache, clearCache } from "../../compartido/lib/pageCache";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import BotonNovedades from "../../compartido/componentes/Common/BotonNovedades";
import { cursoLabel, parseCurso } from "../../compartido/lib/cursos";
import "../../compartido/styles/Dashboard.css";

/* ===== Utils ===== */
const cursoToken = (a) => {
  const p = parseCurso(a?.anio ?? a?.curso ?? a);
  if (!p.anio) return "";
  return cursoLabel({ anio: p.anio, division: p.division });
};
const yearNum = (a) => {
  const p = parseCurso(a?.anio ?? a?.curso ?? a);
  return p.anio || null;
};

/** Normaliza cualquier variante a Array. */
const normalizeList = (v) => {
  if (v == null) return [];
  if (Array.isArray(v)) return v.filter(x => x != null);
  if (typeof v === "string") {
    const s = v.trim();
    if (!s) return [];
    try { const parsed = JSON.parse(s); return normalizeList(parsed); } catch { /* no es JSON, se parsea como lista simple */ }
    if (/^(true|false)$/i.test(s)) return s.toLowerCase() === "true" ? [1] : [];
    const parts = s.split(",").map(x => x.trim()).filter(Boolean);
    return parts.length ? parts : [];
  }
  if (typeof v === "boolean") return v ? [1] : [];
  if (typeof v === "number") return v > 0 ? [v] : [];
  if (typeof v === "object") {
    const cand = v.data ?? v.items ?? v.list ?? null;
    if (Array.isArray(cand)) return cand.filter(x => x != null);
    return Object.keys(v).length ? [v] : [];
  }
  return [];
};

const PALETTE = ["#3b82f6", "#ec4899", "#22c55e", "#f59e0b", "#8b5cf6", "#f97316", "#06b6d4"];
// Colores fijos del grafico de genero (Otro en gris)
const GENERO_COLORS = { Femenino: "#3b82f6", Masculino: "#ec4899", Otro: "#9ca3af" };
const STATE_COLORS = { soloRec: "#f97316", soloInt: "#6366f1", ambos: "#22c55e", ninguno: "#9ca3af" };

const DASH_CACHE_KEY = 'dashboard/data';
const timeAgo = (ts) => {
  if (!ts) return "—";
  const diff = Date.now() - ts;
  const m = Math.round(diff / 60000);
  if (m <= 0) return "justo ahora";
  if (m === 1) return "hace 1 minuto";
  if (m < 60) return `hace ${m} minutos`;
  const h = Math.round(m / 60);
  if (h === 1) return "hace 1 hora";
  return `hace ${h} horas`;
};

/* ============================ Página ============================ */
// Componente principal del panel de inicio con graficos y metricas
const Dashboard = () => {
  const navigate = useNavigate();
  const cached = getCached(DASH_CACHE_KEY);

  const [mostrarAviso, pedirConfirmacion, avisoModal, , pedirDato, mostrarCarga] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();

  const [alumnos, setAlumnos] = useState(cached?.alumnos || []);
  const [profesores, setProfesores] = useState(cached?.profesores || []);
  const [divisionesMap, setDivisionesMap] = useState(cached?.divisionesMap || {});
  const [materiasPorAnio, setMateriasPorAnio] = useState(cached?.materiasPorAnio || {});
  const [error, setError] = useState("");
  const [lastTs, setLastTs] = useState(cached?.ts || null);
  const [topPerformers, setTopPerformers] = useState(cached?.topPerformers || null);
  const [preceptores, setPreceptores] = useState(cached?.preceptores || { total: 0, activos: 0 });
  const mountedRef = useRef(false);
  const abortRef = useRef(null);
  const cerrarCargaRef = useRef(null);
  // N° de pedido: si se dispara otra carga (doble montaje, doble click),
  // solo la última aplica sus resultados; las anteriores se descartan.
  const epochRef = useRef(0);

  useEffect(() => {
    mountedRef.current = true;
    if (cached) {
      refreshData({ showSwal: false });
    } else {
      refreshData({ showSwal: true });
    }
    return () => {
      mountedRef.current = false;
      if (abortRef.current) {
        abortRef.current.abort();
        abortRef.current = null;
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Si otra persona (u otro equipo) guardó algo, al volver al tablero aparece
  // el botón "Hay novedades": los números se actualizan cuando se pulse.
  const { hayNovedades, actualizar } = useAvisoDeCambios(
    "dashboard",
    ["alumnos", "profesores", "usuarios", "cursos", "asistencia", "calificaciones", "documentos", "otros"],
    () => { if (mountedRef.current) refreshData({ showSwal: false }); }
  );

  // Refresca todos los datos del dashboard desde el backend, con opcion de mostrar loading
  const refreshData = async ({ showSwal = false } = {}) => {
    const miEpoch = ++epochRef.current;
    const vigente = () => mountedRef.current && miEpoch === epochRef.current;
    const ctrl = new AbortController();
    abortRef.current = ctrl;

    const cerrarCarga = () => {
      if (cerrarCargaRef.current) {
        cerrarCargaRef.current();
        cerrarCargaRef.current = null;
      }
    };

    // Red de seguridad: por mas que algo se cuelgue, el cartel de carga SIEMPRE
    // se cierra. Cubre cuelgues del servidor, errores inesperados, etc.
    const safetyTimer = setTimeout(cerrarCarga, 25000);

    try {
      setError("");
      if (showSwal) {
        cerrarCargaRef.current = mostrarCarga({
          title: "Actualizando datos…",
          text: "Consultando alumnos, profesores, divisiones y materias",
        });
      }

      // Cada pedido se banca su propio fallo: si uno tarda o falla, los demás
      // igual se muestran (antes un solo pedido lento volteaba todo el inicio).
      // Tope de tiempo por si el servidor no responde: se conserva lo que haya.
      const TIMEOUT_MS = 30000;
      const conTope = (promesa) => Promise.race([
        promesa,
        new Promise((resolve) => setTimeout(() => resolve(null), TIMEOUT_MS)),
      ]);

      const [al, pr, divs, topPerf, preceptores, todas] = await Promise.all([
        conTope(apiGet("/alumnos?per_page=2000", ctrl.signal).catch(() => null)),
        conTope(apiGet("/profesores?per_page=2000", ctrl.signal).catch(() => null)),
        conTope(apiGet("/divisiones", ctrl.signal).catch(() => null)),
        conTope(apiGet("/dashboard/top-performers", ctrl.signal).catch(() => null)),
        conTope(apiGet("/dashboard/preceptores", ctrl.signal).catch(() => null)),
        conTope(apiGet("/materias/todas", ctrl.signal).catch(() => ({}))),
      ]);

      const arrAl = Array.isArray(al?.data) ? al.data : (Array.isArray(al) ? al : (al?.alumnos || []));
      const normAl = (arrAl || []).map((a) => {
        const recRaw =
          a.recursando ?? a.recursa ?? a.recursandos ?? a.alumno_recursandos ?? a.recursantes ?? a.recursado ?? null;
        const intRaw =
          a.intensificacion ?? a.intensifica ?? a.intensificaciones ?? a.alumno_intensificaciones ?? null;

        const recCount =
          a.rec_count ?? a.recursando_count ?? a.recursandos_count ??
          (Array.isArray(recRaw) ? recRaw.length : normalizeList(recRaw).length);

        const intCount =
          a.int_count ?? a.intensificacion_count ?? a.intensificaciones_count ??
          (Array.isArray(intRaw) ? intRaw.length : normalizeList(intRaw).length);

        return {
          ...a,
          genero: (a.genero || a.sexo || "").toUpperCase(),
          _token: cursoToken(a),
          _year: yearNum(a),
          recursando: Array.isArray(recRaw) ? recRaw : normalizeList(recRaw),
          intensificacion: Array.isArray(intRaw) ? intRaw : normalizeList(intRaw),
          _hasRec: Number(recCount) > 0,
          _hasInt: Number(intCount) > 0,
        };
      });

      const arrPr = Array.isArray(pr?.data) ? pr.data : (Array.isArray(pr) ? pr : (pr?.profesores || []));

      const map = todas || {};

      // Si mientras tanto arrancó otra carga, esta respuesta vieja se descarta.
      if (!vigente()) return;

      // Solo se pisa lo que llegó bien: si algo falló se conservan los datos
      // en pantalla y se avisa en vez de dejar todo vacío.
      const fallos = [];
      if (al !== null && al !== undefined) {
        setAlumnos(normAl);
      } else {
        fallos.push("alumnos");
      }
      if (pr !== null && pr !== undefined) {
        setProfesores(arrPr || []);
      } else {
        fallos.push("profesores");
      }
      if (divs !== null && divs !== undefined) {
        setDivisionesMap(divs || {});
      } else {
        fallos.push("divisiones");
      }
      if (todas !== null && todas !== undefined) {
        setMateriasPorAnio(map);
      }
      if (topPerf !== null && topPerf !== undefined) {
        setTopPerformers(topPerf);
      }
      if (preceptores !== null && preceptores !== undefined) {
        setPreceptores(preceptores || { total: 0, activos: 0 });
      }
      if (fallos.length > 0) {
        setError(`No se pudieron actualizar: ${fallos.join(", ")}. Se muestran los últimos datos guardados.`);
      }

      const ts = Date.now();
      // La caché conserva lo último bueno de cada parte (JSON borra los undefined).
      const previoCache = getCached(DASH_CACHE_KEY) || {};
      pgSetCache(DASH_CACHE_KEY, {
        ts,
        alumnos: al != null ? normAl : previoCache.alumnos,
        profesores: pr != null ? (arrPr || []) : previoCache.profesores,
        divisionesMap: divs != null ? (divs || {}) : previoCache.divisionesMap,
        materiasPorAnio: todas != null ? map : previoCache.materiasPorAnio,
        topPerformers: topPerf != null ? topPerf : previoCache.topPerformers,
        preceptores: preceptores != null ? preceptores : previoCache.preceptores,
      });
      setLastTs(ts);

      if (showSwal) {
        cerrarCarga();
      }
    } catch (e) {
      // Pedido viejo o desmonte: no toca nada (el vigente manda).
      if (!vigente()) return;
      // Si se aborto (desmonte o recarga) no mostramos error, pero igual cerramos
      // el cartel para no dejarlo pegado.
      if (e?.name !== 'AbortError') {
        setError(e?.message || "No se pudieron cargar datos.");
      }
      if (showSwal) {
        cerrarCarga();
        if (e?.name !== 'AbortError') {
          await mostrarAviso({
            icon: "error",
            title: "No se pudo actualizar",
            text: e?.message || "Intentá nuevamente.",
          });
        }
      }
    } finally {
      clearTimeout(safetyTimer);
    }
  };

  // ===== Enviar backup de la base de datos por email =====
  const isValidEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v||"").trim());
  // Abre un modal para ingresar email y envia el backup del sistema
  const sendBackupEmail = async () => {
    const prev = (() => { try { return localStorage.getItem("backupEmail") || ""; } catch { return ""; } })();

    const email = await pedirDato({
      title: "Enviá el backup por email",
      text: "Te enviamos un .zip con los JSON del backup.",
      confirmText: "Enviar backup",
      cancelText: "Cancelar",
      input: {
        type: "email",
        placeholder: "tucorreo@colegio.edu.ar",
        initial: prev,
        validate: isValidEmail,
        errorText: "Ingresá un email válido",
      },
    });
    if (email == null) return;

    // Envío de la base completa: exige contraseña (el servidor la valida).
    if (!(await confirmarContrasena())) return;

    try { localStorage.setItem("backupEmail", email); } catch { /* storage no disponible */ }

    const cerrar = mostrarCarga({
      title: "Generando backup…",
      text: "Esto puede tardar unos segundos",
    });

    try {
      await apiPost("/backups/sql/email", { email });
      cerrar();
      await mostrarAviso({
        icon: "success",
        title: "Backup enviado",
        text: `Se envió la copia de la base a ${email}. Revisá tu correo.`,
      });
    } catch (e) {
      cerrar();
      await mostrarAviso({
        icon: "error",
        title: "No se pudo enviar el backup",
        text: e?.message || "Intentá nuevamente.",
      });
    }
  };

  // ===== Descargar backup SQL =====
  const handleDownloadBackup = async () => {
    // Descarga de la base completa: exige contraseña (el servidor la valida).
    if (!(await confirmarContrasena())) return;
    const cerrar = mostrarCarga({
      title: "Generando backup...",
      text: "Esto puede tardar unos segundos",
    });
    try {
      const blob = await apiBlob("/backups/sql/download");
      cerrar();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `sistema_escuela_backup_${new Date().toISOString().slice(0, 10)}.sql`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => window.URL.revokeObjectURL(url), 1000);
      await mostrarAviso({
        icon: "success",
        title: "Backup descargado",
        text: "El archivo .sql se guardó en tu computadora",
        ms: 2200,
      });
    } catch (e) {
      cerrar();
      await mostrarAviso({
        icon: "error",
        title: "Error al descargar",
        text: e?.message || "No se pudo generar el backup",
      });
    }
  };

  // ===== Importar backup SQL =====
  const fileInputRef = useRef(null);
  const handleImportBackup = () => {
    fileInputRef.current?.click();
  };
  const handleImportFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    e.target.value = "";
    const ok = await pedirConfirmacion({
      title: "¿Importar backup?",
      text: `Se reemplazará TODO el contenido de la base de datos con el archivo:\n${file.name} (${(file.size / 1024).toFixed(1)} KB)\n\nEsta acción no se puede deshacer.`,
      confirmText: "Sí, importar",
      cancelText: "Cancelar",
      danger: true,
    });
    if (!ok) return;

    // Reemplaza TODA la base: exige contraseña (el servidor la valida).
    if (!(await confirmarContrasena())) return;

    const cerrar = mostrarCarga({
      title: "Importando...",
      text: "Esto puede tardar varios minutos",
    });

    try {
      const fd = new FormData();
      fd.append("file", file);
      await apiUpload("/backups/sql/import", fd);
      clearCache();
      cerrar();
      await mostrarAviso({
        icon: "success",
        title: "Importado correctamente",
        text: "La base de datos fue restaurada. Se actualizarán los datos.",
        ms: 2600,
      });
      refreshData({ showSwal: true });
    } catch (e) {
      cerrar();
      await mostrarAviso({
        icon: "error",
        title: "Error al importar",
        text: e?.message || "No se pudo importar el backup",
      });
    }
  };

  /* ===== KPIs ===== */
  const totalAlumnos = alumnos.length;
  const totalProfes = profesores.length;

  const totalCursos = useMemo(() => {
    // El reduce sobre un mapa plano no puede lanzar; el guard queda como defensa.
    try {
      const sumFromMap = Object.values(divisionesMap).reduce(
        (acc, arr) => acc + (Array.isArray(arr) ? arr.length : 0), 0
      );
      if (sumFromMap > 0) return sumFromMap;
    } catch { /* fallback a conteo por alumnos */ }
    const set = new Set(alumnos.map(a => a._token).filter(Boolean));
    return set.size;
  }, [divisionesMap, alumnos]);

  /* ===== Estados alumnos ===== */
  const estadoAlumnos = useMemo(() => {
    let recOnly = 0, intOnly = 0, both = 0, none = 0;
    for (const a of alumnos) {
      const r = !!a._hasRec;
      const i = !!a._hasInt;
      if (r && i) both++;
      else if (r) recOnly++;
      else if (i) intOnly++;
      else none++;
    }
    const pct = (n) => (totalAlumnos ? Math.round((n / totalAlumnos) * 100) : 0);
    return {
      recOnly, intOnly, both, none,
      pRecOnly: pct(recOnly),
      pIntOnly: pct(intOnly),
      pBoth:    pct(both),
      pNone:    pct(none),
      pieData: [
        { name: "Recursando",      value: recOnly, key: "soloRec" },
        { name: "Intensificación", value: intOnly, key: "soloInt" },
        { name: "Ambos",           value: both,    key: "ambos" },
        { name: "Sin condición",   value: none,    key: "ninguno" },
      ],
    };
  }, [alumnos, totalAlumnos]);

  const generoDist = useMemo(() => {
    const counts = { F: 0, M: 0, O: 0, S: 0 };
    for (const a of alumnos) {
      const g = (a.genero || "").toUpperCase();
      if (g === "F") counts.F++; else if (g === "M") counts.M++; else if (g === "O") counts.O++; else counts.S++;
    }
    return [
      { name: "Femenino", value: counts.F },
      { name: "Masculino", value: counts.M },
      { name: "Otro", value: counts.O },
    ];
  }, [alumnos]);

  const yearsList = useMemo(() => {
    const keys = Object.keys(divisionesMap || {}).map(n => parseInt(n, 10)).filter(Number.isFinite);
    if (keys.length) {
      const min = Math.min(...keys), max = Math.max(...keys);
      return Array.from({ length: (max - min + 1) }, (_, i) => min + i);
    }
    const ys = alumnos.map(a => a._year).filter(Number.isFinite);
    if (ys.length) {
      const min = Math.min(...ys), max = Math.max(...ys);
      return Array.from({ length: (max - min + 1) }, (_, i) => min + i);
    }
    return [1,2,3,4,5,6,7];
  }, [divisionesMap, alumnos]);

  const alumnosPorAnio = useMemo(() => {
    const map = new Map();
    alumnos.forEach((a) => {
      const y = a._year; if (!y) return;
      map.set(y, (map.get(y) || 0) + 1);
    });
    return yearsList.map(y => ({ anio: cursoLabel({ anio: y, division: null }), alumnos: map.get(y) || 0 }));
  }, [alumnos, yearsList]);

  const materiasBar = useMemo(() => {
    return yearsList.map((y) => {
      const cant = (materiasPorAnio?.[y] || []).length;
      return { anio: cursoLabel({ anio: y, division: null }), materias: cant };
    });
  }, [materiasPorAnio, yearsList]);

  const topCursos = useMemo(() => {
    const m = new Map();
    alumnos.forEach((a) => {
      const token = a._token || ""; if (!token) return;
      m.set(token, (m.get(token) || 0) + 1);
    });
    return Array.from(m.entries())
      .map(([curso, count]) => ({ curso, count }))
      .sort((a, b) => b.count - a.count);
  }, [alumnos]);

  const profesEstado = useMemo(() => {
    let activos = 0, licencia = 0;
    let suplentes = 0, titulares = 0;
    for (const p of profesores) {
      const e = (p.estado || "Activo").toLowerCase();
      if (e.includes("licencia")) licencia++; else activos++;
      if (p.suplente) suplentes++; else titulares++;
    }
    return { activos, licencia, suplentes, titulares };
  }, [profesores]);

  /* ===== UI helpers ===== */
  const ACCENT = {
    blue:   "dash-accent-blue",
    indigo: "dash-accent-indigo",
    emerald: "dash-accent-emerald",
    amber:  "dash-accent-amber",
    rose:   "dash-accent-rose",
  };
  const card = (icon, title, value, subtitle, accent = "blue") => (
    <motion.div
      className="dash-kpi-card"
      initial={{ opacity: 0, y: 6, scale: 0.99 }}
      animate={{ opacity: 1, y: 0, scale: 1, transition: { duration: 0.25 } }}
    >
      <div className={`dash-kpi-icon ${ACCENT[accent] || ACCENT.blue}`}>{icon}</div>
      <div className="dash-kpi-body">
        <div className="dash-kpi-title">{title}</div>
        <div className="dash-kpi-value">{value}</div>
        {subtitle && <div className="dash-kpi-subtitle">{subtitle}</div>}
      </div>
    </motion.div>
  );

  const section = (title, icon, content, right=null) => (
    <motion.section
      className="dash-section"
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0, transition: { duration: 0.25 } }}
    >
      <div className="dash-section-head">
        <div className="dash-section-title-wrap">
          <div className="dash-section-icon">{icon}</div>
          <h3 className="dash-section-title">{title}</h3>
        </div>
        {right}
      </div>
      {content}
    </motion.section>
  );

  const ProfTile = ({ label, value, total, icon, bg, fg, bar, to }) => {
    const pct = total > 0 ? Math.round((value / total) * 100) : 0;
    return (
      <div
        className="dash-tile"
        onClick={to ? () => navigate(to) : undefined}
        role={to ? "button" : undefined}
        tabIndex={to ? 0 : undefined}
        onKeyDown={to ? (e) => { if (e.key === "Enter") navigate(to); } : undefined}
        title={to ? `Ver ${label.toLowerCase()} en Profesores` : undefined}
        style={to ? { cursor: "pointer" } : undefined}
      >
        <div className="dash-tile-row">
          <div className={`dash-tile-icon ${bg} ${fg}`}>{icon}</div>
          <div className="dash-tile-body">
            <div className="dash-tile-label">{label}</div>
            <div className="dash-tile-value">{value}</div>
          </div>
          <div className="dash-tile-pct">{pct}%</div>
        </div>
        <div className="dash-tile-track">
          <div className={`dash-tile-fill ${bar}`} style={{ width: `${Math.min(100, pct)}%` }} />
        </div>
      </div>
    );
  };

  const hasCache = lastTs != null;
  const { user } = useAuth();
  const userRole = (user?.role || '').toLowerCase();
  const canBackup = userRole === 'admin' || userRole === 'jefe_preceptores';

  const top = {
    top_students: topPerformers?.top_students ?? [],
    top_courses: topPerformers?.top_courses ?? [],
  };

  return (
    <div className="dash-page">
      <div className="dash-container">

        {/* Header */}
        <div className="dash-header">
          <div>
            <Breadcrumbs items={[{ label: "Inicio" }]} />
            <h1 className="dash-title">
              {user?.name ? `Hola, ${user.name.split(" ")[0]}` : "Panel de inicio"}
            </h1>
            <p className="dash-subtitle">Resumen visual de alumnos, cursos, materias y profesores</p>
          </div>

          <div className="dash-header-actions">
            <BotonNovedades visible={hayNovedades} onClick={actualizar} />
            {hasCache && (
              <span className="dash-cache-badge">
                Mostrando datos guardados • {timeAgo(lastTs)}
              </span>
            )}

            {/* Enviar backup por email — solo admin y jefe_preceptores */}
            {canBackup && (
              <>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".sql,.txt"
                  className="dash-hidden-input"
                  onChange={handleImportFile}
                />
                <motion.button
                  whileTap={{ scale: 0.98 }}
                  onClick={handleDownloadBackup}
                  className="dash-btn-backup"
                  title="Descargar backup (.sql)"
                >
                  <span className="dash-btn-backup-icon">
                    <Download size={16} />
                  </span>
                  <span className="dash-btn-backup-text">Descargar backup</span>
                  <span className="dash-btn-backup-text-short">Backup</span>
                </motion.button>
                <motion.button
                  whileTap={{ scale: 0.98 }}
                  onClick={handleImportBackup}
                  className="dash-btn-backup"
                  title="Importar backup (.sql)"
                >
                  <span className="dash-btn-backup-icon">
                    <Upload size={16} />
                  </span>
                  <span className="dash-btn-backup-text">Importar backup</span>
                  <span className="dash-btn-backup-text-short">Importar</span>
                </motion.button>
                <motion.button
                  whileTap={{ scale: 0.98 }}
                  onClick={sendBackupEmail}
                  className="dash-btn-backup"
                  title="Enviar backup por email"
                >
                  <span className="dash-btn-backup-icon">
                    <Send size={16} />
                  </span>
                  <span className="dash-btn-backup-text">Enviar backup</span>
                  <span className="dash-btn-backup-text-short">Backup</span>
                </motion.button>
              </>
            )}

            <motion.button
              whileHover="hover"
              whileTap={{ scale: 0.98 }}
              onClick={() => {
                // Fuerza recarga TOTAL: invalida la cache local de TODOS los módulos
                // (alumnos, profesores, preceptores, materias, etc.) para que la próxima
                // vez que se abran vuelvan a pedir datos frescos al backend.
                clearCache();
                refreshData({ showSwal: true });
              }}
              className="dash-btn-refresh"
              title="Actualizar datos"
            >
              <motion.span
                aria-hidden
                className="dash-btn-refresh-icon"
                variants={{ hover: { rotate: 360 } }}
                transition={{ duration: 0.6, ease: "easeOut" }}
              >
                <RefreshCw size={16} />
              </motion.span>
              Actualizar datos
            </motion.button>
          </div>
        </div>

        {error && (
          <div className="dash-error">
            <AlertTriangle size={18} /> {error}
          </div>
        )}

        {/* Aviso si las notas finales están habilitadas */}
        <AvisoNotasFinales />

        {/* Tareas frecuentes: atajos en lenguaje de todos los días */}
        <TareasFrecuentes userRole={userRole} />

        {/* KPIs */}
        <div className="dash-kpi-grid">
          {card(<Users2 size={20} />, "Alumnos", totalAlumnos, "inscriptos en el sistema", "blue")}
          {card(<Layers3 size={20} />, "Cursos", totalCursos, "divisiones activas", "indigo")}
          {card(<BookOpen size={20} />, "Materias", Object.values(materiasPorAnio).reduce((a, l) => a + (l?.length || 0), 0), "en todos los años", "emerald")}
          {card(<GraduationCap size={20} />, "Profesores", totalProfes, `${profesEstado.activos} activos`, "amber")}
          {card(
            <ShieldCheck size={20} />,
            "Preceptores",
            preceptores?.total ?? 0,
            `${preceptores?.activos ?? 0} activos`,
            "rose"
          )}
        </div>

        {/* Fila 1 */}
        <div className="dash-grid dash-grid-3 dash-grid-row-mb">
          {section(
            "Alumnos por año",
            <BarChart3 size={18} />,
            <div className="dash-chart-wrap">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={alumnosPorAnio} margin={{ top: 12, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="anio" fontSize={12} tickLine={false} />
                  <YAxis allowDecimals={false} fontSize={12} tickLine={false} />
                  <Tooltip formatter={(v) => [`${v} alumnos`, "Cantidad"]} cursor={{ fill: "rgba(59,130,246,.06)" }} />
                  <Bar dataKey="alumnos" fill={PALETTE[0]} radius={[4, 4, 0, 0]} label={{ position: "top", fontSize: 11, fill: "#6b7280" }} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}

          {section(
            "Distribución por género",
            <PieIcon size={18} />,
            <div className="dash-chart-wrap">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Tooltip formatter={(v, n) => [`${v} alumnos`, n]} />
                  <Legend formatter={(value) => {
                    const item = generoDist.find((g) => g.name === value);
                    return `${value}: ${item ? item.value : 0}`;
                  }} />
                  <Pie
                    data={generoDist}
                    dataKey="value"
                    nameKey="name"
                    innerRadius={40}
                    outerRadius={65}
                    paddingAngle={2}
                  >
                    {generoDist.map((entry) => (
                      <Cell key={entry.name} fill={GENERO_COLORS[entry.name] || PALETTE[0]} />
                    ))}
                  </Pie>
                </PieChart>
              </ResponsiveContainer>
            </div>
          )}

          {section(
            "Materias por año",
            <Sparkles size={18} />,
            <div className="dash-chart-wrap">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={materiasBar} margin={{ top: 12, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="anio" fontSize={12} tickLine={false} />
                  <YAxis allowDecimals={false} fontSize={12} tickLine={false} />
                  <Tooltip formatter={(v) => [`${v} materias`, "Cantidad"]} cursor={{ fill: "rgba(139,92,246,.06)" }} />
                  <Bar dataKey="materias" fill={PALETTE[4]} radius={[4, 4, 0, 0]} label={{ position: "top", fontSize: 11, fill: "#6b7280" }} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </div>

        {/* Fila 2 */}
        <div className="dash-grid dash-grid-3">
          {section(
            "Top cursos por alumnos",
            <Layers3 size={18} />,
            <div className="dash-list-wrap">
              <div className="dash-scroll-list">
                {topCursos.length === 0 && (
                  <div className="dash-empty">Aún no hay datos suficientes.</div>
                )}
                <AnimatePresence initial={false}>
                  {topCursos.map((c, i) => (
                    <motion.div
                      key={c.curso}
                      initial={{ opacity: 0, y: 4 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, y: 4 }}
                      className="dash-list-item"
                    >
                      <div className="dash-rank">{i + 1}.</div>
                      <div className="dash-item-body">
                        <div className="dash-item-head">
                          <span className="dash-item-name">{c.curso}</span>
                          <span className="dash-item-meta">{c.count} alumnos</span>
                        </div>
                        <div className="dash-progress-track">
                          <div
                            className="dash-progress-fill"
                            style={{ width: `${Math.min(100, (c.count / Math.max(1, topCursos[0]?.count)) * 100)}%` }}
                          />
                        </div>
                      </div>
                    </motion.div>
                  ))}
                </AnimatePresence>
              </div>
            </div>
          )}

          {section(
            "Profesores • Estado",
            <GraduationCap size={18} />,
            (() => {
              const totalEstado = (profesEstado.activos || 0) + (profesEstado.licencia || 0);
              const totalCond   = (profesEstado.titulares || 0) + (profesEstado.suplentes || 0);
              return (
                <div className="dash-tile-grid">
                  <ProfTile label="Activos"    value={profesEstado.activos}   total={totalEstado} icon={<CheckCircle2 size={16} />} bg="dash-tile-bg-green"  fg="dash-tile-fg-green"  bar="dash-bar-green" />
                  <ProfTile label="Licencia"   value={profesEstado.licencia}  total={totalEstado} icon={<PauseCircle size={16} />}   bg="dash-tile-bg-amber"  fg="dash-tile-fg-amber"  bar="dash-bar-amber" />
                  <ProfTile label="Titulares"  value={profesEstado.titulares} total={totalCond}   icon={<UserCheck size={16} />}     bg="dash-tile-bg-blue"   fg="dash-tile-fg-blue"   bar="dash-bar-blue"   to="/profesores?rol=titular" />
                  <ProfTile label="Suplentes"  value={profesEstado.suplentes} total={totalCond}   icon={<UserPlus size={16} />}      bg="dash-tile-bg-purple" fg="dash-tile-fg-purple" bar="dash-bar-purple" to="/profesores?rol=suplente" />
                </div>
              );
            })()
          )}

          {section(
            "Alumnos • Estado",
            <Users2 size={18} />,
            <div className="dash-split-grid">
              <div className="dash-chart-wrap-sm">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Tooltip formatter={(v, n) => [`${v} alumnos`, n]} />
                    <Pie data={estadoAlumnos.pieData} dataKey="value" nameKey="name" innerRadius={45} outerRadius={65} paddingAngle={2}
                         label={(e) => e.value > 0 ? `${e.value}` : ""} labelLine={false}>
                      {estadoAlumnos.pieData.map((d) => (<Cell key={d.key} fill={STATE_COLORS[d.key]} />))}
                    </Pie>
                  </PieChart>
                </ResponsiveContainer>
              </div>

              <div className="dash-legend">
                <div className="dash-legend-row">
                  <div className="dash-legend-label-wrap">
                    <span className="dash-legend-dot" style={{ background: STATE_COLORS.soloRec }} />
                    <span className="dash-legend-label" style={{ color: STATE_COLORS.soloRec }}>Recursando:</span>
                  </div>
                  <span className="dash-legend-value">
                    {estadoAlumnos.recOnly} <span className="dash-legend-pct">({estadoAlumnos.pRecOnly}%)</span>
                  </span>
                </div>
                <div className="dash-legend-row">
                  <div className="dash-legend-label-wrap">
                    <span className="dash-legend-dot" style={{ background: STATE_COLORS.soloInt }} />
                    <span className="dash-legend-label" style={{ color: STATE_COLORS.soloInt }}>Intensificación:</span>
                  </div>
                  <span className="dash-legend-value">
                    {estadoAlumnos.intOnly} <span className="dash-legend-pct">({estadoAlumnos.pIntOnly}%)</span>
                  </span>
                </div>
                <div className="dash-legend-row">
                  <div className="dash-legend-label-wrap">
                    <span className="dash-legend-dot" style={{ background: STATE_COLORS.ambos }} />
                    <span className="dash-legend-label" style={{ color: STATE_COLORS.ambos }}>Ambos:</span>
                  </div>
                  <span className="dash-legend-value">
                    {estadoAlumnos.both} <span className="dash-legend-pct">({estadoAlumnos.pBoth}%)</span>
                  </span>
                </div>
                <div className="dash-legend-row">
                  <div className="dash-legend-label-wrap">
                    <span className="dash-legend-dot" style={{ background: STATE_COLORS.ninguno }} />
                    <span className="dash-legend-label" style={{ color: STATE_COLORS.ninguno }}>Sin condición:</span>
                  </div>
                  <span className="dash-legend-value">
                    {estadoAlumnos.none} <span className="dash-legend-pct">({estadoAlumnos.pNone}%)</span>
                  </span>
                </div>
              </div>
            </div>
          )}
        </div>

        {topPerformers && (
          <div className="dash-grid dash-grid-2 dash-grid-top-mt">
            {section(
              "Mejores promedios — Alumnos",
              <GraduationCap size={18} />,
              <div className="dash-list-wrap">
                <div className="dash-scroll-list-lg">
                  {top.top_students.length === 0 && (
                    <div className="dash-empty">Sin datos de calificaciones.</div>
                  )}
                  <AnimatePresence initial={false}>
                    {top.top_students.map((s, i) => {
                      const maxProm = Number(top.top_students[0]?.promedio ?? 0) || 1;
                      return (
                        <motion.div
                          key={s.alumno_id}
                          initial={{ opacity: 0, y: 4 }}
                          animate={{ opacity: 1, y: 0 }}
                          exit={{ opacity: 0, y: 4 }}
                          className="dash-list-item"
                        >
                          <div className="dash-rank">{i + 1}.</div>
                          <div className="dash-item-body">
                            <div className="dash-item-head">
                              <span className="dash-item-name">{s.apellido}, {s.nombre}</span>
                              <span className="dash-item-score" style={{ color: i === 0 ? '#ca8a04' : '#6b7280' }}>
                                {Number(s.promedio ?? 0).toFixed(2)}
                              </span>
                            </div>
                            <div className="dash-item-sub">
                              <span>{s.curso}</span>
                              <span>·</span>
                              <span>{s.aprobadas}/{s.materias} mat.</span>
                            </div>
                            <div className="dash-progress-track">
                              <div
                                className="dash-progress-fill"
                                    style={{ width: `${Math.min(100, (Number(s.promedio ?? 0) / maxProm) * 100)}%`, background: i === 0 ? '#ca8a04' : '#3b82f6' }}
                              />
                            </div>
                          </div>
                        </motion.div>
                      );
                    })}
                  </AnimatePresence>
                </div>
              </div>
            )}

            {section(
              "Mejores promedios — Cursos",
              <BarChart3 size={18} />,
              <div className="dash-list-wrap">
                <div className="dash-scroll-list-lg">
                  {top.top_courses.length === 0 && (
                    <div className="dash-empty">Sin datos de calificaciones.</div>
                  )}
                  <AnimatePresence initial={false}>
                    {top.top_courses.map((c, i) => {
                      const maxProm = Number(top.top_courses[0]?.promedio ?? 0) || 1;
                      return (
                        <motion.div
                          key={c.curso}
                          initial={{ opacity: 0, y: 4 }}
                          animate={{ opacity: 1, y: 0 }}
                          exit={{ opacity: 0, y: 4 }}
                          className="dash-list-item"
                        >
                          <div className="dash-rank">{i + 1}.</div>
                          <div className="dash-item-body">
                            <div className="dash-item-head">
                              <span className="dash-item-name">{c.curso}</span>
                              <span className="dash-item-score" style={{ color: i === 0 ? '#ca8a04' : '#6b7280' }}>
                                {Number(c.promedio ?? 0).toFixed(2)}
                              </span>
                            </div>
                            <div className="dash-item-sub">
                              <span>{c.alumnos} alumnos</span>
                              <span>·</span>
                              <span>{c.aprobadas}/{c.total_materias} mat.</span>
                            </div>
                            <div className="dash-progress-track">
                              <div
                                className="dash-progress-fill"
                                    style={{ width: `${Math.min(100, (Number(c.promedio ?? 0) / maxProm) * 100)}%`, background: i === 0 ? '#ca8a04' : '#3b82f6' }}
                              />
                            </div>
                          </div>
                        </motion.div>
                      );
                    })}
                  </AnimatePresence>
                </div>
              </div>
            )}
          </div>
        )}

      </div>
      {avisoModal}
      {modalPwd}
    </div>
  );
};

export default Dashboard;
