// ===============================
// FILE: src/components/Alumnos/CursoDropdown.jsx
// ===============================
import React, { useState, useRef, useEffect } from 'react';
import { cargarDivisiones } from "../../compartido/lib/divisiones";
import { cursoLabel, buildCursosDisponiblesFromMap } from '../../compartido/lib/cursos';
import "./CursoDropdown.css";

/*
 * Componente: CursoDropdown
 * Props:
 *   value    - Valor seleccionado (ej. "3° E")
 *   onChange - Callback al seleccionar un curso
 *   className - Clases CSS adicionales
 *   error    - Booleano para marcar error de validacion
 *
 * Dropdown para seleccion de curso (anio y division) obtenido desde
 * el backend. Muestra los anos agrupados con sus divisiones disponibles.
 * Incluye fallback local en caso de que la API no responda.
 */

/* 🔧 Fallback local (por si la API falla): 1ro–6to con divisiones A–G */
const FALLBACK_CURSOS = [
  { anio: '1ro', divisiones: ['1ra', '2da', '3ra', '4ta', '5ta', '6ta', '7ma'] },
  { anio: '2do', divisiones: ['1ra', '2da', '3ra', '4ta', '5ta', '6ta', '7ma'] },
  { anio: '3ro', divisiones: ['1ra', '2da', '3ra', '4ta', '5ta', '6ta', '7ma'] },
  { anio: '4to', divisiones: ['1ra', '2da', '3ra', '4ta', '5ta', '6ta', '7ma'] },
  { anio: '5to', divisiones: ['1ra', '2da', '3ra', '4ta', '5ta', '6ta', '7ma'] },
  { anio: '6to', divisiones: ['1ra', '2da', '3ra', '4ta', '5ta', '6ta', '7ma'] },
];

/* Construye grupos a partir del mapa de /divisiones.
   Incluye TODOS los años con divisiones activas (p.ej. 7, 8). */
const buildCursosDisponibles = (mapa /* { "1":["1","2"], ... } */) => {
  const lista = buildCursosDisponiblesFromMap(mapa);
  if (!lista.length) return FALLBACK_CURSOS;
  return lista.map(c => ({
    anio: c.anio,
    divisiones: c.divisiones,
  }));
};

/* ✅ Normaliza siempre al label ordinal (ej: "3ro 1ra") */
const sanitizeCurso = (val) => cursoLabel(val);

const CursoDropdown = ({ value, onChange, className = '', error = false }) => {
  const [open, setOpen] = useState(false);
  const dropdownRef = useRef(null);

  // Cursos dinámicos desde BD (incluye 7°/8° si están activos)
  const [cursosDisponibles, setCursosDisponibles] = useState(FALLBACK_CURSOS);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    (async () => {
      try {
        const divisionesMap = await cargarDivisiones(); // { "1":[...], "7":[...], ... }
        const cursos = buildCursosDisponibles(divisionesMap);
        setCursosDisponibles(cursos);
      } catch (e) {
        // Si falla, nos quedamos con el fallback
        console.warn("No se pudieron cargar las divisiones. Usando fallback local.", e);
        setCursosDisponibles(FALLBACK_CURSOS);
      }
    })();
  }, []);

  const handleSelect = (val) => {
    const limpio = sanitizeCurso(val);
    onChange?.(limpio);
    setOpen(false);
  };

  // Muestra sanitizada (corrige casos como "3° E°")
  const display = sanitizeCurso(value) || 'Seleccionar curso';

  return (
    <div className={`curso-dropdown-wrap ${className}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className={`curso-dropdown-btn ${error ? 'curso-dropdown-btn-error' : ''}`}
      >
        {display}
      </button>

      {open && (
        <div className="curso-dropdown-panel">
          {(cursosDisponibles || []).map((grupo) => (
            <div key={grupo.anio}>
              <div className="curso-dropdown-group-label">
                {grupo.anio} Año
              </div>
              {(grupo.divisiones || []).map((div) => {
                const curso = `${grupo.anio} ${div}`;
                return (
                  <div
                    key={curso}
                    onClick={() => handleSelect(curso)}
                    className="curso-dropdown-option"
                  >
                    {curso}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default CursoDropdown;



