// ===============================
// FILE: src/components/Curso/EditarDivisionesModal.jsx
// ===============================
import React, { useEffect, useRef, useState, useMemo } from "react";
import { X } from "lucide-react";
import useCierreAnimado from "../../compartido/lib/useCierreAnimado";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { cursoLabel } from "../../compartido/lib/cursos";
import "./EditarDivisionesModal.css";

// Componente: EditarDivisionesModal
// Proposito: Modal que permite editar las divisiones (subdivisiones) de cada anio escolar.
//            Los años 1 a 7 existen siempre: se muestran todos y ninguno puede
//            quedar sin divisiones.
// Props:
//   visible  - Booleano que controla la visibilidad del modal.
//   onClose  - Funcion para cerrar el modal.
//   value    - Objeto con la configuracion actual de divisiones por anio.
//   onSave   - Funcion asincrona que recibe el nuevo objeto de divisiones para persistirlo.

const letras = ["1", "2", "3", "4", "5", "6", "7", "8", "9"];

const DEFAULTS = {
  1: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  2: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  3: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  4: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  5: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  6: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
  7: ["1", "2", "3", "4", "5", "6", "7", "8", "9"],
};

// Comparacion simple de configuracion para detectar cambios sin guardar
const sameConfig = (a, b) => JSON.stringify(a ?? {}) === JSON.stringify(b ?? {});

export default function EditarDivisionesModal({
  visible,
  onClose,
  value,   // { 1: ["1","2",...], 2: [...], 7:[...], 8:[...]? }
  onSave,  // (nuevoObjeto) => Promise<void> | void
}) {
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  // Construye el estado local mezclando defaults (1..7) + lo que venga del back (incluye 8 en adelante si existen)
  const initialLocal = useMemo(() => {
    const src = value && typeof value === "object" ? value : {};
    // Normaliza claves a number y arrays de strings
    const norm = Object.fromEntries(
      Object.entries(src).map(([k, v]) => [Number(k), Array.isArray(v) ? v : []])
    );
    // Mezcla defaults 1..6 con lo que vino del backend
    return { ...DEFAULTS, ...norm };
  }, [value]);

  const [local, setLocal] = useState(initialLocal);
  const [saving, setSaving] = useState(false);
  const { montado, cerrando } = useCierreAnimado(visible);
  const originalRef = useRef(initialLocal);

  // Los anos a editar: 1..7 siempre, mas cualquier anio mayor que exista en "value".
  const years = useMemo(() => {
    const extra = Object.keys(initialLocal)
      .map((k) => Number(k))
      .filter((n) => Number.isFinite(n) && n > 7)
      .sort((a, b) => a - b);
    return [1, 2, 3, 4, 5, 6, 7, ...extra];
  }, [initialLocal]);

  // Reinicia el estado local cada vez que se abre el modal
  useEffect(() => {
    if (visible) {
      setLocal(initialLocal);
      originalRef.current = initialLocal;
    }
  }, [visible, initialLocal]);

  // Cierra el modal al presionar la tecla Escape
  useEffect(() => {
    const onEsc = async (e) => {
      if (e.key === "Escape") await handleRequestClose();
    };
    document.addEventListener("keydown", onEsc);
    return () => document.removeEventListener("keydown", onEsc);
  }, []);

  // Verifica si existen cambios sin guardar comparando estado local con el original
  const hasUnsaved = () => !sameConfig(local, originalRef.current);

  // Muestra dialogo de confirmacion para descartar cambios
  const askDiscard = async () => {
    const ok = await pedirConfirmacion({
      title: "Descartar cambios",
      text: "Tenés cambios sin guardar. Si cerrás ahora, se perderán.",
      icon: "warning",
      confirmText: "Descartar",
      cancelText: "Seguir editando",
    });
    return ok;
  };

  // Maneja el cierre del modal verificando cambios sin guardar
  const handleRequestClose = async () => {
    if (saving) return; // no cerrar mientras guarda
    if (hasUnsaved()) {
      const ok = await askDiscard();
      if (!ok) return;
    }
    onClose?.();
  };

  // Agrega o quita una letra/division de un anio especifico.
  // Los años 1 a 7 existen siempre: no se puede dejar uno sin divisiones.
  const toggleLetra = (anio, letra) => {
    let bloqueado = false;
    setLocal((prev) => {
      const arr = Array.isArray(prev[anio]) ? [...prev[anio]] : [];
      const set = new Set(arr);
      if (set.has(letra)) {
        if (set.size <= 1 && anio >= 1 && anio <= 7) {
          bloqueado = true;
          return prev;
        }
        set.delete(letra);
      } else {
        set.add(letra);
      }
      return { ...prev, [anio]: Array.from(set).sort() };
    });
    if (bloqueado) {
      mostrarAviso({
        title: 'No se puede quitar',
        text: `El año ${cursoLabel({ anio, division: null })} tiene que tener al menos una división.`,
        icon: 'warning',
      });
    }
  };

  // Restablece las divisiones de 1 a 7 a sus valores por defecto (1-9)
  const restablecer = async () => {
    const ok = await pedirConfirmacion({
      title: "Restablecer por defecto",
      text: "Se restaurarán las divisiones por defecto de 1 a 7 (1-9). Los demas anos (8 en adelante) se mantienen como estan.",
      icon: "question",
      confirmText: "Restablecer",
      cancelText: "Cancelar",
    });
    if (ok) {
      setLocal((prev) => {
        // Mantiene 8 en adelante, solo sobreescribe 1..7
        const out = { ...prev };
        for (let anio = 1; anio <= 7; anio++) out[anio] = DEFAULTS[anio];
        return out;
      });
    }
  };

  // Guarda los cambios llamando a onSave y muestra resultado.
  // Por las dudas, antes de enviar se verifica que los años 1 a 7 no queden
  // vacios (el servidor tambien lo exige).
  const handleSave = async () => {
    try {
      setSaving(true);

      // Normaliza antes de enviar (quita undefined, asegura arrays de strings)
      const payload = {};
      years.forEach((anio) => {
        const arr = Array.isArray(local[anio]) ? local[anio] : [];
        payload[anio] = arr.map(String);
      });

      const vacio = [1, 2, 3, 4, 5, 6, 7].find((a) => !(payload[a] || []).length);
      if (vacio) {
        await mostrarAviso({
          title: 'Falta un año',
          text: `El año ${cursoLabel({ anio: vacio, division: null })} tiene que tener al menos una división.`,
          icon: 'warning',
        });
        return;
      }

      // El padre ejecuta el PUT /api/divisiones y actualiza el estado global
      await onSave?.(payload);

      onClose?.();
    } catch (err) {
      console.error(err);
      await mostrarAviso({
        title: "No se pudo guardar",
        text: "Ocurrió un problema al guardar las divisiones. Intentaá nuevamente.",
        icon: "error",
      });
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      {montado && (
        <div className={`modal-ediv-overlay sys-capa${cerrando ? " sys-cerrando" : ""}`}>
          {/* Modal */}
          <div
            className={`modal-ediv-dialog sys-dialogo${cerrando ? " sys-cerrando" : ""}`}
            role="dialog"
            aria-modal="true"
          >
            {/* Header */}
            <div className="modal-ediv-header">
              <h2 className="modal-ediv-title">
                Editar divisiones por año
              </h2>
              <button
                onClick={handleRequestClose}
                className="modal-ediv-close"
                aria-label="Cerrar"
                disabled={saving}
              >
                <X size={18} />
              </button>
            </div>

            {/* Contenido */}
            <div className="modal-ediv-content">
              {/* Grid responsive: 3 columnas en pantallas grandes si hay mas de 6 anos */}
              <div className={`modal-ediv-grid ${years.length > 6 ? "modal-ediv-grid--3col" : ""}`}>
                {years.map((anio) => (
                  <section
                    key={anio}
                    className="modal-ediv-card"
                  >
                    <div className="modal-ediv-card-header">
                      <div className="modal-ediv-card-title">
                        {cursoLabel({ anio, division: null })} Año
                      </div>
                      <div className="modal-ediv-card-count">
                        Seleccionadas: {(local[anio] || []).join(", ") || "—"}
                      </div>
                    </div>

                    <div className="modal-ediv-divisions">
                      {letras.map((letra) => {
                        const checked = (local[anio] || []).includes(letra);
                        return (
                          <label
                            key={`${anio}-${letra}`}
                            className={`modal-ediv-chip ${checked ? "modal-ediv-chip--on" : "modal-ediv-chip--off"}`}
                            title={`División ${letra}`}
                          >
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => toggleLetra(anio, letra)}
                              className="modal-ediv-hidden-input"
                              disabled={saving}
                            />
                            {letra}
                          </label>
                        );
                      })}
                    </div>

                    {/* Los 7 años existen siempre: no se pueden ocultar. */}
                    <p className="modal-ediv-tip">
                      Cada año tiene que tener al menos una división marcada.
                    </p>
                  </section>
                ))}
              </div>
            </div>

            {/* Footer */}
            <div className="modal-ediv-footer">
              <button
                onClick={restablecer}
                className="modal-ediv-btn-reset"
                disabled={saving}
              >
                Restablecer por defecto ({[1,2,3,4,5,6,7].map(n => cursoLabel({ anio: n, division: null })).join("–")})
              </button>

              <div className="modal-ediv-footer-actions">
                <button
                  onClick={handleSave}
                  className="modal-ediv-btn-save"
                  disabled={saving}
                >
                  Guardar cambios
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
      {avisoModal}
    </>
  );
}

