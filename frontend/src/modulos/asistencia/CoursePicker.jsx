import { useEffect, useMemo, useRef, useState } from "react";
import { Check } from "lucide-react";
import { cursoLabel, divisionToDato, anioToOrdinal } from "../../compartido/lib/cursos";
import { esCursoEditable } from "../../compartido/lib/asistencia";
import { useAuth } from "../auth/AuthContext";
import { alcanceVer, alcanceEditar, veTodo } from "../../compartido/lib/permisos";
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

export default function CoursePicker({ maps, value, onChange, disabled = false, allowEmpty = false, editables = null }) {  const { user } = useAuth();
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

  // Cursos donde esta cuenta puede marcar. El backend manda la lista solo cuando
  // el alcance de escritura es "los asignados"; sin lista significa todos.
  const listaEditable = Array.isArray(editables) ? editables : null;
  const esEditable = (anio, division) => esCursoEditable(listaEditable, anio, division);
  const ningunoEditable = listaEditable !== null && listaEditable.length === 0;

  // Por qué está vacío. Sin este detalle una cuenta sin asignaciones leía
  // "no hay cursos disponibles" y no tenía forma de saber que el agujero era
  // suyo: el selector se arma con sus cursos, no con los del colegio.
  const mensajeVacio = useMemo(() => {
    if (alcanceVer(user, "asistencia") === "ninguno") {
      return "Tu rol no tiene acceso a la asistencia.";
    }
    if (!veTodo(user, "asistencia")) {
      return alcanceEditar(user, "asistencia") === "ninguno"
        ? "Tu rol no toma asistencia. La dirección puede habilitarlo en Usuarios › Roles y permisos."
        : "Tu cuenta no tiene ningún curso asignado, así que la lista sale vacía. Pedí a la dirección que te asigne año y división (Usuarios › Asignaciones) o que cargue tus cursos en tu ficha de profesor.";
    }
    return "Todavía no hay cursos activos con alumnos dados de alta.";
  }, [user]);

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
              {ningunoEditable && (
                <div className="picker-nota">
                  Tu cuenta no tiene ningún curso asignado: podés ver la asistencia de
                  todos los cursos pero no marcar en ninguno. Pedí a la dirección que te
                  asigne año y división en Usuarios.
                </div>
              )}

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
                    const puedeMarcar = esEditable(anio, d);
                    return (
                      <button
                        key={d}
                        type="button"
                        onClick={() => select(anio, d)}
                        disabled={!puedeMarcar}
                        title={puedeMarcar ? undefined : "Tu cuenta no tiene asignado este curso: no podés marcar su asistencia."}
                        className={`picker-option ${
                          sel ? "picker-option-selected" : "picker-option-default"
                        } ${puedeMarcar ? "" : "picker-option-bloqueado"}`}
                      >
                        <span className="picker-option-text">{labelCurso(anio, d)}</span>
                        {sel && <Check className="picker-check" />}
                      </button>
                    );
                  })}
                </div>
              ))}

              {grupos.length === 0 && (
                <div className="picker-empty">{mensajeVacio}</div>
              )}
            </div>
      </div>
    </div>
  );
}