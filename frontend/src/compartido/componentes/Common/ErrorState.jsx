import React from "react";
import { AlertTriangle } from "lucide-react";
import "./ErrorState.css";

/*
 * Componente: ErrorState
 * Props:
 *   mensaje     - Texto del fallo (ya traducido a lenguaje de la escuela)
 *   onReintentar - Acción que vuelve a pedir los datos
 *   compact     - Versión angosta, para dentro de un panel
 *
 * Se usa cuando la pantalla NO pudo leer sus datos. Es distinto de EmptyState
 * ("no hay nada") porque acá sí hay información y lo que falló fue el pedido:
 * ocultarla sin avisar hace que se crea que la escuela no tiene datos.
 */

export default function ErrorState({ mensaje, onReintentar, compact = false }) {
  return (
    <div
      className={`errorstate sys-bloque ${compact ? "errorstate-compact" : "errorstate-default"}`}
      role="alert"
    >
      <AlertTriangle size={18} className="errorstate-icon" />
      <span className="errorstate-text">{mensaje}</span>
      {onReintentar && (
        <button type="button" className="errorstate-btn" onClick={onReintentar}>
          Reintentar
        </button>
      )}
    </div>
  );
}
