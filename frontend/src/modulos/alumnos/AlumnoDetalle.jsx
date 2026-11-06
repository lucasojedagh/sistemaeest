// ===============================
// FILE: src/components/Alumnos/AlumnoDetalle.jsx
// ===============================
import React, { useState, useRef, useEffect } from 'react';
import { apiGet, apiPost, apiPut, apiDelete } from "../../compartido/lib/api";
import { cargarDivisiones } from "../../compartido/lib/divisiones";
import { motion, AnimatePresence } from 'framer-motion';
import { X, Trash2, Plus, CalendarClock, Edit3 } from 'lucide-react';
import CursoDropdown from './CursoDropdown';
import { useAuth } from '../../modulos/auth/AuthContext';
import { cursoLabel, parseCurso, ESPECIALIDADES, ANIO_ESPECIALIDAD_DESDE } from '../../compartido/lib/cursos';
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import { mensajeAmable } from "../../compartido/lib/errores";
import { useToast } from "../../compartido/componentes/Common/Toast";
import useAviso from "../../compartido/componentes/modals/useAviso";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import { useDeshacerAviso } from "../../compartido/lib/deshacer.jsx";
import "./AlumnoDetalle.css";

/*
 * Componente: AlumnoDetalle
 * Props:
 *   alumno        - Objeto con datos del alumno (o null para cerrar)
 *   setAlumno     - Setter para actualizar el estado del alumno
 *   actualizarAlumno - Callback para persistir cambios en la lista principal
 *   eliminarAlumno   - Callback para eliminar el alumno de la lista
 *
 * Modal de edicion/detalle de un alumno. Incluye pestanas Editar / Ficha,
 * gestion de materias recursantes, intensificacion, direcciones, telefonos,
 * responsables, e inclusion (PPI/AE). Soporta roles: profesor (solo lectura),
 * preceptor (no puede eliminar) y administrativo.
 */

/* ---------- Dropdown compacto ---------- */
/* Componente interno: dropdown simple reutilizable, con soporte para error y disabled */
function SimpleDropdown({ placeholder='Seleccionar', options=[], value, onChange, error, disabled=false }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    const handle = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', handle);
    return () => document.removeEventListener('mousedown', handle);
  }, []);
  useEffect(() => { if (disabled) setOpen(false); }, [disabled]);

  const label = options.find(o => o.value === value)?.label || placeholder;

  return (
    <div className="alumno-detalle-dropdown-wrap" ref={ref}>
      <button
        type="button"
        aria-disabled={disabled}
        onClick={() => { if (!disabled) setOpen(o => !o); }}
        className={`alumno-detalle-dropdown-btn
          ${disabled ? 'alumno-detalle-dropdown-btn-disabled' : 'alumno-detalle-dropdown-btn-enabled'}
          ${error ? 'alumno-detalle-dropdown-btn-error' : 'alumno-detalle-dropdown-btn-ok'}`}
      >
        {label}
      </button>
      {open && !disabled && (
        <div className="alumno-detalle-dropdown-panel">
          {options.map(opt => (
            <div key={opt.value} onClick={() => { onChange(opt.value); setOpen(false); }} className="alumno-detalle-dropdown-option">
              {opt.label}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ---------- Mini Switch accesible y estable ---------- */
/* Componente interno: switch on/off accesible con aria-checked, usado para PPI, AE, tutela */
function MiniSwitch({ label, checked, onChange, hint }) {
  return (
    <div className="alumno-detalle-switch-row">
      <div className="alumno-detalle-switch-info">
        <span className="alumno-detalle-switch-title">{label}</span>
        {hint ? <span className="alumno-detalle-switch-hint">{hint}</span> : null}
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={!!checked}
        onClick={() => onChange?.(!checked)}
        className={`alumno-detalle-switch-track ${checked ? 'alumno-detalle-switch-track-on' : 'alumno-detalle-switch-track-off'}`}
      >
        <span className={`alumno-detalle-switch-thumb ${checked ? 'alumno-detalle-switch-thumb-on' : 'alumno-detalle-switch-thumb-off'}`} />
      </button>
    </div>
  );
}

/* ---------- Opciones ---------- */
const GENERO_OPTS = [
  { label: 'Femenino', value: 'F' },
  { label: 'Masculino', value: 'M' },
  { label: 'Otro', value: 'O' },
];
const TITULAR_OPTS = [
  { label: 'Alumno/a', value: 'Alumno/a' },
  { label: 'Madre', value: 'Madre' },
  { label: 'Padre', value: 'Padre' },
  { label: 'Tutor/a', value: 'Tutor/a' },
  { label: 'Hermano/a', value: 'Hermano/a' },
  { label: 'Abuelo/a', value: 'Abuelo/a' },
  { label: 'Otro', value: 'Otro' },
];
const VINCULO_OPTS = [
  { label: 'Madre', value: 'Madre' },
  { label: 'Padre', value: 'Padre' },
  { label: 'Tutor/a', value: 'Tutor/a' },
  { label: 'Hermano/a', value: 'Hermano/a' },
  { label: 'Abuelo/a', value: 'Abuelo/a' },
  { label: 'Otro', value: 'Otro' },
];

/* ---------- Estética ---------- */
const btnBase = 'alumno-detalle-btn';
const BTN_BLUE  = `${btnBase} alumno-detalle-btn-blue`;
const BTN_GREEN = `${btnBase} alumno-detalle-btn-green`;
const BTN_GRAY  = `${btnBase} alumno-detalle-btn-gray`;

const fieldLabel = 'alumno-detalle-field-label';
const req = <span className="alumno-detalle-req" aria-hidden="true">*</span>;
const card = 'alumno-detalle-card';
const cardTitle = 'alumno-detalle-card-title';

const initials = (apellido = '', nombre = '') =>
  `${(apellido || '').trim()[0] || ''}${(nombre || '').trim()[0] || ''}`.toUpperCase();

const avatarClassesByGenero = (g) => {
  if (g === 'F') return 'alumno-detalle-avatar-f';
  if (g === 'M') return 'alumno-detalle-avatar-m';
  return 'alumno-detalle-avatar-o';
};

/* ---------- Normalizadores ---------- */
/* Convierte un string de curso en { anio, division } usando el módulo central */
const splitCurso = (anioVal) => parseCurso(anioVal);
const parseCursoForPayload = (val) => {
  const p = parseCurso(val);
  return { anioNum: p.anio ?? null, division: p.division || null };
};
const cleanDivision = (d) =>
  (String(d || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 1) || null);

const pickPhone = (x) => {
  if (x == null) return '';
  if (typeof x === 'string' || typeof x === 'number') return String(x);
  if (typeof x === 'object') return pickPhone(x.telefono ?? x.numero ?? x.value ?? x.val ?? x.nro);
  return '';
};
const pickAddress = (x) => {
  if (x == null) return '';
  if (typeof x === 'string') return x;
  if (typeof x === 'object') return pickAddress(x.direccion ?? x.calle ?? x.texto ?? x.value ?? x.val);
  return '';
};

const normalizeMaterias = (val, anioStr) => {
  const { anio, division } = splitCurso(anioStr);
  if (Array.isArray(val)) {
    if (val.length > 0 && typeof val[0] === 'string') {
      return val.filter(Boolean).map(s => ({ anio, division, materia: String(s).trim() }));
    }
    return val;
  }
  if (typeof val === 'string' && val.trim()) {
    return val.split(',').map(s => ({ anio, division, materia: s.trim() })).filter(m => m.materia);
  }
  return [];
};

const normalizeDirecciones = (alumno) => {
  if (Array.isArray(alumno?.direcciones)) {
    return alumno.direcciones
      .map(d => ({ titular: d?.titular ?? 'Tutor/a', direccion: pickAddress(d?.direccion ?? d) }))
      .filter(d => d.direccion);
  }
  const direccion = pickAddress(alumno?.direccion);
  return direccion ? [{ titular: 'Tutor/a', direccion }] : [];
};

const normalizeTelefonos = (alumno) => {
  if (Array.isArray(alumno?.telefonos)) {
    return alumno.telefonos
      .map(t => ({ titular: t?.titular ?? 'Tutor/a', telefono: pickPhone(t?.telefono ?? t) }))
      .filter(t => t.telefono);
  }
  const telefono = pickPhone(alumno?.telefono);
  return telefono ? [{ titular: 'Tutor/a', telefono }] : [];
};

// Lee `vinculo` o `parentesco` y normaliza flags
const normalizeResponsables = (alumno) => {
  const arr = Array.isArray(alumno?.responsables) ? alumno.responsables : [];
  return arr
    .map(r => ({
      nombre: (r?.nombre ?? '').trim(),
      apellido: (r?.apellido ?? '').trim(),
      vinculo: (r?.vinculo ?? r?.parentesco ?? r?.relacion ?? '').trim(),
      tutor_legal: Boolean(r?.tutor_legal ?? r?.tutor),
      convive: Boolean(r?.convive ?? r?.vive_con),
    }))
    .filter(r => r.vinculo || r.nombre || r.apellido || r.tutor_legal || r.convive);
};

/* ============================ Componente principal ============================ */
/* Modal de edicion y ficha del alumno. Gestiona toda la informacion personal, academica y de contacto. */
const AlumnoDetalle = ({ alumno, setAlumno, actualizarAlumno, eliminarAlumno, onOpenCronograma }) => {
  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const showToast = useToast();
  const { ofrecerDeshacer } = useDeshacerAviso();

  // Snapshot del alumno tal como llegó del servidor: sirve para deshacer
  // el último guardado (re-PUT de los datos originales).
  // Se vuelve a tomar cuando cambia el alumno o cuando se hidratan los detalles
  // (ver efecto de abajo): si se guarda con la copia incompleta, el re-PUT
  // mandaría listas vacías y el backend borra y recrea esas relaciones.
  const alumnoOriginalRef = useRef(null);
  const [recargaFoto, setRecargaFoto] = useState(0);
  React.useEffect(() => {
    if (alumno?.id) {
      alumnoOriginalRef.current = JSON.parse(JSON.stringify(alumno));
    }
    // Al cerrar se limpia para el próximo alumno (no arrastrar datos viejos).
    if (!alumno) alumnoOriginalRef.current = null;
  }, [alumno?.id, !!alumno, recargaFoto]);

  // Auth y roles
  const { user } = useAuth();
  const role = user?.role;
  const isProfesor  = role === 'profesor';
  const isPreceptor = role === 'preceptor';

  // Inputs “agregar”
  const [materiaAnio, setMateriaAnio] = useState('');
  const [materiaDivision, setMateriaDivision] = useState('');
  const [materiaNombre, setMateriaNombre] = useState('');
  const [intensAnio, setIntensAnio] = useState('');
  const [intensDivision, setIntensDivision] = useState('');
  const [intensNombre, setIntensNombre] = useState('');

  const [direccionTitular, setDireccionTitular] = useState('');
  const [direccionTitularOtro, setDireccionTitularOtro] = useState('');
  const [direccionValor, setDireccionValor] = useState('');

  const [telefonoTitular, setTelefonoTitular] = useState('');
  const [telefonoTitularOtro, setTelefonoTitularOtro] = useState('');
  const [telefonoValor, setTelefonoValor] = useState('');

  // Responsables / tutores (inputs locales)
  const [respNombre, setRespNombre] = useState('');
  const [respApellido, setRespApellido] = useState('');
  const [respVinculo, setRespVinculo] = useState('');
  const [respVinculoOtro, setRespVinculoOtro] = useState('');
  const [respTutor, setRespTutor] = useState(false);
  const [respConvive, setRespConvive] = useState(false);

  const isOpen = Boolean(alumno);

  // Cierre con animacion: marcamos "cerrando" (dispara la animacion CSS de
  // salida) y recien desmontamos cuando termina. Asi el cierre se ve igual de
  // suave que la apertura, sin el salto de opacidad de Framer Motion.
  const [cerrando, setCerrando] = useState(false);
  const cerrandoRef = useRef(false);
  const cerrarTimerRef = useRef(null);

  // El foco entra al diálogo al abrir (teclado y lector de pantalla).
  const dialogRef = useRef(null);
  useEffect(() => {
    if (!isOpen) return;
    const t = setTimeout(() => dialogRef.current?.focus({ preventScroll: true }), 60);
    return () => clearTimeout(t);
  }, [isOpen]);

  // 🔁 Vista: Editar ficha (false) / Editar cronograma (true)
  const [esCronograma, setEsCronograma] = useState(false);
  // ⬅️ Bloqueo para PROFESOR y PRECEPTOR: ocultamos el switch y el cronograma
  const soloLectura = isProfesor;

  // Al abrir un alumno volvemos siempre a "Editar ficha" ANTES del primer
  // pintado (patrón de React para ajustar estado cuando cambia una prop).
  // Si se hiciera en un useEffect, durante un instante se vería el panel de
  // cronograma de la vez anterior y después el cambio: ese era el parpadeo.
  const [alumnoAbiertoId, setAlumnoAbiertoId] = useState(null);
  const alumnoAbiertoIdActual = isOpen ? (alumno?.id ?? null) : null;
  if (alumnoAbiertoIdActual !== alumnoAbiertoId) {
    setAlumnoAbiertoId(alumnoAbiertoIdActual);
    if (alumnoAbiertoIdActual != null) {
      if (esCronograma) setEsCronograma(false);
      if (cerrando) setCerrando(false);
      // Si quedo un cierre pendiente, lo cancelamos al abrir otro alumno.
      window.clearTimeout(cerrarTimerRef.current);
      cerrandoRef.current = false;
    }
  }

  // Cierra el modal con la animacion CSS de salida y desmonta al terminar.
  const cerrarConAnimacion = () => {
    if (cerrandoRef.current) return;
    cerrandoRef.current = true;
    setCerrando(true);
    window.clearTimeout(cerrarTimerRef.current);
    cerrarTimerRef.current = window.setTimeout(() => {
      cerrandoRef.current = false;
      setAlumno(null);
    }, 190);
  };

  useEffect(() => () => window.clearTimeout(cerrarTimerRef.current), []);

  // Mapa de divisiones por año cargado desde el backend para los dropdowns
  const [divisionesMap, setDivisionesMap] = useState({});
  useEffect(() => {
    (async () => {
      try {
        const mapa = await cargarDivisiones();
        setDivisionesMap(mapa);
      } catch {
        setDivisionesMap({1:[],2:[],3:[],4:[],5:[],6:[]});
      }
    })();
  }, []);

  // Hidratar detalles faltantes
  useEffect(() => {
    let cancel = false;
    (async () => {
      if (!isOpen || !alumno?.id) return;

      const necesita =
        !(alumno?.fechaNacimiento || alumno?.fecha_nacimiento) ||
        !Array.isArray(alumno?.responsables) ||
        // La lista puede venir de una copia vieja sin responsables: en ese
        // caso se pide el detalle completo para no pisarlos al guardar.
        alumno.responsables.length === 0 ||
        typeof alumno?.tiene_ppi === 'undefined' ||
        typeof alumno?.tiene_ae === 'undefined';

      if (!necesita) return;

      try {
        const full = await apiGet(`/alumnos/${alumno.id}`);
        if (cancel) return;
        setAlumno(prev => ({
          ...prev,
          fechaNacimiento : full?.fecha_nacimiento ?? prev?.fechaNacimiento ?? '',
          fecha_nacimiento: full?.fecha_nacimiento ?? prev?.fecha_nacimiento ?? '',
          responsables    : normalizeResponsables(full),
          direcciones     : normalizeDirecciones(full),
          telefonos       : normalizeTelefonos(full),
          tiene_ppi       : Boolean(full?.tiene_ppi ?? prev?.tiene_ppi),
          tiene_ae        : Boolean(full?.tiene_ae  ?? prev?.tiene_ae),
          version         : full?.version ?? prev?.version,
        }));
        // El snapshot original estaba armado con la copia de la lista: refotografiar
        // ahora que ya están los datos completos del servidor.
        setRecargaFoto((n) => n + 1);
      } catch { /* detalle opcional: si falla seguimos con los datos base */ }
    })();
    return () => { cancel = true; };
  // Deps intencionalmente limitadas: los campos hidratados se recargan aparte.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, alumno?.id]);

  // Listas normalizadas
  const recList  = React.useMemo(() => (isOpen ? normalizeMaterias(alumno.recursando, alumno.anio) : []), [isOpen, alumno?.recursando, alumno?.anio]);
  const intList  = React.useMemo(() => (isOpen ? normalizeMaterias(alumno.intensificacion, alumno.anio) : []), [isOpen, alumno?.intensificacion, alumno?.anio]);
  const dirList  = isOpen ? normalizeDirecciones(alumno) : [];
  const telList  = isOpen ? normalizeTelefonos(alumno) : [];
  const respList = isOpen ? normalizeResponsables(alumno) : [];

  /* Año actual del alumno */
  const alumnoYearNum = (() => {
    const fromCurso = parseCurso(alumno?.curso?.anio).anio;
    if (fromCurso > 0) return fromCurso;
    const fromStr = parseCurso(alumno?.anio).anio;
    return fromStr || null;
  })();

  /* Años/Divisiones/Materias */
  const aniosTodos = React.useMemo(() => {
    const keys = Object.keys(divisionesMap).map(n => Number(n)).filter(Number.isFinite);
    const base = keys.length ? keys : [1,2,3,4,5,6,7];
    return base.sort((a,b)=>a-b).map(n => ({ label: cursoLabel({ anio: n, division: null }), value: cursoLabel({ anio: n, division: null }), n }));
  }, [divisionesMap]);

  const aniosOptsLimited = React.useMemo(() => {
    if (!alumnoYearNum) return aniosTodos;
    return aniosTodos.filter(o => o.n <= alumnoYearNum);
  }, [aniosTodos, alumnoYearNum]);

  /* último año activo (y flag de egreso) */
  const maxAnioActivo = React.useMemo(() => {
    const keys = Object.keys(divisionesMap).map(n => Number(n)).filter(Number.isFinite);
    return keys.length ? Math.max(...keys) : 6;
  }, [divisionesMap]);
  const esUltimoAnio = Number(alumnoYearNum || 0) === Number(maxAnioActivo || 0);

  // materias por año (raw) + loading
  const [materiasRecRaw, setMateriasRecRaw] = useState([]);
  const [materiasIntRaw, setMateriasIntRaw] = useState([]);
  const [loadingRec, setLoadingRec] = useState(false);
  const [loadingInt, setLoadingInt] = useState(false);

  // helpers anti-duplicados
  const normName = (s) => String(s || '').trim().toLowerCase();
  const yearNum = (a) => parseInt(String(a || '').replace(/\D/g, ''), 10) || 0;
  const existeMateriaEnListas = (anioN, nombre) => {
    const n = Number(anioN) || 0;
    const nm = normName(nombre);
    const match = (m) => yearNum(m.anio) === n && normName(m.materia) === nm;
    return recList.some(match) || intList.some(match);
  };

  // fetch materias recursando
  useEffect(() => {
    if (!materiaAnio) { setMateriasRecRaw([]); setMateriaNombre(''); setMateriaDivision(''); return; }
    const n = parseCurso(materiaAnio).anio;
    if (!n) return;
    const divisiones = divisionesMap?.[n] || divisionesMap?.[String(n)] || [];
    const divAlumno = parseCurso(alumno?.anio).division || '';
    const defaultDiv = divisiones.includes(divAlumno) ? divAlumno : (divisiones[0] || '');
    setMateriaDivision(defaultDiv || '');

    let cancel = false;
    (async () => {
      try {
        setLoadingRec(true);
        const lista = await apiGet(`/anios/${n}/materias`);
        if (!cancel) {
          setMateriasRecRaw((lista || []).map(m => ({ label: m.nombre, value: m.nombre })));
          if (!lista?.some(m => m.nombre === materiaNombre)) setMateriaNombre('');
        }
      } catch {
        if (!cancel) { setMateriasRecRaw([]); setMateriaNombre(''); }
      } finally {
        if (!cancel) setLoadingRec(false);
      }
    })();
    return () => { cancel = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [materiaAnio, divisionesMap]);

  // fetch materias intensificación
  useEffect(() => {
    if (!intensAnio) { setMateriasIntRaw([]); setIntensNombre(''); setIntensDivision(''); return; }
    const n = parseCurso(intensAnio).anio;
    if (!n) return;
    const divisiones = divisionesMap?.[n] || divisionesMap?.[String(n)] || [];
    const divAlumno = parseCurso(alumno?.anio).division || '';
    const defaultDiv = divisiones.includes(divAlumno) ? divAlumno : (divisiones[0] || '');
    setIntensDivision(defaultDiv || '');

    let cancel = false;
    (async () => {
      try {
        setLoadingInt(true);
        const lista = await apiGet(`/anios/${n}/materias`);
        if (!cancel) {
          setMateriasIntRaw((lista || []).map(m => ({ label: m.nombre, value: m.nombre })));
          if (!lista?.some(m => m.nombre === intensNombre)) setIntensNombre('');
        }
      } catch {
        if (!cancel) setMateriasIntRaw([]);
      } finally {
        if (!cancel) setLoadingInt(false);
      }
    })();
    return () => { cancel = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [intensAnio, divisionesMap]);

  // opciones filtradas anti-duplicados
  const materiasRecOpts = React.useMemo(() => {
    const n = yearNum(materiaAnio);
    return (materiasRecRaw || []).filter(opt => !existeMateriaEnListas(n, opt.value));
  }, [materiasRecRaw, materiaAnio, recList, intList]);
  const materiasIntOpts = React.useMemo(() => {
    const n = yearNum(intensAnio);
    return (materiasIntRaw || []).filter(opt => !existeMateriaEnListas(n, opt.value));
  }, [materiasIntRaw, intensAnio, recList, intList]);

  useEffect(() => {
    const toNum = (s) => parseInt(String(s || '').replace(/\D/g, ''), 10) || null;
    if (alumnoYearNum) {
      if (toNum(materiaAnio) && toNum(materiaAnio) > alumnoYearNum) {
        setMateriaAnio(''); setMateriaNombre(''); setMateriaDivision('');
      }
      if (toNum(intensAnio) && toNum(intensAnio) > alumnoYearNum) {
        setIntensAnio(''); setIntensNombre(''); setIntensDivision('');
      }
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [alumnoYearNum]);

  const validarCamposObligatorios = () => {
    const dniDigits = String(alumno?.dni ?? '').replace(/\D/g, '');
    const tieneNombre = Boolean(alumno?.nombre?.trim());
    const tieneApellido = Boolean(alumno?.apellido?.trim());
    const tieneGenero = Boolean(alumno?.genero);
    const { anioNum: anioValid, division: divValid } = parseCursoForPayload(alumno?.anio);
    const tieneAnio = Boolean(anioValid || alumno?.curso?.anio);
    const tieneDiv  = Boolean(divValid || alumno?.curso?.division);
    const faltan = [];
    if (dniDigits.length === 0) faltan.push('DNI');
    else if (dniDigits.length < 7 || dniDigits.length > 8) faltan.push('DNI (7 u 8 dígitos)');
    if (!tieneNombre) faltan.push('Nombre');
    if (!tieneApellido) faltan.push('Apellido');
    if (!tieneGenero) faltan.push('Género');
    if (!tieneAnio) faltan.push('Año');
    if (!tieneDiv) faltan.push('División');
    return faltan;
  };

  /* ===== Guardar ===== */
  /* Valida y persiste todos los cambios del alumno via API PUT */
  const [guardando, setGuardando] = useState(false);
  const handleGuardar = async () => {
    if (guardando) return;
    const faltan = validarCamposObligatorios();
    if (faltan.length > 0) {
      await mostrarAviso({ icon: 'warning', title: 'Faltan campos obligatorios', text: `${faltan.join(', ')}` });
      return;
    }
    const y = alumnoYearNum || 0;
    if (y) {
      const recFuera = recList.some(m => (parseCurso(m.anio).anio || 0) > y);
      const intFuera = intList.some(m => (parseCurso(m.anio).anio || 0) > y);
      if (recFuera || intFuera) {
        await mostrarAviso({ icon: 'warning', title: 'Materias inválidas', text: 'No pueden existir materias de años superiores al curso del alumno.' });
        return;
      }
    }

    // Extrae anio y division del campo 'anio' (formato "5° A"); si no hay division, usa curso?.division como fallback
    const { anioNum, division: divFromAnio } = parseCursoForPayload(alumno.anio);
    const division = divFromAnio || alumno?.curso?.division || null;

    // responsables: enviar ambas claves (vinculo y parentesco)
    const responsablesPayload = respList.map(r => ({
      nombre: (r.nombre || '').trim(),
      apellido: (r.apellido || '').trim(),
      vinculo: (r.vinculo || '').trim(),
      parentesco: (r.vinculo || '').trim(),
      tutor_legal: !!r.tutor_legal,
      convive: !!r.convive,
    }));

    const payload = {
      nombre: (alumno.nombre || '').trim(),
      apellido: (alumno.apellido || '').trim(),
      genero: alumno.genero || null,
      dni: (alumno.dni || '').replace(/\D/g, '') || null,
      nacionalidad: (alumno.nacionalidad || '').trim() || null,
      matricula: (alumno.matricula || '').trim() || null,
      email: (alumno.email || '').replace(/\s+/g, '') || null,
      fecha_nacimiento: alumno.fechaNacimiento || alumno.fecha_nacimiento || null,
      anio: anioNum,
      division,
      grupo: alumno?.grupo || null, // Grupo (A/B) dentro de la misma division
      estado: alumno?.estado || 'Activo',
      especialidad: anioNum >= ANIO_ESPECIALIDAD_DESDE ? (alumno?.especialidad || null) : null,
      observaciones: alumno.observaciones || '',
      direcciones: normalizeDirecciones(alumno).map(d => ({ titular: d.titular, direccion: d.direccion })),
      telefonos: normalizeTelefonos(alumno).map(t => ({ titular: t.titular, telefono: t.telefono })),
      recursando: recList.map(r => ({
        anio: parseCurso(r.anio).anio || null,
        division: cleanDivision(r.division),
        materia: (r.materia || '').trim()
      })).filter(r => r.materia),
      intensificacion: intList.map(r => ({
        anio: parseCurso(r.anio).anio || null,
        division: cleanDivision(r.division),
        materia: (r.materia || '').trim()
      })).filter(r => r.materia),
      tiene_ppi: !!alumno?.tiene_ppi,
      tiene_ae : !!alumno?.tiene_ae,
      responsables: responsablesPayload,
      // Control de concurrencia: el server responde 409 si el alumno cambio
      // desde que se abrio esta ficha (evita pisar a otro preceptor).
      _version: alumno?.version ? Number(alumno.version) : undefined,
    };

    setGuardando(true);
    // El conflicto se detecta adentro de confirmAction (donde vive el PUT) y el
    // aviso se muestra despues: su modal tiene que cerrarse antes de abrir otro.
    let hayConflicto = false;
    try {
      let saved = null;
      const detalles = await confirmAction(
        '¿Qué cambios estás haciendo en este alumno?',
        async (d) => {
          saved = await apiPut(`/alumnos/${alumno.id}`, { ...payload, _detalles: d });
        },
        (e) => {
          if (e?.status === 409 && e?.data?.conflicto) {
            // Se toma la version vigente del servidor: si la persona vuelve a tocar
            // Guardar lo hace avisada y pisa a proposito, no por accidente.
            const v = e.data.version ?? null;
            if (v) setAlumno((prev) => ({ ...prev, version: v }));
            hayConflicto = true;
            return true;
          }
          return false;
        }
      );
      if (hayConflicto) {
        await mostrarAviso({
          icon: 'warning',
          title: 'Alguien guardó antes que vos',
          text: 'Tus cambios no se guardaron. Si tocás Guardar de nuevo queda tu versión y se pisán los cambios de esa persona. Lo más seguro es cerrar esta ficha y volver a abrirla.',
        });
        return;
      }
      if (detalles === null) return;

      const yearOnly = (saved?.anio_num ?? anioNum) || null;
      const anStr = yearOnly ? cursoLabel({ anio: yearOnly, division: null }) : '';
      const divStr = (saved?.division ?? division) || '';

      const dirCompat = (saved?.direcciones && saved.direcciones[0]?.direccion) || (dirList[0]?.direccion || '');
      const telCompat = (saved?.telefonos && (saved.telefonos[0]?.telefono ?? saved.telefonos[0]?.numero)) || (telList[0]?.telefono || '');

      actualizarAlumno({
        id: saved?.id ?? alumno.id,
        nombre: saved?.nombre ?? alumno.nombre,
        apellido: saved?.apellido ?? alumno.apellido,
        genero: (saved?.genero ?? alumno.genero) || '',
        dni: saved?.dni ?? alumno.dni,
        nacionalidad: saved?.nacionalidad ?? alumno.nacionalidad,
        matricula: saved?.matricula ?? alumno.matricula,
        email: saved?.email ?? alumno.email,
        fecha_nacimiento: saved?.fecha_nacimiento ?? payload.fecha_nacimiento ?? null,
        fechaNacimiento : saved?.fecha_nacimiento ?? payload.fecha_nacimiento ?? null,

        grupo: saved?.grupo ?? alumno?.grupo ?? null,

        anio: anStr || alumno.anio,
        curso: { anio: String(yearOnly || ''), division: divStr || null },

        recursando: saved?.recursando ?? recList,
        intensificacion: saved?.intensificacion ?? intList,
        direccion: dirCompat,
        telefono: telCompat,
        direcciones: saved?.direcciones ?? dirList,
        telefonos: saved?.telefonos ?? telList,
        observaciones: saved?.observaciones ?? alumno.observaciones,
        estado: saved?.estado ?? alumno.estado,
        egreso_year: saved?.egreso_year ?? alumno.egreso_year,
        egreso_anio: saved?.egreso_anio ?? alumno.egreso_anio,
        egreso_division: saved?.egreso_division ?? alumno.egreso_division,
        tiene_ppi: saved?.tiene_ppi ?? alumno?.tiene_ppi ?? false,
        tiene_ae : saved?.tiene_ae  ?? alumno?.tiene_ae  ?? false,
        responsables: saved?.responsables ?? respList,
        version: saved?.version ?? alumno.version,
      });

      cerrarConAnimacion();
      // Deshacer: vuelve a enviar los datos originales (snapshot tomado al abrir).
      const o = alumnoOriginalRef.current;
      if (o?.id) {
        const antAnio = parseCursoForPayload(o.anio);
        ofrecerDeshacer(
          {
            etiqueta: `Alumno ${o?.apellido || ''} ${o?.nombre || ''}`.trim(),
            deshacer: async () => {
              const anterior = {
                nombre: (o.nombre || '').trim(),
                apellido: (o.apellido || '').trim(),
                genero: o.genero || null,
                dni: (o.dni || '').replace(/\D/g, '') || null,
                nacionalidad: (o.nacionalidad || '').trim() || null,
                matricula: (o.matricula || '').trim() || null,
                email: (o.email || '').replace(/\s+/g, '') || null,
                fecha_nacimiento: o.fecha_nacimiento || o.fechaNacimiento || null,
                anio: antAnio.anioNum,
                division: antAnio.division || o.curso?.division || null,
                grupo: o.grupo || null,
                estado: o.estado || 'Activo',
                especialidad: antAnio.anioNum >= ANIO_ESPECIALIDAD_DESDE ? (o.especialidad || null) : null,
                observaciones: o.observaciones || '',
                direcciones: o.direcciones || [],
                telefonos: o.telefonos || [],
                recursando: (o.recursando || []).map(r => ({
                  anio: parseCurso(r?.anio).anio || null,
                  division: cleanDivision(r?.division),
                  materia: (r?.materia || '').trim(),
                })).filter(r => r.materia),
                intensificacion: (o.intensificacion || []).map(r => ({
                  anio: parseCurso(r?.anio).anio || null,
                  division: cleanDivision(r?.division),
                  materia: (r?.materia || '').trim(),
                })).filter(r => r.materia),
                tiene_ppi: !!o.tiene_ppi,
                tiene_ae: !!o.tiene_ae,
                responsables: o.responsables || [],
                _detalles: 'Deshace el último guardado del alumno',
              };
              await apiPut(`/alumnos/${o.id}`, anterior);
              if (typeof actualizarAlumno === 'function') actualizarAlumno({ ...o, id: o.id });
            },
          },
          'Cambios guardados correctamente'
        );
      } else {
        showToast('Cambios guardados correctamente', 'success');
      }
    } catch (e) {
      await mostrarAviso({ icon: 'error', title: 'No se pudo guardar', text: mensajeAmable(e, 'No se pudo guardar el alumno. Intentá de nuevo.') });
    } finally {
      setGuardando(false);
    }
  };

  /* ===== Toggle Egresado (switch) ===== */
  /* Cambia el estado entre Activo y Egresado. Solo permite egresar si es el ultimo anio activo. */
  const handleToggleEgresado = async (checked) => {
    if (checked) {
      if (!esUltimoAnio) {
        await mostrarAviso({
          icon: 'warning',
          title: 'No se puede egresar',
          text: 'Sólo se puede egresar a alumnos en el último año activo.',
        });
        return;
      }
      try {
        let data = null;
        const detalles = await confirmAction('¿Por qué egresás a este alumno?', async (d) => {
          data = await apiPost(`/alumnos/${alumno.id}/egresar`, { anioLectivo: new Date().getFullYear(), _detalles: d });
        });
        if (detalles === null) return;
        setAlumno(prev => ({
          ...prev,
          estado: 'Egresado',
          egreso_year: (data?.egreso_year ?? new Date().getFullYear()),
          egreso_anio: (data?.egreso_anio ?? alumnoYearNum),
          egreso_division: (data?.egreso_division ?? parseCurso(prev?.anio).division) || null,
        }));
      } catch (e) {
        console.error('No se pudo egresar al alumno:', e?.message || 'Error desconocido.');
      }
    } else {
      // Quitar egresado es solo un borrador: recién se aplica al tocar Guardar
      // (egresar, en cambio, se guarda al instante). Se avisa para no confundir.
      setAlumno(prev => ({
        ...prev,
        estado: 'Activo',
        egreso_year: null,
        egreso_anio: null,
        egreso_division: null,
      }));
      showToast('Se marcó como activo. Tocá Guardar para confirmarlo.', 'info');
    }
  };

  /* Agrega una materia a la lista de recursado o intensificacion segun el tipo */
  const agregarMateria = async (tipo) => {
    const anio      = (tipo === 'recursando' ? materiaAnio      : intensAnio);
    const division  = (tipo === 'recursando' ? materiaDivision  : intensDivision);
    const nombre    = (tipo === 'recursando' ? materiaNombre    : intensNombre);
    if (!anio || !division || !nombre) return;

    const nSel = parseCurso(anio).anio || 0;
    if (!alumnoYearNum) {
      await mostrarAviso({ icon: 'info', title: 'Seleccioná el Curso primero', text: 'Definí el Año y la División antes de agregar materias.' });
      return;
    }
    if (nSel > alumnoYearNum) {
      await mostrarAviso({ icon: 'warning', title: 'Año no permitido', text: 'No podés agregar materias de años superiores al curso del alumno.' });
      return;
    }
    if (existeMateriaEnListas(nSel, nombre)) {
      await mostrarAviso({ icon: 'info', title: 'Materia ya agregada', text: 'Esa materia ya está cargada para ese año (recursando o intensificación).' });
      return;
    }

    const nueva = { anio: nSel, division: cleanDivision(division), materia: nombre };

    setAlumno(prev => {
      const base = normalizeMaterias(prev[tipo], prev.anio);
      return { ...prev, [tipo]: [...base, nueva] };
    });

    if (tipo === 'recursando') { setMateriaAnio(''); setMateriaDivision(''); setMateriaNombre(''); }
    else { setIntensAnio(''); setIntensDivision(''); setIntensNombre(''); }
  };
  /* Elimina una materia de la lista de recursado o intensificacion por su indice */
  const eliminarMateria = (i, tipo) => {
    setAlumno(prev => {
      const base = normalizeMaterias(prev[tipo], prev.anio);
      return { ...prev, [tipo]: base.filter((_, idx) => idx !== i) };
    });
  };

  const canAddRec = Boolean(materiaAnio && materiaDivision && materiaNombre && !loadingRec && !existeMateriaEnListas(parseCurso(materiaAnio).anio, materiaNombre));
  const canAddInt = Boolean(intensAnio && intensDivision && intensNombre && !loadingInt && !existeMateriaEnListas(parseCurso(intensAnio).anio, intensNombre));

  const canAddDir = Boolean(
    direccionTitular &&
    (direccionTitular !== 'Otro' || direccionTitularOtro.trim()) &&
    direccionValor.trim()
  );
  const canAddTel = Boolean(
    telefonoTitular &&
    (telefonoTitular !== 'Otro' || telefonoTitularOtro.trim()) &&
    telefonoValor.trim()
  );

  /* Agrega una nueva direccion a la lista del alumno */
  const agregarDireccion = () => {
    if (!canAddDir) return;
    const titular = direccionTitular === 'Otro' ? direccionTitularOtro.trim() : direccionTitular;
    const direccion = direccionValor.trim();
    setAlumno(prev => ({ ...prev, direcciones: [...normalizeDirecciones(prev), { titular, direccion }] }));
    setDireccionTitular(''); setDireccionTitularOtro(''); setDireccionValor('');
  };
  const eliminarDireccion = (i) => {
    setAlumno(prev => ({ ...prev, direcciones: normalizeDirecciones(prev).filter((_, idx) => idx !== i) }));
  };

  /* Agrega un nuevo telefono a la lista del alumno */
  const agregarTelefono = () => {
    if (!canAddTel) return;
    const titular = telefonoTitular === 'Otro' ? telefonoTitularOtro.trim() : telefonoTitular;
    const telefono = (telefonoValor || '').replace(/\D/g, '');
    setAlumno(prev => ({ ...prev, telefonos: [...normalizeTelefonos(prev), { titular, telefono }] }));
    setTelefonoTitular(''); setTelefonoTitularOtro(''); setTelefonoValor('');
  };
  /* Elimina un telefono de la lista por su indice */
  const eliminarTelefono = (i) => {
    setAlumno(prev => ({ ...prev, telefonos: normalizeTelefonos(prev).filter((_, idx) => idx !== i) }));
  };

  /* Construye las opciones de division para un anio dado, desde el mapa de divisiones */
  const buildDivOpts = (anioStr) => {
    const n = parseCurso(anioStr).anio;
    const arr = divisionesMap?.[n] || divisionesMap?.[String(n)] || [];
    return (arr || []).map(d => ({ label: d, value: d }));
  };

  // Variantes de transición suaves para el contenido
  const contentVariants = {
    initial: { opacity: 0, y: 8 },
    animate: { opacity: 1, y: 0, transition: { duration: 0.22, ease: 'easeOut' } },
    exit:    { opacity: 0, y: -6, transition: { duration: 0.18, ease: 'easeIn' } },
  };

  /* ====== Detección de cambios ====== */
  const sanitizeForCompare = (a) => ({
    nombre: (a?.nombre || '').trim(),
    apellido: (a?.apellido || '').trim(),
    genero: a?.genero || '',
    dni: String(a?.dni || '').replace(/\D/g, ''),
    nacionalidad: (a?.nacionalidad || '').trim(),
    matricula: (a?.matricula || '').trim(),
    email: (a?.email || '').replace(/\s+/g, '').toLowerCase(),
    fecha: a?.fechaNacimiento || a?.fecha_nacimiento || '',
    anio: a?.anio || '',
    estado: a?.estado || 'Activo',
    observaciones: a?.observaciones || '',
    recursando: recList,
    intensificacion: intList,
    direcciones: dirList,
    telefonos: telList,
    responsables: respList,
    tiene_ppi: !!a?.tiene_ppi,
    tiene_ae : !!a?.tiene_ae,
  });

  const [snapshot, setSnapshot] = useState(null);

  useEffect(() => {
    if (isOpen && alumno) setSnapshot(sanitizeForCompare(alumno));
    else setSnapshot(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  // ⬇️ FIX: si sólo cambiaron campos de hidratación (fecha, responsables, tiene_ppi, tiene_ae),
  // no lo consideramos "dirty" y actualizamos el snapshot silenciosamente.
  useEffect(() => {
    if (!isOpen || !snapshot) return;
    const cur = sanitizeForCompare(alumno);

    // si ya son idénticos no hacemos nada
    if (JSON.stringify(cur) === JSON.stringify(snapshot)) return;

    const stripHydrated = (obj) => {
      const { fecha: _fecha, responsables: _responsables, tiene_ppi: _tienePpi, tiene_ae: _tieneAe, ...rest } = obj || {};
      return rest;
    };

    // si al ignorar los campos que vienen por hidratación el resto coincide,
    // entonces actualizamos snapshot para que no dispare la alerta
    if (JSON.stringify(stripHydrated(cur)) === JSON.stringify(stripHydrated(snapshot))) {
      setSnapshot(cur);
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    isOpen,
    alumno?.fechaNacimiento,
    alumno?.fecha_nacimiento,
    alumno?.tiene_ppi,
    alumno?.tiene_ae,
    alumno?.responsables,
    recList, intList, dirList, telList
  ]);

  /* Detecta si hay cambios sin guardar comparando con el snapshot inicial */
  const hasDirty = () => {
    if (!snapshot) return false;
    const cur = sanitizeForCompare(alumno);
    return JSON.stringify(cur) !== JSON.stringify(snapshot);
  };

  /* Maneja el cierre del modal preguntando si hay cambios sin guardar */
  const handleCloseAttempt = async () => {
    if (esCronograma || !hasDirty()) { cerrarConAnimacion(); return; }

    const ok = await pedirConfirmacion({
      icon: 'warning',
      title: '¿Descartar cambios?',
      text: 'Tenés cambios sin guardar. Si cerrás ahora, se perderán.',
      confirmText: 'Descartar',
      cancelText: 'Seguir editando',
    });

    if (ok) cerrarConAnimacion();
  };

  return (
    <>
      {isOpen && (
        <div className={`alumno-detalle-backdrop${cerrando ? ' is-closing' : ''}`}>
          <div
            className={`alumno-detalle-container${cerrando ? ' is-closing' : ''}`}
            role="dialog"
            aria-modal="true"
            aria-label="Editar ficha del alumno"
            ref={dialogRef}
            tabIndex={-1}
          >
            {/* Halos ambientales */}
            <div className="alumno-detalle-halo alumno-detalle-halo-emerald" />
            <div className="alumno-detalle-halo alumno-detalle-halo-teal" />

            {/* Boton de cierre con confirmacion de cambios */}
            <button
              onClick={handleCloseAttempt}
              className="alumno-detalle-close"
              aria-label="Cerrar"
            >
              <X size={20} />
            </button>

            {/* Header: avatar, nombre completo y toggle Editar / Ficha */}
            <div className="alumno-detalle-hero">
              <div className="alumno-detalle-header">
              <div className="alumno-detalle-header-row">
                <div className={`alumno-detalle-avatar ${avatarClassesByGenero(alumno?.genero)}`}>
                  {initials(alumno?.apellido, alumno?.nombre)}
                </div>
                <div>
                  <h2 className="alumno-detalle-title">Editar ficha</h2>
                  <div
                    className="alumno-detalle-subtitle"
                    title={`${alumno?.apellido || ''}, ${alumno?.nombre || ''} • DNI: ${alumno?.dni || '—'} • ${alumno?.anio || '—'}`}
                  >
                    {(alumno?.apellido || '') + ', ' + (alumno?.nombre || '')}
                  </div>
                </div>
              </div>
            </div>
            </div>

            {/* Contenido del modal */}
            <div className="alumno-detalle-body">
              <AnimatePresence mode="wait" initial={false}>
                {!esCronograma ? (
                  <motion.div key="editar" variants={contentVariants} initial="initial" animate="animate" exit="exit" className="alumno-detalle-content">
                    {/* Datos basicos: nombre, apellido, genero, fecha de nacimiento, DNI, curso y grupo */}
                    <div className={card}>
                    <div className="alumno-detalle-grid alumno-detalle-grid-2 alumno-detalle-grid-text">
                      <div className="alumno-detalle-field">
                        <label htmlFor="al-nombre" className={fieldLabel}>Nombre {req}</label>
                        <input
                          id="al-nombre"
                          type="text"
                          placeholder="Ej.: Ana Lucía"
                          value={alumno?.nombre || ''}
                          onChange={(e) => setAlumno({ ...alumno, nombre: e.target.value })}
                          className={`alumno-detalle-input ${!alumno?.nombre?.trim() ? 'alumno-detalle-input-error' : ''}`}
                        />
                      </div>

                      <div className="alumno-detalle-field">
                        <label htmlFor="al-apellido" className={fieldLabel}>Apellido {req}</label>
                        <input
                          id="al-apellido"
                          type="text"
                          placeholder="Ej.: González"
                          value={alumno?.apellido || ''}
                          onChange={(e) => setAlumno({ ...alumno, apellido: e.target.value })}
                          className={`alumno-detalle-input ${!alumno?.apellido?.trim() ? 'alumno-detalle-input-error' : ''}`}
                        />
                      </div>

                      <div className="alumno-detalle-field">
                        <span className={fieldLabel}>Género {req}</span>
                        <SimpleDropdown
                          placeholder="Seleccionar"
                          options={GENERO_OPTS}
                          value={alumno?.genero || ''}
                          onChange={(val) => setAlumno({ ...alumno, genero: val })}
                          error={!alumno?.genero}
                        />
                      </div>

                      <div className="alumno-detalle-field">
                        <label htmlFor="al-fecha" className={fieldLabel}>Fecha de nacimiento</label>
                        <input
                          id="al-fecha"
                          type="date"
                          value={alumno?.fechaNacimiento || alumno?.fecha_nacimiento || ''}
                          onChange={(e) => setAlumno({ ...alumno, fechaNacimiento: e.target.value, fecha_nacimiento: e.target.value })}
                          className="alumno-detalle-input"
                        />
                      </div>

                      <div className="alumno-detalle-field">
                        <label htmlFor="al-dni" className={fieldLabel}>DNI {req}</label>
                        <input
                          id="al-dni"
                          type="text" inputMode="numeric" pattern="[0-9]*" maxLength={8}
                          placeholder="Solo números"
                          value={alumno?.dni || ''}
                          onChange={(e) => setAlumno({ ...alumno, dni: (e.target.value || '').replace(/\D/g, '') })}
                          className={`alumno-detalle-input ${!String(alumno?.dni || '').replace(/\D/g, '') ? 'alumno-detalle-input-error' : ''}`}
                        />
                      </div>

                      <div className="alumno-detalle-field">
                        <label htmlFor="al-nacionalidad" className={fieldLabel}>Nacionalidad</label>
                        <input
                          id="al-nacionalidad"
                          type="text" maxLength={60}
                          placeholder="Ej: Argentina"
                          value={alumno?.nacionalidad || ''}
                          onChange={(e) => setAlumno({ ...alumno, nacionalidad: e.target.value })}
                          className="alumno-detalle-input"
                        />
                      </div>

                      <div className="alumno-detalle-field">
                        <label htmlFor="al-matricula" className={fieldLabel}>N° de matrícula</label>
                        <input
                          id="al-matricula"
                          type="text" maxLength={30}
                          placeholder="Número del registro de alumnos"
                          value={alumno?.matricula || ''}
                          onChange={(e) => setAlumno({ ...alumno, matricula: e.target.value })}
                          className="alumno-detalle-input"
                        />
                      </div>

                      <div className="alumno-detalle-field">
                        <label htmlFor="al-correo" className={fieldLabel}>Correo electrónico</label>
                        <input
                          id="al-correo"
                          type="email" maxLength={120}
                          placeholder="Ej: alumno@correo.com"
                          value={alumno?.email || ''}
                          onChange={(e) => setAlumno({ ...alumno, email: e.target.value })}
                          className="alumno-detalle-input"
                        />
                      </div>

                      <div className="alumno-detalle-field">
                        <span className={fieldLabel}>Curso (Año y División) {req}</span>
                         <CursoDropdown
                           value={alumno?.anio || ''}
                           onChange={(val) => {
                             const p = parseCurso(val);
                             setAlumno({
                               ...alumno,
                               anio: val,
                               curso: p.anio ? { anio: String(p.anio), division: p.division || null } : undefined,
                               especialidad: parseInt(p.anio ?? 0, 10) < ANIO_ESPECIALIDAD_DESDE ? null : alumno?.especialidad
                             });
                           }}
                           error={!alumno?.anio}
                         />
                         {parseInt(alumno?.curso?.anio ?? 0, 10) >= ANIO_ESPECIALIDAD_DESDE && (
                           <div className="alumno-detalle-field">
                             <span className={fieldLabel}>Especialidad {req}</span>
                             <SimpleDropdown
                               placeholder="Seleccionar especialidad"
                               options={ESPECIALIDADES.map((e) => ({ value: e.key, label: e.label }))}
                               value={alumno?.especialidad || ''}
                               onChange={(v) => setAlumno({ ...alumno, especialidad: v || null })}
                               error={!alumno?.especialidad}
                             />
                           </div>
                         )}
                      </div>

                      <div className="alumno-detalle-grupo-row">
                        <span className="alumno-detalle-grupo-label">Grupo:</span>
                        {['', 'A', 'B'].map(g => (
                          <label key={g || 'ninguno'} className="alumno-detalle-radio-label">
                            <input type="radio" name="grupo-detalle" value={g}
                              checked={(alumno?.grupo || '') === g}
                              onChange={() => setAlumno({ ...alumno, grupo: g || null })}
                              className="alumno-detalle-radio-input" />
                            {g || '—'}
                          </label>
                        ))}
                      </div>

                      {/* Estado Activo / Egresado */}
                      <div className="alumno-detalle-col-span-2">
                        {soloLectura ? (
                          <div className="alumno-detalle-estado-row">
                            <span className="alumno-detalle-estado-label">Estado del alumno</span>
                            <span className={`alumno-detalle-estado-badge ${ (alumno?.estado || '') === 'Egresado' ? 'alumno-detalle-estado-badge-egresado' : 'alumno-detalle-estado-badge-activo'}`}>
                              {alumno?.estado || 'Activo'}
                            </span>
                          </div>
                        ) : (
                        <div className="alumno-detalle-estado-row alumno-detalle-estado-row-between">
                          <div className="alumno-detalle-estado-text">
                            <div className="alumno-detalle-estado-label">Estado del alumno</div>
                            <div className="alumno-detalle-estado-hint">Cambiá entre Activo y Egresado</div>
                          </div>

                          <div className="alumno-detalle-switch-group">
                            <span className={`alumno-detalle-switch-side ${ (alumno?.estado || '') === 'Egresado' ? 'alumno-detalle-switch-side-muted' : 'alumno-detalle-switch-side-activo'}`}>Activo</span>
                            <button
                              type="button"
                              role="switch"
                              aria-checked={(alumno?.estado || '') === 'Egresado'}
                              onClick={() => handleToggleEgresado((alumno?.estado || '') !== 'Egresado')}
                              className={`alumno-detalle-switch-track ${ (alumno?.estado || '') === 'Egresado' ? 'alumno-detalle-switch-track-on' : 'alumno-detalle-switch-track-off'}`}
                              title="Cambiar estado"
                            >
                              <span className={`alumno-detalle-switch-thumb ${ (alumno?.estado || '') === 'Egresado' ? 'alumno-detalle-switch-thumb-on' : 'alumno-detalle-switch-thumb-off'}`} />
                            </button>
                            <span className={`alumno-detalle-switch-side ${ (alumno?.estado || '') === 'Egresado' ? 'alumno-detalle-switch-side-egresado' : 'alumno-detalle-switch-side-muted'}`}>Egresado</span>
                          </div>
                        </div>
                        )}
                      </div>
                    </div>
                    </div>

                    {/* ====== Materias: recursado e intensificacion con seleccion de anio, division y materia ====== */}
                    <div className={card}>
                      <h3 className={cardTitle + ' alumno-detalle-card-title-blue'}>Materias que recursa</h3>
                      <div className="alumno-detalle-selectors-row">
                        <div className="alumno-detalle-selector-col">
                          <span className={fieldLabel}>Año</span>
                          <SimpleDropdown placeholder="Seleccionar" options={aniosOptsLimited} value={materiaAnio} onChange={setMateriaAnio} />
                        </div>
                        <div className="alumno-detalle-selector-col">
                          <span className={fieldLabel}>División</span>
                          <SimpleDropdown
                            placeholder="Seleccionar"
                            options={buildDivOpts(materiaAnio)}
                            value={materiaDivision}
                            onChange={setMateriaDivision}
                            disabled={!materiaAnio}
                          />
                        </div>
                        <div className="alumno-detalle-selector-col">
                          <span className={fieldLabel}>Materia</span>
                          <SimpleDropdown
                            placeholder={loadingRec ? 'Cargando...' : 'Seleccionar'}
                            options={materiasRecOpts}
                            value={materiaNombre}
                            onChange={setMateriaNombre}
                            disabled={!materiaAnio || loadingRec}
                          />
                        </div>
                        <div className="alumno-detalle-btn-wrap">
                          <button onClick={() => agregarMateria('recursando')} disabled={!canAddRec} className={BTN_BLUE}>
                            <Plus size={16} /> Agregar
                          </button>
                        </div>
                      </div>
                      <div className="alumno-detalle-chips-row">
                        {recList.map((m, i) => (
                          <span key={i} title={`${cursoLabel({ anio: m.anio, division: m.division })} - ${m.materia}`}
                            className="alumno-detalle-chip">
                            <span className="alumno-detalle-chip-text">{cursoLabel({ anio: m.anio, division: m.division })} - {m.materia}</span>
                            <button onClick={() => eliminarMateria(i, 'recursando')} className="alumno-detalle-chip-del" aria-label="Eliminar materia recursando">
                              <Trash2 size={12} />
                            </button>
                          </span>
                        ))}
                      </div>
                    </div>

                    <div className={card}>
                      <h3 className={cardTitle + ' alumno-detalle-card-title-emerald'}>Materias de intensificación</h3>
                      <div className="alumno-detalle-selectors-row">
                        <div className="alumno-detalle-selector-col">
                          <span className={fieldLabel}>Año</span>
                          <SimpleDropdown placeholder="Seleccionar" options={aniosOptsLimited} value={intensAnio} onChange={setIntensAnio} />
                        </div>
                        <div className="alumno-detalle-selector-col">
                          <span className={fieldLabel}>División</span>
                          <SimpleDropdown
                            placeholder="Seleccionar"
                            options={buildDivOpts(intensAnio)}
                            value={intensDivision}
                            onChange={setIntensDivision}
                            disabled={!intensAnio}
                          />
                        </div>
                        <div className="alumno-detalle-selector-col">
                          <span className={fieldLabel}>Materia</span>
                          <SimpleDropdown
                            placeholder={loadingInt ? 'Cargando...' : 'Seleccionar'}
                            options={materiasIntOpts}
                            value={intensNombre}
                            onChange={setIntensNombre}
                            disabled={!intensAnio || loadingInt}
                          />
                        </div>
                        <div className="alumno-detalle-btn-wrap">
                          <button onClick={() => agregarMateria('intensificacion')} disabled={!canAddInt} className={BTN_GREEN}>
                            <Plus size={16} /> Agregar
                          </button>
                        </div>
                      </div>
                      <div className="alumno-detalle-chips-row">
                        {intList.map((m, i) => (
                          <span key={i} title={`${cursoLabel({ anio: m.anio, division: m.division })} - ${m.materia}`}
                            className="alumno-detalle-chip">
                            <span className="alumno-detalle-chip-text">{cursoLabel({ anio: m.anio, division: m.division })} - {m.materia}</span>
                            <button
                              onClick={() => eliminarMateria(i, 'intensificacion')}
                              className="alumno-detalle-chip-del"
                              aria-label="Eliminar materia intensificación"
                            >
                              <Trash2 size={12} />
                            </button>
                          </span>
                        ))}
                      </div>
                    </div>

                    {/* Seccion de direcciones: titular y direccion con opcion "Otro" */}
                    <div className={card}>
                      <h3 className={cardTitle}>Direcciones</h3>
                      <div className="alumno-detalle-row-md">
                        <div className="alumno-detalle-col-md-25">
                          <span className={fieldLabel}>Titular</span>
                          <SimpleDropdown placeholder="Seleccionar" options={TITULAR_OPTS} value={direccionTitular} onChange={setDireccionTitular} />
                        </div>

                        {direccionTitular === 'Otro' && (
                          <div className="alumno-detalle-col-md-25 alumno-detalle-col-md-mt">
                            <label htmlFor="al-dir-otro" className={fieldLabel}>Especificar titular</label>
                            <input
                              id="al-dir-otro"
                              type="text"
                              placeholder="Ej.: Tía"
                              value={direccionTitularOtro}
                              onChange={(e) => setDireccionTitularOtro(e.target.value)}
                              className="alumno-detalle-input"
                            />
                          </div>
                        )}

                        <div className="alumno-detalle-col-md-flex">
                          <label htmlFor="al-dir" className={fieldLabel}>Dirección</label>
                          <input
                            id="al-dir"
                            type="text"
                            placeholder="Calle 123, Piso 1, Dpto A"
                            value={direccionValor}
                            onChange={(e) => setDireccionValor(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter' && canAddDir) { e.preventDefault(); agregarDireccion(); } }}
                            className="alumno-detalle-input"
                          />
                        </div>

                        <div className="alumno-detalle-btn-wrap-md">
                          <button type="button" onClick={agregarDireccion} disabled={!canAddDir} className={BTN_GRAY}>
                            <Plus size={16} /> Agregar
                          </button>
                        </div>
                      </div>

                      <div className="alumno-detalle-chips-wrap">
                        {dirList.map((d, i) => (
                          <span key={i} title={`${String(d.titular)}: ${String(d.direccion)}`}
                            className="alumno-detalle-chip alumno-detalle-chip-wide">
                            <span className="alumno-detalle-chip-text"><strong>{String(d.titular)}:</strong> {String(d.direccion)}</span>
                            <button onClick={() => eliminarDireccion(i)} className="alumno-detalle-chip-del" aria-label="Eliminar dirección">
                              <Trash2 size={12} />
                            </button>
                          </span>
                        ))}
                      </div>
                    </div>

                    {/* Seccion de telefonos: titular y numero con opcion "Otro" */}
                    <div className={card}>
                      <h3 className={cardTitle}>Teléfonos</h3>
                      <div className="alumno-detalle-row-md">
                        <div className="alumno-detalle-col-md-25">
                          <span className={fieldLabel}>Titular</span>
                          <SimpleDropdown placeholder="Seleccionar" options={TITULAR_OPTS} value={telefonoTitular} onChange={setTelefonoTitular} />
                        </div>

                        {telefonoTitular === 'Otro' && (
                          <div className="alumno-detalle-col-md-25 alumno-detalle-col-md-mt">
                            <label htmlFor="al-tel-otro" className={fieldLabel}>Especificar titular</label>
                            <input
                              id="al-tel-otro"
                              type="text"
                              placeholder="Ej.: Abuela"
                              value={telefonoTitularOtro}
                              onChange={(e) => setTelefonoTitularOtro(e.target.value)}
                              className="alumno-detalle-input"
                            />
                          </div>
                        )}

                        <div className="alumno-detalle-col-md-flex">
                          <label htmlFor="al-tel" className={fieldLabel}>Número</label>
                          <input
                            id="al-tel"
                            type="text" inputMode="numeric" pattern="[0-9]*"
                            placeholder="Solo números"
                            value={telefonoValor}
                            onChange={(e) => setTelefonoValor((e.target.value || '').replace(/\D/g, ''))}
                            onKeyDown={(e) => { if (e.key === 'Enter' && canAddTel) { e.preventDefault(); agregarTelefono(); } }}
                            className="alumno-detalle-input"
                          />
                        </div>

                        <div className="alumno-detalle-btn-wrap-md">
                          <button type="button" onClick={agregarTelefono} disabled={!canAddTel} className={BTN_GRAY}>
                            <Plus size={16} /> Agregar
                          </button>
                        </div>
                      </div>

                      <div className="alumno-detalle-chips-wrap">
                        {telList.map((t, i) => (
                          <span key={i} title={`${String(t.titular)}: ${String(t.telefono)}`}
                            className="alumno-detalle-chip">
                            <span className="alumno-detalle-chip-text"><strong>{String(t.titular)}:</strong> {String(t.telefono)}</span>
                            <button onClick={() => eliminarTelefono(i)} className="alumno-detalle-chip-del" aria-label="Eliminar teléfono">
                              <Trash2 size={12} />
                            </button>
                          </span>
                        ))}
                      </div>
                    </div>

                    {/* Responsables / Tutores */}
                    <div className={card}>
                      <h3 className={cardTitle}>Responsables / Tutores</h3>
                      <div className="alumno-detalle-grid alumno-detalle-grid-2-3">
                        <input
                          type="text"
                          placeholder="Nombre"
                          value={respNombre}
                          onChange={(e) => setRespNombre(e.target.value)}
                          className="alumno-detalle-input"
                        />
                        <input
                          type="text"
                          placeholder="Apellido"
                          value={respApellido}
                          onChange={(e) => setRespApellido(e.target.value)}
                          className="alumno-detalle-input"
                        />

                        <div className="alumno-detalle-col-span-2 alumno-detalle-grid alumno-detalle-grid-2-3">
                          <div>
                            <SimpleDropdown
                              placeholder="Vínculo"
                              options={VINCULO_OPTS}
                              value={respVinculo}
                              onChange={setRespVinculo}
                            />
                          </div>
                          {respVinculo === 'Otro' && (
                            <input
                              type="text"
                              placeholder="Especificar vínculo"
                              value={respVinculoOtro}
                              onChange={(e) => setRespVinculoOtro(e.target.value)}
                              className="alumno-detalle-input"
                            />
                          )}
                        </div>

                        <div className="alumno-detalle-col-span-2 alumno-detalle-grid alumno-detalle-grid-sm2-3">
                          <MiniSwitch
                            label="Tutor legal"
                            checked={respTutor}
                            onChange={setRespTutor}
                            hint="Marca si ejerce la tutela legal."
                          />
                          <MiniSwitch
                            label="Convive con el alumno"
                            checked={respConvive}
                            onChange={setRespConvive}
                            hint="Vive en el mismo domicilio."
                          />
                        </div>

                        <div className="alumno-detalle-col-span-2">
                          {(() => {
                            const canAddResp =
                              (respNombre.trim() || respApellido.trim() || respVinculo || respTutor || respConvive) &&
                              !(respVinculo === 'Otro' && !respVinculoOtro.trim());

                            const agregarResponsable = () => {
                              if (!canAddResp) return;
                              const vinc = respVinculo === 'Otro' ? (respVinculoOtro.trim() || 'Otro') : respVinculo;
                              const nuevo = {
                                nombre: respNombre.trim(),
                                apellido: respApellido.trim(),
                                vinculo: vinc,
                                tutor_legal: !!respTutor,
                                convive: !!respConvive,
                              };
                              setAlumno(prev => ({ ...prev, responsables: [...(normalizeResponsables(prev)), nuevo] }));
                              setRespNombre(''); setRespApellido(''); setRespVinculo(''); setRespVinculoOtro('');
                              setRespTutor(false); setRespConvive(false);
                            };

                            return (
                              <button type="button" onClick={agregarResponsable} disabled={!canAddResp} className={BTN_GRAY}>
                                <Plus size={16} /> Agregar responsable
                              </button>
                            );
                          })()}
                        </div>
                      </div>

                      {/* Chips responsables */}
                      <div className="alumno-detalle-chips-wrap alumno-detalle-chips-wrap-resp">
                        {respList.map((r, i) => {
                          const extras = [r.tutor_legal ? 'Tutor legal' : null, r.convive ? 'Convive' : null]
                            .filter(Boolean).join(' • ');
                          return (
                            <span
                              key={i}
                              className="alumno-detalle-chip alumno-detalle-chip-resp"
                              title={`${r.vinculo || ''}${(r.nombre||r.apellido)?' — ':''}${[r.nombre, r.apellido].filter(Boolean).join(' ')}`}
                            >
                              <span className="alumno-detalle-chip-text">
                                <strong>{r.vinculo || '—'}</strong>
                                {(r.nombre || r.apellido) ? ` — ${[r.nombre, r.apellido].filter(Boolean).join(' ')}` : ''}
                                {extras ? ` (${extras})` : ''}
                              </span>
                              <button
                                onClick={() => setAlumno(prev => ({
                                  ...prev,
                                  responsables: normalizeResponsables(prev).filter((_, idx) => idx !== i)
                                }))}
                                className="alumno-detalle-chip-del alumno-detalle-chip-del-rose"
                                aria-label="Eliminar responsable"
                              >
                                <Trash2 size={12} />
                              </button>
                            </span>
                          );
                        })}
                      </div>
                    </div>

                    {/* Seccion de indicadores de inclusion: PPI y Acompanante Externo */}
                    <div className={card}>
                      <h3 className={cardTitle}>Inclusión y acompañamiento</h3>
                      <div className="alumno-detalle-grid alumno-detalle-grid-sm2-3">
                        <MiniSwitch
                          label="Proyecto Pedagógico de Inclusión (P.P.I.)"
                          checked={!!alumno?.tiene_ppi}
                          onChange={(v) => setAlumno({ ...alumno, tiene_ppi: v })}
                        />
                        <MiniSwitch
                          label="Acompañante Externo (A.E.)"
                          checked={!!alumno?.tiene_ae}
                          onChange={(v) => setAlumno({ ...alumno, tiene_ae: v })}
                        />
                      </div>
                    </div>

                    {/* Campo de observaciones o comentarios adicionales sobre el alumno */}
                    <div className={card}>
                      <h3 className={cardTitle}>Observaciones</h3>
                      <div className="alumno-detalle-field">
                        <label htmlFor="al-obs" className={fieldLabel}>Comentarios / información adicional</label>
                        <textarea
                          id="al-obs"
                          rows={4}
                          placeholder="Info. adicional del alumno"
                          value={alumno?.observaciones || ''}
                          onChange={(e) => setAlumno({ ...alumno, observaciones: e.target.value })}
                          className="alumno-detalle-textarea"
                        />
                      </div>
                    </div>
                  </motion.div>
                ) : (
                  <motion.div key="cronograma" variants={contentVariants} initial="initial" animate="animate" exit="exit" className="alumno-detalle-crono">
                    <div className="alumno-detalle-crono-icon">
                      <CalendarClock size={48} className="alumno-detalle-crono-icon-svg" />
                    </div>
                    <h3 className="alumno-detalle-crono-title">Editor de cronograma</h3>
                    <p className="alumno-detalle-crono-text">
                      Gestioná el horario semanal del alumno, incluyendo materias del plan y superposiciones por recursado o intensificación.
                    </p>
                    <button
                      onClick={() => onOpenCronograma?.(alumno)}
                      className="alumno-detalle-crono-btn"
                    >
                      <CalendarClock size={20} /> Abrir editor de cronograma completo
                    </button>
                  </motion.div>
                  )}
              </AnimatePresence>
            </div>

            {/* Footer con botones segun el modo activo */}
            {!esCronograma ? (
              <div className="alumno-detalle-footer">
                <div>
                  {!isPreceptor && (
                    <button
                      onClick={async () => {
                        const ok = await pedirConfirmacion({
                          title: `¿Eliminar a ${alumno.apellido}, ${alumno.nombre}?`,
                          text: 'Se puede eliminar solo si todavía no tiene notas ni asistencia cargadas. Esta acción no se puede deshacer.',
                          danger: true,
                          confirmText: 'Sí, eliminar',
                          cancelText: 'Cancelar'
                        });
                        if (!ok) return;
                        // Borrado en cascada: el servidor exige contraseña reciente.
                        if (!(await confirmarContrasena())) return;
                        try {
                          const detalles = await confirmAction('¿Por qué eliminás a este alumno?', async (d) => {
                            const qs = d ? `?_detalles=${encodeURIComponent(d)}` : '';
                            await apiDelete(`/alumnos/${alumno.id}${qs}`);
                          });
                          if (detalles === null) return;
                          eliminarAlumno(alumno.id);
                          cerrarConAnimacion();
                        } catch (e) {
                          // El bloqueo por histórico es un mensaje propio del
                          // servidor: se muestra tal cual, con los datos que
                          // impiden el borrado.
                          const texto = e?.status === 422 && e?.data?.message
                            ? e.data.message
                            : mensajeAmable(e, 'No se pudo eliminar al alumno. Intentá de nuevo.');
                          await mostrarAviso({ icon: 'error', title: 'No se pudo eliminar', text: texto });
                        }
                      }}
                      className="alumno-detalle-btn-delete"
                    >
                      Dar de baja
                    </button>
                  )}
                </div>
                <div className="alumno-detalle-footer-actions">
                  <button onClick={handleCloseAttempt} className="alumno-detalle-btn-cancel">
                    Cancelar
                  </button>
                  <button onClick={handleGuardar} disabled={guardando} className="alumno-detalle-btn-save">
                    {guardando ? 'Guardando…' : 'Guardar'}
                  </button>
                </div>
              </div>
            ) : (
              <div className="alumno-detalle-footer alumno-detalle-footer-single">
                <button
                  onClick={() => {
                    onOpenCronograma?.(alumno);
                  }}
                  className="alumno-detalle-footer-crono"
                >
                  <CalendarClock size={18} /> Abrir editor de cronograma completo
                </button>
              </div>
            )}
          </div>
        </div>
      )}
      {confirmModal}
      {avisoModal}
      {modalPwd}
    </>
  );
};

export default AlumnoDetalle;
