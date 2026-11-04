// Pagina de gestion de preceptores.
// Incluye un modal interno (PreceptorModal) para crear/editar,
// busqueda, filtros y eliminacion con confirmacion.
import './Preceptores.css';
import React, { useEffect, useState, useMemo, useRef } from 'react';
import { Search, UsersRound, X, Shield, Eye, EyeOff, Lock, Pencil, Trash2, SlidersHorizontal } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import useAviso from '../../compartido/componentes/modals/useAviso';
import usePasswordPrompt from '../../compartido/componentes/modals/usePasswordPrompt';
import { apiAdminUsersByRole, apiAdminCreateUser, apiAdminUpdateUser, apiAdminDeleteUser, apiAdminGetAssignments, apiAdminSaveAssignments } from '../../compartido/api/auth';
import Breadcrumbs from '../../compartido/componentes/Common/Breadcrumbs';
import PreceptorFicha from './PreceptorFicha';
import DropdownFiltroAnios from '../alumnos/DropdownFiltroAnios';
import DropdownFiltroEstado from '../alumnos/DropdownFiltroEstado';
import { useAuth } from '../auth/AuthContext';
import { getCached, setCache } from '../../compartido/lib/pageCache';
import { cargarDivisiones } from '../../compartido/lib/divisiones';
import { apiAdminUpdateUserRole } from '../../compartido/api/auth';
import { cursoLabel, parseCurso, divisionToOrdinal, divisionToDato, cursoEnAsignaciones } from '../../compartido/lib/cursos';
import useConfirmAction from '../../compartido/hooks/useConfirmAction';
import { mensajeAmable } from '../../compartido/lib/errores';
import { ORDENES, ORDEN_DEFAULT, ordenarPersonas, leerOrden, guardarOrden } from '../../compartido/lib/orden';
import { coincideBusqueda } from '../../compartido/lib/busqueda';
import DropdownMini from '../../compartido/componentes/Common/Dropdownmini';
import { useToast } from '../../compartido/componentes/Common/Toast';
import { useDeshacerAviso } from '../../compartido/lib/deshacer.jsx';
import useAvisoDeCambios from '../../compartido/hooks/useAvisoDeCambios';

const ROLES_DISPONIBLES = [
  { label: 'Profesor', value: 'profesor' },
  { label: 'Preceptor', value: 'preceptor' },
  { label: 'Jefe de preceptores', value: 'jefe_preceptores' },
  { label: 'Directivo', value: 'directivo' },
  { label: 'Admin', value: 'admin' },
];

// Roles que NO requieren asignaciones de cursos (controlan todo el sistema).
const ROLES_SIN_ASIGNACIONES = ['jefe_preceptores', 'directivo', 'admin'];

/* ---- Dropdown simple reutilizable ---- */
function CursoDropdownInline({ cursosDisponibles = [], anio, division, onSelect }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    const handleClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, []);
  const label = (anio && division)
    ? cursoLabel({ anio: Number(anio), division })
    : (anio ? cursoLabel({ anio: Number(anio), division: null }) + ' (todas)' : 'Seleccionar curso');
  return (
    <div className="prec-dropdown-wrap" ref={ref}>
      <button type="button" onClick={() => setOpen(o => !o)}
        className="prec-dropdown-trigger">
        {label}
      </button>
      {open && (
        <div className="prec-dropdown-menu">
          {cursosDisponibles.map(grupo => (
            <div key={grupo.anio}>
              <div className="prec-dropdown-group-label">{grupo.anio} Año</div>
              {grupo.divisiones.map(div => {
                const cursoStr = `${grupo.anio} ${div}`;
                return (
                  <div key={cursoStr} onClick={() => { onSelect(String(parseCurso(grupo.anio).anio), divisionToDato(div)); setOpen(false); }}
                    className="prec-dropdown-item">{cursoStr}</div>
                );
              })}
              <div onClick={() => { onSelect(String(parseCurso(grupo.anio).anio), ''); setOpen(false); }}
                className="prec-dropdown-item prec-dropdown-item-all">Todas las divisiones</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function SimpleDropdown({ placeholder = 'Seleccionar', options = [], value, onChange, error, disabled = false }) {
  return (
    <div className={`prec-select-wrap ${disabled ? 'prec-select-disabled' : ''}`}>
      <select
        value={value || ''}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        className={`prec-select ${error ? 'prec-select-error' : ''}`}
      >
        <option value="" disabled>{placeholder}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
      {error && <span className="prec-select-error-msg">{error}</span>}
    </div>
  );
}

const statusLabels = {
  active: 'Activo',
  pending: 'Pendiente',
  rejected: 'Rechazado',
};

// Modal para crear o editar un preceptor: formulario con datos personales, contrasena y asignaciones
function PreceptorModal({ visible, onClose, preceptor, onSave, cursosDisponibles, targetRole = 'preceptor', onDarDeBaja }) {
  const isEdit = preceptor?.id;
  // Estado del formulario: datos basicos y lista de asignaciones (anio/division)
  const [form, setForm] = useState({ name: '', apellido: '', email: '', dni: '', password: '', passwordConfirm: '', asignaciones: [] });
  // Rol actual (solo en edicion) y rol seleccionado para el cambio
  const [rolActual, setRolActual] = useState(targetRole);
  const [rolSeleccionado, setRolSeleccionado] = useState(targetRole);
  // Control de visibilidad de contrasenas
  const [showPassword, setShowPassword] = useState(false);
  const [showPasswordConfirm, setShowPasswordConfirm] = useState(false);
  const [anio, setAnio] = useState('');
  const [division, setDivision] = useState('');
  const [errors, setErrors] = useState({});
  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, , avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const { ofrecerDeshacer } = useDeshacerAviso();

  useEffect(() => {
    if (visible) {
      if (preceptor?.id) {
        const rol = preceptor.role || targetRole;
        setRolActual(rol);
        setRolSeleccionado(rol);
        setForm({
          name: preceptor.name || '',
          apellido: preceptor.apellido || '',
          email: preceptor.email || '',
          dni: preceptor.dni || '',
          password: '',
          passwordConfirm: '',
          asignaciones: preceptor.asignaciones || [],
        });
      } else {
        setRolActual(targetRole);
        setRolSeleccionado(targetRole);
        setForm({ name: '', apellido: '', email: '', dni: '', password: '', passwordConfirm: '', asignaciones: [] });
      }
      setAnio('');
      setDivision('');
      setErrors({});
    }
    // Solo al abrir o al cambiar de usuario: si no, el fetch de asignaciones
    // que llega despues de abrir pisaba lo que se estaba editando.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible, preceptor?.id]);

  // Roles que NO requieren asignaciones de cursos: ocultamos la seccion.
  const ocultarAsignaciones = ROLES_SIN_ASIGNACIONES.includes(isEdit ? rolSeleccionado : targetRole);

  // Agrega una asignacion (año/division) a la lista del preceptor
  let assignKeyCounter = useRef(0);
  const agregarAsignacion = () => {
    if (!anio) return;
    const exists = form.asignaciones.some(a => a.anio === Number(anio) && (a.division || '').toUpperCase() === (division || '').toUpperCase());
    if (exists) { mostrarAviso({ icon: 'info', title: 'Ya existe esa asignación' }); return; }
    const key = Date.now() + assignKeyCounter.current++;
    setForm(prev => ({ ...prev, asignaciones: [...prev.asignaciones, { anio: Number(anio), division: division || null, _key: key }] }));
    setDivision('');
  };

  // Quita una asignacion de la lista por su indice
  const quitarAsignacion = (idx) => {
    setForm(prev => ({ ...prev, asignaciones: prev.asignaciones.filter((_, i) => i !== idx) }));
  };

  // Valida los campos obligatorios y guarda el preceptor (creacion o actualizacion)
  // Evita el doble-click (duplicaba el guardado).
  const [guardando, setGuardando] = useState(false);
  const handleSave = async () => {
    if (guardando) return;
    // Validacion de campos obligatorios
    const err = {};
    if (!form.name.trim()) err.name = true;
    if (!form.apellido.trim()) err.apellido = true;
    if (!form.email.trim()) err.email = true;
    const dniDigits = form.dni.replace(/\D/g, '');
    if (dniDigits.length < 7 || dniDigits.length > 8) err.dni = true;
    if (!isEdit && !form.password) err.password = true;
    if (form.password && form.password !== form.passwordConfirm) err.passwordConfirm = true;
    setErrors(err);
    if (Object.keys(err).length > 0) {
      const msg = err.dni ? 'El DNI debe tener entre 7 y 8 dígitos.' : err.passwordConfirm ? 'Las contraseñas no coinciden.' : 'Completá los campos marcados en rojo.';
      mostrarAviso({ icon: 'warning', title: 'Revisá los datos', text: msg });
      return;
    }

    // Cambio de rol: exige contraseña (el servidor la valida).
    if (isEdit && rolSeleccionado && rolSeleccionado !== rolActual && !(await confirmarContrasena())) return;

    setGuardando(true);
    let createdId = null;
    const msg = isEdit ? 'Describí los cambios que realizás en este usuario.' : 'Describí el motivo por el que creás este usuario.';
    try {
      const detalles = await confirmAction(msg, async (d) => {
        const payload = {
          name: form.name.trim(),
          apellido: form.apellido.trim(),
          email: form.email.trim(),
          dni: form.dni.trim() || null,
          role: targetRole,
          _detalles: d,
          ...(form.password ? { password: form.password } : {}),
        };

        if (isEdit) {
          await apiAdminUpdateUser(preceptor.id, payload);
          if (form.asignaciones?.length) {
            await apiAdminSaveAssignments(preceptor.id, form.asignaciones, d);
          }
          if (rolSeleccionado && rolSeleccionado !== rolActual) {
            await apiAdminUpdateUserRole(preceptor.id, rolSeleccionado, d);
            if (ROLES_SIN_ASIGNACIONES.includes(rolSeleccionado)) {
              await apiAdminSaveAssignments(preceptor.id, [], d);
            }
          }
        } else {
          payload.status = 'active';
          const created = await apiAdminCreateUser(payload);
          createdId = created?.id || null;
          if (form.asignaciones.length && created?.id) {
            await apiAdminSaveAssignments(created.id, form.asignaciones, d);
          }
        }
      });
      if (detalles === null) { setGuardando(false); return; }

      onSave?.();
      // Deshacer: en creación revierte el alta (borra el usuario); en
      // edición restaura los datos y asignaciones anteriores.
      ofrecerDeshacer(
        {
          etiqueta: preceptor?.id
            ? `Usuario ${preceptor?.name || ''} ${preceptor?.apellido || ''}`.trim()
            : 'Alta de usuario',
          exigePassword: !preceptor?.id,
          deshacer: async () => {
            if (preceptor?.id) {
              const orig = preceptor;
              const rolOrig = orig.role || rolActual;
              await apiAdminUpdateUser(orig.id, {
                name: orig.name || '',
                apellido: orig.apellido || '',
                email: orig.email || '',
                dni: orig.dni || null,
                role: rolOrig,
                _detalles: 'Deshace el último guardado',
              });
              await apiAdminSaveAssignments(orig.id, orig.asignaciones || [], 'Deshace el último guardado');
              if (rolSeleccionado && rolSeleccionado !== rolActual) {
                await apiAdminUpdateUserRole(orig.id, rolOrig);
                if (ROLES_SIN_ASIGNACIONES.includes(rolOrig)) {
                  await apiAdminSaveAssignments(orig.id, [], 'Deshace el último guardado');
                }
              }
            } else if (createdId) {
              await apiAdminDeleteUser(createdId);
            }
            onSave?.();
          },
        },
        preceptor?.id ? 'Cambios guardados correctamente' : 'Usuario creado correctamente'
      );
      onClose();
    } catch (e) {
      mostrarAviso({ icon: 'error', title: 'No se pudo guardar', text: mensajeAmable(e, 'No se pudo guardar. Revisá los datos.') });
    } finally {
      setGuardando(false);
    }
  };

    return (
    <>
    <AnimatePresence>
      {visible && (
        <motion.div
          key="preceptor-modal-backdrop"
          initial={false} exit={{ opacity: 0 }}
          transition={{ duration: 0.18, ease: "easeOut" }}
          className="prec-modal-backdrop"
        >
          <motion.div
            key="preceptor-modal"
            initial={false}
            exit={{ opacity: 0, scale: 0.96, y: 12 }}
            transition={{ duration: 0.24, ease: [0.25, 0.46, 0.45, 0.94] }}
            className="prec-modal"
            role="dialog" aria-modal="true"
          >
            {/* Top Accent */}
            <div className="prec-modal-top-bar" />

            {/* Hero header */}
            <div className="prec-modal-hero">
              <div className="prec-halo prec-halo-blue" />
              <div className="prec-halo prec-halo-purple" />

              <div className="prec-modal-header">
                <h3 className="prec-modal-title">{isEdit ? 'Editar ' : 'Agregar '}{targetRole === 'jefe_preceptores' ? 'jefe de preceptores' : 'preceptor'}</h3>
                <button
                  onClick={onClose}
                  className="prec-modal-close"
                ><X size={20} /></button>
              </div>
            </div>
            <div className="prec-modal-body">
              {/* Tarjeta: Rol (solo en edicion, para quienes pueden gestionar) */}
              {isEdit && (
                <div className="prec-card">
                  <label className="prec-label-strong">Rol actual</label>
                  <div className="prec-inline-wrap">
                    <SimpleDropdown
                      placeholder="Seleccionar rol"
                      options={ROLES_DISPONIBLES}
                      value={rolSeleccionado}
                      onChange={setRolSeleccionado}
                    />
                    {rolSeleccionado !== rolActual && (
                      <span className="prec-role-change-badge">
                        Se cambiará de «{ROLES_DISPONIBLES.find(r => r.value === rolActual)?.label || rolActual}» a «{ROLES_DISPONIBLES.find(r => r.value === rolSeleccionado)?.label || rolSeleccionado}»
                      </span>
                    )}
                  </div>
                  {ROLES_SIN_ASIGNACIONES.includes(rolSeleccionado) && (
                    <p className="prec-hint">Los roles de control total no requieren asignaciones de cursos.</p>
                  )}
                </div>
              )}

              {/* Tarjeta: Datos personales */}
              <div className="prec-card prec-card-stack">
                <div className="prec-grid-2">
                  <div>
                    <label className="prec-label">Nombre</label>
                    <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                      className={`prec-input ${errors.name ? 'prec-input-error' : ''}`} />
                  </div>
                  <div>
                    <label className="prec-label">Apellido</label>
                    <input value={form.apellido} onChange={e => setForm(f => ({ ...f, apellido: e.target.value }))}
                      className={`prec-input ${errors.apellido ? 'prec-input-error' : ''}`} />
                  </div>
                </div>
                <div>
                  <label className="prec-label">DNI</label>
                  <input value={form.dni} onChange={e => setForm(f => ({ ...f, dni: e.target.value.replace(/\D/g, '') }))} maxLength={8}
                    className="prec-input" />
                </div>
              </div>

              {/* Tarjeta: Credenciales */}
              <div className="prec-card prec-card-stack">
                <div>
                  <label className="prec-label">Email</label>
                  <input type="email" value={form.email} onChange={e => setForm(f => ({ ...f, email: e.target.value }))}
                    className={`prec-input ${errors.email ? 'prec-input-error' : ''}`} />
                </div>
                <div className="prec-grid-2">
                  {/* Campo de contrasena con toggle de visibilidad */}
                  <div>
                    <label className="prec-label">{isEdit ? 'Nueva contraseña (opcional)' : 'Contraseña'}</label>
                    <div className="prec-input-wrap">
                      <input type={showPassword ? 'text' : 'password'} value={form.password}
                        onChange={e => setForm(f => ({ ...f, password: e.target.value }))}
                        className={`prec-input prec-input-password ${errors.password ? 'prec-input-error' : ''}`} />
                      <button type="button" onClick={() => setShowPassword(s => !s)}
                        className="prec-input-toggle">
                        {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                      </button>
                    </div>
                  </div>
                  {/* Campo de confirmacion de contrasena con toggle de visibilidad */}
                  <div>
                    <label className="prec-label">Confirmar contraseña</label>
                    <div className="prec-input-wrap">
                      <input type={showPasswordConfirm ? 'text' : 'password'} value={form.passwordConfirm}
                        onChange={e => setForm(f => ({ ...f, passwordConfirm: e.target.value }))}
                        className={`prec-input prec-input-password ${errors.passwordConfirm ? 'prec-input-error' : ''}`} />
                      <button type="button" onClick={() => setShowPasswordConfirm(s => !s)}
                        className="prec-input-toggle">
                        {showPasswordConfirm ? <EyeOff size={18} /> : <Eye size={18} />}
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              {/* Tarjeta: Asignaciones (oculta para roles de control total) */}
              {!ocultarAsignaciones && (
              <div className="prec-card">
                <label className="prec-label-strong-lg">Asignaciones (años y divisiones)</label>
                <div className="prec-assign-row">
                  <CursoDropdownInline
                    cursosDisponibles={cursosDisponibles}
                    anio={anio}
                    division={division}
                    onSelect={(a, d) => { setAnio(a); setDivision(d); }}
                  />
                  <button onClick={agregarAsignacion} disabled={!anio}
                    className="prec-btn-add">Agregar</button>
                </div>
                {form.asignaciones.length > 0 && (
                  <div className="prec-chips">
                    {form.asignaciones.map((a, i) => (
                        <span key={a._key ?? i} className="prec-chip">
                         {cursoLabel({ anio: a.anio, division: a.division }) || '(todas)'}{a.anio && !a.division ? ' (todas)' : ''}
                        <button onClick={() => quitarAsignacion(i)} className="prec-chip-remove"><X size={14} /></button>
                      </span>
                    ))}
                  </div>
                )}
              </div>
              )}
            </div>
            <div className="prec-modal-footer">
              <div>
                {preceptor && onDarDeBaja && (
                  <button onClick={() => onDarDeBaja(preceptor)} className="prec-btn-danger">Dar de baja</button>
                )}
              </div>
              <div className="prec-btn-group">
                <button onClick={onClose} className="prec-btn-ghost">Cancelar</button>
                <button onClick={handleSave} disabled={guardando} className="prec-btn-primary">{guardando ? 'Guardando…' : 'Guardar'}</button>
              </div>
            </div>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
    {confirmModal}
    {avisoModal}
    {modalPwd}
    </>
  );
}

// Componente principal de la pagina de Preceptores

const Preceptores = ({ role: targetRole = 'preceptor' }) => {
  const { user } = useAuth();
  const isPreceptorUser = user?.role === 'preceptor';
  const readonly = isPreceptorUser;
  const cacheKey = `preceptores/${targetRole}`;

  const [preceptores, setPreceptores] = useState(() => getCached(cacheKey) || []);
  const [loading, setLoading] = useState(() => !getCached(cacheKey));
  const [errorCarga, setErrorCarga] = useState(null);
  const [busqueda, setBusqueda] = useState('');
  const [filtroEstado, setFiltroEstado] = useState('todos');
  const [filtroCurso, setFiltroCurso] = useState('todos');
  // Orden de la lista (se recuerda por pantalla y rol).
  const [orden, setOrden] = useState(() => leerOrden(`preceptores-${targetRole}`));
  useEffect(() => { guardarOrden(`preceptores-${targetRole}`, orden); }, [orden, targetRole]);
  const [mostrarModal, setMostrarModal] = useState(false);
  const [editando, setEditando] = useState(null);
  const [cursosDisponibles, setCursosDisponibles] = useState(() => getCached('preceptores/divisiones') || []);

  const [confirmAction, confirmModal] = useConfirmAction();
  const [, , avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const showToast = useToast();

  // Carga la lista de usuarios con rol preceptor y sus asignaciones (1 sola consulta)
  const cargarPreceptores = async () => {
    try {
      const result = await apiAdminUsersByRole(targetRole);
      const userList = result?.data ?? result ?? [];
      const mapped = userList.map(u => ({ ...u, asignaciones: Array.isArray(u.asignaciones) ? u.asignaciones : [] }));
      setPreceptores(mapped);
      setCache(cacheKey, mapped);
      setErrorCarga(null);
    } catch (e) {
      // Si falla (por ejemplo un 429 del throttle) se conserva lo que hay en
      // pantalla y se avisa, en lugar de dejar la tabla vacia sin explicacion.
      setErrorCarga(mensajeAmable(e, 'No se pudo cargar la lista.'));
      throw e;
    }
  };

  const reintentarCarga = async () => {
    setLoading(true);
    try { await cargarPreceptores(); } catch { /* el aviso ya lo pone cargarPreceptores */ }
    setLoading(false);
  };

  const cachedCursos = getCached('preceptores/divisiones');

  // Al cambiar el rol objetivo (preceptor <-> jefe_preceptores) forzamos recarga.
  useEffect(() => {
    setLoading(true);
  }, [targetRole]);

  useEffect(() => {
    if (!loading) return;
    (async () => {
      const [divisionesResult] = await Promise.all([
        cargarDivisiones().catch(() => null),
        cargarPreceptores().catch(() => {}),
      ]);
      if (divisionesResult) {
        const lista = Object.entries(divisionesResult).map(([anio, divisiones]) => ({
          anio: cursoLabel({ anio: Number(anio), division: null }),
          divisiones: (divisiones || []).map(d => divisionToOrdinal(d)),
        }));
        setCursosDisponibles(lista);
        setCache('preceptores/divisiones', lista);
      } else if (!cachedCursos || cachedCursos.length === 0) {
        const fallback = [1,2,3,4,5,6,7].map(n => ({ anio: cursoLabel({ anio: n, division: null }), divisiones: ['1.ª','2.ª','3.ª','4.ª','5.ª','6.ª','7.ª','8.ª','9.ª'] }));
        setCursosDisponibles(fallback);
        setCache('preceptores/divisiones', fallback);
      }
      setLoading(false);
    })();
  }, [loading, targetRole]);

  // Aunque haya copia guardada, al montar se pide lo fresco por detrás.
  useEffect(() => {
    if (getCached(cacheKey)) cargarPreceptores().catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [targetRole]);

  // Si otra persona tocó usuarios, esta pantalla se actualiza sola.
  useAvisoDeCambios(
    `preceptores-${targetRole}`,
    ["usuarios"],
    () => cargarPreceptores()
  );

  // Panel de filtros colapsable + contador de filtros activos.
  const [filtrosAbiertos, setFiltrosAbiertos] = useState(true);
  const filtrosActivos =
    (filtroEstado !== 'todos' ? 1 : 0) +
    (filtroCurso && filtroCurso !== 'todos' ? 1 : 0) +
    (orden !== ORDEN_DEFAULT ? 1 : 0);
  const limpiarTodo = () => {
    setBusqueda('');
    setFiltroEstado('todos');
    setFiltroCurso('todos');
    setOrden(ORDEN_DEFAULT);
  };

  const filtrados = useMemo(() => {
    const q = busqueda.trim();
    const lista = preceptores.filter(p => {
      if (q) {
        const texto = `${p.name || ''} ${p.apellido || ''} ${p.email || ''}`;
        if (!coincideBusqueda(texto, q)) return false;
      }
      if (filtroEstado !== 'todos') {
        const activo = p.status === 'active';
        if (filtroEstado === 'activo' && !activo) return false;
        if (filtroEstado === 'licencia' && activo) return false;
      }
      if (filtroCurso && filtroCurso !== 'todos') {
        if (!cursoEnAsignaciones(filtroCurso, p.asignaciones)) return false;
      }
      return true;
    });
    return ordenarPersonas(lista, orden);
  }, [preceptores, busqueda, filtroEstado, filtroCurso, orden]);

  // Elimina (da de baja) un preceptor solicitando un motivo con el modal de confirmacion
  const eliminarPreceptor = async (p) => {
    // Eliminación: exige contraseña (el servidor la valida).
    if (!(await confirmarContrasena())) return;
    const detalles = await confirmAction(
      `¿Por qué das de baja a ${p.apellido}, ${p.name} (${p.email})?`,
      async (d) => {
        await apiAdminDeleteUser(p.id, d);
      }
    );
    if (detalles === null) return;
    // La baja ya la acepto el servidor: se saca la fila de una vez para que la
    // pantalla refleje el estado real aunque el refresco despues falle (429).
    setPreceptores((prev) => prev.filter((x) => x.id !== p.id));
    await cargarPreceptores().catch(() => {});
    setMostrarModal(false);
    setEditando(null);
    setMostrarFicha(null);
    showToast('Usuario dado de baja correctamente', 'success');
  };

  const [mostrarFicha, setMostrarFicha] = useState(null);

  const verFicha = (p) => setMostrarFicha(p);

  const abrirEdicion = (p) => {
    setEditando({ ...p, asignaciones: Array.isArray(p.asignaciones) ? p.asignaciones : [] });
    setMostrarModal(true);
    apiAdminGetAssignments(p.id)
      .then(asig => {
        if (Array.isArray(asig) && asig.length) {
          setEditando(prev => (prev && prev.id === p.id ? { ...prev, asignaciones: asig } : prev));
        }
      })
      .catch(() => {});
  };

  return (
    <div className="prec-page">
      <div className="prec-container">
        <div className="prec-header">
          <div>
            <Breadcrumbs items={[{ label: targetRole === 'jefe_preceptores' ? 'Jefes de Preceptores' : 'Preceptores' }]} />
            <h1 className="prec-title">
              <Shield size={24} /> {targetRole === 'jefe_preceptores' ? 'Gestión de Jefes de Preceptores' : 'Gestión de Preceptores'}
            </h1>
            <p className="prec-subtitle">{targetRole === 'jefe_preceptores' ? 'Usuarios con rol de jefe de preceptores y sus asignaciones' : 'Usuarios con rol de preceptor y sus asignaciones'}</p>
          </div>

        </div>

        <div className="prec-filters-card">
          <div className="tb-search-row">
            {/* búsqueda protagonista */}
            <div className="prec-search-wrap tb-search-grow">
              <Search className="prec-search-icon" size={20} />
              <input
                type="text"
                placeholder="Buscar por nombre, apellido o email..."
                value={busqueda}
                onChange={(e) => setBusqueda(e.target.value)}
                className="prec-search-input tb-search-input"
                aria-label="Buscar preceptores"
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
                  onChange={(val) => setFiltroCurso(val)}
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
            <span>Mostrando <b>{filtrados.length}</b> de <b>{preceptores.length}</b> {targetRole === 'jefe_preceptores' ? 'jefes' : 'preceptores'}</span>
            {(busqueda.trim() || filtrosActivos > 0) && (
              <button className="tb-limpiar" onClick={limpiarTodo}>
                Limpiar todo
              </button>
            )}
          </div>
        </div>

        {loading && preceptores.length === 0 && !errorCarga ? (
          <div className="prec-loading">Cargando datos...</div>
        ) : (
          <>
            {errorCarga && (
              <div className="prec-error-carga" role="alert">
                <span>{errorCarga}</span>
                <button type="button" onClick={reintentarCarga}>Reintentar</button>
              </div>
            )}
        <div className="prec-table-wrap">
          <div className="prec-fade-top" />
          <div className="prec-fade-bottom" />
          <div className="prec-table-scroll">
            <table className="prec-table">
              <tbody className="prec-tbody">
                {filtrados.map((p) => (
                  <tr key={p.id} className="prec-table-row">
                    <td className="prec-td">
                      <div className="prec-user-cell">
                        <div className="prec-user-avatar">
                          <UsersRound size={20} className="prec-user-avatar-icon" />
                        </div>
                        <div className="prec-user-info">
                          <h3 className="prec-user-name">{p.apellido}, {p.name}</h3>
                          <p className="prec-user-email">{p.email}</p>
                          <div className="prec-asig-list">
                            {p.asignaciones?.length > 0 ? (
                              p.asignaciones.map((a, i) => (
                                <span key={a.id ?? a._key ?? i}
                                  className="prec-asig-chip"
                                >
                                  {cursoLabel({ anio: a.anio, division: a.division }) || '(todas)'}{a.anio && !a.division ? ' (todas)' : ''}
                                </span>
                              ))
                            ) : (
                              <span className="prec-asig-empty">Sin asignaciones</span>
                            )}
                          </div>
                        </div>
                      </div>
                    </td>
                    <td className="prec-td prec-td-right">
                      <div className="prec-row-actions">
                        <span className={`prec-status ${
                          p.status === 'active'
                            ? 'prec-status-active'
                            : p.status === 'rejected'
                            ? 'prec-status-rejected'
                            : 'prec-status-pending'
                        }`}>
                          {statusLabels[p.status] || p.status}
                        </span>
                        <button
                          onClick={() => verFicha(p)}
                          className="prec-icon-btn prec-icon-btn-eye"
                          title="Ver ficha"
                        >
                          <Eye size={20} />
                        </button>
                        {!readonly ? (
                          <button
                            onClick={() => abrirEdicion(p)}
                            className="prec-icon-btn prec-icon-btn-edit"
                            title="Editar"
                          >
                            <Pencil size={20} />
                          </button>
                        ) : (
                          <span className="prec-readonly-badge">
                            <Lock size={12} /> Solo lectura
                          </span>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
                {filtrados.length === 0 && (
                  <tr>
                    <td colSpan={2} className="prec-td prec-empty-row">
                      {busqueda
                        ? 'No se encontraron usuarios con ese criterio.'
                        : targetRole === 'jefe_preceptores'
                        ? 'No hay jefes de preceptores registrados.'
                        : 'No hay preceptores registrados.'}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
          </>
        )}

        {!readonly && (
        <PreceptorModal
          visible={mostrarModal}
          onClose={() => { setMostrarModal(false); setEditando(null); }}
          preceptor={editando}
          onSave={() => cargarPreceptores().catch(() => {})}
          cursosDisponibles={cursosDisponibles}
          targetRole={targetRole}
          onDarDeBaja={eliminarPreceptor}
        />
        )}

        <PreceptorFicha
          open={!!mostrarFicha}
          onClose={() => setMostrarFicha(null)}
          preceptor={mostrarFicha ? { ...mostrarFicha, rolLabel: targetRole === 'jefe_preceptores' ? 'Jefe de preceptores' : 'Preceptor' } : null}
        />
        {confirmModal}
        {avisoModal}
        {modalPwd}
      </div>
    </div>
  );
};

export default Preceptores;
