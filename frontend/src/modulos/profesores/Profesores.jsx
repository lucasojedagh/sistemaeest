// ===============================
// Pagina de gestion de profesores con busqueda, filtros,
// modales de detalle y creacion/edicion de docentes.
// ===============================
import React, { useEffect, useState, useRef, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { getCached, setCache } from "../../compartido/lib/pageCache";
import { apiGet, apiPut, apiDelete } from "../../compartido/lib/api";
import { cargarDivisiones } from "../../compartido/lib/divisiones";
import { useToast } from "../../compartido/componentes/Common/Toast";
import { Search, UsersRound, SlidersHorizontal, X } from 'lucide-react';
import { motion } from 'framer-motion';
import useCierreAnimado from '../../compartido/lib/useCierreAnimado';
import ProfesoresDetalle from './ProfesoresDetalle';
import ProfesorFicha from './ProfesorFicha';
import ListaProfesores from './ListaProfesores';
import DropdownFiltroAnios from '../alumnos/DropdownFiltroAnios';
import DropdownFiltroRol from '../alumnos/DropdownFiltroRol';
import DropdownFiltroEstado from '../alumnos/DropdownFiltroEstado';
import { useAuth } from '../auth/AuthContext';
import { esGestion } from '../auth/roles';
import { accionHabilitada } from '../../compartido/lib/permisos';
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import ErrorState from "../../compartido/componentes/Common/ErrorState";
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import useAviso from "../../compartido/componentes/modals/useAviso";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import { useDeshacerAviso } from "../../compartido/lib/deshacer.jsx";
import { cursoLabel, parseCurso } from "../../compartido/lib/cursos";
import { mensajeAmable } from "../../compartido/lib/errores";
import { ORDENES, ORDEN_DEFAULT, ordenarPersonas, leerOrden, guardarOrden } from "../../compartido/lib/orden";
import DropdownMini from "../../compartido/componentes/Common/Dropdownmini";
import { bloquearScroll } from "../../compartido/lib/lockScroll";
import "./Profesores.css";

/* --- Dropdown para seleccionar materia filtrada por año --- */
function DropdownMaterias({ value, onChange, anioSeleccionado, disabled: extDisabled = false }) {
  const disabled = extDisabled || !anioSeleccionado || anioSeleccionado === 'todos';
  const anioNum = parseCurso(anioSeleccionado).anio || null;

  const [open, setOpen] = useState(false);
  const [opciones, setOpciones] = useState([]);
  const { montado, cerrando } = useCierreAnimado(open, 150);
  const label = value === 'todas' || !value ? 'Materia' : value;

  useEffect(() => { if (disabled) setOpen(false); }, [disabled]);

  useEffect(() => {
    let cancel = false;
    async function load() {
      if (!anioNum) { setOpciones([]); return; }
      try {
        const lista = await apiGet(`/anios/${anioNum}/materias`);
        if (!cancel) setOpciones((lista || []).map(m => ({ label: m.nombre, value: m.nombre })));
      } catch {
        if (!cancel) setOpciones([]);
      }
    }
    load();
    return () => { cancel = true; };
  }, [anioNum]);

  return (
    <div className="prof-drop-wrap">
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen(o => !o)}
        className={`prof-drop-btn ${disabled ? 'prof-drop-btn--disabled' : 'prof-drop-btn--enabled'}`}
      >
        {disabled ? 'Materia' : label}
        <motion.span
          className="prof-drop-chevron"
          animate={{ rotate: open ? 180 : 0 }}
          transition={{ duration: 0.2 }}
        >
          <motion.svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M6 9l3 3 3-3" />
          </motion.svg>
        </motion.span>
      </button>

      {montado && !disabled && (
        <div className={`prof-drop-panel sys-desplegable${cerrando ? " sys-cerrando" : ""}`}>
            <div
              className={`prof-drop-option prof-drop-option--all ${value === 'todas' ? 'prof-drop-option--selected' : ''}`}
              onClick={() => { onChange('todas'); setOpen(false); }}
            >
              Todas las materias
            </div>
            {opciones.map(opt => (
              <div
                key={opt.value}
                className={`prof-drop-option ${value === opt.value ? 'prof-drop-option--selected' : ''}`}
                onClick={() => { onChange(opt.value); setOpen(false); }}
              >
                {opt.label}
              </div>
            ))}
        </div>
      )}
    </div>
  );
}

/* ========= Página ========= */
// Componente principal de la pagina de Profesores
const Profesores = () => {
  const [confirmAction, confirmModal] = useConfirmAction();
  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();
  const [mostrarAviso, , avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const { user } = useAuth();
  // Tres casillas del catálogo, tres acciones distintas en esta pantalla:
  // editar la ficha, dar de baja definitiva y mover la cadena de suplencias.
  const puedeEditarProfesor = !!user && accionHabilitada(user, 'profesores.editar', esGestion(user.role));
  const puedeEliminarProfesor = !!user && accionHabilitada(user, 'profesores.eliminar', esGestion(user.role));
  const soloLectura = !puedeEditarProfesor;

  // UI
  const [busqueda, setBusqueda] = useState('');
  const [debouncedBusqueda, setDebouncedBusqueda] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedBusqueda(busqueda), 350);
    return () => clearTimeout(timer);
  }, [busqueda]);
  const [mostrarDetalle, setMostrarDetalle] = useState(null);
  const [mostrarFicha, setMostrarFicha] = useState(null);

  // Filtros
  const [searchParams] = useSearchParams();
  const rolDesdeUrl = (() => {
    const r = (searchParams.get("rol") || "").toLowerCase();
    return ["titular", "suplente"].includes(r) ? r : "todos";
  })();
  const [filtroCurso, setFiltroCurso] = useState('todos');
  const [filtroMateria, setFiltroMateria] = useState('todas');
  const [filtroRol, setFiltroRol] = useState(rolDesdeUrl);
  const [filtroEstado, setFiltroEstado] = useState('todos');
  // Si se llega con ?rol=suplente (ej: desde el panel), el filtro acompaña.
  useEffect(() => {
    if (["titular", "suplente"].includes(rolDesdeUrl)) setFiltroRol(rolDesdeUrl);
  }, [rolDesdeUrl]);
  // Orden de la lista (se recuerda por pantalla).
  const [orden, setOrden] = useState(() => leerOrden('profesores'));
  useEffect(() => { guardarOrden('profesores', orden); }, [orden]);

  // Panel de filtros colapsable + contador de filtros activos.
  const [filtrosAbiertos, setFiltrosAbiertos] = useState(true);
  const filtrosActivos =
    (filtroCurso !== 'todos' ? 1 : 0) +
    (filtroMateria !== 'todas' ? 1 : 0) +
    (filtroRol !== 'todos' ? 1 : 0) +
    (filtroEstado !== 'todos' ? 1 : 0) +
    (orden !== ORDEN_DEFAULT ? 1 : 0);
  const limpiarTodo = () => {
    setBusqueda('');
    setFiltroCurso('todos');
    setFiltroMateria('todas');
    setFiltroRol('todos');
    setFiltroEstado('todos');
    setOrden(ORDEN_DEFAULT);
  };

  // Cursos desde back (inicializado desde cache)
  const [cursosDisponibles, setCursosDisponibles] = useState(() => getCached('profesores/divisiones') || []);

  // Datos (inicializados desde cache para carga instantanea)
  const [loading, setLoading] = useState(() => !getCached('profesores/activos'));
  const [filtrando, setFiltrando] = useState(false);
  const [profesores, setProfesores] = useState(() => getCached('profesores/activos') || []);
  // Aviso cuando la lista no pudo leerse (distinto de "no hay profesores").
  const [errorCarga, setErrorCarga] = useState(null);

  // El reintento dispara el efecto de carga (depende de `loading`), así no se
  // hacen dos pedidos del mismo listado.
  const reintentarCarga = () => {
    setErrorCarga(null);
    setLoading(true);
  };

  useEffect(() => {
    if (!mostrarDetalle && !mostrarFicha) return;
    return bloquearScroll();
  }, [mostrarDetalle, mostrarFicha]);

  // Cursos (año + divisiones) para los filtros. Si el servidor no responde se
  // usa la lista fija de 7 años, para que el filtro nunca quede vacío.
  const cargarCursos = async () => {
    try {
      const mapa = await cargarDivisiones();
      const lista = Object.entries(mapa).map(([anio, divisiones]) => ({
        anio: cursoLabel({ anio: Number(anio), division: null }),
        divisiones: divisiones || [],
      }));
      setCursosDisponibles(lista);
      setCache('profesores/divisiones', lista);
    } catch {
      const fallback = [
        { anio: '1ro', divisiones: ['1','2','3','4','5','6','7','8','9'] },
        { anio: '2do', divisiones: ['1','2','3','4','5','6','7','8','9'] },
        { anio: '3ro', divisiones: ['1','2','3','4','5','6','7','8','9'] },
        { anio: '4to', divisiones: ['1','2','3','4','5','6','7','8','9'] },
        { anio: '5to', divisiones: ['1','2','3','4','5','6','7','8','9'] },
        { anio: '6to', divisiones: ['1','2','3','4','5','6','7','8','9'] },
        { anio: '7mo', divisiones: ['1','2','3','4','5','6','7','8','9'] },
      ];
      setCursosDisponibles(fallback);
      setCache('profesores/divisiones', fallback);
    }
  };

  useEffect(() => {
    if (cursosDisponibles.length > 0) return;
    cargarCursos().catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const parseFiltroCurso = (valor) => {
    const p = parseCurso(valor);
    return { anio: p.anio || null, division: p.division || null };
  };

  const refreshBoth = async () => {
    const qsActivos = new URLSearchParams();
    if (debouncedBusqueda.trim()) qsActivos.set('q', debouncedBusqueda.trim());
    const { anio, division } = parseFiltroCurso(filtroCurso);
    if (anio) qsActivos.set('anio', String(anio));
    if (division) qsActivos.set('division', division);
    if (filtroMateria !== 'todas') qsActivos.set('materia', filtroMateria);
    if (filtroRol !== 'todos') qsActivos.set('rol', filtroRol);
    if (filtroEstado !== 'todos') qsActivos.set('estado', filtroEstado === 'activo' ? 'Activo' : 'Licencia');

    try {
      const act = await apiGet(`/profesores?per_page=2000&${qsActivos.toString()}`);
      const activos = Array.isArray(act) ? act : (act?.data ?? []);
      setProfesores(activos);
      setCache('profesores/activos', activos);
      setErrorCarga(null);
    } catch (e) {
      // Se conserva lo que ya estaba en pantalla, pero el fallo se dice: sin
      // esto una tabla vieja parece datos al día.
      setErrorCarga(mensajeAmable(e, 'No se pudo cargar la lista de profesores.'));
    }
  };

  // Refs para leer el estado actual de las listas sin incluirlas en las deps del efecto
  // (evita refetch en cascada cada vez que se actualizan los datos).
  const profesoresRef = useRef(profesores);
  profesoresRef.current = profesores;

  // Arranca en true solo si aun no se pidio la lista (sin copia guardada).
  // Evita el pedido doble: el primer cambio de `loading` a false ya cerraria
  // una carga que se hizo en el disparo anterior.
  const arranqueRef = useRef(loading);

  // Si otra persona (u otro equipo) tocó profesores o usuarios, al volver a
  // esta pantalla se actualiza sola; el botón solo aparece si estás editando.
  const actualizarTodo = async () => {
    await Promise.all([
      refreshBoth().catch(() => {}),
      cargarCursos().catch(() => {}),
    ]);
  };
  useAvisoDeCambios(
    "profesores",
    ["profesores", "usuarios"],
    actualizarTodo
  );

  useEffect(() => {
    let cancel = false;
    let timer = null;

    // El paso de `loading` a false cierra la carga que ya se hizo en el
    // disparo anterior: no se vuelve a pedir nada.
    if (arranqueRef.current && !loading) {
      arranqueRef.current = false;
      setLoading(false);
      setFiltrando(false);
      return;
    }
    arranqueRef.current = false;

    // Aunque haya copia guardada, se pide lo fresco por detrás (la copia se
    // muestra al instante y se reemplaza en silencio al llegar lo nuevo).
    // El spinner grande solo sale si no hay nada que mostrar.
    if (!loading && profesoresRef.current.length > 0) setFiltrando(true);

    const t0 = Date.now();
    (async () => {
      try {
        await refreshBoth();
      } catch {
        // Si falla, mantenemos los datos que ya estaban en pantalla (cache previo)
        // en lugar de dejar la lista vacia.
      }
      const elapsed = Date.now() - t0;
      const restante = Math.max(0, 280 - elapsed);
      timer = setTimeout(() => {
        if (!cancel) { setLoading(false); setFiltrando(false); }
      }, restante);
    })();
    return () => { cancel = true; if (timer) clearTimeout(timer); };
  }, [debouncedBusqueda, filtroCurso, filtroMateria, filtroRol, filtroEstado, loading]);

  const profesoresFiltrados = useMemo(
    () => ordenarPersonas(profesores, orden),
    [profesores, orden]
  );

  // Acciones
  // Elimina un profesor de forma permanente (no hay papelera), solicitando un motivo.
  const eliminarProfesorDefinitivo = async (prof) => {
    // Borrado definitivo: primero la contraseña (el servidor la valida),
    // después el motivo y el borrado.
    if (!(await confirmarContrasena())) return;

    const detalles = await confirmAction(
      `¿Por qué eliminás a ${prof.nombre} ${prof.apellido}? Esta acción es irreversible.`,
      async (d) => {
        const qs = d ? `?_detalles=${encodeURIComponent(d)}` : '';
        await apiDelete(`/profesores/${prof.id}/force${qs}`);
      }
    );
    if (detalles === null) return;

    try {
      setMostrarDetalle(null);
      setMostrarFicha(null);
      await refreshBoth();
      showToast('Profesor eliminado correctamente', 'success');
    } catch (e) {
      await mostrarAviso({ icon: 'error', title: 'No se pudo eliminar', text: mensajeAmable(e, 'No se pudo eliminar al profesor.') });
    }
  };

  const actualizarProfesor = async (parcial) => {
    try {
      const detalles = await confirmAction('¿Qué cambios estás haciendo en este profesor?', async (d) => {
        await apiPut(`/profesores/${parcial.id}`, { ...parcial, _detalles: d });
      });
      if (detalles === null) return;
      setMostrarDetalle(null);
      await refreshBoth();
      // Deshacer: revierte a los datos que tenía el profesor al abrir el modal.
      const orig = profesores.find(p => p.id === parcial.id) || null;
      if (orig?.id) {
        ofrecerDeshacer(
          {
            etiqueta: `Profesor ${orig?.apellido || ''} ${orig?.nombre || ''}`.trim(),
            deshacer: async () => {
              await apiPut(`/profesores/${orig.id}`, {
                id: orig.id,
                nombre: String(orig.nombre || '').trim(),
                apellido: String(orig.apellido || '').trim(),
                email: orig.email || null,
                dni: String(orig.dni || '').trim() || null,
                estado: orig.estado || 'Activo',
                suplente: !!orig.suplente,
                observaciones: orig.observaciones || '',
                asignaciones: (orig.asignaciones || [])
                  .filter(a => String(a?.materia ?? '').trim())
                  .map(a => ({
                    anio: (() => {
                      const s = String(a?.anio ?? '');
                      const n = parseInt(s.replace(/\D/g, ''), 10);
                      return n || null;
                    })(),
                    division: String(a?.division ?? '').trim() || null,
                    grupo: a.grupo || null,
                    materia: String(a?.materia ?? '').trim(),
                  })),
                _detalles: 'Deshace el último guardado del profesor',
              });
              await refreshBoth();
            },
          },
          'Cambios guardados correctamente'
        );
      } else {
        showToast('Cambios guardados correctamente', 'success');
      }
    } catch (e) {
      await mostrarAviso({ icon: 'error', title: 'No se pudo guardar', text: mensajeAmable(e, 'No se pudo guardar al profesor.') });
    }
  };

  const eliminarProfesor = async (id) => {
    const prof = profesores.find(p => p.id === id);
    if (!prof) return;
    await eliminarProfesorDefinitivo(prof);
  };

  return (
    <div className="prof-page">
      <div className="prof-page-inner">
        {/* Header */}
        <div className="prof-header">
          <div>
            <Breadcrumbs items={[{ label: "Profesores" }]} />
            <h1 className="prof-title">
              <UsersRound size={24} /> Gestión de Profesores
            </h1>
            <p className="prof-subtitle">Docentes y asignaciones por curso</p>
          </div>

          <div className="prof-header-actions">
          </div>
        </div>

        {/* Buscador + filtros */}
        <div className="prof-filter-card">
          <div className="tb-search-row">
            {/* búsqueda protagonista */}
            <div className="prof-search-wrap tb-search-grow">
              <Search className="prof-search-icon" size={20} />
              <input
                type="text"
                placeholder="Buscar por nombre, apellido o DNI..."
                value={busqueda}
                onChange={(e) => setBusqueda(e.target.value)}
                className="prof-search-input tb-search-input"
                aria-label="Buscar profesores"
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
                <span className="tb-field-label">Rol</span>
                <DropdownFiltroRol
                  value={filtroRol}
                  onChange={setFiltroRol}
                />
              </div>

              <div className="tb-field">
                <span className="tb-field-label">Estado</span>
                <DropdownFiltroEstado
                  value={filtroEstado}
                  onChange={setFiltroEstado}
                />
              </div>

              <div className="tb-field">
                <span className="tb-field-label">Curso</span>
                <DropdownFiltroAnios
                  value={filtroCurso}
                  onChange={(val) => { setFiltroCurso(val); setFiltroMateria('todas'); }}
                  cursosDisponibles={cursosDisponibles}
                />
              </div>

              <div className="tb-field">
                <span className="tb-field-label">Materia</span>
                <DropdownMaterias
                  value={filtroMateria}
                  onChange={setFiltroMateria}
                  anioSeleccionado={filtroCurso}
                  disabled={false}
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
            <span>Mostrando <b>{profesoresFiltrados.length}</b> de <b>{profesores.length}</b> profesores</span>
            {(busqueda.trim() || filtrosActivos > 0) && (
              <button className="tb-limpiar" onClick={limpiarTodo}>
                Limpiar todo
              </button>
            )}
          </div>
        </div>

        {loading && profesoresFiltrados.length === 0 ? (
          <div className="prof-loading">Cargando datos...</div>
        ) : errorCarga && profesores.length === 0 ? (
          <ErrorState mensaje={errorCarga} onReintentar={reintentarCarga} compact />
        ) : (
        <div className="prof-list-wrap">
          {errorCarga && (
            <ErrorState mensaje={errorCarga} onReintentar={reintentarCarga} compact />
          )}
          {/* "Baja definitiva de profesores" vive dentro del detalle, así que el
              botón se muestra también cuando el rol solo puede dar de baja. */}
          <ListaProfesores
            profesores={profesoresFiltrados}
            soloLectura={soloLectura}
            onVerFicha={setMostrarFicha}
            onMostrarDetalle={!soloLectura || puedeEliminarProfesor ? setMostrarDetalle : undefined}
          />
          {filtrando && (
            <div className="prof-list-overlay">
              <div className="prof-list-spinner" />
            </div>
          )}
        </div>
        )}

        {/* Modal de edicion. La ficha de solo lectura se muestra en
            ProfesorFicha de forma independiente. `soloLectura` va al modal: sin la
            casilla "Crear y editar profesores" los campos quedan en lectura y el
            botón Guardar no aparece (el PUT /profesores respondería 403). */}
        <ProfesoresDetalle
          profesor={mostrarDetalle}
          setProfesor={setMostrarDetalle}
          soloLectura={soloLectura}
          actualizarProfesor={soloLectura ? undefined : actualizarProfesor}
          eliminarProfesor={puedeEliminarProfesor ? eliminarProfesor : undefined}
          onSuplenciasChange={refreshBoth}
        />

        <ProfesorFicha
          open={!!mostrarFicha}
          onClose={() => setMostrarFicha(null)}
          profesor={mostrarFicha}
        />
      </div>
      {confirmModal}
      {avisoModal}
      {modalPwd}
    </div>
  );
};

export default Profesores;
