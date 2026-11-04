import { useState, useCallback, useRef } from "react";
import ConfirmActionModal from "../componentes/modals/ConfirmActionModal";
import { mensajeAmable } from "../lib/errores";

export default function useConfirmAction() {
  const [open, setOpen] = useState(false);
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const resolveRef = useRef(null);
  const submitRef = useRef(null);
  const onErrorRef = useRef(null);

  // onError (opcional): devuelve true para quedarse con el error. Se usa con
  // respuestas que merecen una UI propia, como el aviso de conflicto al guardar
  // un alumno, que no tiene sentido dentro de este modal de reintentos.
  const confirmAction = useCallback((msg, submitFn, onError) => {
    return new Promise((resolve) => {
      setMessage(msg);
      setOpen(true);
      setLoading(false);
      setError(null);
      resolveRef.current = resolve;
      submitRef.current = submitFn || null;
      onErrorRef.current = typeof onError === 'function' ? onError : null;
    });
  }, []);

  const handleConfirm = useCallback(async (detalles) => {
    setError(null);
    setLoading(true);
    try {
      if (submitRef.current) {
        await submitRef.current(detalles);
      }
    } catch (e) {
      setLoading(false);
      if (onErrorRef.current && onErrorRef.current(e) === true) {
        setOpen(false);
        onErrorRef.current = null;
        if (resolveRef.current) {
          resolveRef.current(null);
          resolveRef.current = null;
        }
        return;
      }
      // Lenguaje en criollo: nunca mostrar códigos (422/500) ni tecnicismos.
      const raw = e?.data?.message || e?.message || '';
      let msg = mensajeAmable(e, 'No se pudo guardar. Revisá los datos e intentá de nuevo.');
      try {
        const parsed = JSON.parse(raw);
        msg = mensajeAmable({ ...e, data: parsed }, msg);
      } catch { /* el mensaje ya es legible */ }
      setError(msg);
      return;
    }
    setOpen(false);
    if (resolveRef.current) {
      resolveRef.current(detalles);
      resolveRef.current = null;
    }
  }, []);

  const handleCancel = useCallback(() => {
    setOpen(false);
    setLoading(false);
    setError(null);
    if (resolveRef.current) {
      resolveRef.current(null);
      resolveRef.current = null;
    }
  }, []);

  const modal = (
    <ConfirmActionModal
      open={open}
      loading={loading}
      error={error}
      message={message}
      onConfirm={handleConfirm}
      onCancel={handleCancel}
    />
  );

  return [confirmAction, modal];
}
