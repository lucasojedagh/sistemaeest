import { useEffect, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import {
  BarChart3,
  CalendarCheck,
  CalendarDays,
  FileWarning,
  History,
  ClipboardList,
} from "lucide-react";
import { useAuth } from "../auth/AuthContext";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { getCached, setCache } from "../../compartido/lib/pageCache";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import { mensajeAmable } from "../../compartido/lib/errores";
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import TomarAsistencia from "./TomarAsistencia";
import ClasesAsistencia from "./ClasesAsistencia";
import HistorialAsistencia from "./HistorialAsistencia";
import InasistenciasAsistencia from "./InasistenciasAsistencia";
import JustificacionesAsistencia from "./JustificacionesAsistencia";
import EstadisticasAsistencia from "./EstadisticasAsistencia";
import "./Asistencia.css";

const CACHE_KEY = "asistencia:cursos";

const TABS = [
  { id: "tomar", label: "Tomar asistencia", icon: CalendarCheck },
  { id: "clases", label: "Clases", icon: CalendarDays },
  { id: "historial", label: "Historial", icon: History },
  { id: "inasistencias", label: "Inasistencias", icon: FileWarning },
  { id: "justificaciones", label: "Justificaciones", icon: ClipboardList },
  { id: "estadisticas", label: "Estadísticas", icon: BarChart3 },
];

export default function Asistencia() {
  const { user } = useAuth();
  // Atajos del inicio llegan con ?tab=tomar|inasistencias... para caer
  // directo en la tarea (pasar lista, ver inasistencias).
  const [searchParams] = useSearchParams();
  const [tab, setTab] = useState(() => {
    const t = searchParams.get("tab");
    return TABS.some((x) => x.id === t) ? t : "tomar";
  });
  // La URL manda UNA sola vez por valor (?tab=tomar desde un atajo).
  // Sin esto, cada click en otra pestana volvia solo a la de la URL y no se
  // podia navegar: el efecto leia ?tab= y lo reimponia sobre el click.
  const tabAplicadaDeUrl = useRef(null);
  // Si se navega de un atajo a otro (ej: de "Pasar lista" a "Ver inasistencias")
  // sin recargar la página, el tab acompaña a la URL.
  useEffect(() => {
    const t = searchParams.get("tab");
    if (t && TABS.some((x) => x.id === t) && tabAplicadaDeUrl.current !== t) {
      tabAplicadaDeUrl.current = t;
      setTab(t);
    }
  }, [searchParams]);

  // Tabs ya abiertos: se mantienen montados (ocultos) para no recargar datos al navegar.
  const [visited, setVisited] = useState(() => ({ tomar: true, [tab]: true }));
  // Si ya hubo una carga previa, se muestra al instante (caché en memoria/localStorage)
  // y se refresca en segundo plano; si no, se muestra "Cargando datos…".
  const [maps, setMaps] = useState(() => getCached(CACHE_KEY));
  const [error, setError] = useState(null);
  const [initialTomar, setInitialTomar] = useState(null);
  // Cada vez que se guarda en "tomar", las tarjetas de "clases" recargan.
  const [clasesKey, setClasesKey] = useState(0);
  const fetchingRef = useRef(false);

  useEffect(() => {
    if (fetchingRef.current) return;
    fetchingRef.current = true;
    asistenciaApi
      .cursos()
      .then((m) => {
        setMaps(m);
        setCache(CACHE_KEY, m);
        setError(null);
      })
      .catch((e) => {
        // En criollo: apiGate expone "MÉTODO URL CÓDIGO" y eso nunca se muestra.
        setError(mensajeAmable(e, "No se pudieron cargar los cursos. Revisá tu conexión."));
      });
  }, []);

  // Si otra persona pasó lista o tocó alumnos, la pantalla se recarga sola;
  // mientras estás marcando lista espera a que termines.
  const actualizarTodo = () => {
    asistenciaApi.cursos()
      .then((m) => { setMaps(m); setCache(CACHE_KEY, m); setError(null); })
      .catch(() => {});
    setClasesKey((k) => k + 1);
  };
  useAvisoDeCambios(
    "asistencia",
    ["asistencia", "alumnos"],
    actualizarTodo
  );

  const activateTab = (id) => {
    setTab(id);
    setVisited((v) => (v[id] ? v : { ...v, [id]: true }));
  };

  // El tab activo también marca como visitado (por si llegó por URL).
  useEffect(() => {
    setVisited((v) => (v[tab] ? v : { ...v, [tab]: true }));
  }, [tab]);

  const handleClasesEdit = (req) => {
    setInitialTomar(req);
    activateTab("tomar");
  };

  // Al guardar en "tomar": se limpia el pedido de edición y "clases" recarga.
  const handleTomarSaved = () => {
    setInitialTomar(null);
    setClasesKey((k) => k + 1);
  };

  return (
    <div className="asist-page">
      <div className="asist-container">
        <div className="asist-header">
          <div>
            <Breadcrumbs items={[{ label: "Asistencia" }]} />
            <h1 className="asist-title">Asistencia e inasistencias</h1>
            <p className="asist-subtitle">Clases, asistencias y justificaciones por curso.</p>
          </div>
        </div>

        <div className="asist-tabs">
          {TABS.map((t) => {
            const Icon = t.icon;
            const active = tab === t.id;
            return (
              <button
                key={t.id}
                type="button"
                onClick={() => activateTab(t.id)}
                className={`asist-tab ${active ? "asist-tab-active" : "asist-tab-inactive"}`}
              >
                <Icon className="asist-tab-icon" />
                {t.label}
              </button>
            );
          })}
        </div>

        {error && !maps && (
          <div className="asist-error">{error}</div>
        )}

        {!maps && !error && (
          <div className="asist-loading">Cargando datos…</div>
        )}

        {maps && (
          <>
            {TABS.map((t) =>
              visited[t.id] ? (
                <div key={t.id} className={tab === t.id ? "asist-panel" : "asist-panel-hidden"}>
                  {t.id === "tomar" && (
                    <TomarAsistencia maps={maps} initial={tab === "tomar" ? initialTomar : null} onSaved={handleTomarSaved} />
                  )}
                  {t.id === "clases" && <ClasesAsistencia maps={maps} user={user} onEdit={handleClasesEdit} refreshKey={clasesKey} />}
                  {t.id === "historial" && <HistorialAsistencia maps={maps} />}
                  {t.id === "inasistencias" && <InasistenciasAsistencia maps={maps} />}
                  {t.id === "justificaciones" && <JustificacionesAsistencia maps={maps} user={user} />}
                  {t.id === "estadisticas" && <EstadisticasAsistencia maps={maps} />}
                </div>
              ) : null
            )}
          </>
        )}
      </div>
    </div>
  );
}