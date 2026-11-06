// ===============================  
// Pagina de gestion de cursos con tarjetas por año, horarios, materias,
// edicion de divisiones y busqueda de profesores.
// ===============================
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useNavigate } from "react-router-dom";
import { motion, AnimatePresence } from "framer-motion";
import { Clock, Users, BookOpen } from "lucide-react";
import ModalHorario from "./ModalHorario";
import ModalMaterias from "./ModalMaterias";
import EditarDivisionesModal from "./EditarDivisionesModal";
import ModalPromocion from "./ModalPromocion";
import ModalBuscarProfesor from "./ModalBuscarProfesor";
import { useAuth } from "../auth/AuthContext";
import { apiAmbito } from "../../compartido/api/auth";
import { getCached, setCache } from "../../compartido/lib/pageCache";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import { apiGet, apiPut } from "../../compartido/lib/api";
import { cargarDivisiones } from "../../compartido/lib/divisiones";
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import { cursoLabel } from "../../compartido/lib/cursos";
import { esGestion } from "../auth/roles";
import useAviso from "../../compartido/componentes/modals/useAviso";
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import { useDeshacerAviso } from "../../compartido/lib/deshacer.jsx";
import "./Cursos.css";

/* ========== Fallback local con años 1 a 6 y sus divisiones ========== */
const DEFAULT_DIVISIONES = {
  1: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  2: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  3: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  4: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  5: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  6: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  7: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
};

/* ========== Componente principal de la pagina Cursos ========== */
const Cursos = () => {
  const [confirmAction, confirmModal] = useConfirmAction();
  const { ofrecerDeshacer } = useDeshacerAviso();
  const [mostrarAviso, , avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const { user } = useAuth();
  const isPreceptor = user?.role === 'preceptor';
  const isProfesor = user?.role === 'profesor';
  const [ambito, setAmbito] = useState(() => getCached('ambito') || null); // { role, asignaciones: [{anio, division}] | null }
  // Evita el destello de todos los cursos mientras llega el ambito del profesor
  const [ambitoListo, setAmbitoListo] = useState(() => !!getCached('ambito'));

  const [loading, setLoading] = useState(true);
  // Mapa de divisiones disponibles por año (editable via modal)
  const [divisionesPorAnio, setDivisionesPorAnio] = useState(DEFAULT_DIVISIONES);
  const getDivisiones = (anioId) => divisionesPorAnio[anioId] || ["1", "2", "3", "4", "5", "6", "7"];

  // Modales
  const [modalVisible, setModalVisible] = useState(false);
  const [showMateriasGlobal, setShowMateriasGlobal] = useState(false);
  const [cargandoMateriasGlobal, setCargandoMateriasGlobal] = useState(false);
  const [cursoActual, setCursoActual] = useState(null);
  const [cargandoHorario, setCargandoHorario] = useState(false);
  const [showEditDiv, setShowEditDiv] = useState(false);
  const [showPromo, setShowPromo] = useState(false);

  // NUEVO: modal de búsqueda + resaltado + estado persistente
  const [showBuscarProf, setShowBuscarProf] = useState(false);
  const [highlightTarget, setHighlightTarget] = useState(null);
  const [cameFromSearch, setCameFromSearch] = useState(false);      // ⬅️ controla el botón "Volver a búsqueda"
  const [buscarProfState, setBuscarProfState] = useState(null);     // ⬅️ snapshot de búsqueda (q, filtro, resultados, selección)

  // Todos los años empiezan expandidos
  const [expandedYears, setExpandedYears] = useState(() => new Set());
  const toggleYear = (id) => {
    setExpandedYears((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  // Datos cacheados
  const [materiasPorAnio, setMateriasPorAnio] = useState({});
  const [horariosPorCurso, setHorariosPorCurso] = useState({});

  // Cache local de materias por año: timestamps en memoria + pageCache (TTL 1 hora)
  const materiasTsRef = useRef({});
  // Ref de los años ya cargados: la revalidación al volver los refresca.
  const materiasPorAnioRef = useRef(materiasPorAnio);
  materiasPorAnioRef.current = materiasPorAnio;
  const MATERIAS_TTL_MS = 60 * 60 * 1000;
  const materiasCacheKey = (id) => `materias/curso/${id}`;


  // Carga inicial: divisiones del backend y ambito del usuario logueado
  // Carga inicial: divisiones del backend y ambito del usuario logueado
  useEffect(() => {
    (async () => {
      try {
        const [normalizado, ambitoData] = await Promise.all([
          cargarDivisiones(),
          apiAmbito().catch(() => ({ asignaciones: [] })),
        ]);
        setDivisionesPorAnio((prev) => ({ ...prev, ...normalizado }));
        setAmbito(ambitoData);
        setAmbitoListo(true);
        if (ambitoData?.role) setCache('ambito', ambitoData);
      } catch {
        setAmbitoListo(true);
      }
      setLoading(false);
    })();
  }, []);

  // Si en otra pantalla o equipo se tocaron cursos, divisiones o materias, al
  // volver la pantalla se recarga sola (el botón queda para cuando se edita).
  const actualizarTodo = async () => {
    const pedidos = [
      cargarDivisiones({ forzar: true }).then((normalizado) => {
        if (Object.keys(normalizado || {}).length) {
          setDivisionesPorAnio((prev) => ({ ...prev, ...normalizado }));
        }
      }).catch(() => {}),
      apiAmbito().then((ambitoData) => {
        if (ambitoData?.role) {
          setAmbito(ambitoData);
          setAmbitoListo(true);
          setCache('ambito', ambitoData);
        }
      }).catch(() => {}),
    ];
    const anios = Object.keys(materiasPorAnioRef.current);
    anios.forEach((anio) => {
      pedidos.push(
        apiGet(`/anios/${anio}/materias`).then((mats) => {
          const lista = mats || [];
          setMateriasPorAnio((prev) => ({ ...prev, [anio]: lista }));
          setCache(materiasCacheKey(anio), lista);
          materiasTsRef.current[anio] = Date.now();
        }).catch(() => {})
      );
    });
    await Promise.all(pedidos);
  };
  useAvisoDeCambios("cursos", ["cursos", "alumnos"], actualizarTodo);

  // Determina si el usuario puede editar un curso específico
  const puedeEditarCurso = (anio, division) => {
    if (!user) return false;
    if (esGestion(user.role)) return true;
    if (user.role === 'preceptor' && ambito?.asignaciones?.length) {
      return ambito.asignaciones.some(a => a.anio === anio && (a.division || '').toUpperCase() === (division || '').toUpperCase());
    }
    return false;
  };

  // Determina los niveles (años) a mostrar segun el ambito del usuario y las divisiones disponibles
  const nivelesUI = useMemo(() => {
    const base = [1,2,3,4,5,6,7];
    const extras = Object.keys(divisionesPorAnio)
      .map(n => Number(n))
      .filter(n => Number.isFinite(n) && n > 7 && (divisionesPorAnio[n]?.length ?? 0) > 0);
    const todos = Array.from(new Set([...base, ...extras])).sort((a,b)=>a-b);
    let years = todos;
    // Profesor: solo ve años asignados. Preceptor ve todos.
    if (isProfesor && ambito?.asignaciones?.length) {
      const allowed = new Set(ambito.asignaciones.map(a => a.anio));
      years = todos.filter(y => allowed.has(y));
    }
    return years.map((id) => ({ id, nombre: `${cursoLabel({ anio: id, division: null })} Año` }));
  }, [divisionesPorAnio, ambito]);

  const navigate = useNavigate();

  // Helper: refetch de TODOS los horarios de un año
  const reloadHorariosDeAnio = async (anio) => {
    const divisiones = divisionesPorAnio[anio] || [];
    if (!divisiones.length) return;
    try {
      const pairs = await Promise.all(
        divisiones.map(async (div) => {
          const data = await apiGet(`/horarios/${anio}/${div}`);
          return [`${anio}-${div}`, data || {}];
        })
      );
      setHorariosPorCurso((prev) => {
        const next = { ...prev };
        for (const [key, horario] of pairs) next[key] = horario;
        return next;
      });
    } catch (e) {
      console.error("[Cursos] No se pudieron recargar horarios del año", anio, e);
    }
  };

  const cargarMateriasYHorario = async (anio, division) => {
    const [mats, data] = await Promise.all([
      apiGet(`/anios/${anio}/materias`).catch(() => []),
      apiGet(`/horarios/${anio}/${division}`).catch(() => ({})),
    ]);
    setMateriasPorAnio((prev) => ({ ...prev, [anio]: mats || [] }));
    setCache(materiasCacheKey(anio), mats || []);
    materiasTsRef.current[anio] = Date.now();
    setHorariosPorCurso((prev) => ({ ...prev, [`${anio}-${division}`]: data || {} }));
  };

  const [searchParams] = useSearchParams();
  const deepAbiertoRef = useRef(false);

  // Deep link ?anio=&division= (atajos, buscador): abre el horario directo.
  useEffect(() => {
    if (deepAbiertoRef.current) return;
    const a = searchParams.get("anio");
    const d = searchParams.get("division");
    if (a && d) {
      deepAbiertoRef.current = true;
      abrirModalHorario(a, d);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const abrirModalHorario = async (anio, division) => {
    // Abrimos el modal de inmediato y cargamos los datos en segundo plano
    // para que no haya demora visible al presionar el boton.
    setHighlightTarget(null);
    setCursoActual({ anio, division });
    setCameFromSearch(false);
    setCargandoHorario(true);
    setModalVisible(true);
    try {
      await cargarMateriasYHorario(anio, division);
    } catch (e) {
      console.error("No se pudo cargar el horario", e);
    } finally {
      setCargandoHorario(false);
    }
  };

  const abrirHorarioDeCurso = async (anio, division) => {
    setCursoActual({ anio, division });
    setCargandoHorario(true);
    setModalVisible(true);
    try {
      await cargarMateriasYHorario(anio, division);
    } catch (e) {
      console.error("No se pudo cargar el horario", e);
    } finally {
      setCargandoHorario(false);
    }
  };

  // Materias: se gestionan por curso (año), no por división.
  // Carga las materias de TODOS los años y abre un único modal con pestañas por año.
  const abrirModalMateriasGlobal = async () => {
    setShowMateriasGlobal(true);

    const ahora = Date.now();
    const listo = {};
    const faltantes = [];
    nivelesUI.forEach((n) => {
      const ts = materiasTsRef.current[n.id] || 0;
      if (Array.isArray(materiasPorAnio[n.id]) && ahora - ts < MATERIAS_TTL_MS) {
        listo[n.id] = materiasPorAnio[n.id]; // fresco en memoria
        return;
      }
      const c = getCached(materiasCacheKey(n.id)); // vence a la hora de su escritura
      if (Array.isArray(c)) {
        listo[n.id] = c;
        materiasTsRef.current[n.id] = ahora;
      } else {
        faltantes.push(n.id);
      }
    });
    if (Object.keys(listo).length) {
      setMateriasPorAnio((prev) => ({ ...prev, ...listo }));
    }
    if (faltantes.length === 0) return; // todo vigente: apertura instantánea

    // Faltan años (primera vez o vencidos): se piden solo esos, en segundo plano.
    setCargandoMateriasGlobal(true);
    try {
      const listas = await Promise.all(
        faltantes.map(async (id) => {
          try {
            const lista = await apiGet(`/anios/${id}/materias`);
            const arr = Array.isArray(lista) ? lista : [];
            setCache(materiasCacheKey(id), arr);
            materiasTsRef.current[id] = Date.now();
            return [id, arr];
          } catch {
            // Error puntual (red/servidor): NO se cachea para reintentar en la próxima apertura.
            return [id, []];
          }
        })
      );
      setMateriasPorAnio((prev) => ({ ...prev, ...Object.fromEntries(listas) }));
    } finally {
      setCargandoMateriasGlobal(false);
    }
  };

  return (
    <div className="cursos-page">
      <div className="cursos-breadcrumbs-wrap">
        <Breadcrumbs items={[{ label: "Cursos" }]} />
      </div>
      <div className="cursos-header">
        <h1 className="cursos-title">Cursos</h1>
        <div className="cursos-header-actions">
          {/* Visible para todos los roles: el backend recorta los cursos
              a los horarios que cada usuario puede ver. */}
          <motion.button
            onClick={() => setShowBuscarProf(true)}
            whileTap={{ scale: 0.97 }}
            className="cursos-btn-outline"
          >
            Buscar profesor
          </motion.button>
          {!isPreceptor && !isProfesor && (
            <>
              <motion.button
                onClick={() => setShowEditDiv(true)}
                whileTap={{ scale: 0.97 }}
                className="cursos-btn-outline"
              >
                Editar divisiones
              </motion.button>
              <motion.button
                onClick={() => abrirModalMateriasGlobal()}
                whileTap={{ scale: 0.97 }}
                className="cursos-btn-outline"
              >
                Materias
              </motion.button>
              <motion.button
                onClick={() => setShowPromo(true)}
                whileTap={{ scale: 0.97 }}
                className="cursos-btn-outline"
              >
                Cambio de ciclo
              </motion.button>
            </>
          )}
        </div>
      </div>

      {/* Lista de cursos agrupada por año */}
      <div className="cursos-list">
        {nivelesUI.map((nivel) => {
          let divisionesDisponibles = getDivisiones(nivel.id);
          if (isProfesor && ambito?.asignaciones?.length) {
            const allowed = new Set(
              ambito.asignaciones.filter(a => a.anio === nivel.id).map(a => a.division)
            );
            divisionesDisponibles = divisionesDisponibles.filter(d => allowed.has(d));
          }
          if (!divisionesDisponibles.length) return null;

          const isOpen = expandedYears.has(nivel.id);
          return (
            <div key={nivel.id} className="cursos-group">
              <div
                onClick={() => toggleYear(nivel.id)}
                className="cursos-year-header group"
              >
                <motion.svg
                  width="24" height="24" viewBox="0 0 20 20"
                  fill="none" stroke="currentColor" strokeWidth="2"
                  strokeLinecap="round" strokeLinejoin="round"
                  animate={{ rotate: isOpen ? 90 : 0 }}
                  transition={{ duration: 0.2 }}
                  className="cursos-chevron"
                >
                  <path d="M7 4l6 6-6 6" />
                </motion.svg>
                <h2 className="cursos-year-title">
                  {nivel.nombre}
                </h2>
                <span className="cursos-year-count">
                  {divisionesDisponibles.length} {divisionesDisponibles.length === 1 ? 'división' : 'divisiones'}
                </span>
              </div>
              <AnimatePresence initial={false}>
                {isOpen && (
                  <motion.div
                    key="content"
                    initial={{ height: 0, opacity: 0 }}
                    animate={{ height: 'auto', opacity: 1 }}
                    exit={{ height: 0, opacity: 0 }}
                    transition={{ duration: 0.25, ease: 'easeInOut' }}
                    className="cursos-year-collapse"
                  >
                    <div className="cursos-year-content">
                      {divisionesDisponibles.map((division) => (
                        <div
                          key={division}
                          className="cursos-card-curso"
                        >
                          <span className="cursos-card-nombre">
                            {cursoLabel({ anio: nivel.id, division })}
                          </span>
                          <div className="cursos-card-actions">
                            <motion.button
                              onClick={() => abrirModalHorario(nivel.id, division)}
                              whileTap={{ scale: 0.95 }}
                              className="cursos-btn-accion cursos-btn-horario"
                            >
                              <Clock size={16} />
                              Horarios y Materias
                            </motion.button>
                            <motion.button
                              onClick={() => navigate(`/alumnos?anio=${nivel.id}&division=${division}`)}
                              whileTap={{ scale: 0.95 }}
                              className="cursos-btn-accion cursos-btn-alumnos"
                            >
                              <Users size={16} />
                              Alumnos
                            </motion.button>
                          </div>
                        </div>
                      ))}
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>
            </div>
          );
        })}

        {/* Para profesores no se muestra nada hasta que llega el ambito:
            asi nunca se ven cursos fuera de su asignacion */}
        {(loading && Object.keys(divisionesPorAnio).length === 0) || (isProfesor && !ambitoListo) ? (
          <div className="cursos-loading">Cargando datos...</div>
        ) : nivelesUI.every((n) => {
          const divs = getDivisiones(n.id);
          const allowed = isProfesor && ambito?.asignaciones?.length
            ? new Set(ambito.asignaciones.filter(a => a.anio === n.id).map(a => a.division))
            : null;
          const filtered = allowed ? divs.filter(d => allowed.has(d)) : divs;
          return !filtered.length;
        }) && (
          <div className="cursos-empty">
            <p className="cursos-empty-text">No hay cursos disponibles</p>
          </div>
        )}
      </div>

        {/* Modal de horario */}
      <ModalHorario
        visible={modalVisible}
        loading={cargandoHorario}
        onClose={() => { setModalVisible(false); setHighlightTarget(null); }}
        curso={cursoActual}
        horario={horariosPorCurso[`${cursoActual?.anio}-${cursoActual?.division}`] || []}
        setHorario={(nuevo) =>
          setHorariosPorCurso((prev) => ({
            ...prev,
            [`${cursoActual?.anio ?? ''}-${cursoActual?.division ?? ''}`]: nuevo
          }))
        }
        materiasDisponibles={materiasPorAnio[cursoActual?.anio] || []}
        canEdit={cursoActual ? puedeEditarCurso(cursoActual.anio, cursoActual.division) : false}
        highlightTarget={highlightTarget}
        onBackToSearch={
          cameFromSearch
            ? () => {
                setModalVisible(false);
                setShowBuscarProf(true);
              }
            : undefined
        }
      />

      {/* Modal de materias (por curso/año, con pestañas) */}
      <ModalMaterias
        visible={showMateriasGlobal}
        loading={cargandoMateriasGlobal}
        onClose={() => setShowMateriasGlobal(false)}
        anios={nivelesUI}
        materiasPorAnio={materiasPorAnio}
        setMaterias={(anio, nuevas) => {
          // 1) Actualizar materias del año (estado + cache local)
          const prevLista = materiasPorAnio[anio] || [];
          setMateriasPorAnio((prev) => ({
            ...prev,
            [anio]: nuevas,
          }));
          setCache(materiasCacheKey(anio), nuevas || []);
          materiasTsRef.current[anio] = Date.now();

          // 2) Detectar eliminadas
          const prevNames = new Set(prevLista.map((m) => m.nombre));
          const newNames  = new Set(nuevas.map((m) => m.nombre));
          const eliminadas = [...prevNames].filter((n) => !newNames.has(n));

          // 3) Limpiar esas celdas en todos los horarios del año
          if (eliminadas.length) {
            setHorariosPorCurso((prev) => {
              const out = { ...prev };
              const divisiones = divisionesPorAnio[anio] || [];
              divisiones.forEach((div) => {
                const key = `${anio}-${div}`;
                const h = out[key];
                if (!h) return;

                const nuevo = {};
                Object.entries(h).forEach(([slot, diasObj]) => {
                  const nd = { ...diasObj };
                  Object.entries(diasObj || {}).forEach(([dia, val]) => {
                    const materia = typeof val === "string" ? val : (val?.materia || "");
                    if (eliminadas.includes(materia)) {
                      nd[dia] = { materia: "", profesorId: "" };
                    }
                  });
                  nuevo[slot] = nd;
                });

                out[key] = nuevo;
              });
              return out;
            });
          }

          // 4) Refrescar horarios del año
          (async () => { await reloadHorariosDeAnio(anio); })();
        }}
      />

      {/* Modal para editar divisiones */}
      <EditarDivisionesModal
        visible={showEditDiv}
        onClose={() => setShowEditDiv(false)}
        value={divisionesPorAnio}
        onSave={async (nuevo) => {
          // El servidor exige contraseña reciente para cambios masivos.
          if (!(await confirmarContrasena())) return;
          try {
            const detalles = await confirmAction('¿Qué cambios estás haciendo en las divisiones?', async (d) => {
              const data = await apiPut("/divisiones", { ...nuevo, _detalles: d });
              const normalizado = Object.fromEntries(
                Object.entries(data).map(([k, v]) => [Number(k), v])
              );
              setDivisionesPorAnio(normalizado);
            });
            if (detalles === null) return;
            const anterior = divisionesPorAnio;
            // Deshacer: restaura las divisiones previas.
            ofrecerDeshacer(
              {
                etiqueta: 'Divisiones',
                deshacer: async () => {
                  const revert = await apiPut('/divisiones', { ...anterior, _detalles: 'Deshace el último guardado' });
                  const revertNorm = Object.fromEntries(
                    Object.entries(revert || {}).map(([k, v]) => [Number(k), v])
                  );
                  setDivisionesPorAnio(revertNorm);
                  await Promise.all(Object.keys(anterior || {}).map((a) => reloadHorariosDeAnio(Number(a))));
                },
              },
              'Cambios guardados correctamente'
            );
          } catch {
            await mostrarAviso({ icon: 'error', title: 'Error', text: 'No se pudieron guardar los cambios.' });
          }
        }}
      />

      {/* Modal Cambio de ciclo */}
      <ModalPromocion
        visible={showPromo}
        onClose={() => setShowPromo(false)}
        onDone={async ({ divisiones } = {}) => {
          try {
            let mapa = divisiones;
            if (!mapa) mapa = await cargarDivisiones({ forzar: true });
            const normalizado = Object.fromEntries(
              Object.entries(mapa).map(([k, v]) => [Number(k), v])
            );
            setDivisionesPorAnio((prev) => ({ ...prev, ...normalizado }));
          } catch {
            await mostrarAviso({ icon: 'warning', title: 'Atención', text: 'La promoción se aplicó, pero no se pudo actualizar la grilla de cursos.' });
          }
        }}
      />

      {/* Modal de búsqueda de profesor (con estado persistente) */}
      <ModalBuscarProfesor
        visible={showBuscarProf}
        onClose={() => setShowBuscarProf(false)}
        initialState={buscarProfState || undefined}
        onSnapshot={(st) => setBuscarProfState(st)}
        onPick={async (m) => {
          setHighlightTarget({
            profesorId: m.profesorId,
            profesorNombre: m.profesorNombre,
            materiaNombre: m.materiaNombre,
            dia: m.dia,
            slotKey: m.slot_key,
            color: "rgba(59,130,246,.14)"
          });
          setCameFromSearch(true);             
          await abrirHorarioDeCurso(m.anio, m.division);
        }}
      />
      {confirmModal}
      {avisoModal}
      {modalPwd}
    </div>
  );
};

export default Cursos;
