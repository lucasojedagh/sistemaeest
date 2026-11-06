// Pagina de historial de actividad del sistema.
// Comportamiento tipo "pestaña del navbar": al abrir se muestra INSTANTANEAMENTE
// la ultima carga cacheada (memoria/localStorage). Los filtros y la paginacion se
// resuelven en el backend sobre TODO el historial: antes se traian solo 200
// registros y se filtraban aqui, asi que buscar un alumno antiguo no encontraba
// nada. Al pulsar "Refrescar" (o cuando llega actividad nueva) se pide la primera
// pagina forzada: /historial/refresh invalida la cache y devuelve los datos, con
// una sola consulta en lugar de dos.
import React, { useEffect, useState, useMemo, useRef } from "react";
import {
  Search, Filter, FileText, User as UserIcon, GraduationCap, BookOpen, CalendarClock,
  Layers, Users, Clock, Inbox, RefreshCw, ClipboardCheck, BookMarked, Award, Lock, Database,
} from "lucide-react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import ExportButton from "../../compartido/componentes/Common/ExportButton";
import { useAuth } from "../auth/AuthContext";
import { puede } from "../../compartido/lib/permisos";
import { getCached, setCache } from "../../compartido/lib/pageCache";
import { apiGet, apiPost } from "../../compartido/lib/api";
import { formatearFecha } from "../../compartido/lib/fechas";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import "./Historial.css";

const CACHE_KEY = "historial";
const PER_PAGE = 50;

const ICON_MAP = {
  doc: FileText, user: UserIcon, alumno: GraduationCap, profesor: Users,
  nota: BookOpen, reloj: CalendarClock, division: Layers, engranaje: Clock,
  asistencia: ClipboardCheck, materia: BookMarked, promocion: Award,
  ventana: Lock, backup: Database,
};

const COLOR_STYLES = {
  green:  { dot: "hist-dot-green",  chip: "hist-chip-green",  ring: "hist-ring-green" },
  blue:   { dot: "hist-dot-blue",   chip: "hist-chip-blue",   ring: "hist-ring-blue" },
  red:    { dot: "hist-dot-red",    chip: "hist-chip-red",    ring: "hist-ring-red" },
  violet: { dot: "hist-dot-violet", chip: "hist-chip-violet", ring: "hist-ring-violet" },
  amber:  { dot: "hist-dot-amber",  chip: "hist-chip-amber",  ring: "hist-ring-amber" },
  indigo: { dot: "hist-dot-indigo", chip: "hist-chip-indigo", ring: "hist-ring-indigo" },
  slate:  { dot: "hist-dot-slate",  chip: "hist-chip-slate",  ring: "hist-ring-slate" },
};

const TIPO_CAMBIO = {
  add:    { text: "hist-change-add",    sign: "+" },
  remove: { text: "hist-change-remove", sign: "−" },
  change: { text: "hist-change-change", sign: "→" },
  info:   { text: "hist-change-info",   sign: "•" },
  student:{ text: "",                   sign: "" },
};

// La clave es el prefijo del action_type: el backend filtra con "empieza con".
const CATEGORIAS = [
  { key: "", label: "Todas las áreas" },
  { key: "user", label: "Usuarios" },
  { key: "alumno", label: "Alumnos" },
  { key: "profesor", label: "Profesores" },
  { key: "calificaciones", label: "Calificaciones" },
  { key: "asistencia", label: "Asistencia" },
  { key: "materias", label: "Materias" },
  { key: "horario", label: "Horarios" },
  { key: "divisiones", label: "Divisiones" },
  { key: "curso", label: "Cursos" },
  { key: "documento", label: "Documentos" },
  { key: "promocion", label: "Promociones" },
  { key: "ventana", label: "Ventana de notas" },
  { key: "backup", label: "Copias de seguridad" },
];

// La clave es el ULTIMO tramo del action_type: el backend filtra con "termina en".
const OPERACIONES = [
  { key: "", label: "Todas las acciones" },
  { key: "store", label: "Creaciones" },
  { key: "update", label: "Ediciones" },
  { key: "destroy", label: "Eliminaciones" },
  { key: "login", label: "Inicios de sesión" },
  { key: "download", label: "Descargas" },
  { key: "print", label: "Impresiones" },
  { key: "register", label: "Registros públicos" },
  { key: "egresar", label: "Egresos" },
  { key: "justificar", label: "Justificaciones" },
  { key: "replace", label: "Reemplazos" },
  { key: "sync", label: "Sincronizaciones" },
  { key: "avatar", label: "Fotos de perfil" },
  { key: "restore", label: "Restauraciones" },
  { key: "cerrar", label: "Cierres" },
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

// Lee la copia guardada tolerando el formato viejo (solo el arreglo de registros).
function leerCache() {
  const guardado = getCached(CACHE_KEY);
  if (!guardado) return null;
  if (Array.isArray(guardado)) {
    return { logs: guardado, total: guardado.length, ultima: 1, conteos: {} };
  }
  return guardado.logs ? guardado : null;
}

export default function Historial() {
  const { user } = useAuth();
  // Ultima carga sin filtros, que se muestra de inmediato al abrir la pestaña.
  const copia = useMemo(leerCache, []);
  const [logs, setLogs] = useState(() => copia?.logs || []);
  const [total, setTotal] = useState(() => copia?.total || 0);
  const [ultima, setUltima] = useState(() => copia?.ultima || 1);
  const [pagina, setPagina] = useState(1);
  const [conteos, setConteos] = useState(() => copia?.conteos || {});
  const [cargando, setCargando] = useState(false);
  const [cargandoMas, setCargandoMas] = useState(false);
  const [refrescando, setRefrescando] = useState(false);
  const [avisoError, setAvisoError] = useState("");
  const [cargandoInicial, setCargandoInicial] = useState(!logs.length);
  const primeraVez = useRef((copia?.logs?.length ?? 0) === 0);
  const peticionActual = useRef(null);
  const [mostrarAviso, , avisoModal] = useAviso();

  // Filtros: se envian al backend, que consulta el historial completo.
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [categoria, setCategoria] = useState("");
  const [operacion, setOperacion] = useState("");
  const [desde, setDesde] = useState("");
  const [hasta, setHasta] = useState("");

  const hoy = hoyISO();
  const hayFiltros = !!(debouncedSearch || categoria || operacion || desde || hasta);

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

  // Pide una pagina al backend. `anexar` suma al final (Cargar mas) y `forzar`
  // invalida la cache del servidor antes de leer.
  const traer = async ({ pagina: pedir = 1, anexar = false, forzar = false } = {}) => {
    // Cada pedido reemplaza al anterior: si el filtro cambia mientras llega una
    // respuesta, esa respuesta se descarta en vez de pisar la nueva.
    peticionActual.current?.abort();
    const ctrl = new AbortController();
    peticionActual.current = ctrl;

    const qs = new URLSearchParams({ page: String(pedir), per_page: String(PER_PAGE) });
    if (categoria) qs.set("categoria", categoria);
    if (operacion) qs.set("operacion", operacion);
    if (debouncedSearch) qs.set("search", debouncedSearch);
    if (desde) qs.set("desde", desde);
    if (hasta) qs.set("hasta", hasta);

    if (anexar) setCargandoMas(true);
    else if (forzar) setRefrescando(true);
    else setCargando(true);

    try {
      // /historial/refresh limpia la cache y DEVUELVE la pagina: una sola consulta.
      const data = forzar
        ? await apiPost(`/historial/refresh?${qs.toString()}`, {}, { signal: ctrl.signal })
        : await apiGet(`/historial?${qs.toString()}`, { signal: ctrl.signal });

      const nuevos = data.data || [];
      setLogs((prev) => (anexar ? [...prev, ...nuevos] : nuevos));
      setTotal(data.total ?? nuevos.length);
      setUltima(data.last_page ?? 1);
      setPagina(data.current_page ?? pedir);
      if (data.conteos) setConteos(data.conteos);
      setAvisoError("");

      // Solo se guarda la vista sin filtros: es la que tiene que aparecer al
      // instante al volver a la pestaña.
      if (!anexar && !hayFiltros) {
        setCache(CACHE_KEY, {
          logs: nuevos,
          total: data.total ?? nuevos.length,
          ultima: data.last_page ?? 1,
          conteos: data.conteos || {},
        });
      }
    } catch (e) {
      if (e?.name === "AbortError") return;
      setAvisoError(e?.message || "No se pudo cargar el historial.");
      if (primeraVez.current && logs.length === 0) {
        mostrarAviso({ icon: "error", title: "Error", text: "No se pudo cargar el historial." });
      } else if (forzar) {
        mostrarAviso({ icon: "error", title: "Error", text: "No se pudo refrescar el historial." });
      }
    } finally {
      // Solo este pedido apaga su indicador: si lo reemplazo con otro, el spinner
      // lo mantiene la peticion nueva.
      if (peticionActual.current === ctrl) {
        setCargando(false);
        setCargandoMas(false);
        setRefrescando(false);
        setCargandoInicial(false);
      }
    }
  };

  // Cualquier filtro nuevo vuelve a la pagina 1 y consulta la base.
  useEffect(() => {
    traer({ pagina: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch, categoria, operacion, desde, hasta]);

  // Al desmontar se descarta la respuesta pendiente.
  useEffect(() => () => peticionActual.current?.abort(), []);

  const refrescar = () => traer({ pagina: 1, forzar: true });
  const cargarMas = () => traer({ pagina: pagina + 1, anexar: true });

  // Como en el resto de las pantallas: la lista se recarga sola cuando llega
  // actividad nueva de otro usuario.
  useAvisoDeCambios(
    "historial",
    ["alumnos", "profesores", "usuarios", "cursos", "asistencia", "calificaciones", "documentos", "otros"],
    refrescar
  );

  // Filas planas y en espanol: el resumen de cambios llega como lista de objetos
  // y el CSV la escribia como "[object Object]".
  const filasExport = useMemo(
    () => logs.map((l) => ({
      Fecha: l.fecha_legible || "",
      Área: l.categoria || "",
      Operación: l.operacion || "",
      Acción: l.titulo || "",
      Actor: l.actor || "",
      Rol: l.actor_role_legible || "",
      Entidad: l.entidad || "",
      Cambios: (l.resumen || []).map((r) => r.texto).join(" | "),
      Motivo: l.detalles || "",
    })),
    [logs]
  );

  const puedenCargarMas = pagina < ultima;

  return (
    <div className="hist-page">
      <div className="hist-container">
        <Breadcrumbs items={[{ label: "Historial" }]} />
        <div className="hist-header">
          <div>
            <h1 className="hist-title">Historial de actividad</h1>
            <p className="hist-subtitle">Registro de todas las operaciones realizadas en el sistema</p>
            {total > 0 && (
              <p className="hist-counter">
                Mostrando {logs.length} de {total} {total === 1 ? "registro" : "registros"}
                {hayFiltros ? " con estos filtros" : ""}
              </p>
            )}
          </div>
          <div className="hist-actions">
            <button
              onClick={refrescar}
              disabled={refrescando}
              className="hist-btn-refresh"
            >
              <RefreshCw size={16} className={refrescando || cargando ? "hist-spin" : ""} />
              {refrescando ? "Refrescando…" : "Refrescar"}
            </button>
            {/* El archivo lo arma el navegador, así que no hay ruta que frenar:
                la casilla "Exportar el historial" se cumple acá. */}
            {puede(user, "historial.exportar") && (
              <ExportButton data={filasExport} filename="historial" label="Exportar" />
            )}
          </div>
        </div>

        {/* Filtros */}
        <div className="hist-filters-card">
          <div className="hist-filters-row">
            <div className="hist-search-wrap">
              <Search className="hist-search-icon" size={18} />
              <input
                type="text" placeholder="Buscar por acción, detalle o nombre"
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
                {conteos[c.key] ? (
                  <span className={`hist-cat-count ${categoria === c.key ? "hist-cat-count-active" : ""}`}>
                    {conteos[c.key]}
                  </span>
                ) : null}
              </button>
            ))}
          </div>
        </div>

        {avisoError && !cargando && (
          <p className="hist-alerta" role="status">
            {avisoError}
            <button onClick={refrescar} className="hist-alerta-reintento">Reintentar</button>
          </p>
        )}

        {cargandoInicial ? (
          <div className="hist-loading">Cargando datos…</div>
        ) : logs.length === 0 ? (
          <div className="hist-empty">
            <div className="hist-empty-inner">
              <Inbox size={42} className="hist-empty-icon" />
              <p className="hist-empty-text">{hayFiltros ? "No se encontraron registros con esos filtros." : "Aún no hay actividad registrada."}</p>
            </div>
          </div>
        ) : (
          <>
          <div className="hist-timeline">
            <div className="hist-timeline-line" />
            <div className="hist-list">
              {logs.map((log, i) => {
                const style = COLOR_STYLES[log.color] || COLOR_STYLES.slate;
                const Icon = ICON_MAP[log.icono] || Clock;
                return (
                  <div
                    key={log.id}
                    className="hist-item sys-bloque"
                    style={{ animationDelay: `${Math.min((i % PER_PAGE) * 15, 200)}ms` }}
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
                            <span className="hist-actor-role">({log.actor_role_legible || log.actor_role})</span>
                        </span>
                        {log.detalles ? (
                          <span className="hist-motivo">Motivo: {log.detalles}</span>
                        ) : null}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <div className="hist-mas-wrap">
            {puedenCargarMas ? (
              <button onClick={cargarMas} disabled={cargandoMas || cargando} className="hist-btn-mas">
                {cargandoMas ? "Cargando…" : `Cargar más (quedan ${Math.max(total - logs.length, 0)})`}
              </button>
            ) : (
              <span className="hist-mas-fin">Fin del historial</span>
            )}
          </div>
          </>
        )}
      </div>
      {avisoModal}
    </div>
  );
}
