// ===============================
// FILE: src/components/Alumnos/DropdownFiltroEstado.jsx
// ===============================
import React, { useState, useRef, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import "./DropdownFiltroEstado.css";

/**
 * Dropdown simple para filtrar por estado del usuario (Activos / Licencia).
 * Props:
 *   value     - 'todos' | 'activo' | 'licencia'
 *   onChange  - Callback al seleccionar
 *   className - Clases CSS adicionales
 */

const OPTIONS = [
  { value: 'todos',    label: 'Todos' },
  { value: 'activo',   label: 'Activos' },
  { value: 'licencia', label: 'Licencia' },
];

const DropdownFiltroEstado = ({ value, onChange, className = '' }) => {
  const [open, setOpen] = useState(false);
  const ref = useRef();

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (ref.current && !ref.current.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const currentLabel = OPTIONS.find(o => o.value === value)?.label || 'Todos';

  return (
    <div className={`dropdown-estado-prof-wrap ${className}`} ref={ref}>
      <button
        type="button"
        className={`dropdown-estado-prof-trigger ${open ? 'dropdown-estado-prof-trigger-open' : ''}`}
        onClick={() => setOpen((o) => !o)}
      >
        {currentLabel}
        <motion.span
          className="dropdown-estado-prof-chevron"
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
            className="dropdown-estado-prof-panel"
          >
            <div>
              {OPTIONS.map((opt, i) => (
                <div
                  key={opt.value}
                  className={`dropdown-estado-prof-option ${i === 0 ? 'dropdown-estado-prof-option-first' : ''} ${value === opt.value ? 'dropdown-estado-prof-option-active' : ''}`}
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

export default DropdownFiltroEstado;
