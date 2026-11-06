// ===============================
// FILE: src/components/Alumnos/DropdownFiltroRol.jsx
// ===============================
import React, { useState, useRef, useEffect } from 'react';
import { motion } from 'framer-motion';
import useCierreAnimado from "../../compartido/lib/useCierreAnimado";
import "./DropdownFiltroRol.css";

/**
 * Dropdown simple para filtrar por rol (Titulares / Suplentes).
 * Props:
 *   value     - 'todos' | 'titular' | 'suplente'
 *   onChange  - Callback al seleccionar
 *   className - Clases CSS adicionales
 */

const OPTIONS = [
  { value: 'todos',    label: 'Todos' },
  { value: 'titular',  label: 'Titulares' },
  { value: 'suplente', label: 'Suplentes' },
];

const DropdownFiltroRol = ({ value, onChange, className = '' }) => {
  const [open, setOpen] = useState(false);
  const { montado, cerrando } = useCierreAnimado(open, 150);
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
    <div className={`dropdown-rol-wrap ${className}`} ref={ref}>
      <button
        type="button"
        className={`dropdown-rol-trigger ${open ? 'dropdown-rol-trigger-open' : ''}`}
        onClick={() => setOpen((o) => !o)}
      >
        {currentLabel}
        <motion.span
          className="dropdown-rol-chevron"
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
            className={`dropdown-rol-panel sys-desplegable${cerrando ? " sys-cerrando" : ""}`}
          >
            <div>
              {OPTIONS.map((opt, i) => (
                <div
                  key={opt.value}
                  className={`dropdown-rol-option ${i === 0 ? 'dropdown-rol-option-first' : ''} ${value === opt.value ? 'dropdown-rol-option-active' : ''}`}
                  onClick={() => { onChange(opt.value); setOpen(false); }}
                >
                  {opt.label}
                </div>
              ))}
            </div>
          </div>
        )}
      
    </div>
  );
};

export default DropdownFiltroRol;
