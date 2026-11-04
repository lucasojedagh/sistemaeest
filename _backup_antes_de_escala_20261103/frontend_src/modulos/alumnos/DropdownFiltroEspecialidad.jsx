// ===============================
// FILE: src/components/Alumnos/DropdownFiltroEspecialidad.jsx
// ===============================
import React, { useState, useRef, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { ESPECIALIDADES } from '../../compartido/lib/cursos';
import "./DropdownFiltroEspecialidad.css";

/*
 * Componente: DropdownFiltroEspecialidad
 * Props:
 *   value     - Valor seleccionado ("todos" o la clave de especialidad)
 *   onChange  - Callback al seleccionar una especialidad
 *
 * Dropdown para filtrar alumnos por especialidad técnica (solo 4to año en adelante).
 */

const DropdownFiltroEspecialidad = ({ value, onChange, className = '' }) => {
  const [open, setOpen] = useState(false);
  const ref = useRef();

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (ref.current && !ref.current.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const currentLabel =
    value === 'todos'
      ? 'Todas las especialidades'
      : (ESPECIALIDADES.find((e) => e.key === value)?.label || 'Especialidad');

  const opciones = ESPECIALIDADES.map((e) => ({ value: e.key, label: e.label }));

  return (
    <div className={`dropdown-esp-wrap ${className}`} ref={ref}>
      <button
        type="button"
        className={`dropdown-esp-trigger ${open ? 'dropdown-esp-trigger-open' : ''}`}
        onClick={() => setOpen((o) => !o)}
      >
        {currentLabel}
        <motion.span
          className="dropdown-esp-chevron"
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

      <AnimatePresence>
        {open && (
          <motion.div
            initial={{ y: -10 }}
            animate={{ y: 0 }}
            exit={{ opacity: 0, y: -10 }}
            transition={{ duration: 0.2 }}
            className="dropdown-esp-panel"
          >
            <div>
              <div
                className={`dropdown-esp-option dropdown-esp-option-first ${value === 'todos' ? 'dropdown-esp-option-active' : ''}`}
                onClick={() => { onChange('todos'); setOpen(false); }}
              >
                Todas las especialidades
              </div>

              {opciones.map((opt) => (
                <div
                  key={opt.value}
                  className={`dropdown-esp-option ${value === opt.value ? 'dropdown-esp-option-active' : ''}`}
                  onClick={() => { onChange(opt.value); setOpen(false); }}
                >
                  {opt.label}
                </div>
              ))}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
};

export default DropdownFiltroEspecialidad;
