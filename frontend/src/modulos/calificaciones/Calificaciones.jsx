// Pagina de carga de calificaciones por curso y materia.
// Permite seleccionar una materia, ver los alumnos y cargar notas por periodo.
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Search, GraduationCap, BookOpen, Lock, Printer, ChevronRight, CalendarClock, Sheet } from 'lucide-react';
import { coincideBusqueda } from '../../compartido/lib/busqueda';
import "./Calificaciones.css";

import DropdownFiltroAnios from "../alumnos/DropdownFiltroAnios";
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import DropdownMini from "../../compartido/componentes/Common/Dropdownmini";
import FormularioCalificaciones from "./FormularioCalificaciones";
import PlanillaModal from "./PlanillaModal";
import { createApiGate } from "../../compartido/lib/apiGate";
import { getCached, setCache } from "../../compartido/lib/pageCache";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import { useAuth } from '../auth/AuthContext';
import { apiAmbito } from '../../compartido/api/auth';
import { apiGet, API_BASE } from '../../compartido/lib/api';
import { cargarDivisiones } from '../../compartido/lib/divisiones';
import { cursoLabel, parseCurso } from '../../compartido/lib/cursos';
import VentanaNotaFinalModal from './VentanaNotaFinalModal';

// Gate de API con control de concurrencia
const api = createApiGate(API_BASE, { maxConcurrent: 6 });

function useDebounce(value, delay = 180) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return v;
}

// Componente principal de la pagina de Calificaciones
export default function Calificaciones() {
  const [planillaModal, setPlanillaModal] = useState(null);
  // Planilla real (formato papel) en modo edición para cargar notas.
  const [planillaReal, setPlanillaReal] = useState(null);
  const [expanded, setExpanded] = useState({});

  const [searchParams] = useSearchParams();
  // Un solo curso a la vez, igual que en Alumnos ('todos' = todos los cursos).
  const [filtroCurso, setFiltroCurso] = useState(() => {
    const qAnio = searchParams.get('anio');
    const qDiv = searchParams.get('division');
    if (qAnio) {
      try { return cursoLabel({ anio: qAnio, division: qDiv || null }); } catch { /* cae a 'todos' */ }
    }
    return 'todos';
  });
  const [busqueda, setBusqueda] = useState('');
  const busquedaDebounced = useDebounce(busqueda, 180);
  const [ciclo, setCiclo] = useState(new Date().getFullYear());

  const { user } = useAuth();
  const isProfesor = user?.role === 'profesor';
  const puedeConfigurarVentana = ['admin', 'jefe_preceptores'].includes(user?.role);
  const [ambito, setAmbito] = useState(() => getCached('ambito') || null);

  const [ventana, setVentana] = useState(null);
  const [showVentanaModal, setShowVentanaModal] = useState(false);

  const ventanaAbierta = !!ventana?.abierta;
  const alcanceVentana = ventana?.alcance === 'p1' || ventana?.alcance === 'p2' ? ventana.alcance : 'ambos';
  const p1Habilitado = ventanaAbierta && (ventana?.abierta_p1 ?? (alcanceVentana === 'ambos' || alcanceVentana === 'p1'));
  const p2Habilitado = ventanaAbierta && (ventana?.abierta_p2 ?? (alcanceVentana === 'ambos' || alcanceVentana === 'p2'));
  // Compat: la prop vieja queda como "alguno habilitado".
  const finalHabilitado = !!(p1Habilitado || p2Habilitado);
  const alcanceLabel = alcanceVentana === 'p1' ? 'Solo 1er cuat.' : alcanceVentana === 'p2' ? 'Solo 2do cuat.' : 'Los dos cuat.';

  useEffect(() => {
    let alive = true;
    apiGet('/calificaciones/ventana')
      .then((d) => { if (alive) setVentana(d || null); })
      .catch(() => { if (alive) setVentana(null); });
    return () => { alive = false; };
  }, []);

  const cachedDivs = () => getCached('divisiones');
  const cachedMats = () => getCached('materias/todas');
  const [loading, setLoading] = useState(() => !(cachedDivs() && cachedMats() && getCached('ambito')));
  // Sube cada vez que el refresco automático recarga las notas abiertas.
  const [notasKey, setNotasKey] = useState(0);
  const [divisionesMap, setDivisionesMap] = useState(() => cachedDivs() || {});
  const [materiasByYear, setMateriasByYear] = useState(() => cachedMats() || {});

  const yearsInDivisiones = useMemo(() =>
    Object.keys(divisionesMap).map(Number).filter(Number.isFinite).sort((a,b) => a - b),
    [divisionesMap]
  );

  // Carga inicial. Si hay copia guardada, la pantalla se ve al instante y los
  // datos frescos llegan por detrás (sin cartel de carga); si no, se espera.
  useEffect(() => {
    let cancel = false;
    (async () => {
      const [mapa, ambitoData, todas] = await Promise.all([
        cargarDivisiones().catch(() => ({})),
        apiAmbito().catch(() => ({ asignaciones: [] })),
        api.get('/materias/todas').catch(() => ({})),
      ]);
      if (cancel) return;
      if (mapa) setDivisionesMap(mapa);
      setAmbito(ambitoData);
      setMateriasByYear(todas);
      setCache('materias/todas', todas || {});
      // Solo cacheamos el ambito si trajo datos reales (tiene role); no cachear
      // un fallback vacio producto de un error de red (evita "envenenar" la cache).
      if (ambitoData && ambitoData.role) {
        setCache('ambito', ambitoData);
      }
      if (!cancel) setLoading(false);
    })();
    return () => { cancel = true; };
  }, []);

  // Si en otra pantalla o equipo se tocaron notas, alumnos o cursos, la lista se
  // actualiza sola; con el formulario de notas abierto espera a que se cierre.
  const actualizarTodo = async () => {
    // Las notas abiertas se vuelven a pedir (por si otro profesor las cambió).
    setNotasKey((k) => k + 1);
    const [mapa, todas, ambitoData] = await Promise.all([
      cargarDivisiones({ forzar: true }).catch(() => null),
      api.get('/materias/todas').catch(() => null),
      apiAmbito().catch(() => null),
    ]);
    if (mapa) { setDivisionesMap(mapa); }
    if (todas) { setMateriasByYear(todas); setCache('materias/todas', todas); }
    if (ambitoData && ambitoData.role) { setAmbito(ambitoData); setCache('ambito', ambitoData); }
  };
  useAvisoDeCambios(
    "calificaciones",
    ["calificaciones", "alumnos", "cursos"],
    actualizarTodo
  );

  const cursosDisponibles = useMemo(() => {
    return yearsInDivisiones.map(n => ({
      anio: cursoLabel({ anio: n, division: null }),
      divisiones: Array.isArray(divisionesMap[n]) ? divisionesMap[n] : (Array.isArray(divisionesMap[String(n)]) ? divisionesMap[String(n)] : []),
    }));
  }, [yearsInDivisiones, divisionesMap]);

  const puedeEditarCurso = (anio, division) => {
    if (!user) return false;
    if (['admin', 'directivo', 'jefe_preceptores'].includes(user.role)) return true;
    if (ambito?.asignaciones?.length) {
      return ambito.asignaciones.some(a => Number(a.anio) === Number(anio) && (a.division || '').toUpperCase() === (division || '').toUpperCase());
    }
    return false;
  };

  const subjectsList = useMemo(() => {
    if (!divisionesMap || !materiasByYear) return [];
    const list = [];
    // Profesor: materias que ensena segun su ficha (vinculo por email).
    // Si no hay datos, se mantiene el comportamiento previo (todas las materias de sus cursos).
    const matsProfe = isProfesor && Array.isArray(ambito?.materias) ? ambito.materias : null;
    const usaMats = matsProfe && matsProfe.length > 0;
    for (const [yearStr, divisions] of Object.entries(divisionesMap)) {
      const year = parseInt(yearStr, 10);
      if (!Number.isFinite(year)) continue;
      // Pre-index materias del año fuera del loop de divisiones
      const materias = materiasByYear[year] || materiasByYear[String(year)] || [];
      for (const div of divisions) {
        const divUpper = String(div).trim().toUpperCase();
        // Profesor: solo ve cursos asignados
        if (isProfesor && ambito?.asignaciones?.length) {
          const asignado = ambito.asignaciones.some(a => Number(a.anio) === year && ((a.division || '').toUpperCase() === divUpper));
          if (!asignado) continue;
        }
        // Preceptor: ve todos los cursos en la lista, solo puede editar los asignados
        for (const m of materias) {
          // Profesor: dentro del curso, solo la materia que ensena
          if (usaMats && !matsProfe.some(a =>
            Number(a.anio) === year &&
            String(a.division || '').toUpperCase() === divUpper &&
            Number(a.materia_id) === Number(m.id)
          )) continue;
          list.push({
            anio: year,
            division: divUpper,
            materia_id: m.id,
            materia_nombre: m.nombre,
            canEdit: puedeEditarCurso(year, divUpper),
          });
        }
      }
    }
    return list;
  }, [divisionesMap, materiasByYear, ambito, isProfesor, user]);

  // Coincide un curso/materia con el filtro único (año solo o año+división).
  const coincideCurso = useCallback((anio, division) => {
    if (!filtroCurso || filtroCurso === 'todos') return true;
    const tParsed = parseCurso(filtroCurso);
    if (!tParsed.anio) return true;
    const itemCourse = cursoLabel({ anio, division });
    const itemYear = cursoLabel({ anio, division: null });
    if (!tParsed.division) return parseCurso(itemYear).anio === tParsed.anio;
    return parseCurso(itemCourse).anio === tParsed.anio &&
      parseCurso(itemCourse).division === tParsed.division;
  }, [filtroCurso]);

  const filteredSubjects = useMemo(() => {
    return subjectsList.filter(item => {
      if (!coincideCurso(item.anio, item.division)) return false;
      // Búsqueda que perdona: "matematica" encuentra "Matemática".
      if (busquedaDebounced.trim() && !coincideBusqueda(item.materia_nombre, busquedaDebounced)) return false;
      return true;
    });
  }, [subjectsList, coincideCurso, busquedaDebounced]);

  // Agrupa por curso+división usando TODOS los años de divisiones (1°-7°),
  // aunque un curso aún no tenga materias cargadas. Así el 7° aparece en la lista.
  const groups = useMemo(() => {
    const q = busquedaDebounced.trim().toLowerCase();
    const map = new Map();

    // Reutiliza yearsInDivisiones para evitar recalcular Object.entries(divisionesMap)
    for (const year of yearsInDivisiones) {
      const divisions = divisionesMap[year] ?? divisionesMap[String(year)];
      const divList = Array.isArray(divisions) ? divisions : [];
      for (const div of divList) {
        const divUpper = String(div).trim().toUpperCase();
        if (isProfesor && ambito?.asignaciones?.length) {
          const asignado = ambito.asignaciones.some(
            (a) => Number(a.anio) === year && (a.division || '').toUpperCase() === divUpper
          );
          if (!asignado) continue;
        }
        if (!coincideCurso(year, divUpper)) continue;
        const key = `${year}|${divUpper}`;
        if (!map.has(key)) {
          map.set(key, {
            key,
            anio: year,
            division: divUpper,
            label: cursoLabel({ anio: year, division: divUpper }),
            materias: [],
          });
        }
      }
    }

    for (const item of filteredSubjects) {
      const g = map.get(`${item.anio}|${item.division}`);
      if (g) g.materias.push(item);
    }

    const result = [];
    for (const g of map.values()) {
      if (q && g.materias.length === 0) continue;
      result.push(g);
    }
    result.sort((a, b) => a.anio - b.anio || String(a.division).localeCompare(String(b.division)));
    return result;
  }, [divisionesMap, yearsInDivisiones, filteredSubjects, isProfesor, ambito, coincideCurso, busquedaDebounced]);


  return (
    <div className="calif-page">
      <div className="calif-container">
        <>
            <div className="calif-header">
              <div>
                <Breadcrumbs items={[{ label: "Calificaciones" }]} />
                <h1 className="calif-title">Calificaciones</h1>
                <p className="calif-subtitle">Seleccioná una materia y curso para cargar notas</p>
              </div>
              {puedeConfigurarVentana && (
                <div className="calif-ventana-box">
                  <button
                    type="button"
                    className="calif-btn-ventana"
                    onClick={() => setShowVentanaModal(true)}
                  >
                    <CalendarClock size={16} /> Configurar ventana de nota final
                  </button>
                  <span className={`calif-ventana-estado ${ventanaAbierta ? 'abierta' : 'cerrada'}`}>
                    {ventana == null
                      ? 'Sin ventana configurada'
                      : ventanaAbierta
                        ? `Ventana ABIERTA (${alcanceLabel}) hasta ${ventana.fecha_fin ? ventana.fecha_fin.replace('T', ' ').slice(0, 16) : ''}`
                        : 'Ventana CERRADA'}
                  </span>
                </div>
              )}
            </div>

            <div className="calif-card">
              <div className="calif-filters">
                <div className="calif-search-wrap">
                  <Search className="calif-search-icon" size={20} />
                  <input
                    type="text" placeholder="Buscar materia…" value={busqueda}
                    onChange={(e) => setBusqueda(e.target.value)}
                    className="calif-search-input"
                  />
                </div>
                <div className="calif-dropdown-year">
                  <DropdownFiltroAnios
                    value={filtroCurso}
                    onChange={setFiltroCurso}
                    cursosDisponibles={cursosDisponibles}
                  />
                </div>
                <div className="calif-dropdown-small">
                  <DropdownMini
                    value={ciclo} onChange={(v) => setCiclo(parseInt(v, 10))} placeholder="Ciclo" width={120}
                    opciones={(() => { const y = new Date().getFullYear(); const arr = []; for (let i = y + 2; i >= y - 4; i--) arr.push({ value: i, label: String(i) }); return arr; })()}
                  />
                </div>
              </div>
            </div>

            {loading && groups.length === 0 ? (
              <div className="calif-loading">Cargando datos...</div>
            ) : (
              <div className="calif-table-card">
                <div className="calif-accordion">
                  {groups.map((g) => {
                    const open = busqueda.trim() ? true : !!expanded[g.key];
                    return (
                      <div className="calif-group" key={g.key}>
                        <button
                          type="button"
                          className="calif-group-header"
                          onClick={() => setExpanded((p) => ({ ...p, [g.key]: !p[g.key] }))}
                        >
                          <ChevronRight size={18} className={`calif-chevron ${open ? 'open' : ''}`} />
                          <span className="calif-group-title">
                            <BookOpen size={16} /> {g.label}
                          </span>
                          <span className="calif-group-count">{g.materias.length} materia(s)</span>
                        </button>
                        {open && (
                          <div className="calif-group-body">
                            {g.materias.length === 0 && (
                              <div className="calif-empty-materias">
                                No hay materias cargadas para este curso.
                              </div>
                            )}
                            {g.materias.map((item) => (
                              <div className="calif-materia-row" key={item.materia_id}>
                                <span className="calif-materia-nombre">{item.materia_nombre}</span>
                                <span className="calif-materia-accion">
                                  {item.canEdit === false ? (
                                    <span className="calif-lock-badge">
                                      <Lock size={12} /> Solo lectura
                                    </span>
                                  ) : (
                                    <>
                                      <button
                                        onClick={() =>
                                          setPlanillaModal({
                                            anio: item.anio,
                                            division: item.division,
                                            materiaId: item.materia_id,
                                            materiaNombre: item.materia_nombre,
                                            ciclo,
                                          })
                                        }
                                        className="calif-btn-agregar"
                                      >
                                        <Printer size={14} /> Cargar calificaciones
                                      </button>
                                      <button
                                        onClick={() =>
                                          setPlanillaReal({
                                            anio: item.anio,
                                            division: item.division,
                                            materiaId: item.materia_id,
                                            materiaNombre: item.materia_nombre,
                                            ciclo,
                                          })
                                        }
                                        className="calif-btn-agregar calif-btn-planilla"
                                        title="Abrir la planilla real (formato papel) para cargar las notas"
                                      >
                                        <Sheet size={14} /> Planilla
                                      </button>
                                    </>
                                  )}
                                </span>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    );
                  })}
                  {groups.length === 0 && (
                    <div className="calif-empty-state">
                      <GraduationCap size={32} className="calif-empty-icon" />
                      <p className="calif-empty-title">
                        {busqueda.trim() ? "No encontramos esa materia" : "Todavía no hay nada para mostrar acá"}
                      </p>
                      <p className="calif-empty-text">
                        {busqueda.trim()
                          ? `Probá con menos palabras (por ejemplo "mat" en vez de "${busqueda.trim().slice(0, 30)}").`
                          : "Elegí un curso arriba para ver sus materias y cargar notas."}
                      </p>
                      {busqueda.trim() ? (
                        <button type="button" className="calif-btn-agregar" onClick={() => setBusqueda('')}>
                          Limpiar búsqueda
                        </button>
                      ) : (
                        filtroCurso !== 'todos' && (
                          <button type="button" className="calif-btn-agregar" onClick={() => setFiltroCurso('todos')}>
                            Ver todos los cursos
                          </button>
                        )
                      )}
                    </div>
                  )}
                </div>
              </div>
            )}
          </>
      </div>
      {planillaModal && (
        <FormularioCalificaciones
          anio={planillaModal.anio}
          division={planillaModal.division}
          materiaId={planillaModal.materiaId}
          materiaNombre={planillaModal.materiaNombre}
          ciclo={planillaModal.ciclo}
          finalHabilitado={finalHabilitado}
          p1Habilitado={p1Habilitado}
          p2Habilitado={p2Habilitado}
          recargarKey={notasKey}
          onClose={() => setPlanillaModal(null)}
          onSaved={() => {}}
        />
      )}
      {planillaReal && (
        <PlanillaModal
          anio={planillaReal.anio}
          division={planillaReal.division}
          materiaId={planillaReal.materiaId}
          materiaNombre={planillaReal.materiaNombre}
          ciclo={planillaReal.ciclo}
          editable={false}
          onClose={() => setPlanillaReal(null)}
          onSaved={() => {}}
        />
      )}
      {showVentanaModal && (
        <VentanaNotaFinalModal
          ventana={ventana}
          onClose={() => setShowVentanaModal(false)}
          onGuardado={(v) => { setVentana(v); setShowVentanaModal(false); }}
        />
      )}
    </div>
  );
}
