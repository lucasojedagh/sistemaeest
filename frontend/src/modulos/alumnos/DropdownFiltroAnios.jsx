// =============================== 
// FILE: src/components/Alumnos/DropdownFiltroAnios.jsx
// ===============================
import React, { useState, useRef, useEffect, useMemo } from 'react';
import { motion } from 'framer-motion';
import { cargarDivisiones } from "../../compartido/lib/divisiones";
import useCierreAnimado from "../../compartido/lib/useCierreAnimado";
import { cursoLabel, parseCurso } from '../../compartido/lib/cursos';
import "./DropdownFiltroAnios.css";

/*
 * Componente: DropdownFiltroAnios
 * Props:
 *   value            - Valor seleccionado ("todos", "N°" o "N° X")
 *   onChange         - Callback al seleccionar un filtro
 *   cursosDisponibles - Array opcional de cursos (si no se envia, se carga del backend)
 *   className        - Clases CSS adicionales
 *
 * Dropdown para filtrar por ano y/o division. Soporta seleccion de
 * anos completos o cursos especificos (ano + division). Los datos se
 * cargan desde /api/divisiones con fallback local.
 */

/* ===== Normalización de tokens =====
   Convierte lo que sea al label ordinal del módulo central.
   - Año:  "1ro"
   - Curso:"1ro 1ra"
 */
const normalizeToken = (raw) => cursoLabel(raw);

// Acepta cualquier año solo (sin división)
const isYearOnly = (token) => {
  const p = parseCurso(token);
  return !!p.anio && !p.division;
};

const tokenToLabel = (token) => {
  // Para mostrar en el botón
  if (!token || token === 'todos') return 'Todos los años';
  const t = normalizeToken(token);
  return isYearOnly(t) ? `${t} Año` : t;
};

/* Fallback local por si la API falla */
const FALLBACK = [
  { anio: '1ro', divisiones: ['1ra','2da','3ra','4ta','5ta','6ta','7ma'] },
  { anio: '2do', divisiones: ['1ra','2da','3ra','4ta','5ta','6ta','7ma'] },
  { anio: '3ro', divisiones: ['1ra','2da','3ra','4ta','5ta','6ta','7ma'] },
  { anio: '4to', divisiones: ['1ra','2da','3ra','4ta','5ta','6ta','7ma'] },
  { anio: '5to', divisiones: ['1ra','2da','3ra','4ta','5ta','6ta','7ma'] },
  { anio: '6to', divisiones: ['1ra','2da','3ra','4ta','5ta','6ta','7ma'] },
];

/* ⬇️ NUEVO: construye la lista a partir de TODOS los años que devuelva la API */
const buildCursos = (mapa /* { "1":[...], "7":[...], "8":[...], ... } */) => {
  const entries = Object.entries(mapa || {});
  const list = entries.map(([anioKey, divisiones]) => ({
    anio: cursoLabel({ anio: Number(anioKey), division: null }),
    divisiones: (Array.isArray(divisiones) ? divisiones : [])
      .filter(Boolean)
      .map((d) => cursoLabel({ anio: Number(anioKey), division: String(d).trim() }).split(' ')[1]),
  }));
  // ordena por año ascendente
  list.sort((a, b) => (parseCurso(a.anio).anio - parseCurso(b.anio).anio));
  return list;
};

const DropdownFiltroAnios = ({
  value,
  onChange,
  cursosDisponibles,   // opcional: si viene, se usa; si no, se consulta al backend
  className = ''
}) => {
  const [open, setOpen] = useState(false);
  const { montado, cerrando } = useCierreAnimado(open, 150);
  const ref = useRef(null);

  // Si no recibimos cursos por props, cargamos desde /api/divisiones
  const [cursosRemotos, setCursosRemotos] = useState(FALLBACK);

  useEffect(() => {
    if (cursosDisponibles && Array.isArray(cursosDisponibles)) return; // usar props
    (async () => {
      try {
        const mapa = await cargarDivisiones(); // { "1":[...], "7":[...], ... }
        setCursosRemotos(buildCursos(mapa));
      } catch (e) {
        console.warn("No se pudieron cargar divisiones (usando fallback).", e);
        setCursosRemotos(FALLBACK);
      }
    })();
  }, [cursosDisponibles]);

  // Fuente de datos final
  const data = (cursosDisponibles && Array.isArray(cursosDisponibles))
    ? cursosDisponibles
    : cursosRemotos;

  // Normalizamos el value que viene de arriba para comparar/mostrar
  const normalizedValue = useMemo(() => {
    if (!value || value === 'todos') return value || 'todos';
    return normalizeToken(value);
  }, [value]);

  // Cerrar el dropdown si se hace click fuera
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (ref.current && !ref.current.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handlePick = (rawToken) => {
    const token = normalizeToken(rawToken);
    // Emite exactamente "N°" o "N° X"
    onChange?.(token);
    setOpen(false);
  };

  return (
    <div className={`dropdown-anios-wrap ${className}`} ref={ref}>
      <button
        type="button"
        className={`dropdown-anios-trigger ${open ? 'dropdown-anios-trigger-open' : ''}`}
        onClick={() => setOpen((o) => !o)}
      >
        {tokenToLabel(normalizedValue)}
        <motion.span
          className="dropdown-anios-chevron"
          animate={{ rotate: open ? 180 : 0 }}
          transition={{ duration: 0.2 }}
        >
          <motion.svg
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M6 9l3 3 3-3" />
          </motion.svg>
        </motion.span>
      </button>

      {montado && (
          <div
            className={`dropdown-anios-panel sys-desplegable${cerrando ? " sys-cerrando" : ""}`}
          >
            <div>
              {/* Opción para todos */}
              <div
                className={`dropdown-anios-option dropdown-anios-option-first ${normalizedValue === 'todos' ? 'dropdown-anios-option-active' : ''}`}
                onClick={() => { onChange?.('todos'); setOpen(false); }}
              >
                Todos los años
              </div>

              {/* Años y divisiones */}
              {data.map((grupo) => {
                const yearToken = normalizeToken(grupo.anio); // "N°"
                const isYearActive = normalizedValue === yearToken;

                return (
                  <div key={yearToken}>
                    {/* Año completo */}
                    <div
                      className={`dropdown-anios-option dropdown-anios-option-year ${isYearActive ? 'dropdown-anios-option-active' : ''}`}
                      onClick={() => handlePick(yearToken)}
                    >
                      {yearToken} Año
                    </div>

                    {/* Divisiones */}
                    {(grupo.divisiones || []).map((divRaw) => {
                      const cursoToken = normalizeToken(`${yearToken} ${divRaw}`);
                      const isActive = normalizedValue === cursoToken;
                      return (
                        <div
                          key={cursoToken}
                          className={`dropdown-anios-option dropdown-anios-option-div ${isActive ? 'dropdown-anios-option-active' : ''}`}
                          onClick={() => handlePick(cursoToken)}
                        >
                          {cursoToken}
                        </div>
                      );
                    })}
                  </div>
                );
              })}
            </div>
          </div>
        )}
      
    </div>
  );
};

export default DropdownFiltroAnios;
