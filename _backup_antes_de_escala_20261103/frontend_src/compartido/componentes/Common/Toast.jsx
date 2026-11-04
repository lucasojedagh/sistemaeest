import React, { createContext, useContext, useState, useCallback, useRef } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { CheckCircle, XCircle, AlertTriangle, Info, X } from "lucide-react";
import "./Toast.css";

/*
 * Sistema de notificaciones Toast
 *
 * ToastProvider - Proveedor de contexto que envuelve la aplicacion
 *   Props: children - Componentes hijos
 *
 * useToast() - Hook para acceder a la funcion addToast(message, type, duration|opciones)
 *   Tipos: "success" | "error" | "warning" | "info"
 *   Duracion en ms (default: 4000, 0 = persistente), o objeto
 *   { duration, action: { label, onClick } } para un botón de acción
 *   dentro de la notificación (ej: "Deshacer"). Al tocar la acción, el
 *   botón entra en "cargando" y la notificación solo se cierra cuando
 *   la acción termina (éxito o error).
 *
 * Las notificaciones aparecen en la esquina superior derecha con
 * animacion de entrada/salida y pueden cerrarse manualmente.
 */

const ToastContext = createContext();

const ICONS = {
  success: CheckCircle,
  error: XCircle,
  warning: AlertTriangle,
  info: Info,
};

const COLORS = {
  success: { bg: "toast-success", text: "toast-success-text", icon: "toast-success-icon" },
  error: { bg: "toast-error", text: "toast-error-text", icon: "toast-error-icon" },
  warning: { bg: "toast-warning", text: "toast-warning-text", icon: "toast-warning-icon" },
  info: { bg: "toast-info", text: "toast-info-text", icon: "toast-info-icon" },
};

function ToastItem({ toast, onStartAccion, onQuitar }) {
  const [esperando, setEsperando] = useState(false);
  const Icon = ICONS[toast.type] || ICONS.info;
  const c = COLORS[toast.type] || COLORS.info;

  const onAction = async () => {
    if (esperando) return;
    // La acción puede tardar (confirmación, contraseña, ida al server):
    // se cancela el auto-cierre y el botón muestra "cargando".
    onStartAccion(toast.id);
    setEsperando(true);
    try {
      await toast.action?.onClick?.();
    } catch {
      /* el flujo muestra su propio aviso de error */
    } finally {
      onQuitar(toast.id);
    }
  };

  return (
    <motion.div
      layout
      initial={{ opacity: 0, x: 100, scale: 0.9 }}
      animate={{ opacity: 1, x: 0, scale: 1 }}
      exit={{ opacity: 0, x: 100, scale: 0.9 }}
      transition={{ type: "spring", stiffness: 400, damping: 30 }}
      className={`toast-item ${c.bg}${esperando ? " toast-item-esperando" : ""}`}
    >
      <Icon size={20} className={`toast-item-icon ${c.icon}`} />
      <div className="toast-body">
        <p className={`toast-message ${c.text}`}>{toast.message}</p>
        {toast.action && (
          <button onClick={onAction} className="toast-action" disabled={esperando}>
            {esperando ? (
              <span className="toast-action-cargando">
                <span className="toast-spinner" />
                Deshaciendo…
              </span>
            ) : (
              toast.action.label || "Deshacer"
            )}
          </button>
        )}
      </div>
      <button onClick={() => onQuitar(toast.id)} className="toast-close" disabled={esperando} aria-label="Cerrar">
        <X size={16} />
      </button>
    </motion.div>
  );
}

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const timeoutsRef = useRef({});

  const quitar = useCallback((id) => {
    const t = timeoutsRef.current[id];
    if (t) {
      clearTimeout(t);
      delete timeoutsRef.current[id];
    }
    setToasts((prev) => prev.filter((x) => x.id !== id));
  }, []);

  const cancelarAutoCierre = useCallback((id) => {
    const t = timeoutsRef.current[id];
    if (t) {
      clearTimeout(t);
      delete timeoutsRef.current[id];
    }
  }, []);

  const addToast = useCallback((message, type = "info", durationOrOptions = 4000) => {
    const opts = typeof durationOrOptions === "object" && durationOrOptions !== null
      ? durationOrOptions
      : { duration: durationOrOptions };
    const duration = opts.duration ?? 4000;
    const action = opts.action ?? null;
    const id = Date.now() + Math.random();
    setToasts((prev) => [...prev, { id, message, type, duration, action }]);
    if (duration > 0) {
      timeoutsRef.current[id] = setTimeout(() => quitar(id), duration);
    }
  }, [quitar]);

  return (
    <ToastContext.Provider value={addToast}>
      {children}
      <div className="toast-container">
        <AnimatePresence>
          {toasts.map((t) => (
            <ToastItem
              key={t.id}
              toast={t}
              onStartAccion={cancelarAutoCierre}
              onQuitar={quitar}
            />
          ))}
        </AnimatePresence>
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  return useContext(ToastContext);
}