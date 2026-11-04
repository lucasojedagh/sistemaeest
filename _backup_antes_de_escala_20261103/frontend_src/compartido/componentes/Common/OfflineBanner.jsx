// Barrita de conexion: avisa en criollo cuando no hay internet.
// Abajo, gris y chica, para que no tape ni moleste.
// "Sin conexión. No se pueden guardar datos."
import React, { useEffect, useState } from "react";
import { WifiOff, Wifi } from "lucide-react";
import "./OfflineBanner.css";

export default function OfflineBanner() {
  const [enLinea, setEnLinea] = useState(
    typeof navigator !== "undefined" ? navigator.onLine : true
  );
  const [mostrarRecuperado, setMostrarRecuperado] = useState(false);

  useEffect(() => {
    const seCayo = () => setEnLinea(false);
    const volvio = () => {
      setEnLinea(true);
      setMostrarRecuperado(true);
      setTimeout(() => setMostrarRecuperado(false), 4000);
    };
    window.addEventListener("offline", seCayo);
    window.addEventListener("online", volvio);
    return () => {
      window.removeEventListener("offline", seCayo);
      window.removeEventListener("online", volvio);
    };
  }, []);

  if (enLinea && !mostrarRecuperado) return null;

  return (
    <div
      className={enLinea ? "offline-banner offline-banner-ok" : "offline-banner offline-banner-off"}
      role="alert"
    >
      {enLinea ? <Wifi size={13} /> : <WifiOff size={13} />}
      <span>
        {enLinea
          ? "Volvió la conexión. Ya podés guardar."
          : "Sin conexión. No se pueden guardar datos."}
      </span>
    </div>
  );
}
