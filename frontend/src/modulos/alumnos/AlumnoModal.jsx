// ===============================
// FILE: src/components/Alumnos/AlumnoModal.jsx
// ===============================
import React, {
  useEffect, useRef, useState, useMemo, useCallback, useDeferredValue
} from 'react';
import { apiGet } from "../../compartido/lib/api";
import { cargarDivisiones } from "../../compartido/lib/divisiones";
import { motion, AnimatePresence } from 'framer-motion';
import { X, Trash2, Plus } from 'lucide-react';
import CursoDropdown from './CursoDropdown';
import useAviso from "../../compartido/componentes/modals/useAviso";
import { cursoLabel, parseCurso, ESPECIALIDADES, ANIO_ESPECIALIDAD_DESDE } from '../../compartido/lib/cursos';
import "./AlumnoModal.css";

/*
 * Componente: AlumnoModal
 * Props:
 *   visible       - Booleano que controla la apertura del modal
 *   onClose       - Callback al cerrar el modal
 *   alumno        - Objeto con los datos del nuevo alumno
 *   setAlumno     - Setter para modificar los datos del alumno
 *   agregarAlumno - Callback para crear el alumno via API
 *   errores       - Objeto con errores de validacion por campo
 *   setErrores    - Setter para actualizar errores
 *
 * Modal para dar de alta un nuevo alumno con todos sus datos:
 * informacion personal, materias recursando/intensificacion,
 * direcciones, telefonos, responsables, apoyos (PPI/AE) y observaciones.
 */

/* ---------- Dropdown simple (memo) ---------- */
/* Dropdown simple memoizado para seleccion de opciones */
const SimpleDropdown = React.memo(function SimpleDropdown({
  placeholder='Seleccionar', options=[], value, onChange, error, disabled=false
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    const handleClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, []);

  useEffect(() => { if (disabled) setOpen(false); }, [disabled]);

  const label = options.find(o => o.value === value)?.label || placeholder;

  return (
    <div className="alumno-modal-dropdown-wrap" ref={ref}>
      <button
        type="button"
        aria-disabled={disabled}
        onClick={() => { if (!disabled) setOpen(o => !o); }}
        title={String(label)}
        className={`alumno-modal-dropdown-btn
          ${disabled ? 'alumno-modal-dropdown-btn-disabled' : 'alumno-modal-dropdown-btn-enabled'}
          ${error ? 'alumno-modal-dropdown-btn-error' : 'alumno-modal-dropdown-btn-ok'}`}
      >
        {label}
      </button>

      {open && !disabled && (
        <div className="alumno-modal-dropdown-panel">
          {options.map(opt => (
            <div
              key={opt.value}
              title={String(opt.label)}
              onClick={() => { onChange(opt.value); setOpen(false); }}
              className="alumno-modal-dropdown-option"
            >
              {opt.label}
            </div>
          ))}
        </div>
      )}
    </div>
  );
});

/* ---------- Mini Switch (memo) ---------- */
/* Switch memoizado para opciones binarias (tutor legal, convive, PPI, AE) */
const MiniSwitch = React.memo(function MiniSwitch({ label, checked, onChange, hint }) {
  const id = `sw-${label.replace(/\s+/g,'-')}-${Math.random().toString(36).slice(2,7)}`;
  return (
    <label htmlFor={id} className="alumno-modal-switch-label">
      <div className="alumno-modal-switch-info">
        <span className="alumno-modal-switch-title">{label}</span>
        {hint ? <span className="alumno-modal-switch-hint">{hint}</span> : null}
      </div>
      <div className="alumno-modal-switch-wrap">
        <input
          id={id}
          type="checkbox"
          className="alumno-modal-switch-sr"
          checked={!!checked}
          onChange={(e) => onChange?.(e.target.checked)}
        />
        <div className={`alumno-modal-switch-track ${checked ? 'alumno-modal-switch-track-on' : 'alumno-modal-switch-track-off'}`}>
          <div className={`alumno-modal-switch-thumb ${checked ? 'alumno-modal-switch-thumb-on' : 'alumno-modal-switch-thumb-off'}`} />
        </div>
      </div>
    </label>
  );
});

/* ---------- ChipsRow (memo) ---------- */
/* Fila de chips con boton de eliminacion, usado para materias, direcciones, telefonos y responsables */
const ChipsRow = React.memo(function ChipsRow({ items, onDelete, maxW = 220 }) {
  // items: [{ title, label }]
  return (
    <div className="alumno-modal-chips-row">
      {items.map((it, i) => (
        <span
          key={it.title ?? i}
          title={it.title}
          className="alumno-modal-chip"
          style={{ maxWidth: `${maxW}px` }}
        >
          <span className="alumno-modal-chip-text">{it.label}</span>
          <button
            onClick={() => onDelete(i)}
            className="alumno-modal-chip-del"
            aria-label="Eliminar item"
          >
            <Trash2 size={12} />
          </button>
        </span>
      ))}
    </div>
  );
});

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

/* ---------- Botones ---------- */
const baseBtn = 'alumno-modal-btn';
const BTN_BLUE  = `${baseBtn} alumno-modal-btn-blue`;
const BTN_GREEN = `${baseBtn} alumno-modal-btn-green`;
const BTN_GRAY  = `${baseBtn} alumno-modal-btn-gray`;

/* ===== Normalizadores ===== */
const cleanDivision = (d) =>
  (String(d || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 1) || null);

/** Parseo robusto usando el módulo central */
const splitCursoStr = (s) => {
  const p = parseCurso(s);
  return {
    anioStr: p.anio ? cursoLabel({ anio: p.anio, division: null }) : '',
    divisionStr: p.division || '',
  };
};

const parseCursoForPayload = (val) => {
  const p = parseCurso(val);
  const anioNum = p.anio || null;
  const division = cleanDivision(p.division);
  return { anioNum, division };
};
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

/* ============================ Componente principal ============================ */
/* Modal de creacion de nuevo alumno con formulario completo y validacion */
export default function AlumnoModal({ visible, onClose, alumno, setAlumno, agregarAlumno, errores, setErrores }) {
  // Selecciones para agregar
  const [materiaAnio, setMateriaAnio] = useState('');
  const [materiaDivision, setMateriaDivision] = useState('');
  const [materiaNombre, setMateriaNombre] = useState('');
  const [intensAnio, setIntensAnio] = useState('');
  const [intensDivision, setIntensDivision] = useState('');
  const [intensNombre, setIntensNombre] = useState('');

  // Materias por año (raw) + loading
  const [materiasRecRaw, setMateriasRecRaw] = useState([]);
  const [materiasIntRaw, setMateriasIntRaw] = useState([]);
  const [loadingRec, setLoadingRec] = useState(false);
  const [loadingInt, setLoadingInt] = useState(false);

  // Divisiones por año
  const [divisionesMap, setDivisionesMap] = useState({});
  const [maxAnioActivo, setMaxAnioActivo] = useState(7);

  // Direcciones
  const [direccionTitular, setDireccionTitular] = useState('');
  const [direccionTitularOtro, setDireccionTitularOtro] = useState('');
  const [direccionValor, setDireccionValor] = useState('');

  // Teléfonos
  const [telefonoTitular, setTelefonoTitular] = useState('');
  const [telefonoTitularOtro, setTelefonoTitularOtro] = useState('');
  const [telefonoValor, setTelefonoValor] = useState('');

  // Responsables / tutores
  const [respNombre, setRespNombre] = useState('');
  const [respApellido, setRespApellido] = useState('');
  const [respVinculo, setRespVinculo] = useState('');
  const [respVinculoOtro, setRespVinculoOtro] = useState('');
  const [respTutor, setRespTutor] = useState(false);
  const [respConvive, setRespConvive] = useState(false);

  const nombreRef = useRef();
  useEffect(() => { if (visible) setTimeout(() => nombreRef.current?.focus(), 50); }, [visible]);

  /* ===== Asistente por pasos: 1 Quién es · 2 Curso · 3 Familia y contacto ===== */
  const [paso, setPaso] = useState(1);
  const [avisosPaso, setAvisosPaso] = useState([]);
  // Evita el doble-click en Inscribir (duplicaba alumnos).
  const [guardandoModal, setGuardandoModal] = useState(false);
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  useEffect(() => { if (visible) { setPaso(1); setAvisosPaso([]); } }, [visible]);

  const dniSoloDigitos = () => String(alumno?.dni ?? '').replace(/\D/g, '');

  /* Qué falta en cada paso, dicho en criollo para mostrarlo abajo del botón */
  const validarPaso1 = () => {
    const faltan = [];
    if (!alumno?.nombre?.trim()) faltan.push('el nombre');
    if (!alumno?.apellido?.trim()) faltan.push('el apellido');
    if (!alumno?.genero) faltan.push('el género');
    const d = dniSoloDigitos();
    if (!d) faltan.push('el DNI');
    else if (d.length < 7 || d.length > 8) faltan.push('un DNI válido (7 u 8 números)');
    return faltan;
  };

  const validarPaso2 = () => {
    const faltan = [];
    const { anioNum, division: divFromAnio } = parseCursoForPayload(alumno.anio);
    const divisionFinal =
      cleanDivision(alumno?.division) ||
      cleanDivision(alumno?.curso?.division) ||
      divFromAnio;
    if (!anioNum || !divisionFinal) faltan.push('el curso (año y división)');
    if ((anioNum || 0) >= ANIO_ESPECIALIDAD_DESDE && !alumno?.especialidad) faltan.push('la especialidad');
    return faltan;
  };

  const marcarErroresPaso1 = () => {
    const d = dniSoloDigitos();
    setErrores({
      nombre: !alumno?.nombre?.trim(),
      apellido: !alumno?.apellido?.trim(),
      genero: !alumno?.genero,
      dni: !d || d.length < 7 || d.length > 8,
    });
  };

  const continuar = () => {
    if (paso === 1) {
      const f = validarPaso1();
      if (f.length) { setAvisosPaso(f); marcarErroresPaso1(); return; }
    }
    if (paso === 2) {
      const f = validarPaso2();
      if (f.length) {
        setAvisosPaso(f);
        setErrores((e) => ({ ...(e || {}), anio: true, especialidad: !alumno?.especialidad }));
        return;
      }
    }
    setAvisosPaso([]);
    setErrores?.({});
    setPaso((p) => Math.min(3, p + 1));
  };

  const atras = () => {
    setAvisosPaso([]);
    setPaso((p) => Math.max(1, p - 1));
  };

  /* ===== Traer divisiones y tope activo ===== */
  useEffect(() => {
    if (!visible) return;
    let cancel = false;
    (async () => {
      try {
        const norm = await cargarDivisiones();
        if (cancel) return;
        setDivisionesMap(norm);
        const keys = Object.keys(norm).map(k => Number(k)).filter(n => Number.isFinite(n) && (norm[n]?.length ?? 0) > 0);
        const top = keys.length ? Math.max(...keys) : 6;
        setMaxAnioActivo(Math.max(6, top));
      } catch {
        if (!cancel) { setDivisionesMap({1:[],2:[],3:[],4:[],5:[],6:[]}); setMaxAnioActivo(6); }
      }
    })();
    return () => { cancel = true; };
  }, [visible]);

  /* ===== Año actual del alumno (num) ===== */
  const alumnoYearNum = (() => {
    const fromCurso = parseCurso(alumno?.curso?.anio).anio;
    if (fromCurso > 0) return fromCurso;
    const fromStr = parseCurso(alumno?.anio).anio;
    return fromStr || null;
  })();

  /* ===== Opciones de año ===== */
  const aniosOptsAll = Array.from({ length: maxAnioActivo }, (_, i) => {
    const ord = cursoLabel({ anio: i+1, division: null });
    return { label: ord, value: ord };
  });
  const aniosOptsLimited = alumnoYearNum
    ? aniosOptsAll.filter(o => parseCurso(o.value).anio <= alumnoYearNum)
    : aniosOptsAll;

  // Helpers duplicados / normalizadores
  const normName = (s) => String(s || '').trim().toLowerCase();
  const yearNum = (a) => parseInt(String(a || '').replace(/\D/g, ''), 10) || 0;
  const recList = alumno.recursando || [];
  const intList = alumno.intensificacion || [];
  const existeMateriaEnListas = (anioN, nombre) => {
    const n = Number(anioN) || 0;
    const nm = normName(nombre);
    const match = (m) => yearNum(m.anio) === n && normName(m.materia) === nm;
    return recList.some(match) || intList.some(match);
  };

  // 👉 división del alumno
  const getAlumnoDivision = () =>
    cleanDivision(alumno?.division) ||
    cleanDivision(alumno?.curso?.division) ||
    cleanDivision(parseCursoForPayload(alumno?.anio).division);

  const buildDivOpts = (anioStr) => {
    const n = yearNum(anioStr);
    const arr = divisionesMap?.[n] || divisionesMap?.[String(n)] || [];
    return (arr || []).map(d => ({ label: d, value: d }));
  };

  // Si cambia el curso a un año menor, limpiar selecciones mayores
  useEffect(() => {
    const toNum = (s) => parseInt(String(s || '').replace(/\D/g, ''), 10) || null;
    if (alumnoYearNum) {
      if (toNum(materiaAnio) && toNum(materiaAnio) > alumnoYearNum) {
        setMateriaAnio(''); setMateriaNombre(''); setMateriaDivision('');
        setMateriasRecRaw([]);
      }
      if (toNum(intensAnio) && toNum(intensAnio) > alumnoYearNum) {
        setIntensAnio(''); setIntensNombre(''); setIntensDivision('');
        setMateriasIntRaw([]);
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [alumnoYearNum]);

  /* Cargar materias (recursando) */
  useEffect(() => {
    if (!materiaAnio) { setMateriasRecRaw([]); setMateriaNombre(''); setMateriaDivision(''); return; }
    const n = yearNum(materiaAnio);
    if (!n) return;

    const divisiones = divisionesMap?.[n] || divisionesMap?.[String(n)] || [];
    const divAlumno = getAlumnoDivision() || '';
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

  /* Cargar materias (intensificación) */
  useEffect(() => {
    if (!intensAnio) { setMateriasIntRaw([]); setIntensNombre(''); setIntensDivision(''); return; }
    const n = yearNum(intensAnio);
    if (!n) return;

    const divisiones = divisionesMap?.[n] || divisionesMap?.[String(n)] || [];
    const divAlumno = getAlumnoDivision() || '';
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
        if (!cancel) { setMateriasIntRaw([]); setIntensNombre(''); }
      } finally {
        if (!cancel) setLoadingInt(false);
      }
    })();
    return () => { cancel = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [intensAnio, divisionesMap]);

  // Opciones filtradas anti-duplicados
  const materiasRecOpts = useMemo(() => {
    const n = yearNum(materiaAnio);
    return (materiasRecRaw || []).filter(opt => !existeMateriaEnListas(n, opt.value));
  }, [materiasRecRaw, materiaAnio, recList, intList]);

  const materiasIntOpts = useMemo(() => {
    const n = yearNum(intensAnio);
    return (materiasIntRaw || []).filter(opt => !existeMateriaEnListas(n, opt.value));
  }, [materiasIntRaw, intensAnio, recList, intList]);

  /* --------- Defer: listas pesadas para que tipear sea fluido --------- */
  const recDef   = useDeferredValue(alumno.recursando || []);
  const intDef   = useDeferredValue(alumno.intensificacion || []);
  const dirsDef  = useDeferredValue(alumno.direcciones || []);
  const telsDef  = useDeferredValue(alumno.telefonos || []);
  const respDef  = useDeferredValue(alumno.responsables || []);

  const recItems = useMemo(
    () => (recDef || []).map(m => {
      const text = `${cursoLabel({ anio: m.anio, division: m.division })} - ${m.materia}`;
      return { title: text, label: text };
    }),
    [recDef]
  );
  const intItems = useMemo(
    () => (intDef || []).map(m => {
      const text = `${cursoLabel({ anio: m.anio, division: m.division })} - ${m.materia}`;
      return { title: text, label: text };
    }),
    [intDef]
  );
  const dirItems = useMemo(
    () => (dirsDef || []).map(d => {
      const text = `${d.titular}: ${d.direccion}`;
      return { title: text, label: <><strong>{d.titular}:</strong> {d.direccion}</> };
    }),
    [dirsDef]
  );
  const telItems = useMemo(
    () => (telsDef || []).map(t => {
      const text = `${t.titular}: ${t.telefono}`;
      return { title: text, label: <><strong>{t.titular}:</strong> {t.telefono}</> };
    }),
    [telsDef]
  );
  const respItems = useMemo(
    () => (respDef || []).map(r => {
      const extras = [r.tutor_legal ? 'Tutor legal' : null, r.convive ? 'Convive' : null].filter(Boolean).join(' • ');
      const vinc = r.vinculo || r.parentesco || '—';
      const title = `${vinc}${(r.nombre||r.apellido)?' — ':''}${[r.nombre, r.apellido].filter(Boolean).join(' ')}${extras?` (${extras})`:''}`;
      const label = (
        <>
          <strong>{vinc}</strong>
          {(r.nombre || r.apellido) ? ` — ${[r.nombre, r.apellido].filter(Boolean).join(' ')}` : ''}
          {extras ? ` (${extras})` : ''}
        </>
      );
      return { title, label };
    }),
    [respDef]
  );

  /* Guardar */
  const handleGuardar = async () => {
    if (guardandoModal) return;
    const err = {};
    const { anioNum, division: divFromAnio } = parseCursoForPayload(alumno.anio);
    const divisionFinal =
      cleanDivision(alumno?.division) ||
      cleanDivision(alumno?.curso?.division) ||
      divFromAnio;

    if (!alumno?.nombre?.trim()) err.nombre = true;
    if (!alumno?.apellido?.trim()) err.apellido = true;
    if (!alumno?.genero) err.genero = true;

    const dniDigits = String(alumno?.dni ?? '').replace(/\D/g, '');
    if (dniDigits.length < 7 || dniDigits.length > 8) err.dni = true;

    const tieneAnio = Boolean(anioNum);
    const tieneDiv  = Boolean(divisionFinal);
    if (!tieneAnio || !tieneDiv) err.anio = true;

    const y = alumnoYearNum || 0;
    if (y) {
      const toYear = (a) => parseInt(String(a || '').replace(/\D/g, ''), 10) || 0;
      const recFuera = (alumno?.recursando || []).some(m => (toYear(m.anio) || 0) > y);
      const intFuera = (alumno?.intensificacion || []).some(m => (toYear(m.anio) || 0) > y);
      if (recFuera || intFuera) err.materias = true;
    }

    setErrores(err);
    if (Object.keys(err).length > 0) {
      let msg = 'Revisá los campos marcados en rojo.';
      if (err.materias) msg = 'Hay materias con año superior al curso del alumno.';
      else if (err.dni) msg = 'El DNI debe tener entre 7 y 8 dígitos.';
      await mostrarAviso({ icon: 'warning', title: 'Datos incompletos o inválidos', text: msg });
      return;
    }
    setGuardandoModal(true);
    try {
      await agregarAlumno(construirPayload());
    } finally {
      setGuardandoModal(false);
    }
  };

  /* Arma el payload final para crear el alumno */
  const construirPayload = () => {
    const { anioNum, division: divFromAnio } = parseCursoForPayload(alumno.anio);
    const divisionFinal =
      cleanDivision(alumno?.division) ||
      cleanDivision(alumno?.curso?.division) ||
      divFromAnio;
    const dniDigits = String(alumno?.dni ?? '').replace(/\D/g, '');

    const responsablesPayload = (Array.isArray(alumno?.responsables) ? alumno.responsables : []).map((r) => {
      const v = String(r?.vinculo ?? r?.parentesco ?? '').trim();
      return {
        nombre: (r?.nombre || '').trim(),
        apellido: (r?.apellido || '').trim(),
        vinculo: v,
        parentesco: v,
        tutor_legal: !!(r?.tutor_legal ?? r?.tutor),
        convive: !!(r?.convive ?? r?.vive_con),
      };
    });

    const recursandoPayload = (alumno?.recursando || []).map((m) => ({
      anio: parseInt(String(m?.anio || '').replace(/\D/g, ''), 10) || null,
      division: cleanDivision(m?.division),
      materia: String(m?.materia || '').trim(),
    })).filter(m => m.materia);

    const intensificacionPayload = (alumno?.intensificacion || []).map((m) => ({
      anio: parseInt(String(m?.anio || '').replace(/\D/g, ''), 10) || null,
      division: cleanDivision(m?.division),
      materia: String(m?.materia || '').trim(),
    })).filter(m => m.materia);

    const payload = {
      nombre: (alumno?.nombre || '').trim(),
      apellido: (alumno?.apellido || '').trim(),
      genero: alumno?.genero || null,
      dni: dniDigits || null,
      nacionalidad: (alumno?.nacionalidad || '').trim() || null,
      matricula: (alumno?.matricula || '').trim() || null,
      fecha_nacimiento: alumno?.fechaNacimiento || alumno?.fecha_nacimiento || null,
      anio: anioNum,
      division: divisionFinal,
      grupo: alumno?.grupo || null,
      estado: alumno?.estado || 'Activo',
      especialidad: parseInt(parseCursoForPayload(alumno.anio)?.anio ?? 0, 10) >= ANIO_ESPECIALIDAD_DESDE ? (alumno?.especialidad || null) : null,
      observaciones: alumno?.observaciones || '',
      direcciones: normalizeDirecciones(alumno).map(d => ({ titular: d.titular, direccion: d.direccion })),
      telefonos: normalizeTelefonos(alumno).map(t => ({ titular: t.titular, telefono: t.telefono })),
      recursando: recursandoPayload,
      intensificacion: intensificacionPayload,
      tiene_ppi: !!alumno?.tiene_ppi,
      tiene_ae: !!alumno?.tiene_ae,
      responsables: responsablesPayload,
    };

    return payload;
  };

  /* Agregar / quitar ítems */
  const agregarMateria = async (tipo) => {
    const toYear = (a) => parseInt(String(a || '').replace(/\D/g, ''), 10) || 0;
    const anio      = tipo === 'recursando' ? materiaAnio      : intensAnio;
    const division  = tipo === 'recursando' ? materiaDivision  : intensDivision;
    const nombre    = tipo === 'recursando' ? materiaNombre    : intensNombre;
    if (!anio || !division || !nombre) return;

    const nSel = toYear(anio);
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

    const nueva = { anio: nSel, division, materia: nombre };
    setAlumno(prev => ({ ...prev, [tipo]: [...(prev[tipo] || []), nueva] }));

    if (tipo === 'recursando') { setMateriaAnio(''); setMateriaDivision(''); setMateriaNombre(''); }
    else { setIntensAnio(''); setIntensDivision(''); setIntensNombre(''); }
  };

  const eliminarMateria = (index, tipo) => {
    const list = (alumno[tipo] || []).filter((_, i) => i !== index);
    setAlumno({ ...alumno, [tipo]: list });
  };
  const onDeleteRec = useCallback((i) => eliminarMateria(i, 'recursando'), [alumno]); // mismo comportamiento
  const onDeleteInt = useCallback((i) => eliminarMateria(i, 'intensificacion'), [alumno]);
  const agregarDireccion = () => {
    const titularBase = direccionTitular;
    const titular = titularBase === 'Otro' ? (direccionTitularOtro.trim() || '') : titularBase;
    const direccion = direccionValor.trim();
    if (!titular || !direccion) return;
    setAlumno(prev => ({ ...prev, direcciones: [...(prev.direcciones || []), { titular, direccion }] }));
    setDireccionTitular(''); setDireccionTitularOtro(''); setDireccionValor('');
  };
  const eliminarDireccion = useCallback((i) => {
    setAlumno(prev => ({ ...prev, direcciones: (prev.direcciones || []).filter((_, idx) => idx !== i) }));
  }, [setAlumno]);

  const agregarTelefono = () => {
    const titularBase = telefonoTitular;
    const titular = titularBase === 'Otro' ? (telefonoTitularOtro.trim() || '') : titularBase;
    const telefono = telefonoValor.trim();
    if (!titular || !telefono) return;
    setAlumno(prev => ({ ...prev, telefonos: [...(prev.telefonos || []), { titular, telefono }] }));
    setTelefonoTitular(''); setTelefonoTitularOtro(''); setTelefonoValor('');
  };
  const eliminarTelefono = useCallback((i) => {
    setAlumno(prev => ({ ...prev, telefonos: (prev.telefonos || []).filter((_, idx) => idx !== i) }));
  }, [setAlumno]);

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
      parentesco: vinc,
      tutor_legal: !!respTutor,
      convive: !!respConvive,
    };
    setAlumno(prev => ({ ...prev, responsables: [...(prev.responsables || []), nuevo] }));
    setRespNombre(''); setRespApellido(''); setRespVinculo(''); setRespVinculoOtro('');
    setRespTutor(false); setRespConvive(false);
  };
  const eliminarResponsable = useCallback((i) => {
    setAlumno(prev => ({ ...prev, responsables: (prev.responsables || []).filter((_, idx) => idx !== i) }));
  }, [setAlumno]);

  /* ============= Confirmación cierre ============= */
  const sanitizeForCompare = (a) => ({
    nombre: (a?.nombre || '').trim(),
    apellido: (a?.apellido || '').trim(),
    genero: a?.genero || '',
    dni: String(a?.dni || '').replace(/\D/g, ''),
    nacionalidad: (a?.nacionalidad || '').trim(),
    matricula: (a?.matricula || '').trim(),
    fecha: a?.fechaNacimiento || a?.fecha_nacimiento || '',
    anio: a?.anio || '',
    division: cleanDivision(a?.division) || '',
    grupo: a?.grupo || '',
    estado: a?.estado || 'Activo',
    observaciones: a?.observaciones || '',
    recursando: (a?.recursando || []).map(m => ({
      anio: String(m?.anio || ''),
      division: cleanDivision(m?.division) || '',
      materia: (m?.materia || '').trim(),
    })),
    intensificacion: (a?.intensificacion || []).map(m => ({
      anio: String(m?.anio || ''),
      division: cleanDivision(m?.division) || '',
      materia: (m?.materia || '').trim(),
    })),
    direcciones: normalizeDirecciones(a).map(d => ({ titular: d.titular, direccion: d.direccion })),
    telefonos: normalizeTelefonos(a).map(t => ({ titular: t.titular, telefono: t.telefono })),
    responsables: (Array.isArray(a?.responsables) ? a.responsables : []).map(r => ({
      nombre: (r?.nombre || '').trim(),
      apellido: (r?.apellido || '').trim(),
      vinculo: (r?.vinculo || r?.parentesco || '').trim(),
      tutor_legal: !!(r?.tutor_legal ?? r?.tutor),
      convive: !!(r?.convive ?? r?.vive_con),
    })),
    tiene_ppi: !!a?.tiene_ppi,
    tiene_ae: !!a?.tiene_ae,
  });

  const deepClone = (v) => JSON.parse(JSON.stringify(v ?? {}));
  const [snapshot, setSnapshot] = useState(null);
  const [rawSnapshot, setRawSnapshot] = useState(null);

  useEffect(() => {
    if (visible) {
      setSnapshot(sanitizeForCompare(alumno));
      setRawSnapshot(deepClone(alumno));
    } else {
      setSnapshot(null);
      setRawSnapshot(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible]);

  const hasDirty = () => {
    if (!snapshot) return false;
    const cur = sanitizeForCompare(alumno);
    return JSON.stringify(cur) !== JSON.stringify(snapshot);
  };

  const resetLocalUI = () => {
    setMateriaAnio(''); setMateriaDivision(''); setMateriaNombre('');
    setIntensAnio(''); setIntensDivision(''); setIntensNombre('');
    setDireccionTitular(''); setDireccionTitularOtro(''); setDireccionValor('');
    setTelefonoTitular(''); setTelefonoTitularOtro(''); setTelefonoValor('');
    setRespNombre(''); setRespApellido(''); setRespVinculo(''); setRespVinculoOtro('');
    setRespTutor(false); setRespConvive(false);
    setErrores?.({});
  };

  const handleCloseAttempt = async () => {
    if (!hasDirty()) { onClose?.(); return; }
    const ok = await pedirConfirmacion({
      icon: 'warning',
      title: '¿Descartar cambios?',
      text: 'Tenés cambios sin guardar. Si cerrás ahora, se perderán.',
      confirmText: 'Descartar',
      cancelText: 'Seguir editando',
    });
    if (ok) {
      if (rawSnapshot) setAlumno(rawSnapshot);
      resetLocalUI();
      onClose?.();
    }
  };

  return (
    <>
    <AnimatePresence>
      {visible && (
        <motion.div
          key="alumno-modal-backdrop"
          initial={false} exit={{ opacity: 0 }}
          transition={{ duration: 0.18, ease: "easeOut" }}
className="alumno-modal-backdrop"
        >
          <motion.div
            initial={false}
            exit={{ opacity: 0, scale: 0.96, y: 12 }}
            transition={{ duration: 0.24, ease: [0.25, 0.46, 0.45, 0.94] }}
            className="alumno-modal-container"
            role="dialog" aria-modal="true"
          >
            {/* Top Accent */}
            <div className="alumno-modal-accent" />

            {/* Hero header */}
            <div className="alumno-modal-hero">
              <div className="alumno-modal-halo alumno-modal-halo-blue" />
              <div className="alumno-modal-halo alumno-modal-halo-purple" />

              <div className="alumno-modal-header">
                <h2 className="alumno-modal-title">Inscribir un alumno</h2>
                <button
                  onClick={handleCloseAttempt}
                  aria-label="Cerrar"
                  className="alumno-modal-close"
                >
                  <X size={20} />
                </button>
              </div>

              {/* Pasos del asistente */}
              <ol className="alumno-modal-steps">
                {['Quién es', 'Curso', 'Familia y contacto'].map((titulo, i) => {
                  const n = i + 1;
                  const estado = n === paso ? 'actual' : (n < paso ? 'listo' : 'falta');
                  return (
                    <li key={titulo} className={`alumno-modal-step alumno-modal-step-${estado}`}>
                      <span className="alumno-modal-step-num">{n < paso ? '✓' : n}</span>
                      <span className="alumno-modal-step-titulo">{titulo}</span>
                    </li>
                  );
                })}
              </ol>
            </div>

            {/* BODY */}
            <div className="alumno-modal-body">
              {/* Paso 1: quién es el alumno */}
              {paso === 1 && (
              <div className="alumno-modal-card alumno-modal-card-spaced">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-gray">Datos del alumno</h3>
                <div className="alumno-modal-grid alumno-modal-grid-2">
                <input
                  ref={nombreRef}
                  type="text"
                  placeholder="Nombre *"
                  value={alumno.nombre}
                  onChange={(e) => setAlumno({ ...alumno, nombre: e.target.value })}
                  className={`alumno-modal-input ${errores.nombre ? 'alumno-modal-input-error' : 'alumno-modal-input-ok'}`}
                />
                <input
                  type="text"
                  placeholder="Apellido *"
                  value={alumno.apellido}
                  onChange={(e) => setAlumno({ ...alumno, apellido: e.target.value })}
                  className={`alumno-modal-input ${errores.apellido ? 'alumno-modal-input-error' : 'alumno-modal-input-ok'}`}
                />
                <SimpleDropdown
                  placeholder="Seleccionar género *"
                  options={GENERO_OPTS}
                  value={alumno.genero}
                  onChange={(val) => setAlumno({ ...alumno, genero: val })}
                  error={errores.genero}
                />
                <input
                  type="date"
                  value={alumno.fechaNacimiento || ''}
                  onChange={(e) => setAlumno({ ...alumno, fechaNacimiento: e.target.value })}
                  className="alumno-modal-input alumno-modal-input-ok"
                  aria-label="Fecha de nacimiento"
                />
                <input
                  type="text"
                  inputMode="numeric"
                  pattern="[0-9]*"
                  maxLength={8}
                  placeholder="DNI (solo números) *"
                  value={alumno.dni || ''}
                  onChange={(e) => setAlumno({ ...alumno, dni: e.target.value.replace(/\D/g, '') })}
                  className={`alumno-modal-input ${errores.dni ? 'alumno-modal-input-error' : 'alumno-modal-input-ok'}`}
                />
                <input
                  type="text"
                  maxLength={60}
                  placeholder="Nacionalidad (ej: Argentina)"
                  aria-label="Nacionalidad"
                  value={alumno.nacionalidad || ''}
                  onChange={(e) => setAlumno({ ...alumno, nacionalidad: e.target.value })}
                  className="alumno-modal-input alumno-modal-input-ok"
                />
                <input
                  type="text"
                  maxLength={30}
                  placeholder="N° de matrícula"
                  aria-label="Nro. de matrícula"
                  value={alumno.matricula || ''}
                  onChange={(e) => setAlumno({ ...alumno, matricula: e.target.value })}
                  className="alumno-modal-input alumno-modal-input-ok"
                />
                </div>
              </div>
              )}

              {/* Paso 2: a qué curso va */}
              {paso === 2 && (
              <div className="alumno-modal-card alumno-modal-card-spaced">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-gray">Curso del alumno</h3>
                <div className="alumno-modal-grid alumno-modal-grid-2">
                <CursoDropdown
                  value={alumno.anio}
                  onChange={(val) => {
                    const { anioStr, divisionStr } = splitCursoStr(val);
                    const p = parseCurso(val);
                    setAlumno({
                      ...alumno,
                      anio: anioStr + (divisionStr ? ` ${cursoLabel({ anio: p.anio, division: p.division }).split(' ')[1] || divisionStr}` : ''),
                      division: divisionStr || '',
                      curso: anioStr ? { anio: String(p.anio ?? ''), division: divisionStr || '' } : undefined,
                      // Si baja de 4to año, se limpia la especialidad.
                      especialidad: parseInt(p.anio ?? 0, 10) < ANIO_ESPECIALIDAD_DESDE ? null : alumno.especialidad
                    });
                  }}
                  error={errores.anio}
                />
                {parseInt(alumno.curso?.anio ?? 0, 10) >= ANIO_ESPECIALIDAD_DESDE && (
                  <div className="alumno-modal-col-span-2">
                    <SimpleDropdown
                      placeholder="Especialidad *"
                      options={ESPECIALIDADES.map((e) => ({ value: e.key, label: e.label }))}
                      value={alumno.especialidad || ''}
                      onChange={(v) => setAlumno({ ...alumno, especialidad: v || null })}
                      error={errores.especialidad}
                    />
                  </div>
                )}
                <div className="alumno-modal-grupo-row alumno-modal-col-span-2">
                  <span className="alumno-modal-grupo-label">Grupo:</span>
                  {['', 'A', 'B'].map(g => (
                    <label key={g || 'ninguno'} className="alumno-modal-radio-label">
                      <input type="radio" name="grupo" value={g}
                        checked={(alumno.grupo || '') === g}
                        onChange={() => setAlumno({ ...alumno, grupo: g || null })}
                        className="alumno-modal-radio-input" />
                      <span className="alumno-modal-radio-text">{g || '—'}</span>
                    </label>
                  ))}
                </div>
                </div>
              </div>
              )}

              {/* Seccion de materias recursando: selector de anio, division y materia */}
              {paso === 2 && (
              <div className="alumno-modal-card">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-orange">Materias Recursando</h3>
                <div className="alumno-modal-selectors-row">
                  <div className="alumno-modal-selector-col">
                    <SimpleDropdown placeholder="Año" options={aniosOptsLimited} value={materiaAnio} onChange={setMateriaAnio} />
                  </div>
                  <div className="alumno-modal-selector-col">
                    <SimpleDropdown
                      placeholder="División"
                      options={buildDivOpts(materiaAnio)}
                      value={materiaDivision}
                      onChange={setMateriaDivision}
                      disabled={!materiaAnio}
                    />
                  </div>
                  <div className="alumno-modal-selector-col">
                    <SimpleDropdown
                      placeholder={loadingRec ? 'Cargando...' : (materiaAnio && materiaNombre && existeMateriaEnListas(parseInt(String(materiaAnio).replace(/\D/g,''),10), materiaNombre) ? 'Ya agregada' : 'Materia')}
                      options={materiasRecOpts}
                      value={materiaNombre}
                      onChange={setMateriaNombre}
                      disabled={!materiaAnio || loadingRec}
                    />
                  </div>
                  <button onClick={() => agregarMateria('recursando')} disabled={!(materiaAnio && materiaDivision && materiaNombre && !loadingRec && !(materiaAnio && materiaNombre && existeMateriaEnListas(parseInt(String(materiaAnio).replace(/\D/g,''),10), materiaNombre)))} className={BTN_BLUE}>
                    <Plus size={16} /> Agregar
                  </button>
                </div>

                <ChipsRow items={recItems} onDelete={onDeleteRec} />
              </div>
              )}

              {/* Seccion de materias de intensificacion: selector de anio, division y materia */}
              {paso === 2 && (
              <div className="alumno-modal-card">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-blue">Materias Intensificación</h3>
                <div className="alumno-modal-selectors-row">
                  <div className="alumno-modal-selector-col">
                    <SimpleDropdown placeholder="Año" options={aniosOptsLimited} value={intensAnio} onChange={setIntensAnio} />
                  </div>
                  <div className="alumno-modal-selector-col">
                    <SimpleDropdown
                      placeholder="División"
                      options={buildDivOpts(intensAnio)}
                      value={intensDivision}
                      onChange={setIntensDivision}
                      disabled={!intensAnio}
                    />
                  </div>
                  <div className="alumno-modal-selector-col">
                    <SimpleDropdown
                      placeholder={loadingInt ? 'Cargando...' : (intensAnio && intensNombre && existeMateriaEnListas(parseInt(String(intensAnio).replace(/\D/g,''),10), intensNombre) ? 'Ya agregada' : 'Materia')}
                      options={materiasIntOpts}
                      value={intensNombre}
                      onChange={setIntensNombre}
                      disabled={!intensAnio || loadingInt}
                    />
                  </div>
                  <button onClick={() => agregarMateria('intensificacion')} disabled={!(intensAnio && intensDivision && intensNombre && !loadingInt && !(intensAnio && intensNombre && existeMateriaEnListas(parseInt(String(intensAnio).replace(/\D/g,''),10), intensNombre)))} className={BTN_GREEN}>
                    <Plus size={16} /> Agregar
                  </button>
                </div>

                <ChipsRow items={intItems} onDelete={onDeleteInt} />
              </div>
              )}

              {/* Paso 3: dónde vive y quién lo cuida */}
              {paso === 3 && (
              <>
              <div className="alumno-modal-card alumno-modal-card-spaced">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-gray">Revisá antes de inscribir</h3>
                <p className="alumno-modal-resumen">
                  Vas a inscribir a <strong>{alumno.apellido || '—'}, {alumno.nombre || ''}</strong>
                  {dniSoloDigitos() ? <> (DNI {dniSoloDigitos()})</> : null}
                  {alumno.anio ? <> en <strong>{alumno.anio}</strong></> : null}.
                </p>
              </div>

              {/* Direcciones */}
              <div className="alumno-modal-card">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-gray">Direcciones</h3>
                <div className="alumno-modal-row-md">
                  <div className="alumno-modal-col-md-25">
                    <SimpleDropdown placeholder="Titular" options={TITULAR_OPTS} value={direccionTitular} onChange={setDireccionTitular} />
                  </div>
                  {direccionTitular === 'Otro' && (
                    <input
                      type="text"
                      placeholder="Especificar titular"
                      value={direccionTitularOtro}
                      onChange={(e) => setDireccionTitularOtro(e.target.value)}
                      className="alumno-modal-input alumno-modal-input-plain alumno-modal-input-md-25"
                    />
                  )}
                  <input
                    type="text"
                    placeholder="Ej.: Calle 123, Piso 1, Dto. A"
                    value={direccionValor}
                    onChange={(e) => setDireccionValor(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); const titularBase = direccionTitular; const titular = titularBase === 'Otro' ? (direccionTitularOtro.trim() || '') : titularBase; const direccion = direccionValor.trim(); if (titular && direccion) agregarDireccion(); } }}
                    className="alumno-modal-input alumno-modal-input-plain alumno-modal-input-md-flex"
                  />
                  <button type="button" onClick={agregarDireccion} disabled={!(direccionTitular && (direccionTitular !== 'Otro' || direccionTitularOtro.trim()) && direccionValor.trim())} className={BTN_GRAY}>
                    <Plus size={16} /> Agregar
                  </button>
                </div>

                <ChipsRow items={dirItems} onDelete={eliminarDireccion} maxW={260} />
              </div>

              {/* Teléfonos */}
              <div className="alumno-modal-card">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-gray">Teléfonos</h3>
                <div className="alumno-modal-row-md">
                  <div className="alumno-modal-col-md-25">
                    <SimpleDropdown placeholder="Titular" options={TITULAR_OPTS} value={telefonoTitular} onChange={setTelefonoTitular} />
                  </div>
                  {telefonoTitular === 'Otro' && (
                    <input
                      type="text"
                      placeholder="Especificar titular"
                      value={telefonoTitularOtro}
                      onChange={(e) => setTelefonoTitularOtro(e.target.value)}
                      className="alumno-modal-input alumno-modal-input-plain alumno-modal-input-md-25"
                    />
                  )}
                  <input
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    placeholder="Núm. de teléfono (solo num.)"
                    value={telefonoValor}
                    onChange={(e) => setTelefonoValor(e.target.value.replace(/\D/g, ''))}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); const titularBase = telefonoTitular; const titular = titularBase === 'Otro' ? (telefonoTitularOtro.trim() || '') : titularBase; const telefono = telefonoValor.trim(); if (titular && telefono) agregarTelefono(); } }}
                    className="alumno-modal-input alumno-modal-input-plain alumno-modal-input-md-flex"
                  />
                  <button type="button" onClick={agregarTelefono} disabled={!(telefonoTitular && (telefonoTitular !== 'Otro' || telefonoTitularOtro.trim()) && telefonoValor.trim())} className={BTN_GRAY}>
                    <Plus size={16} /> Agregar
                  </button>
                </div>

                <ChipsRow items={telItems} onDelete={eliminarTelefono} />
              </div>

              {/* Responsables / Tutores */}
              <div className="alumno-modal-card">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-gray">Responsables / Tutores</h3>
                <div className="alumno-modal-grid alumno-modal-grid-2-3">
                  <input
                    type="text"
                    placeholder="Nombre"
                    value={respNombre}
                    onChange={(e) => setRespNombre(e.target.value)}
                    className="alumno-modal-input alumno-modal-input-plain"
                  />
                  <input
                    type="text"
                    placeholder="Apellido"
                    value={respApellido}
                    onChange={(e) => setRespApellido(e.target.value)}
                    className="alumno-modal-input alumno-modal-input-plain"
                  />

                  <div className="alumno-modal-col-span-2 alumno-modal-grid alumno-modal-grid-2-3">
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
                        className="alumno-modal-input alumno-modal-input-plain"
                      />
                    )}
                  </div>

                  <div className="alumno-modal-col-span-2 alumno-modal-grid alumno-modal-grid-sm2-3">
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

                  <div className="alumno-modal-col-span-2">
                    <button type="button" onClick={agregarResponsable} disabled={!canAddResp} className={BTN_GRAY}>
                      <Plus size={16} /> Agregar responsable
                    </button>
                  </div>
                </div>

                <ChipsRow items={respItems} onDelete={eliminarResponsable} maxW={340} />
              </div>

              {/* AE / PPI */}
              <div className="alumno-modal-card">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-gray">Apoyos</h3>
                <div className="alumno-modal-grid alumno-modal-grid-sm2-3">
                  <MiniSwitch
                    label="Proyecto Pedagógico de Inclusión (P.P.I.)"
                    checked={!!alumno.tiene_ppi}
                    onChange={(v) => setAlumno({ ...alumno, tiene_ppi: v })}
                    hint="Indica si cuenta con PPI."
                  />
                  <MiniSwitch
                    label="Acompañante Externo (A.E.)"
                    checked={!!alumno.tiene_ae}
                    onChange={(v) => setAlumno({ ...alumno, tiene_ae: v })}
                    hint="Indica si tiene acompañante externo."
                  />
                </div>
              </div>

              {/* Observaciones */}
              <div className="alumno-modal-card">
                <h3 className="alumno-modal-card-title alumno-modal-card-title-gray">Observaciones</h3>
                <textarea
                  rows={4}
                  placeholder="Info. adicional del alumno"
                  value={alumno.observaciones || ''}
                  onChange={(e) => setAlumno({ ...alumno, observaciones: e.target.value })}
                  className="alumno-modal-textarea"
                />
              </div>
              </>
              )}
            </div>

            {/* Avisos en criollo: qué falta para poder seguir */}
            {avisosPaso.length > 0 && (
              <div className="alumno-modal-avisos" role="alert">
                Te falta completar {avisosPaso.join(', ')}.
              </div>
            )}

            {/* Footer del asistente */}
            <div className="alumno-modal-footer">
              {paso > 1 && (
                <button
                  type="button"
                  onClick={atras}
                  className="alumno-modal-back"
                >
                  Atrás
                </button>
              )}
              {paso < 3 && (
                <button
                  type="button"
                  onClick={continuar}
                  className="alumno-modal-save"
                >
                  Continuar
                </button>
              )}
              {paso === 3 && (
                <button
                  onClick={handleGuardar}
                  disabled={guardandoModal}
                  className="alumno-modal-save"
                >
                  {guardandoModal ? 'Inscribiendo…' : 'Inscribir alumno'}
                </button>
              )}
            </div>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
    {avisoModal}
    </>
  );
}
