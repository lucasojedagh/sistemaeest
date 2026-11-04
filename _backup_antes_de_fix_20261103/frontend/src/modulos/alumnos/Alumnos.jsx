// ===============================
// Pagina de gestion de alumnos del sistema escolar.
// Permite filtrar, buscar, agregar y visualizar alumnos con condiciones especiales.
// ===============================
import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { useSearchParams } from "react-router-dom";
import { Search, Plus, Download, SlidersHorizontal, X, Printer } from 'lucide-react';
import { exportCSV } from "../../compartido/utils/export";
import { mensajeDescarga } from "../../compartido/lib/descarga";
import { imprimirUrl, pedirTicketImpresion } from "../../compartido/lib/imprimir";

const RAW_API_BASE = import.meta?.env?.VITE_API_BASE || "http://localhost:8000/api";
const BACKEND_BASE = RAW_API_BASE.replace(/\/api\/?$/, "");
import AlumnoModal from './AlumnoModal';
import AlumnoDetalle from './AlumnoDetalle';
import AlumnoFicha from './AlumnoFicha';
import HorarioAlumnoModal from './HorarioAlumnoModal';
import DropdownFiltroAnios from './DropdownFiltroAnios';
import DropdownFiltroGenero from './DropdownFiltroGenero';
import DropdownFiltroEspecialidad from './DropdownFiltroEspecialidad';
import ListaAlumnos from './ListaAlumnos';
import DropdownMini from '../../compartido/componentes/Common/Dropdownmini';
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import { useAuth } from '../auth/AuthContext';
import { apiAmbito } from '../../compartido/api/auth';
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import { getCached, setCache } from "../../compartido/lib/pageCache";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import BotonNovedades from "../../compartido/componentes/Common/BotonNovedades";
import { apiGet, apiPost, apiDelete } from "../../compartido/lib/api";
import { useDeshacerAviso } from "../../compartido/lib/deshacer.jsx";
import { useToast } from "../../compartido/componentes/Common/Toast";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { coincideBusqueda, coincideDni } from "../../compartido/lib/busqueda";
import { ORDENES, ORDEN_DEFAULT, ordenarPersonas, leerOrden, guardarOrden } from "../../compartido/lib/orden";
import { mensajeAmable } from "../../compartido/lib/errores";
import "../../compartido/styles/Alumnos.css";
import {
  cursoLabel,
  parseCurso,
  cursoEnAsignaciones,
  buildCursosDisponiblesFromMap,
  ESPECIALIDADES,
  especialidadLabel,
} from "../../compartido/lib/cursos";

function useDebounce(value, delay = 180) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return v;
}

/* Construye la lista de cursos disponibles a partir del mapa de divisiones */
const buildCursosDisponibles = (mapa) => {
  return buildCursosDisponiblesFromMap(mapa);
};

// Componente principal de la pagina de Alumnos
const Alumnos = () => {
  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, , avisoModal] = useAviso();
  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();
  const { user, ready } = useAuth();
  const role = user?.role;
  const isProfesor = role === 'profesor';
  // Ambito desde cache: permite filtrar al instante sin esperar la red
  const [ambito, setAmbito] = useState(() => getCached('ambito') || null);
  // Evita el destello de alumnos de otros cursos mientras llega el ambito del profesor
  const [ambitoListo, setAmbitoListo] = useState(
    () => !getCached('alumnos/lista') || !!getCached('ambito')
  );
  // Preceptor puede editar solo alumnos de sus cursos asignados
  const puedeEditarAlumno = useCallback((alumno) => {
    if (!user) return false;
    if (['admin', 'directivo', 'jefe_preceptores'].includes(user.role)) return true;
    if (user.role === 'preceptor') {
      // Se usa la etiqueta del curso ("1ro 1ra"), mismo formato que el filtro de ambito:
      // el objeto normalizado del alumno no tiene anio/division numericos directos.
      return cursoEnAsignaciones(alumno?.anio, ambito?.asignaciones);
    }
    return false;
  }, [user, ambito]);
  const [searchParams] = useSearchParams();
  const paramAnio = searchParams.get('anio');
  const paramDivision = searchParams.get('division');
  const paramBuscar = searchParams.get('buscar');
  const paramAgregar = searchParams.get('agregar');
  // Curso inicial: lo que venga por URL (año solo o año+división), si no 'todos'.
  const initialFiltro = (() => {
    try {
      if (paramAnio && paramDivision) return cursoLabel({ anio: paramAnio, division: paramDivision });
      if (paramAnio) return cursoLabel({ anio: paramAnio, division: null });
    } catch { /* cae a 'todos' */ }
    return 'todos';
  })();

  const [filtroAnio, setFiltroAnio]     = useState(initialFiltro);
  const [filtroGenero, setFiltroGenero] = useState('todos');
  const [filtroEspecialidad, setFiltroEspecialidad] = useState('todos');
  const [busqueda, setBusqueda]         = useState(paramBuscar || '');
  const busquedaDebounced = useDebounce(busqueda, 180);
  // Orden de la lista (se recuerda por pantalla).
  const [orden, setOrden] = useState(() => leerOrden('alumnos'));
  useEffect(() => { guardarOrden('alumnos', orden); }, [orden]);

  // Paginación simple en frontend: 50 por página
  const POR_PAGINA = 50;
  const [pagina, setPagina] = useState(1);



  const [mostrarModal, setMostrarModal]   = useState(false);
  // Los atajos ("Inscribir un alumno") llegan con ?agregar=1: abre el modal directo.
  useEffect(() => {
    if (paramAgregar) setMostrarModal(true);
  }, [paramAgregar]);
  const [mostrarDetalle, setMostrarDetalle] = useState(null);
  const [mostrarFicha, setMostrarFicha] = useState(null);
  const [mostrarHorario, setMostrarHorario] = useState(null);

  const [nuevoAlumno, setNuevoAlumno] = useState({
    nombre:'', apellido:'', fechaNacimiento:'', dni:'',
    genero:'', anio:'', curso: undefined,
    grupo: null,
    recursando:[], intensificacion:[],
    direcciones:[], telefonos:[],
    observaciones:'',
    // === NUEVO ===
    tiene_ppi: false,
    tiene_ae: false,
    responsables: [], // [{nombre, apellido, vinculo, tutor_legal:bool, convive:bool}]
  });

  const [errores, setErrores] = useState({});
  const [loading, setLoading] = useState(() => !getCached('alumnos/lista'));
  const [alumnos, setAlumnos] = useState(() => getCached('alumnos/lista') || []);
  const [cursosDisponibles, setCursosDisponibles] = useState([
    { anio:'1°', divisiones:['1','2','3','4','5','6','7','8','9'] },
    { anio:'2°', divisiones:['1','2','3','4','5','6','7','8','9'] },
    { anio:'3°', divisiones:['1','2','3','4','5','6','7','8','9'] },
    { anio:'4°', divisiones:['1','2','3','4','5','6','7','8','9'] },
    { anio:'5°', divisiones:['1','2','3','4','5','6','7','8','9'] },
    { anio:'6°', divisiones:['1','2','3','4','5','6','7','8','9'] },
    { anio:'7°', divisiones:['1','2','3','4','5','6','7','8','9'] },
  ]);

  /* ---------- Carga la lista de alumnos desde el backend ---------- */
  const refrescarAlumnos = async () => {
    let res;
    try { res = await apiGet('/alumnos?per_page=500'); } catch { return []; }
    const rows = res?.data ?? res ?? [];

    const normalizados = (rows || []).map(a => {
      // Prioriza anio_num (numero) sobre anio (string); fallback a grado
      const anioNum = a.anio_num ?? a.anio ?? a.grado ?? null;
      // Extrae la primera letra/número de la division (soporta digitos para grupos)
      const divRaw  = a.division ?? a.curso ?? '';
      const divMatch = String(divRaw || '').toUpperCase().match(/[A-ZÁÉÍÓÚÑ0-9]/);
      const divLet   = divMatch ? divMatch[0] : '';
      const anioStr = cursoLabel({ anio: anioNum, division: a.division ?? divLet });

      const dir = Array.isArray(a.direcciones) && a.direcciones.length ? (a.direcciones[0]?.direccion ?? a.direcciones[0]) : (a.direccion || '');
      const tel = Array.isArray(a.telefonos)   && a.telefonos.length   ? (a.telefonos[0]?.numero    ?? a.telefonos[0])    : (a.telefono || '');

      // === NUEVO: flags y responsables ===
      const flagPpi = a.tiene_ppi ?? a.ppi ?? a.PPI ?? false;
      const flagAe  = a.tiene_ae  ?? a.ae  ?? a.AE  ?? false;
      const responsables = Array.isArray(a.responsables) ? a.responsables
                         : Array.isArray(a.tutores) ? a.tutores
                         : [];

      return {
        id: a.id,
        nombre: a.nombre,
        apellido: a.apellido,
        genero: (a.genero || '').toUpperCase(),
        dni: a.dni || '',
        creado_en: a.creado_en || a.created_at || null,
        fechaNacimiento: a.fecha_nacimiento || a.fechaNacimiento || '',
        direccion: dir, telefono: tel,
        anio: anioStr,
        grupo: a.grupo || null,                  // Grupo A/B dentro de la division
        curso: a.anio_num ? { anio: String(a.anio_num), division: a.division || null } : undefined,

        // conservar estado y egreso del back
        estado: a.estado ?? null,
        egreso_year: a.egreso_year ?? null,
        egreso_anio: a.egreso_anio ?? null,
        egreso_division: a.egreso_division ?? null,

        recursando: a.recursando ?? [],
        intensificacion: a.intensificacion ?? [],
        observaciones: a.observaciones ?? '',

        // === NUEVO ===
        tiene_ppi: !!flagPpi,
        tiene_ae: !!flagAe,
        responsables,

        especialidad: a.especialidad || null,
        especialidad_label: a.especialidad_label || (a.especialidad ? especialidadLabel(a.especialidad) : null),
      };
    });

    setAlumnos(normalizados);
    setCache('alumnos/lista', normalizados);
  };

  useEffect(() => {
    if (!ready) return;
    let cancel = false;
    (async () => {
      setLoading(true);
      const [divisionesMap, rawAmbito] = await Promise.all([
        apiGet('/divisiones').catch(() => null),
        apiAmbito().catch(() => ({ asignaciones: [] })),
        refrescarAlumnos().catch(() => {}),
      ]);
      if (cancel) return;
      try {
        setAmbito(rawAmbito);
        setAmbitoListo(true);
        // Cachea el ambito (misma clave que usa Calificaciones) para el proximo ingreso
        if (rawAmbito?.role) setCache('ambito', rawAmbito);
        let cursos = buildCursosDisponibles(divisionesMap);
        const ambitoFilter = user?.role === 'profesor' ? rawAmbito : null;
        if (ambitoFilter?.asignaciones?.length) {
          const allowedYears = new Set(ambitoFilter.asignaciones.map(a => Number(a.anio)));
          cursos = cursos.filter(c => allowedYears.has(c.anioNum));
          if (ambitoFilter.asignaciones.length === 1 && ambitoFilter.asignaciones[0].division) {
            const a = ambitoFilter.asignaciones[0];
            setFiltroAnio(cursoLabel({ anio: a.anio, division: a.division }));
          } else if (cursos.length === 1) {
            setFiltroAnio(cursos[0].anio);
          }
        }
        setCursosDisponibles(cursos);
      } catch { /* se usa el fallback de cursos por defecto */ }
      if (!cancel) setLoading(false);
    })();
    return () => { cancel = true; };
  }, [ready, user?.role]);

  // Si otra persona (u otro equipo) tocó alumnos, al volver a esta pantalla
  // aparece el botón "Hay novedades"; la lista se actualiza cuando se pulse.
  const actualizarTodo = async () => {
    if (!ready) return;
    const [, rawAmbito] = await Promise.all([
      refrescarAlumnos().catch(() => []),
      apiAmbito().catch(() => null),
    ]);
    if (rawAmbito?.role) {
      setAmbito(rawAmbito);
      setAmbitoListo(true);
      setCache('ambito', rawAmbito);
    }
  };
  const { hayNovedades, actualizar } = useAvisoDeCambios("alumnos", ["alumnos", "cursos"], actualizarTodo);

  // Bloquea el scroll del body solo cuando hay al menos un modal abierto
  const modalsOpen = [mostrarModal, mostrarDetalle, mostrarFicha, mostrarHorario].filter(Boolean).length;
  useEffect(() => {
    const prev = document.body.style.overflow;
    document.body.style.overflow = modalsOpen > 0 ? 'hidden' : prev;
    return () => { document.body.style.overflow = prev; };
  }, [modalsOpen]);

  // Filtra alumnos por año y genero, respetando restricciones de ambito
  const pasaAnioYGenero = (a) => {
    const cursoAlumno = parseCurso(a.anio);
    const filtro = parseCurso(filtroAnio);

    const okAnio =
      filtroAnio === 'todos' ||
      cursoAlumno.anio === filtro.anio &&
        (!filtro.division || cursoAlumno.division === filtro.division);

    // Preceptor ve todos los alumnos; profesor ve solo sus cursos asignados
    const ambitoOk = role !== 'profesor' || !ambito?.asignaciones?.length ||
      cursoEnAsignaciones(a.anio, ambito?.asignaciones);

    const okGen = filtroGenero === 'todos' || (a?.genero && a.genero.toUpperCase() === filtroGenero);
    const okEsp = filtroEspecialidad === 'todos' || (a?.especialidad ?? null) === filtroEspecialidad;
    return okAnio && okGen && okEsp && ambitoOk;
  };

  const alumnosFiltrados = useMemo(() => {
    const q = busquedaDebounced.trim();
    const filtrados = [];

    for (const a of alumnos) {
      if (!pasaAnioYGenero(a)) continue;

      // Búsqueda que perdona: ignora tildes y mayúsculas, acepta las
      // palabras en cualquier orden y el DNI con o sin puntos.
      const nombreCompleto = `${a.nombre} ${a.apellido}`;
      const nombreInvertido = `${a.apellido} ${a.nombre}`;
      const okBusq = !q
        || coincideBusqueda(nombreCompleto, q)
        || coincideBusqueda(nombreInvertido, q)
        || coincideDni(a.dni, q);
      if (!okBusq) continue;

      filtrados.push(a);
    }

    return ordenarPersonas(filtrados, orden);
  }, [alumnos, busquedaDebounced, filtroAnio, filtroGenero, filtroEspecialidad, ambito, role, orden]);

  // Derivados de paginación a partir de alumnosFiltrados ya filtrado+ordenado
  const totalPaginas = Math.max(1, Math.ceil(alumnosFiltrados.length / POR_PAGINA));
  const alumnosPaginados = useMemo(
    () => alumnosFiltrados.slice((pagina - 1) * POR_PAGINA, pagina * POR_PAGINA),
    [alumnosFiltrados, pagina]
  );
  // Resetea a página 1 cuando cambia cualquier filtro/búsqueda/orden
  useEffect(() => { setPagina(1); }, [busqueda, filtroAnio, filtroGenero, filtroEspecialidad, orden]);
  // Si el total se achica y la página queda fuera de rango, ajusta
  useEffect(() => { if (pagina > totalPaginas) setPagina(totalPaginas); }, [pagina, totalPaginas]);

  // Panel de filtros colapsable + contador de filtros activos.
  const [filtrosAbiertos, setFiltrosAbiertos] = useState(true);
  const filtrosActivos =
    (filtroAnio !== 'todos' ? 1 : 0) +
    (filtroGenero !== 'todos' ? 1 : 0) +
    (filtroEspecialidad !== 'todos' ? 1 : 0) +
    (orden !== ORDEN_DEFAULT ? 1 : 0);
  const limpiarTodo = () => {
    setBusqueda('');
    setFiltroAnio('todos');
    setFiltroGenero('todos');
    setFiltroEspecialidad('todos');
    setOrden(ORDEN_DEFAULT);
  };

  /* ---------- POST centralizado para agregar alumno (usa el payload del modal) ---------- */
  const agregarAlumnoBD = async (payloadFinalDelModal) => {
    // El modal ya envía { anio: number, division: 'A'|'B'|..., ... }
    const res = await apiPost('/alumnos', payloadFinalDelModal);
    await refrescarAlumnos();
    return res;
  };

  return (
    <div className="alumnos-page">
      <div className="alumnos-container">
        {/* Header */}
        <div className="alumnos-header">
          <div>
            <Breadcrumbs items={[{ label: "Alumnos" }]} />
            <h1 className="alumnos-title">Gestión de Alumnos</h1>
            <p className="alumnos-subtitle">Alumnos con trayectorias educativas complejas</p>
          </div>

          <div className="alumnos-header-actions">
            <BotonNovedades visible={hayNovedades} onClick={actualizar} />
            {/* Descarga en un click la lista que se está mirando */}
            <button
              onClick={() => {
                const filas = alumnosFiltrados.map((a) => ({
                  Apellido: a.apellido || '',
                  Nombre: a.nombre || '',
                  DNI: a.dni || '',
                  Curso: a.anio || '',
                  Genero: a.genero || '',
                  Telefono: a.telefono || '',
                }));
                if (!filas.length) {
                  showToast('No hay alumnos para descargar con esos filtros.', 'info');
                  return;
                }
                const nombre = `lista-alumnos${filtroAnio !== 'todos' ? `-${filtroAnio.replace(/\s+/g, '')}` : ''}.csv`;
                exportCSV(filas, nombre);
                showToast(mensajeDescarga(nombre), 'success');
              }}
              className="alumnos-btn-download"
              title="Descargar la lista que estás viendo"
            >
              <Download size={18} className="alumnos-btn-add-icon" />
              Descargar lista
            </button>
            <button
              onClick={async () => {
                const pc = parseCurso(filtroAnio);
                if (!pc.anio) {
                  showToast('Elegí un curso para imprimir su lista.', 'info');
                  return;
                }
                const ticket = await pedirTicketImpresion();
                if (!ticket) {
                  showToast('No se pudo autorizar la impresión. Probá de nuevo.', 'error');
                  return;
                }
                const params = new URLSearchParams({
                  anio: String(pc.anio),
                  ticket,
                });
                if (pc.division) params.set("division", pc.division);
                await imprimirUrl(`${BACKEND_BASE}/print/alumnos?${params.toString()}`);
              }}
              className="alumnos-btn-download"
              title="Imprimir la lista del curso con firma"
            >
              <Printer size={18} className="alumnos-btn-add-icon" />
              Imprimir lista
            </button>
            {/* ⬇️ Ocultar botón si es PROFESOR */}
            {!isProfesor && (
              <button
                onClick={() => setMostrarModal(true)}
                className="alumnos-btn-add"
              >
                <Plus size={18} className="alumnos-btn-add-icon" />
                Agregar alumno
              </button>
            )}
          </div>
        </div>

        {/* Buscador + filtros */}
        <div className="alumnos-filtros-card">
          <div className="tb-search-row">
            {/* búsqueda protagonista */}
            <div className="alumnos-search-wrap tb-search-grow">
              <Search className="alumnos-search-icon" size={20} />
              <input
                type="text"
                placeholder="Buscar por nombre, apellido o DNI..."
                value={busqueda}
                onChange={(e) => setBusqueda(e.target.value)}
                className="alumnos-search-input tb-search-input"
                aria-label="Buscar alumnos"
              />
              {busqueda && (
                <button
                  className="tb-clear"
                  onClick={() => setBusqueda('')}
                  aria-label="Limpiar búsqueda"
                  title="Limpiar búsqueda"
                >
                  <X size={16} />
                </button>
              )}
            </div>

            <button
              className={`tb-toggle${filtrosAbiertos ? " tb-toggle--open" : ""}`}
              onClick={() => setFiltrosAbiertos((v) => !v)}
              aria-expanded={filtrosAbiertos}
            >
              <SlidersHorizontal size={16} />
              Filtros
              {filtrosActivos > 0 && <span className="tb-badge">{filtrosActivos}</span>}
            </button>
          </div>

          {filtrosAbiertos && (
            <div className="tb-panel">
              <div className="tb-field">
                <span className="tb-field-label">Género</span>
                <DropdownFiltroGenero
                  value={filtroGenero}
                  onChange={setFiltroGenero}
                />
              </div>

              <div className="tb-field">
                <span className="tb-field-label">Especialidad</span>
                <DropdownFiltroEspecialidad
                  value={filtroEspecialidad}
                  onChange={setFiltroEspecialidad}
                />
              </div>

              <div className="tb-field">
                <span className="tb-field-label">Curso</span>
                <DropdownFiltroAnios
                  value={filtroAnio}
                  onChange={setFiltroAnio}
                  cursosDisponibles={cursosDisponibles}
                />
              </div>

              <div className="tb-field">
                <span className="tb-field-label">Ordenar</span>
                <DropdownMini
                  value={orden} onChange={setOrden}
                  placeholder="Ordenar" width="100%"
                  opciones={ORDENES}
                />
              </div>
            </div>
          )}

          <div className="tb-meta">
            <span>Mostrando <b>{alumnosFiltrados.length}</b> de <b>{alumnos.length}</b> alumnos</span>
            {(busqueda.trim() || filtrosActivos > 0) && (
              <button className="tb-limpiar" onClick={limpiarTodo}>
                Limpiar todo
              </button>
            )}
          </div>
        </div>

        {/* Lista */}
        {/* Para profesores no se muestra nada hasta que llega el ambito:
            asi nunca se ven alumnos de cursos fuera de su asignacion */}
        {(loading && alumnosFiltrados.length === 0) || (isProfesor && !ambitoListo) ? (
          <div className="alumnos-loading">Cargando datos...</div>
        ) : (
          <>
            <ListaAlumnos
              alumnos={alumnosPaginados}
              onMostrarDetalle={setMostrarDetalle}
              onVerFicha={setMostrarFicha}
              cursoTexto={filtroAnio !== 'todos' ? filtroAnio : null}
              hayBusqueda={busqueda.trim().length > 0}
              onLimpiarBusqueda={() => setBusqueda('')}
              onAgregarAlumno={isProfesor ? undefined : () => setMostrarModal(true)}
              onOpenCronograma={setMostrarHorario}
              onGuardarHorario={(alumnoId, entradas) => {
                setAlumnos(prev => prev.map(a => a.id === alumnoId ? { ...a, horarioPersonalizado: entradas } : a));
              }}
              puedeEditarAlumno={puedeEditarAlumno}
            />
            {alumnosFiltrados.length > 0 && (
              <div
                className="alumnos-paginacion"
                style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: '12px', marginTop: '16px', flexWrap: 'wrap' }}
              >
                <button
                  type="button"
                  className="alumnos-btn-download"
                  disabled={pagina <= 1}
                  onClick={() => setPagina((p) => Math.max(1, p - 1))}
                  style={{ opacity: pagina <= 1 ? 0.5 : 1, cursor: pagina <= 1 ? 'not-allowed' : 'pointer' }}
                >
                  Anterior
                </button>
                <span style={{ fontSize: '14px', color: 'var(--slate-600, #475569)' }}>
                  Página {pagina} de {totalPaginas} (mostrando {alumnosFiltrados.length === 0 ? 0 : (pagina - 1) * POR_PAGINA + 1}-{Math.min(pagina * POR_PAGINA, alumnosFiltrados.length)} de {alumnosFiltrados.length})
                </span>
                <button
                  type="button"
                  className="alumnos-btn-download"
                  disabled={pagina >= totalPaginas}
                  onClick={() => setPagina((p) => Math.min(totalPaginas, p + 1))}
                  style={{ opacity: pagina >= totalPaginas ? 0.5 : 1, cursor: pagina >= totalPaginas ? 'not-allowed' : 'pointer' }}
                >
                  Siguiente
                </button>
              </div>
            )}
          </>
        )}

        {/* Detalle */}
        <AlumnoDetalle
          alumno={mostrarDetalle}
          setAlumno={setMostrarDetalle}
          actualizarAlumno={(actualizado) => {
            // Se sincroniza la caché para no mostrar datos viejos en otras pantallas.
            // Se conserva la fecha de alta (el detalle no siempre la trae).
            const next = alumnos.map(a => a.id === actualizado.id
              ? { ...actualizado, creado_en: actualizado.creado_en || a.creado_en || null }
              : a);
            setAlumnos(next);
            setCache('alumnos/lista', next);
          }}
          eliminarAlumno={(id) => {
            const next = alumnos.filter(a => a.id !== id);
            setAlumnos(next);
            setCache('alumnos/lista', next);
          }}
          onOpenCronograma={(a) => { setMostrarDetalle(null); setMostrarHorario(a); }}
        />

        {/* Ficha de solo lectura (mismo diseño que profesores/preceptores) */}
        <AlumnoFicha
          open={!!mostrarFicha}
          onClose={() => setMostrarFicha(null)}
          alumno={mostrarFicha}
        />

        {/* Cronograma / Horario */}
        <HorarioAlumnoModal
          visible={!!mostrarHorario}
          alumno={mostrarHorario}
          onClose={() => setMostrarHorario(null)}
          onGuardar={(alumnoId, entradas) => {
            setAlumnos(prev => prev.map(a => a.id === alumnoId ? { ...a, horarioPersonalizado: entradas } : a));
          }}
        />

        {/* Modal Agregar */}
        <AlumnoModal
          visible={mostrarModal}
          onClose={() => setMostrarModal(false)}
          alumno={nuevoAlumno}
          setAlumno={setNuevoAlumno}
          agregarAlumno={async (nuevo) => {
            try {
              let creado = null;
              const detalles = await confirmAction('¿Qué motivo tenés para agregar este alumno?', async (d) => {
                creado = await agregarAlumnoBD({ ...nuevo, _detalles: d });
              });
              if (detalles === null) return;
              setErrores({});
              setMostrarModal(false);
              if (creado?.id) {
                // Deshacer borra al alumno recién creado: exige contraseña.
                ofrecerDeshacer(
                  {
                    etiqueta: `Alumno ${creado?.apellido || nuevo?.apellido || ''} ${creado?.nombre || nuevo?.nombre || ''}`.trim(),
                    exigePassword: true,
                    deshacer: async () => {
                      await apiDelete(`/alumnos/${creado.id}`);
                      await refrescarAlumnos();
                    },
                  },
                  'Cambios guardados correctamente'
                );
              } else {
                showToast('Cambios guardados correctamente', 'success');
              }
              setNuevoAlumno({
                nombre:'', apellido:'', fechaNacimiento:'', dni:'',
                genero:'', anio:'', curso: undefined,
                grupo: null,
                recursando:[], intensificacion:[],
                direcciones:[], telefonos:[],
                observaciones:'',
                tiene_ppi: false,
                tiene_ae: false,
                responsables: [],
              });
            } catch (err) {
              console.error(err);
              const e = {};
              if (!nuevo?.nombre?.trim()) e.nombre = true;
              if (!nuevo?.apellido?.trim()) e.apellido = true;
              setErrores(e);
              await mostrarAviso({ icon: 'error', title: 'No se pudo guardar', text: mensajeAmable(err, 'No se pudo guardar el alumno. Revisá los datos e intentá de nuevo.'), ms: 3500 });
            }
          }}
          errores={errores}
          setErrores={setErrores}
        />
      </div>
      {confirmModal}
      {avisoModal}
    </div>
  );
};

export default Alumnos;
