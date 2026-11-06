import { useEffect, useRef, useState } from "react";

// Mantiene el modal montado mientras termina la animación de salida por CSS.
//
// Framer Motion solía hacerse cargo del cierre, pero al terminar su animación
// suelta el estilo inline y deja un frame con el diálogo opaco otra vez antes de
// desmontar: eso es el parpadeo que se ve al cerrar. Con la salida en CSS y este
// retardo el elemento se va recién cuando ya se ve transparente.
//
// Uso:
//   const { montado, cerrando } = useCierreAnimado(visible);
//   if (!montado) return null;
//   <div className={`backdrop${cerrando ? " sys-cerrando" : ""} sys-capa`}>
//
// La duración tiene que coincidir con la de las keyframes de cierreModal.css.
export const MS_CIERRE = 220;

const sinAnimacion = () =>
  typeof window !== "undefined" &&
  typeof window.matchMedia === "function" &&
  window.matchMedia("(prefers-reduced-motion: reduce)").matches;

export default function useCierreAnimado(abierto, ms = MS_CIERRE) {
  const [montado, setMontado] = useState(abierto);
  const [cerrando, setCerrando] = useState(false);
  const montadoRef = useRef(abierto);
  const timer = useRef(null);

  useEffect(() => {
    if (abierto) {
      if (timer.current) {
        clearTimeout(timer.current);
        timer.current = null;
      }
      montadoRef.current = true;
      setMontado(true);
      setCerrando(false);
      return;
    }
    if (!montadoRef.current) return;
    if (sinAnimacion()) {
      montadoRef.current = false;
      setMontado(false);
      setCerrando(false);
      return;
    }
    setCerrando(true);
    timer.current = setTimeout(() => {
      timer.current = null;
      montadoRef.current = false;
      setMontado(false);
      setCerrando(false);
    }, ms);
  }, [abierto, ms]);

  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current);
    },
    []
  );

  return { montado, cerrando };
}
