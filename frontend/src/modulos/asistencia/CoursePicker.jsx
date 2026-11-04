import { useEffect, useMemo, useRef, useState } from "react";
import { Check } from "lucide-react";
import { cursoLabel, divisionToDato, anioToOrdinal } from "../../compartido/lib/cursos";
import "./CoursePicker.css";

// Etiqueta visible de un curso. Usa el mismo formato que Calificaciones
// (ordinales: "1ro 1ra") y cae al valor crudo si la división no es numérica.
const labelCurso = (anio, division) => {
  const aOrd = anioToOrdinal(Number(anio));
  if (!division) return aOrd;
  if (division === "*") return `${aOrd} · Todas las divisiones`;
  const dNorm = divisionToDato(division);
  return dNorm ? cursoLabel({ anio, division: dNorm }) : `${aOrd} ${division}`;
};

export default function CoursePicker({ maps, value, onChange, disabled = false, allowEmpty = false }) {
  const [open, setOpen] = useState(false);
  const btnRef = useRef(null);
  const menuRef = useRef(null);

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

  // Cursos ordenados por año y división, agrupados por año.
  const grupos = useMemo(() => {
    const years = Object.keys(maps || {})
      .map(Number)
      .filter(Number.isFinite)
      .sort((a, b) => a - b);
    return years.map((anio) => ({
      anio,
      label: anioToOrdinal(anio),
      divisiones: (maps[anio] || []).map(String).sort(),
    }));
  }, [maps]);

  const isSelected = (anio, division) =>
    String(value?.anio ?? "") === String(anio) && String(value?.division ?? "") === String(division);

  const select = (anio, division) => {
    onChange({ anio: String(anio), division });
    setOpen(false);
  };

  const selectedLabel = useMemo(() => {
    if (value?.anio) return labelCurso(value.anio, value.division);
    return "Seleccionar curso";
  }, [value]);

  return (
    <div className="picker-wrap">
      <button
        ref={btnRef}
        type="button"
        onClick={() => !disabled && setOpen((o) => !o)}
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        className={`picker-btn ${disabled ? "picker-btn-disabled" : "picker-btn-enabled"} ${open ? "picker-btn-open" : ""}`}
      >
        <span className="picker-btn-text">{selectedLabel}</span>
        <span className={open ? "picker-chevron open" : "picker-chevron"}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="6 9 12 15 18 9" />
          </svg>
        </span>
      </button>

      <div
        ref={menuRef}
        className={`picker-menu ${open && !disabled ? "picker-menu-open" : ""}`}
        role="listbox"
        aria-hidden={!open || disabled}
      >
            <div className="picker-menu-scroll">
              {allowEmpty && (
                <button
                  type="button"
                  onClick={() => { onChange({ anio: "", division: "" }); setOpen(false); }}
                  className={`picker-option-all ${
                    !value?.anio ? "picker-option-all-selected" : "picker-option-all-default"
                  }`}
                >
                  Todos los cursos
                </button>
              )}

              {grupos.map(({ anio, label, divisiones }) => (
                <div key={anio} className="picker-group">
                  <div className="picker-group-label">
                    {label} Año
                  </div>
                  {divisiones.length === 0 && (
                    <div className="picker-group-empty">Sin divisiones cargadas</div>
                  )}
                  {divisiones.map((d) => {
                    const sel = isSelected(anio, d);
                    return (
                      <button
                        key={d}
                        type="button"
                        onClick={() => select(anio, d)}
                        className={`picker-option ${
                          sel ? "picker-option-selected" : "picker-option-default"
                        }`}
                      >
                        <span className="picker-option-text">{labelCurso(anio, d)}</span>
                        {sel && <Check className="picker-check" />}
                      </button>
                    );
                  })}
                </div>
              ))}

              {grupos.length === 0 && (
                <div className="picker-empty">No hay cursos disponibles.</div>
              )}
            </div>
      </div>
    </div>
  );
}