// Host invisible del flujo Deshacer: solo monta los modales
// (confirmación + contraseña). El botón vive dentro de la notificación
// de éxito, vía useDeshacerAviso().ofrecerDeshacer().
import React from "react";
import { useDeshacerAviso } from "../../lib/deshacer.jsx";

export default function DeshacerHost() {
  const { modalesDeshacer } = useDeshacerAviso();
  return <>{modalesDeshacer}</>;
}