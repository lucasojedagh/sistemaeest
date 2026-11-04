// Pagina de historial de actividad del sistema.
// Comportamiento tipo "pestaña del navbar": al abrir se muestra INSTANTANEAMENTE
// la ultima carga cacheada (memoria/localStorage). No se vuelve a consultar el
// backend salvo la primera vez, al pulsar "Refrescar", o cuando se detecta que
// hay actividad nueva en segundo plano. Los filtros se aplican en el cliente
// sobre los datos ya cargados, por lo que son inmediatos.
import React, { useEffect, useState, useMemo, useRef } from "react";
import { motion } from "framer-motion";
import { Search, Filter, FileText, User as UserIcon, GraduationCap, BookOpen, CalendarClock, Layers, Download, Users, Clock, Inbox, RefreshCw } from "lucide-react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import ExportButton from "../../compartido/componentes/Common/ExportButton";
import { getCached, setCache } from "../../compartido/lib/pageCache";
import { apiGet, apiPost } from "../../compartido/lib/api";
import { formatearFecha } from "../../compartido/lib/fechas";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import "./Historial.css";

const CACHE_KEY = "historial";


const ICON_MAP = {
  doc: FileText, user: UserIcon, alumno: GraduationCap, profesor: Users,
  nota: BookOpen, reloj: CalendarClock, division: Layers, engranaje: Clock,
};

const COLOR_STYLES = {
  green:  { dot: "hist-dot-green",  chip: "hist-chip-green",  ring: "hist-ring-green" },
  blue:   { dot: "hist-dot-blue",   chip: "hist-chip-blue",   ring: "hist-ring-blue" },
  red:    { dot: "hist-dot-red",    chip: "hist-chip-red",    ring: "hist-ring-red" },
  violet: { dot: "hist-dot-violet", chip: "hist-chip-violet", ring: "hist-ring-violet" },
  amber:  { dot: "hist-dot-amber",  chip: "hist-chip-amber",  ring: "hist-ring-amber" },
  slate:  { dot: "hist-dot-slate",  chip: "hist-chip-slate",  ring: "hist-ring-slate" },
};

const TIPO_CAMBIO = {
  add:    { text: "hist-change-add",    sign: "+" },
  remove: { text: "hist-change-remove", sign: "−" },
  change: { text: "hist-change-change", sign: "→" },
  info:   { text: "hist-change-info",   sign: "•" },
  student:{ text: "",                   sign: "" },
};

const CATEGORIAS = [
  { key: "", label: "Todas las áreas" },
  { key: "user", label: "Usuarios" },
  { key: "alumno", label: "Alumnos" },
  { key: "profesor", label: "Profesores" },
  { key: "calificaciones", label: "Calificaciones" },
  { key: "materias", label: "Materias" },
  { key: "horario", label: "Horarios" },
  { key: "divisiones", label: "Divisiones" },
  { key: "documento", label: "Documentos" },
];

const OPERACIONES = [
  { key: "", label: "Todas las acciones" },
  { key: "store", label: "Creaciones" },
  { key: "update", label: "Ediciones" },
  { key: "destroy", label: "Eliminaciones" },
  { key: "download", label: "Descargas" },
];

// Devuelve la fecha de HOY en formato local YYYY-MM-DD (no hardcodeada), en la
// zona horaria del usuario para no desplazar un día al comparar con los inputs.
function hoyISO() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const dia = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${dia}`;
}

// Valida que una cadena sea una fecha real con formato YYYY-MM-DD.
// Rechaza fechas inexistentes (31/02), futuras y con formato incorrecto.
function fechaValida(iso) {
  if (typeof iso !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return false;
  const partes = iso.split("-").map(Number);
  const d = new Date(partes[0], partes[1] - 1, partes[2]);
  if (d.getFullYear() !== partes[0] || d.getMonth() !== partes[1] - 1 || d.getDate() !== partes[2]) return false;
  return !(iso > hoyISO()); // futuras: inválidas
}

function tiempoRelativo(iso) {
  if (!iso) return "";
  const diff = Date.now() - new Date(iso).getTime();
  const s = Math.floor(diff / 1000);
  if (s < 60) return "hace un momento";
  const m = Math.floor(s / 60);
  if (m < 60) return `hace ${m} min`;
  const h = Math.floor(m / 60);
  if (h < 24) return `hace ${h} h`;
  const d = Math.floor(h / 24);
  if (d === 1) return "ayer";
  if (d < 7) return `hace ${d} días`;
  return formatearFecha(iso);
}

export default function Historial() {
  // Datos base cacheados (sin filtros) que se muestran de inmediato.
  const [allLogs, setAllLogs] = useState(() => getCached(CACHE_KEY) || []);
  const [cargandoInicial, setCargandoInicial] = useState(!allLogs.length);
  const [refrescando, setRefrescando] = useState(false);
  const primeraVez = useRef(allLogs.length === 0);
  const [mostrarAviso, , avisoModal] = useAviso();

  // Filtros (se aplican en el cliente, instantáneo).
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [categoria, setCategoria] = useState("");
  const [operacion, setOperacion] = useState("");
  const [desde, setDesde] = useState("");
  const [hasta, setHasta] = useState("");

  const hoy = hoyISO();

  // Maneja el cambio de "Desde": solo acepta fechas válidas (existentes, no
  // futuras) y nunca posteriores a "hasta" si ya está elegido.
  const setDesdeFiltrado = (val) => {
    if (!val) { setDesde(""); return; }
    if (!fechaValida(val)) return;                    // inválida o futura
    if (hasta && val > hasta) return;                 // incoherente con Hasta
    setDesde(val);
  };

  // Maneja el cambio de "Hasta": solo acepta fechas válidas, no futuras y nunca
  // anteriores a "desde" si ya está elegido.
  const setHastaFiltrado = (val) => {
    if (!val) { setHasta(""); return; }
    if (!fechaValida(val)) return;                    // inválida o futura
    if (desde && val < desde) return;                 // incoherente con Desde
    setHasta(val);
  };

  // "Hoy": selecciona la fecha actual (máximo válido). Siempre genera un rango
  // coherente (Desde = Hoy, Hasta = Hoy) y nunca futura.
  const aplicarHoy = () => {
    setDesde(hoy);
    setHasta(hoy);
  };

  // "Borrar": limpia las fechas y restablece las restricciones del selector.
  const limpiarFechas = () => { setDesde(""); setHasta(""); };

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search.trim()), 400);
    return () => clearTimeout(t);
  }, [search]);

  // Carga desde el backend y actualiza caché.
  const cargar = async (forzarRefresh = false) => {
    try {
      if (forzarRefresh) setRefrescando(true);
      let data;
      if (forzarRefresh) {
        await apiPost("/historial/refresh?page=1&per_page=200");
        data = await apiGet("/historial?page=1&per_page=200");
      } else {
        data = await apiGet("/historial?page=1&per_page=200");
      }
      const logs = data.data || [];
      setAllLogs(logs);
      setCache(CACHE_KEY, logs);
    } catch {
      if (primeraVez.current) {
        mostrarAviso({ icon: "error", title: "Error", text: "No se pudo cargar el historial." });
      }
    } finally {
      setCargandoInicial(false);
      setRefrescando(false);
    }
  };

  // Al montar: si no hay cache, cargamos. Si hay cache, se muestra al instante.
  useEffect(() => {
    cargar(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refrescar = () => cargar(true);

  // Como en el resto de las pantallas: la lista se recarga sola cuando llega
  // actividad nueva de otro usuario.
  useAvisoDeCambios(
    "historial",
    ["alumnos", "profesores", "usuarios", "cursos", "asistencia", "calificaciones", "documentos", "otros"],
    () => cargar(true)
  );

  // Aplicar filtros en el cliente (instantaneo).
  const logsFiltrados = useMemo(() => {
    const q = debouncedSearch.toLowerCase();
    const dDesde = desde ? new Date(desde + "T00:00:00") : null;
    const dHasta = hasta ? new Date(hasta + "T23:59:59") : null;
    return allLogs.filter((l) => {
      if (categoria && l.categoria_key !== categoria) return false;
      if (operacion) {
        const op = (l.action_type || "").split(".").pop();
        if (op !== operacion) return false;
      }
      if (q) {
        const hay = (l.titulo + " " + l.actor_email + " " + (l.detalles || "")).toLowerCase().includes(q);
        if (!hay) return false;
      }
      if (dDesde || dHasta) {
        const f = l.fecha ? new Date(l.fecha) : null;
        if (!f || Number.isNaN(f.getTime())) return false;
        if (dDesde && f < dDesde) return false;
        if (dHasta && f > dHasta) return false;
      }
      return true;
    });
  }, [allLogs, categoria, operacion, debouncedSearch, desde, hasta]);

  const conteoPorCategoria = useMemo(() => {
    const map = {};
    for (const l of allLogs) map[l.categoria_key] = (map[l.categoria_key] || 0) + 1;
    return map;
  }, [allLogs]);

  const hayFiltros = !!(debouncedSearch || categoria || operacion || desde || hasta);
  const vista = hayFiltros ? logsFiltrados : allLogs;

  return (
    <div className="hist-page">
      <div className="hist-container">
        <Breadcrumbs items={[{ label: "Historial" }]} />
        <div className="hist-header">
          <div>
            <h1 className="hist-title">Historial de actividad</h1>
            <p className="hist-subtitle">Registro de todas las operaciones realizadas en el sistema</p>
          </div>
          <div className="hist-actions">
            <button
              onClick={refrescar}
              disabled={refrescando}
              className="hist-btn-refresh"
            >
              <RefreshCw size={16} className={refrescando ? "hist-spin" : ""} />
              {refrescando ? "Refrescando…" : "Refrescar"}
            </button>
            <ExportButton data={vista} filename="historial" label="Exportar" />
          </div>
        </div>

        {/* Filtros */}
        <div className="hist-filters-card">
          <div className="hist-filters-row">
            <div className="hist-search-wrap">
              <Search className="hist-search-icon" size={18} />
              <input
                type="text" placeholder="Buscar por acción o detalle"
                value={search} onChange={(e) => setSearch(e.target.value)}
                className="hist-search-input"
              />
            </div>
            <div className="hist-filter-selects">
              <Filter size={16} className="hist-filter-icon" />
              <select
                value={categoria} onChange={(e) => setCategoria(e.target.value)}
                className="hist-select"
              >
                {CATEGORIAS.map(c => <option key={c.key} value={c.key}>{c.label}</option>)}
              </select>
              <select
                value={operacion} onChange={(e) => setOperacion(e.target.value)}
                className="hist-select"
              >
                {OPERACIONES.map(o => <option key={o.key} value={o.key}>{o.label}</option>)}
              </select>
            </div>
          </div>

          <div className="hist-dates-row">
            <span className="hist-dates-label">Desde</span>
            <input
              type="date"
              value={desde}
              max={hasta || hoy}            // nunca después de Hasta ni futuro
              onChange={(e) => setDesdeFiltrado(e.target.value)}
              className="hist-select"
            />
            <span className="hist-dates-label">Hasta</span>
            <input
              type="date"
              value={hasta}
              min={desde || ""}             // nunca antes de Desde
              max={hoy}                     // nunca futura
              onChange={(e) => setHastaFiltrado(e.target.value)}
              className="hist-select"
            />
            <button onClick={aplicarHoy} className="hist-clear-dates">
              Hoy
            </button>
            {(desde || hasta) && (
              <button
                onClick={limpiarFechas}
                className="hist-clear-dates"
              >
                Borrar
              </button>
            )}
          </div>

          <div className="hist-cat-list">
            {CATEGORIAS.filter(c => c.key !== "").map(c => (
              <button
                key={c.key}
                onClick={() => setCategoria(categoria === c.key ? "" : c.key)}
                className={`hist-cat-chip ${categoria === c.key ? "hist-cat-chip-active" : "hist-cat-chip-idle"}`}
              >
                {c.label}
                {conteoPorCategoria[c.key] ? (
                  <span className={`hist-cat-count ${categoria === c.key ? "hist-cat-count-active" : ""}`}>
                    {conteoPorCategoria[c.key]}
                  </span>
                ) : null}
              </button>
            ))}
          </div>
        </div>

        {cargandoInicial ? (
          <div className="hist-loading">Cargando datos…</div>
        ) : vista.length === 0 ? (
          <div className="hist-empty">
            <div className="hist-empty-inner">
              <Inbox size={42} className="hist-empty-icon" />
              <p className="hist-empty-text">{hayFiltros ? "No se encontraron registros con esos filtros." : "Aún no hay actividad registrada."}</p>
            </div>
          </div>
        ) : (
          <div className="hist-timeline">
            <div className="hist-timeline-line" />
            <div className="hist-list">
              {vista.map((log, i) => {
                const style = COLOR_STYLES[log.color] || COLOR_STYLES.slate;
                const Icon = ICON_MAP[log.icono] || Clock;
                return (
                  <motion.div
                    key={log.id}
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2, delay: Math.min(i * 0.015, 0.2) }}
                    className="hist-item"
                  >
                    <div className={`hist-avatar ${style.dot} ${style.ring}`}>
                      <Icon size={22} />
                    </div>
                    <div className="hist-card">
                      <div className="hist-card-head">
                        <div className="hist-card-main">
                          <div className="hist-badges">
                            <span className={`hist-badge ${style.chip}`}>
                              {log.operacion}
                            </span>
                            <span className="hist-badge-cat">
                              {log.categoria}
                            </span>
                            {log.entidad && (
                              <span className="hist-badge-muted">{log.entidad}</span>
                            )}
                          </div>
                          <h3 className="hist-card-title">
                            {log.titulo}
                          </h3>
                        </div>
                        <div className="hist-card-time">
                          <div className="hist-card-time-rel">{tiempoRelativo(log.fecha)}</div>
                          <div className="hist-card-time-abs">{log.fecha_legible}</div>
                        </div>
                      </div>

                      {log.resumen && log.resumen.length > 0 && (
                        <div className="hist-resumen">
                          {log.resumen.map((r, idx) => {
                            if (r.tipo === 'student') {
                              return (
                                <div key={idx} className="hist-resumen-student">
                                  {r.texto}
                                </div>
                              );
                            }
                            const ts = TIPO_CAMBIO[r.tipo] || TIPO_CAMBIO.info;
                            return (
                              <div key={idx} className={`hist-change ${ts.text}`}>
                                <span className="hist-change-sign">{ts.sign}</span>
                                <span className="hist-change-text">{r.texto}</span>
                              </div>
                            );
                          })}
                        </div>
                      )}

                      <div className="hist-card-foot">
                        <span className="hist-actor">
                          <span className="hist-actor-name">{log.actor}</span>
                          <span className="hist-actor-role">({log.actor_role})</span>
                        </span>
                        {log.detalles ? (
                          <span className="hist-motivo">Motivo: {log.detalles}</span>
                        ) : null}
                      </div>
                    </div>
                  </motion.div>
                );
              })}
            </div>
          </div>
        )}
      </div>
      {avisoModal}
    </div>
  );
}
