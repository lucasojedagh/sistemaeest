import React, { useEffect, useRef, useState } from "react";
import { AnimatePresence, motion } from "framer-motion";
import {
  X, Info, CircleX, TriangleAlert, CircleCheck, CircleHelp,
  Mail, Lock, Loader2, Eye, EyeOff,
} from "lucide-react";
import "./AvisoModal.css";

const ICONOS = {
  info: Info,
  error: CircleX,
  warning: TriangleAlert,
  success: CircleCheck,
  question: CircleHelp,
};

// Modal genérico de avisos del sistema (reemplaza los Swal.fire sueltos).
// Props:
//   open        - visible o no
//   icon        - info | error | warning | success | question
//   title       - título
//   text        - texto (string)
//   showCancel  - muestra botón Cancelar (modo confirmación)
//   confirmText - texto del botón principal
//   cancelText  - texto del botón cancelar
//   danger      - botón principal en rojo (acciones destructivas)
//   terciarioText - texto de un botón extra (opcional); onTerciario al clic
//   input       - { type: 'text'|'email'|'password', placeholder, initial, validate, errorText }
//                 habilita un campo de entrada; onConfirm(valor) devuelve el valor.
//   onConfirm   - al aceptar (recibe el valor del input si hubo)
//   onCancel    - al cancelar/cerrar
//   onTerciario - al clic en el botón terciario
export default function AvisoModal({
  open,
  icon = "info",
  title = "",
  text = "",
  showCancel = false,
  confirmText = "Entendido",
  cancelText = "Cancelar",
  danger = false,
  terciarioText = "",
  input = null,
  onConfirm,
  onCancel,
  onTerciario,
}) {
  const [valor, setValor] = useState("");
  const [error, setError] = useState("");
  const [visible, setVisible] = useState(false);
  const inputRef = useRef(null);

  // Reinicia el estado del input cada vez que se abre el modal.
  useEffect(() => {
    if (!open) return;
    setValor(input?.initial ?? "");
    setError("");
    setVisible(false);
  }, [open, input?.initial, input]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e) => {
      if (e.key === "Escape") return onCancel?.();
      if (e.key === "Enter" && input) {
        e.preventDefault();
        handleSubmit();
      }
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, onCancel, input, valor]);

  useEffect(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => { document.body.style.overflow = prev; };
  }, [open]);

  useEffect(() => {
    if (open && input) setTimeout(() => inputRef.current?.focus(), 60);
  }, [open, input]);

  const Icono = ICONOS[icon] || Info;

  const handleSubmit = () => {
    if (!input) return onConfirm?.();
    const val = valor.trim();
    if (input.validate && !input.validate(val)) {
      setError(input.errorText || "Valor inválido");
      return;
    }
    onConfirm?.(val);
  };

  const inputIcon =
    input?.type === "password" ? <Lock size={18} /> :
    input?.type === "email" ? <Mail size={18} /> : null;

  return (
    <AnimatePresence>
      {open && (
        <motion.div
          key="aviso-backdrop"
          initial={false}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.15, ease: "easeOut" }}
          className="aviso-backdrop"
          onClick={onCancel}
        >
          <motion.div
            key="aviso-modal"
            initial={false}
            exit={{ opacity: 0, scale: 0.95, y: 10 }}
            transition={{ duration: 0.2, ease: [0.25, 0.46, 0.45, 0.94] }}
            className="aviso-modal"
            role="dialog"
            aria-modal="true"
            onClick={(e) => e.stopPropagation()}
          >
            <div className={`aviso-icono aviso-icono-${icon}`}>
              <Icono size={26} />
            </div>
            {title ? <h3 className="aviso-titulo">{title}</h3> : null}
            {text ? <p className="aviso-texto">{text}</p> : null}

            {input && (
              <div className={`aviso-input-wrap ${error ? "aviso-input-error" : ""}`}>
                {inputIcon && <span className="aviso-input-icon">{inputIcon}</span>}
                <input
                  ref={inputRef}
                  className="aviso-input"
                  type={visible ? "text" : (input.type || "text")}
                  placeholder={input.placeholder || ""}
                  value={valor}
                  onChange={(e) => { setValor(e.target.value); if (error) setError(""); }}
                  autoFocus={!input.type}
                />
                {input.type === "password" && (
                  <button
                    type="button"
                    className="aviso-input-eye"
                    aria-label={visible ? "Ocultar contraseña" : "Mostrar contraseña"}
                    onClick={() => setVisible((v) => !v)}
                  >
                    {visible ? <EyeOff size={18} /> : <Eye size={18} />}
                  </button>
                )}
              </div>
            )}
            {input && error ? <p className="aviso-input-msj">{error}</p> : null}

            <div className="aviso-acciones">
              {terciarioText && (
                <button type="button" onClick={onTerciario} className="aviso-btn aviso-btn-terciario">
                  {terciarioText}
                </button>
              )}
              {showCancel && (
                <button type="button" onClick={onCancel} className="aviso-btn aviso-btn-secundario">
                  {cancelText}
                </button>
              )}
              <button
                type="button"
                onClick={handleSubmit}
                className={`aviso-btn ${danger ? "aviso-btn-peligro" : "aviso-btn-primario"}`}
                autoFocus={!input}
              >
                {confirmText}
              </button>
            </div>
            <button type="button" onClick={onCancel} className="aviso-cerrar" aria-label="Cerrar">
              <X size={16} />
            </button>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}

// Toast de esquina (aviso breve no bloqueante). Auto-cierra con ms.
export function AvisoToast({ toast }) {
  if (!toast) return null;
  const Icono = ICONOS[toast.icon] || CircleCheck;
  return (
    <AnimatePresence>
      <motion.div
        key={toast.key ?? "aviso-toast"}
        initial={{ opacity: 0, y: -14, scale: 0.97 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        exit={{ opacity: 0, y: -10, scale: 0.97 }}
        transition={{ duration: 0.22, ease: "easeOut" }}
        className={`aviso-toast aviso-toast-${toast.icon}`}
        role="status"
      >
        <span className="aviso-toast-icon"><Icono size={18} /></span>
        <span className="aviso-toast-text">{toast.title}</span>
      </motion.div>
    </AnimatePresence>
  );
}

// Cartel de carga (spinner + texto). No es cerrable por el usuario.
export function AvisoCarga({ carga }) {
  if (!carga) return null;
  return (
    <div className="aviso-carga" role="status" aria-live="polite">
      <div className="aviso-carga-card">
        <Loader2 className="aviso-carga-spin" size={34} />
        {carga.title ? <div className="aviso-carga-title">{carga.title}</div> : null}
        {carga.text ? <div className="aviso-carga-text">{carga.text}</div> : null}
      </div>
    </div>
  );
}