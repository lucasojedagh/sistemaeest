// ===============================
// FILE: src/components/Alumnos/DropdownFiltroGenero.jsx
// ===============================
import React, { useState, useRef, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import "./DropdownFiltroGenero.css";

/*
 * Componente: DropdownFiltroGenero
 * Props:
 *   value     - Valor seleccionado ("todos", "F", "M" u "O")
 *   onChange  - Callback al seleccionar un genero
 *   opciones  - Array de opciones disponibles por defecto [{ value: 'F' }, { value: 'M' }, { value: 'O' }]
 *   className - Clases CSS adicionales
 *
 * Dropdown simple para filtrar alumnos por genero (Femenino / Masculino / Otro).
 */

const labelFromValue = (v) => {
  switch (v) {
    case 'F': return 'Femenino';
    case 'M': return 'Masculino';
    case 'O': return 'Otro';
    default:  return v;
  }
};

/**
 * Props:
 *  - value: 'todos' | 'F' | 'M' | 'O'
 *  - onChange: (nuevoValor) => void
 *  - opciones: Array<{ value: string, label?: string }>
 */

const DropdownFiltroGenero = ({
  value,
  onChange,
  opciones = [{ value: 'F' }, { value: 'M' }, { value: 'O' }],
  className = ''
}) => {
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
      ? 'Todos los géneros'
      : (opciones.find(o => o.value === value)?.label || labelFromValue(value) || 'Seleccionar género');

  return (
    <div className={`dropdown-genero-wrap ${className}`} ref={ref}>
      <button
        type="button"
        className={`dropdown-genero-trigger ${open ? 'dropdown-genero-trigger-open' : ''}`}
        onClick={() => setOpen((o) => !o)}
      >
        {currentLabel}
        <motion.span
          className="dropdown-genero-chevron"
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
            className="dropdown-genero-panel"
          >
            <div>
              <div
                className={`dropdown-genero-option dropdown-genero-option-first ${value === 'todos' ? 'dropdown-genero-option-active' : ''}`}
                onClick={() => { onChange('todos'); setOpen(false); }}
              >
                Todos los géneros
              </div>

              {opciones.map(opt => {
                const label = opt.label || labelFromValue(opt.value);
                return (
                  <div
                    key={opt.value}
                    className={`dropdown-genero-option ${value === opt.value ? 'dropdown-genero-option-active' : ''}`}
                    onClick={() => { onChange(opt.value); setOpen(false); }}
                  >
                    {label}
                  </div>
                );
              })}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
};

export default DropdownFiltroGenero;

