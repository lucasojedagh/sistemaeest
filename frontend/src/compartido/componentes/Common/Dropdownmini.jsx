// ===============================
// FILE: src/components/Common/DropdownMini.jsx
// ===============================
import React, { useEffect, useRef, useState } from "react";
import "./Dropdownmini.css";

/*
 * Componente: DropdownMini
 * Props:
 *   value       - Valor actual seleccionado
 *   onChange    - Callback al seleccionar una opcion
 *   opciones    - Array de objetos { value, label }
 *   placeholder - Texto por defecto cuando no hay seleccion
 *   width       - Ancho del dropdown en pixeles (default 140)
 *   disabled    - Booleano para deshabilitar el dropdown
 *   debounceMs  - Milisegundos de debounce antes de emitir onChange
 *
 * Dropdown compacto y reutilizable con animacion, soporte para
 * debounce y navegacion por teclado.
 */

export default function DropdownMini({
  value,
  onChange,
  opciones = [],
  placeholder = "Seleccionar",
  width = 140,
  disabled = false,
  debounceMs = 0,
}) {
  const [open, setOpen] = useState(false);
  const btnRef = useRef(null);
  const menuRef = useRef(null);
  const debounceRef = useRef(null);

  const current = opciones.find((o) => String(o.value) === String(value));
  const label = current?.label ?? placeholder;

  useEffect(() => {
    const handleClick = (e) => {
      if (!btnRef.current || !menuRef.current) return;
      if (!btnRef.current.contains(e.target) && !menuRef.current.contains(e.target)) setOpen(false);
    };
    const handleEsc = (e) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", handleClick);
    document.addEventListener("keydown", handleEsc);
    return () => {
      document.removeEventListener("mousedown", handleClick);
      document.removeEventListener("keydown", handleEsc);
    };
  }, []);

  useEffect(() => () => { if (debounceRef.current) clearTimeout(debounceRef.current); }, []);

  const fireChange = (v) => {
    if (String(v) === String(value)) return; 
    if (!onChange) return;
    if (debounceMs > 0) {
      if (debounceRef.current) clearTimeout(debounceRef.current);
      debounceRef.current = setTimeout(() => onChange(v), debounceMs);
    } else {
      onChange(v);
    }
  };

  const pick = (v) => {
    fireChange(v);
    setOpen(false);
  };

  return (
    <div className="dropdownmini" style={{ width }}>
      <button
        ref={btnRef}
        type="button"
        onClick={() => !disabled && setOpen((o) => !o)}
        className={`dropdownmini-btn
                    ${disabled ? 'dropdownmini-btn-disabled' : 'dropdownmini-btn-active'}`}
        aria-haspopup="listbox"
        aria-expanded={open}
        disabled={disabled}
      >
        {label}
        <span className={open ? "dropdownmini-chevron open" : "dropdownmini-chevron"}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="6 9 12 15 18 9" />
          </svg>
        </span>
      </button>

      <div
        ref={menuRef}
        className={`dropdownmini-menu ${open && !disabled ? "dropdownmini-menu-open" : ""}`}
        role="listbox"
        aria-hidden={!open || disabled}
      >
            <div className="dropdownmini-menu-scroll">
              {opciones.map((opt) => {
                const active = String(opt.value) === String(value);
                return (
                  <button
                    key={String(opt.value)}
                    type="button"
                    onClick={() => pick(opt.value)}
                    className={`dropdownmini-option
                               ${active ? 'dropdownmini-option-active' : 'dropdownmini-option-idle'}`}
                    role="option"
                    aria-selected={active}
                  >
                    {opt.label ?? opt.value}
                  </button>
                );
              })}
            </div>
      </div>
    </div>
  );
}
