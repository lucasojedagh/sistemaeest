import React, { useState, useEffect, useRef } from "react";
import { X, FileText, Loader2 } from "lucide-react";
import useCierreAnimado from "../../lib/useCierreAnimado";
import { bloquearScroll } from "../../lib/lockScroll";
import "./ConfirmActionModal.css";

export default function ConfirmActionModal({ open, message, onConfirm, onCancel, loading, error }) {
  const [detalles, setDetalles] = useState("");
  const textareaRef = useRef(null);
  const dialogRef = useRef(null);
  // Cierre animado por CSS (sin el salto de opacidad de Framer al desmontar).
  const { montado, cerrando } = useCierreAnimado(open);

  useEffect(() => {
    if (open) {
      setDetalles("");
      setTimeout(() => textareaRef.current?.focus(), 100);
    }
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e) => e.key === "Escape" && !loading && onCancel?.();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, loading, onCancel]);

  useEffect(() => {
    if (!montado) return;
    return bloquearScroll();
  }, [montado]);

  // El detalle es opcional: Guardar lo envía (o "sin motivo", si está vacío)
  // y Omitir lo saltea directo con "sin motivo". Cancelar/X/Escape no guardan
  // nada (resuelven null) para que los llamadores distingan "omitir" de "salir".
  const texto = detalles.trim() || 'sin motivo';

  return (
    <>
      {montado && (
        <div className={`confirm-backdrop sys-capa${cerrando ? " sys-cerrando" : ""}`}>
          <div
            ref={dialogRef}
            className={`confirm-modal sys-dialogo${cerrando ? " sys-cerrando" : ""}`}
            role="dialog"
            aria-modal="true"
          >
            <div className="confirm-header">
              <div className="confirm-header-left">
                <div className="confirm-header-icon">
                  <FileText size={18} />
                </div>
                <h3 className="confirm-header-title">Detalles del cambio</h3>
              </div>
              <button
                onClick={onCancel}
                disabled={loading}
                className="confirm-close"
              >
                <X size={18} />
              </button>
            </div>

            <div className="confirm-body">
              <p className="confirm-message">{message}</p>
              <label className="confirm-label">
                Motivo / detalle <span className="confirm-label-hint">(opcional)</span>
              </label>
              <textarea
                ref={textareaRef}
                value={detalles}
                onChange={(e) => setDetalles(e.target.value)}
                placeholder="Escribí el motivo del cambio."
                rows={3}
                disabled={loading}
                className="confirm-textarea"
                onKeyDown={(e) => {
                  if (e.key === "Enter" && e.ctrlKey && !loading) {
                    onConfirm?.(texto);
                  }
                }}
              />
              {error && (
                <p className="confirm-error">
                  {error}
                </p>
              )}
            </div>

            <div className="confirm-footer">
              <button
                onClick={onCancel}
                disabled={loading}
                className="confirm-cancel"
              >
                Cancelar
              </button>
              <div className="confirm-footer-actions">
                <button
                  onClick={() => onConfirm?.('sin motivo')}
                  disabled={loading}
                  className="confirm-skip"
                  title="Se guarda sin motivo."
                >
                  Omitir
                </button>
                <button
                  onClick={() => onConfirm?.(texto)}
                  disabled={loading}
                  className="confirm-submit confirm-submit-primary"
                  title="Se guarda igual, con o sin motivo."
                >
                  {loading && <Loader2 size={16} className="confirm-spinner" />}
                  {loading ? "Guardando..." : error ? "Reintentar" : "Guardar"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
