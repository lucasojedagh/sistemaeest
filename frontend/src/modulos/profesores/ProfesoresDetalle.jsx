// ===============================
// FILE: src/components/Profesores/ProfesoresDetalle.jsx
// ===============================
import React, { useState, useRef, useEffect } from 'react';
import { apiGet } from "../../compartido/lib/api";
import { cargarDivisiones } from "../../compartido/lib/divisiones";
import { X, Trash2, Plus } from 'lucide-react';
import { useAuth } from '../../modulos/auth/AuthContext';
import { apiAdminUpdateUserRole, apiAdminSaveAssignments } from '../../compartido/api/auth';
import { cursoLabel, parseCurso as parseCursoLib, divisionToDato, buildCursosDisponiblesFromMap } from '../../compartido/lib/cursos';
import { mensajeAmable } from '../../compartido/lib/errores';
import useAviso from "../../compartido/componentes/modals/useAviso";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import SuplenciasCard from "./SuplenciasCard";
import "./ProfesoresDetalle.css";

const ROLES_DISPONIBLES = [
  { label: 'Profesor', value: 'profesor' },
  { label: 'Preceptor', value: 'preceptor' },
  { label: 'Prosecretaria', value: 'prosecretaria' },
  { label: 'Jefe de preceptores', value: 'jefe_preceptores' },
  { label: 'Directivo', value: 'directivo' },
  { label: 'Admin', value: 'admin' },
];

// Roles que NO requieren asignaciones de cursos (controlan todo el sistema).
const ROLES_SIN_ASIGNACIONES = ['jefe_preceptores', 'prosecretaria', 'directivo', 'admin'];

// Componente: ProfesoresDetalle
// Proposito: Modal de detalle y edicion de un profesor. Permite modificar datos personales,
//            estado, suplente/titular, asignaciones (anio/division/grupo/materia) y
//            observaciones. Incluye deteccion de cambios sin guardar y confirmacion al cerrar.
// Props:
//   profesor          - Objeto con los datos del profesor a editar.
//   setProfesor       - Funcion para cerrar el detalle (seteando profesor a null).
//   actualizarProfesor- Funcion asincrona para persistir los cambios.
//   eliminarProfesor  - Funcion para eliminar/dar de baja al profesor.

/* ===== Util: validación de email ===== */
const isValidEmail = (val) => {
  const s = String(val || '').trim();
  if (s === '') return true; // opcional
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i.test(s);
};

/* ---------- Utilidades de año ---------- */
const labelAnio = (v) => {
  const n = parseInt(String(v ?? '').replace(/\D/g, ''), 10);
  return n ? cursoLabel({ anio: n, division: null }) : String(v ?? '').trim();
};
const anioNumFromLabel = (label) => parseInt(String(label || '').replace(/\D/g, ''), 10) || null;

/* Dropdown compacto reutilizable con cierre al hacer clic fuera */
function CursoDropdownInline({ cursosOptions = [], asigAnio, asigDivision, onSelect }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    const handleClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, []);
  const label = (asigAnio && asigDivision)
    ? cursoLabel({ anio: Number(asigAnio), division: asigDivision })
    : (asigAnio ? cursoLabel({ anio: Number(asigAnio), division: null }) + ' (todas)' : 'Seleccionar curso');
  return (
    <div className="det-curso-dd" ref={ref}>
      <button type="button" onClick={() => setOpen(o => !o)}
        className="det-curso-dd-btn">
        {label}
      </button>
      {open && (
        <div className="det-curso-dd-panel">
          {cursosOptions.map(grupo => (
            <div key={grupo.anio}>
              <div className="det-curso-dd-header">{grupo.anio} Año</div>
              {grupo.divisiones.map(div => {
                const cursoStr = `${grupo.anio} ${div}`;
                return (
                  <div key={cursoStr} onClick={() => { onSelect(String(parseCursoLib(grupo.anio).anio), divisionToDato(div)); setOpen(false); }}
                    className="det-curso-dd-option">{cursoStr}</div>
                );
              })}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function SimpleDropdown({ placeholder='Seleccionar', options=[], value, onChange, error, disabled=false }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  React.useEffect(() => {
    const handle = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', handle);
    return () => document.removeEventListener('mousedown', handle);
  }, []);
  React.useEffect(() => { if (disabled) setOpen(false); }, [disabled]);

  const label = options.find(o => o.value === value)?.label || placeholder;

  return (
    <div className="det-simple-dd" ref={ref}>
      <button
        type="button"
        aria-disabled={disabled}
        onClick={() => { if (!disabled) setOpen(o => !o); }}
        className={`det-simple-btn
          ${disabled ? 'det-simple-btn--disabled' : 'det-simple-btn--enabled'}
          ${error ? 'det-simple-btn--error' : ''}`}
      >
        {label}
      </button>
      {open && !disabled && (
        <div className="det-simple-dd-panel">
          {options.map(opt => (
            <div
              key={opt.value}
              onClick={() => { onChange(opt.value); setOpen(false); }}
              className="det-simple-dd-option"
            >
              {opt.label}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ---------- Normalizacion y conversion de datos ---------- */
const toEditableAsignaciones = (list = []) =>
  (list || []).map(a => {
    if (a?.curso) {
      const { anio, division } = parseCursoLib(a.curso);
      return { anio, division: String(division ?? '').trim(), grupo: a.grupo || null, materia: String(a.materia || '').trim() };
    }
    return {
      anio: a?.anio ?? '',
      division: String(a?.division ?? '').trim(),
      grupo: a.grupo || null,
      materia: String(a?.materia ?? '').trim(),
    };
  }).filter(a => a.anio || a.materia);

/* ---------- Opciones ---------- */
const ESTADO_OPTS = [
  { label: 'Activo', value: 'Activo' },
  { label: 'Licencia', value: 'Licencia' },
];

/* ---------- Estilos de etiquetas ---------- */
const fieldLabel = 'det-field-label';
const req = <span className="det-required" aria-hidden="true">*</span>;

/* ============================ Componente principal ============================ */
const ProfesoresDetalle = ({ profesor, setProfesor, actualizarProfesor, eliminarProfesor, soloLectura = false, onSuplenciasChange }) => {
  // Nota: este componente ahora solo se usa en modo edicion (roles con permiso).
  // La ficha de solo lectura se muestra por separado en ProfesorFicha.
  const { user: currentUser } = useAuth();
  const isOpen = Boolean(profesor);
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();

  // Rol actual y rol seleccionado para el cambio (solo en edicion).
  const [rolActual, setRolActual] = useState(profesor?.role || 'profesor');
  const [rolSeleccionado, setRolSeleccionado] = useState(profesor?.role || 'profesor');

  const [local, setLocal] = useState(() => ({
    id: profesor?.id ?? null,
    user_id: profesor?.user_id ?? null,
    nombre: profesor?.nombre ?? '',
    apellido: profesor?.apellido ?? '',
    email: profesor?.email ?? '',
    dni: profesor?.dni ?? '',
    estado: profesor?.estado ?? 'Activo',
    suplente: !!profesor?.suplente,
    asignaciones: toEditableAsignaciones(profesor?.asignaciones || []),
    observaciones: profesor?.observaciones ?? '',
  }));

  const [errors, setErrors] = useState({ email: false });

  // Cierre con animacion: marca "cerrando" (dispara la animacion CSS de salida)
  // y desmonta cuando termina, igual que en el detalle de alumnos.
  const [cerrando, setCerrando] = useState(false);
  const cerrandoRef = useRef(false);
  const cerrarTimerRef = useRef(null);

  const cerrarConAnimacion = () => {
    if (cerrandoRef.current) return;
    cerrandoRef.current = true;
    setCerrando(true);
    window.clearTimeout(cerrarTimerRef.current);
    cerrarTimerRef.current = window.setTimeout(() => {
      cerrandoRef.current = false;
      setProfesor(null);
    }, 190);
  };

  useEffect(() => () => window.clearTimeout(cerrarTimerRef.current), []);

  /* La sincronizacion del estado local con el profesor abierto se hace en el
     propio render (mas abajo), no en un useEffect: con el efecto, durante un
     instante se veian los datos del profesor anterior y despues el cambio. */

  const [asigAnio, setAsigAnio] = useState('');
  const [asigDivision, setAsigDivision] = useState('');
  const [asigGrupo, setAsigGrupo] = useState('');
  const [asigMateria, setAsigMateria] = useState('');

  const [materiasOpts, setMateriasOpts] = useState([]);
  const [loadingMat, setLoadingMat] = useState(false);

  const [divisionesMap, setDivisionesMap] = useState({});

  React.useEffect(() => {
    if (!isOpen) return;
    let cancel = false;
    (async () => {
      try {
        const mapa = await cargarDivisiones();
        if (!cancel) setDivisionesMap(mapa || {});
      } catch {
        if (!cancel) setDivisionesMap({});
      }
    })();
    return () => { cancel = true; };
  }, [isOpen]);

  /* Cursos combinados (año + division) agrupados por año, construidos desde /divisiones */
  const cursosOptions = React.useMemo(() => {
    const keys = Object.keys(divisionesMap || {}).map(n => parseInt(n, 10)).filter(Number.isFinite);
    if (keys.length) return buildCursosDisponiblesFromMap(divisionesMap);
    return [];
  }, [divisionesMap]);

  React.useEffect(() => {
    if (!asigAnio) { setMateriasOpts([]); setAsigMateria(''); return; }
    const n = anioNumFromLabel(asigAnio);
    if (!n) return;
    let cancel = false;
    (async () => {
      try {
        setLoadingMat(true);
        const lista = await apiGet(`/anios/${n}/materias`);
        if (!cancel) {
          setMateriasOpts((lista || []).map(m => ({ label: m.nombre, value: m.nombre })));
          if (!lista?.some(m => m.nombre === asigMateria)) setAsigMateria('');
        }
      } catch {
        if (!cancel) setMateriasOpts([]);
      } finally {
        if (!cancel) setLoadingMat(false);
      }
    })();
    return () => { cancel = true; };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [asigAnio]);

  /* Verifica si se puede agregar una asignacion (anio y materia seleccionados) */
  const canAddAsig = Boolean(asigAnio && asigMateria);

  /* Valida que los campos obligatorios esten completos */
  const validarDetalle = () =>
    !local?.nombre?.trim() || !local?.apellido?.trim() || !local?.estado || (local?.asignaciones?.length || 0) === 0;

  /* Guarda los cambios delegando en el padre (que hace el PUT y actualiza el estado) */
  // Evita el doble-click (duplicaba el guardado).
  const [guardando, setGuardando] = useState(false);
  const handleGuardar = async () => {
    if (guardando) return;
    const email = String(local.email || '').trim();
    if (email && !isValidEmail(email)) {
      setErrors(prev => ({ ...prev, email: true }));
      await mostrarAviso({ icon: 'warning', title: 'Revisá el correo', text: 'El correo electrónico no es válido.' });
      return;
    }

    const dniDigits = String(local.dni || '').replace(/\D/g, '');
    if (dniDigits.length < 7 || dniDigits.length > 8) {
      setErrors(prev => ({ ...prev, dni: true }));
      await mostrarAviso({ icon: 'warning', title: 'Revisá el DNI', text: 'El DNI debe tener entre 7 y 8 dígitos.' });
      return;
    }

    if (validarDetalle()) {
      await mostrarAviso({
        icon: 'warning',
        title: 'Faltan campos obligatorios',
        text: 'Completá Nombre, Apellido, Estado y cargá al menos una asignación.',
      });
      return;
    }

    const payload = {
      id: local.id,
      nombre: local.nombre.trim(),
      apellido: local.apellido.trim(),
      email: email || null,
      dni: local.dni.trim() || null,
      estado: local.estado,
      suplente: !!local.suplente,
      observaciones: local.observaciones || '',
      asignaciones: (local.asignaciones || [])
        .filter(a => String(a?.materia ?? '').trim())
        .map(a => ({
          anio: (() => {
            const s = String(a?.anio ?? '');
            const n = parseInt(s.replace(/\D/g, ''), 10);
            return n || null;
          })(),
          division: String(a?.division ?? '').trim() || null,
          grupo: a.grupo || null,
          materia: String(a?.materia ?? '').trim()
        })),
    };

    // Cambio de rol: se pide la contraseña ANTES de guardar para no dejar
    // el resto aplicado y el rol pendiente si el usuario cancela.
    const cambiaraRol = rolSeleccionado && rolSeleccionado !== rolActual;
    if (cambiaraRol && !(await confirmarContrasena())) return;

    setGuardando(true);
    try {
      await actualizarProfesor(payload);          // el padre hace el PUT y cierra el modal
      // El DNI se guarda directamente en el profesor (ver ProfesorController).

      // Cambio de rol (si el usuario lo modifico en la edicion)
      if (cambiaraRol) {
        await apiAdminUpdateUserRole(local.id, rolSeleccionado);
        // Si el nuevo rol no requiere cursos, se limpian las asignaciones previas.
        if (ROLES_SIN_ASIGNACIONES.includes(rolSeleccionado)) {
          await apiAdminSaveAssignments(local.id, []);
        }
      }

    } catch (e) {
      await mostrarAviso({ icon: 'error', title: 'No se pudo guardar', text: mensajeAmable(e, 'No se pudo guardar al profesor.') });
    } finally {
      setGuardando(false);
    }
  };

  /* Delega la eliminacion al padre, incluyendo metadatos del usuario que realiza la baja */
  const handleEliminar = () => {
    // ⚠️ PASO NUEVO: mandamos meta con el usuario que realiza la baja
    const meta = {
      by_user_id:   currentUser?.id ?? null,
      by_user_name: currentUser?.name ?? null,
      by_user_role: currentUser?.role ?? null,
    };
    eliminarProfesor?.(local.id, meta); // el padre puede usar este 2º parámetro para guardar en BD
  };

  /* Actualiza un campo del estado local */
  const setField = (patch) => setLocal(prev => ({ ...prev, ...patch }));

  /* Agrega una nueva asignacion a la lista local */
  const agregarAsignacion = () => {
    if (!canAddAsig) return;
    setField({
      asignaciones: [
        ...(local.asignaciones || []),
        { anio: asigAnio, division: (asigDivision || '').trim().toUpperCase(), grupo: asigGrupo || null, materia: asigMateria }
      ]
    });
    setAsigAnio(''); setAsigDivision(''); setAsigGrupo(''); setAsigMateria('');
  };

  /* Elimina una asignacion por su indice en la lista */
  const eliminarAsignacion = (i) => {
    setField({ asignaciones: (local.asignaciones || []).filter((_, idx) => idx !== i) });
  };

  /* Convierte scroll vertical en horizontal para la seccion de chips */
  const chipsRef = useRef(null);
  const handleWheelToHorizontal = (e) => {
    if (e.deltaY !== 0) {
      e.preventDefault();
      e.currentTarget.scrollLeft += e.deltaY;
    }
  };

  /* ====== Deteccion de cambios sin guardar + confirmacion al cerrar ====== */
  const normalizeAsignaciones = (list = []) => {
    const mapped = (list || []).map(a => ({
      anio: labelAnio(a?.anio ?? ''),
      division: String(a?.division ?? '').trim().toUpperCase(),
      grupo: a.grupo || null,
      materia: String(a?.materia ?? '').trim(),
    })).filter(a => a.anio || a.materia);
    mapped.sort((a, b) =>
      (a.anio || '').localeCompare(b.anio || '') ||
      (a.division || '').localeCompare(b.division || '') ||
      (a.materia || '').localeCompare(b.materia || '')
    );
    return mapped;
  };

  const sanitizeFromLocal = (obj) => ({
    nombre: (obj?.nombre || '').trim(),
    apellido: (obj?.apellido || '').trim(),
    email: (obj?.email || '').trim(),
    dni: (obj?.dni || '').trim(),
    estado: obj?.estado || 'Activo',
    suplente: !!obj?.suplente,
    observaciones: obj?.observaciones || '',
    asignaciones: normalizeAsignaciones(obj?.asignaciones || []),
  });

  const sanitizeFromProfesor = (p) => ({
    nombre: (p?.nombre || '').trim(),
    apellido: (p?.apellido || '').trim(),
    email: (p?.email || '').trim(),
    dni: (p?.dni || '').trim(),
    estado: p?.estado || 'Activo',
    suplente: !!p?.suplente,
    observaciones: p?.observaciones || '',
    asignaciones: normalizeAsignaciones(toEditableAsignaciones(p?.asignaciones || [])),
  });

  const [snapshot, setSnapshot] = useState(null);

  // Ajuste de estado durante el render: al abrir otro profesor, local, roles,
  // errores y snapshot se sincronizan ANTES del primer pintado del modal.
  const [profesorAbiertoId, setProfesorAbiertoId] = useState(null);
  const profesorAbiertoIdActual = isOpen ? (profesor?.id ?? null) : null;
  if (profesorAbiertoIdActual !== profesorAbiertoId) {
    setProfesorAbiertoId(profesorAbiertoIdActual);
    if (profesorAbiertoIdActual != null) {
      const rol = profesor?.role || 'profesor';
      setRolActual(rol);
      setRolSeleccionado(rol);
      setLocal({
        id: profesor?.id ?? null,
        user_id: profesor?.user_id ?? null,
        nombre: profesor?.nombre ?? '',
        apellido: profesor?.apellido ?? '',
        email: profesor?.email ?? '',
        dni: profesor?.dni ?? '',
        estado: profesor?.estado ?? 'Activo',
        suplente: !!profesor?.suplente,
        asignaciones: toEditableAsignaciones(profesor?.asignaciones || []),
        observaciones: profesor?.observaciones ?? '',
      });
      setErrors({ email: false });
      setSnapshot(sanitizeFromProfesor(profesor));
      // Si quedo un cierre pendiente, lo cancelamos al abrir otro profesor.
      if (cerrando) setCerrando(false);
      window.clearTimeout(cerrarTimerRef.current);
      cerrandoRef.current = false;
    }
  }

  const hasDirty = () => {
    if (!snapshot) return false;
    const cur = sanitizeFromLocal(local);
    return JSON.stringify(cur) !== JSON.stringify(snapshot);
  };

  const handleCloseAttempt = async () => {
    if (!hasDirty()) {
      cerrarConAnimacion();
      return;
    }
    const res = await pedirConfirmacion({
      icon: 'warning',
      title: '¿Descartar cambios?',
      text: 'Tenés cambios sin guardar. Si cerrás ahora, se perderán.',
      confirmText: 'Descartar',
      cancelText: 'Seguir editando',
    });
    if (res) {
      cerrarConAnimacion();
    }
  };

  return (
    <>
      {isOpen && (
        <div className={`det-backdrop${cerrando ? ' is-closing' : ''}`}>
          <div
            className={`det-modal${cerrando ? ' is-closing' : ''}`}
            role="dialog" aria-modal="true"
          >
            {/* Barra superior de acento */}
            <div className="det-accent" />

            {/* Cerrar */}
            <button
              onClick={handleCloseAttempt}
              aria-label="Cerrar"
              className="det-close"
            >
              <X size={20} />
            </button>

            {/* Hero header */}
            <div className="det-hero">
              <div className="det-halo det-halo--blue" />
              <div className="det-halo det-halo--purple" />
              <div className="det-hero-inner">
                <h2 className="det-hero-title">Editar profesor</h2>
              </div>
            </div>

            {/* BODY */}
            <div className="det-body">
              {/* Datos básicos + Estado + Suplente */}
              <div className="det-card">
              <div className="det-grid">
                <div className="det-field">
                  <label htmlFor="pf-nombre" className={fieldLabel}>Nombre {!soloLectura && req}</label>
                  {soloLectura ? (
                    <div className="det-static">{local.nombre}</div>
                  ) : (
                  <input
                    id="pf-nombre"
                    type="text"
                    placeholder="Ej.: Juan"
                    value={local.nombre}
                    onChange={(e) => setField({ nombre: e.target.value })}
                    className={`det-input ${!local?.nombre?.trim() ? 'det-input--error' : ''}`}
                  />
                  )}
                </div>

                <div className="det-field">
                  <label htmlFor="pf-apellido" className={fieldLabel}>Apellido {!soloLectura && req}</label>
                  {soloLectura ? (
                    <div className="det-static">{local.apellido}</div>
                  ) : (
                  <input
                    id="pf-apellido"
                    type="text"
                    placeholder="Ej.: Pérez"
                    value={local.apellido}
                    onChange={(e) => setField({ apellido: e.target.value })}
                    className={`det-input ${!local?.apellido?.trim() ? 'det-input--error' : ''}`}
                  />
                  )}
                </div>

                <div className="det-field">
                  <span className={fieldLabel}>Estado {!soloLectura && req}</span>
                  {soloLectura ? (
                    <div className="det-static">{local.estado || 'Activo'}</div>
                  ) : (
                  <SimpleDropdown
                    placeholder="Seleccionar"
                    options={ESTADO_OPTS}
                    value={local.estado}
                    onChange={(val) => setField({ estado: val })}
                    error={!local?.estado}
                  />
                  )}
                </div>

                <div className="det-field det-field--span3">
                  <label htmlFor="pf-email" className={fieldLabel}>Correo electrónico</label>
                  {soloLectura ? (
                    <div className="det-static">{local.email || '-'}</div>
                  ) : (
                  <input
                    id="pf-email"
                    type="email"
                    placeholder="nombre@colegio.edu"
                    value={local.email}
                    onChange={(e) => {
                      const next = e.target.value;
                      setField({ email: next });
                      if (errors.email && isValidEmail(next)) {
                        setErrors(prev => ({ ...prev, email: false }));
                      }
                    }}
                    onBlur={(e) => {
                      const val = e.target.value;
                      setErrors(prev => ({ ...prev, email: Boolean(val) && !isValidEmail(val) }));
                    }}
                    aria-invalid={errors.email ? 'true' : 'false'}
                    className={`det-input ${errors.email ? 'det-input--error' : ''}`}
                  />
                  )}
                  {!soloLectura && errors.email && (
                    <p className="det-error-msg">
                      Ingresá un correo válido (ej.: nombre@colegio.edu).
                    </p>
                  )}
                </div>

                <div className="det-field det-field--span3">
                  <label htmlFor="pf-dni" className={fieldLabel}>DNI</label>
                  {soloLectura ? (
                    <div className="det-static">{local.dni || '-'}</div>
                  ) : (
                  <input
                    id="pf-dni"
                    type="text"
                    inputMode="numeric"
                    maxLength={8}
                    placeholder="Ej.: 30123456"
                    value={local.dni}
                    onChange={(e) => setField({ dni: e.target.value.replace(/\D/g, '') })}
                    className={`det-input ${errors?.dni ? 'det-input-error' : ''}`}
                  />
                  )}
                </div>

                {!soloLectura && (
                <div className="det-field--span3">
                  <label className="det-checkbox-label">
                    <input
                      type="checkbox"
                      checked={!!local.suplente}
                      onChange={(e) => setField({ suplente: e.target.checked })}
                      className="det-checkbox"
                    />
                    <span className="det-checkbox-text">Suplente (destildado = Titular)</span>
                  </label>
                </div>
                )}
              </div>
              </div>

              {/* Rol (solo en edicion) */}
              {!soloLectura && (
              <div className="det-card">
                <h3 className="det-card-title det-card-title--mb2">Rol</h3>
                <div className="det-rol-row">
                  <SimpleDropdown
                    placeholder="Seleccionar rol"
                    options={ROLES_DISPONIBLES}
                    value={rolSeleccionado}
                    onChange={setRolSeleccionado}
                  />
                  {rolSeleccionado !== rolActual && (
                    <span className="det-rol-pill">
                      Se cambiará de «{ROLES_DISPONIBLES.find(r => r.value === rolActual)?.label || rolActual}» a «{ROLES_DISPONIBLES.find(r => r.value === rolSeleccionado)?.label || rolSeleccionado}»
                    </span>
                  )}
                </div>
                {ROLES_SIN_ASIGNACIONES.includes(rolSeleccionado) && (
                  <p className="det-note">Los roles de control total no requieren asignaciones de cursos.</p>
                )}
              </div>
              )}

              {/* Asignaciones */}
              {!ROLES_SIN_ASIGNACIONES.includes(rolSeleccionado) && (
              <div className="det-card">
                <h3 className="det-card-title det-card-title--blue">Asignaciones (Año / División / Materia)</h3>

                {!soloLectura && (
                <div className="det-asig-form">
                  <div className="det-asig-field det-asig-field--curso">
                    <span className={fieldLabel}>Curso</span>
                    <CursoDropdownInline
                      cursosOptions={cursosOptions}
                      asigAnio={asigAnio}
                      asigDivision={asigDivision}
                      onSelect={(a, d) => { setAsigAnio(a); setAsigDivision(d); setAsigMateria(''); }}
                    />
                  </div>

                  <div className="det-asig-field det-asig-field--grupo">
                    <span className={fieldLabel}>Grupo</span>
                    <div className="det-grupo-opts">
                      {['', 'A', 'B'].map(g => (
                        <label key={g || '—'} className="det-grupo-opt">
                          <input type="radio" name="detGrupo" value={g}
                            checked={asigGrupo === g}
                            onChange={() => setAsigGrupo(g)}
                            className="det-radio" />
                          {g || '—'}
                        </label>
                      ))}
                    </div>
                  </div>

                  <div className="det-asig-field det-asig-field--materia">
                    <span className={fieldLabel}>Materia</span>
                    <SimpleDropdown
                      placeholder={loadingMat ? 'Cargando...' : 'Seleccionar'}
                      options={materiasOpts}
                      value={asigMateria}
                      onChange={setAsigMateria}
                      disabled={!asigAnio || loadingMat}
                    />
                  </div>

                  <div className="det-asig-add-wrap">
                    <button
                      type="button"
                      onClick={agregarAsignacion}
                      disabled={!canAddAsig}
                      className="det-asig-add"
                    >
                      <Plus size={16} /> Agregar
                    </button>
                  </div>
                </div>
                )}

                {/* Chips de asignaciones (scroll horizontal) */}
                <div
                  ref={chipsRef}
                  onWheel={handleWheelToHorizontal}
                  className="det-chips"
                >
                  {(local.asignaciones || []).map((a, i) => {
                    const g = a.grupo ? ` Grp.${a.grupo}` : '';
                    return (
                      <span
                        key={`${a.anio}-${a.division}-${a.grupo}-${a.materia}-${i}`}
                        title={`${cursoLabel({ anio: a.anio, division: a.division })}${g} - ${a.materia}`}
                        className="det-chip"
                        data-anio={a.anio}
                        data-division={a.division}
                        data-grupo={a.grupo}
                      >
                        <span className="det-chip-text">{cursoLabel({ anio: a.anio, division: a.division })}{g} - {a.materia}</span>
                        {!soloLectura && (
                        <button
                          onClick={() => eliminarAsignacion(i)}
                          className="det-chip-del"
                          aria-label="Eliminar asignación"
                        >
                          <Trash2 size={12} />
                        </button>
                        )}
                      </span>
                    );
                  })}
                </div>

                {(local.asignaciones || []).length === 0 && (
                  <p className="det-hint">{soloLectura ? 'Sin asignaciones.' : 'Agregá al menos una asignación.'}</p>
                )}
              </div>
              )}

              {/* Suplencias: quién lo cubre y a quién cubre, por materia */}
              {profesor?.id && (
              <SuplenciasCard
                profesorId={profesor.id}
                asignaciones={local.asignaciones || []}
                soloLectura={soloLectura}
                onCambio={onSuplenciasChange}
              />
              )}

              {/* Observaciones */}
              <div className="det-card">
                <h3 className="det-card-title det-card-title--mb3">Observaciones</h3>
                <div className="det-field">
                  <label htmlFor="pf-obs" className={fieldLabel}>Comentarios / información adicional</label>
                  {soloLectura ? (
                    <div className="det-static det-static--obs">{local.observaciones || '-'}</div>
                  ) : (
                  <textarea
                    id="pf-obs"
                    rows={4}
                    placeholder="Info. adicional del profesor"
                    value={local.observaciones}
                    onChange={(e) => setField({ observaciones: e.target.value })}
                    className="det-textarea"
                  />
                  )}
                </div>
              </div>
            </div>

            {/* Footer */}
            {!soloLectura && (
            <div className="det-footer">
              <div>
                <button onClick={handleEliminar} className="det-btn-danger">Dar de baja</button>
              </div>
              <div className="det-footer-actions">
                <button onClick={handleCloseAttempt} className="det-btn-secondary">Cancelar</button>
                <button onClick={handleGuardar} disabled={guardando} className="det-btn-primary">{guardando ? 'Guardando…' : 'Guardar'}</button>
              </div>
            </div>
            )}
          </div>
        </div>
      )}
      {avisoModal}
      {modalPwd}
    </>
  );
};

export default ProfesoresDetalle;



