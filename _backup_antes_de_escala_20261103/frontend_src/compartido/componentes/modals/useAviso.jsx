import React, { useCallback, useRef, useState } from "react";
import AvisoModal, { AvisoToast, AvisoCarga } from "./AvisoModal";

// Hook para mostrar avisos, confirmaciones, toasts, cargas y pedidos de dato
// con el modal del sistema (reemplaza los Swal.fire sueltos).
//
//   const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
//   const [mostrarAviso, pedirConfirmacion, avisoModal, mostrarToast, pedirDato, mostrarCarga] = useAviso();
//
//   await mostrarAviso({ icon: "error", title: "...", text: "..." });
//   await mostrarAviso({ icon: "warning", title: "...", text: "...", ms: 1400 }); // se cierra solo
//   const ok = await pedirConfirmacion({ title: "...", text: "..." }); // true/false
//   const ok = await pedirConfirmacion({ title: "...", danger: true, confirmText: "Sí, eliminar" });
//
//   mostrarToast({ icon: "success", title: "Guardado", ms: 1500 }); // aviso de esquina
//   const valor = await pedirDato({ title: "...", input: { type: "email", placeholder: "..." } }); // null si cancela
//   const cerrar = mostrarCarga({ title: "Generando…", text: "..." }); cerrar(); // cierra el cartel
//
// Hay que renderizar {avisoModal} una vez en el componente.
export default function useAviso() {
  const [estado, setEstado] = useState(null);
  const [toast, setToast] = useState(null);
  const [carga, setCarga] = useState(null);
  const resolveRef = useRef(null);
  const timerRef = useRef(null);
  const toastTimerRef = useRef(null);

  const cerrar = useCallback((valor) => {
    if (timerRef.current) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
    setEstado(null);
    if (resolveRef.current) {
      resolveRef.current(valor);
      resolveRef.current = null;
    }
  }, []);

  // Aviso simple. Con ms>0 se cierra solo (para mensajes breves).
  const mostrarAviso = useCallback((opciones = {}) => {
    return new Promise((resolve) => {
      resolveRef.current = resolve;
      const { ms, ...resto } = opciones;
      setEstado({ showCancel: false, confirmText: "Entendido", ...resto });
      if (ms && Number(ms) > 0) {
        timerRef.current = setTimeout(() => cerrar(), Number(ms));
      }
    });
  }, [cerrar]);

  // Confirmación: resuelve true (aceptar) o false (cancelar/cerrar).
  const pedirConfirmacion = useCallback((opciones = {}) => {
    return new Promise((resolve) => {
      resolveRef.current = resolve;
      setEstado({
        icon: "question",
        confirmText: "Continuar",
        cancelText: "Cancelar",
        showCancel: true,
        ...opciones,
      });
    });
  }, []);

  // Pedido de dato: resuelve el valor (string) o null si cancela. Habilita el
  // input del modal y lo valida antes de resolver.
  const pedirDato = useCallback((opciones = {}) => {
    return new Promise((resolve) => {
      resolveRef.current = resolve;
      const { input, ...resto } = opciones;
      setEstado({
        icon: "question",
        confirmText: "Continuar",
        cancelText: "Cancelar",
        showCancel: true,
        input: input || { type: "text" },
        ...resto,
      });
    });
  }, []);

  const handleToastClose = useCallback(() => {
    if (toastTimerRef.current) {
      clearTimeout(toastTimerRef.current);
      toastTimerRef.current = null;
    }
    setToast(null);
  }, []);

  // Toast de esquina, se cierra solo (ms por defecto 2200).
  const mostrarToast = useCallback((opciones = {}) => {
    setToast(null);
    const { ms, ...resto } = opciones;
    setToast({ icon: "success", ...resto, key: Date.now() });
    if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
    toastTimerRef.current = setTimeout(handleToastClose, Number(ms || 2200));
  }, [handleToastClose]);

  // Cartel de carga: devuelve una función para cerrarlo.
  const mostrarCarga = useCallback((opciones = {}) => {
    setCarga(opciones);
    return () => setCarga(null);
  }, []);

  const handleConfirm = useCallback(
    (valor) => {
      const eraConfirm = Boolean(estado?.showCancel);
      if (estado?.input) {
        cerrar(valor); // pedirDato resuelve con el valor ya validado
      } else if (estado?.terciarioText) {
        cerrar(true);  // confirmación con botón terciario: "confirmar"
      } else {
        cerrar(eraConfirm ? true : undefined);
      }
    },
    [cerrar, estado]
  );

  const handleTerciario = useCallback(() => {
    cerrar("terciario");
  }, [cerrar]);

  const handleCancel = useCallback(() => {
    if (estado?.input) return cerrar(null); // pedirDato: cancelar => null
    cerrar(estado?.showCancel ? false : undefined);
  }, [cerrar, estado]);

  const modal = (
    <>
      <AvisoModal
        open={Boolean(estado)}
        icon={estado?.icon || "info"}
        title={estado?.title || ""}
        text={estado?.text || ""}
        showCancel={Boolean(estado?.showCancel)}
        confirmText={estado?.confirmText || "Entendido"}
        cancelText={estado?.cancelText || "Cancelar"}
        danger={Boolean(estado?.danger)}
        terciarioText={estado?.terciarioText || ""}
        input={estado?.input || null}
        onConfirm={handleConfirm}
        onCancel={handleCancel}
        onTerciario={handleTerciario}
      />
      <AvisoToast toast={toast} />
      <AvisoCarga carga={carga} />
    </>
  );

  return [mostrarAviso, pedirConfirmacion, modal, mostrarToast, pedirDato, mostrarCarga];
}