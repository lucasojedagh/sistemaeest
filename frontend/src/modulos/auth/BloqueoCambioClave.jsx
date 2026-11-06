// Pantalla que bloquea el sistema hasta que el usuario reemplace la clave
// temporal que le entregaron en secretaría por una propia.
import React from "react";
import CambioClaveForm from "../../compartido/componentes/Common/CambioClaveForm";
import { useAuth } from "./AuthContext";
import "./BloqueoCambioClave.css";

export default function BloqueoCambioClave() {
  const { user } = useAuth();
  return (
    <div className="bcc-fondo" role="dialog" aria-modal="true" aria-label="Cambio de clave obligatorio">
      <div className="bcc-tarjeta">
        <p className="bcc-cuenta">{user?.email}</p>
        <CambioClaveForm />
      </div>
    </div>
  );
}
