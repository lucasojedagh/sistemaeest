import React, { useState, useRef, useEffect } from "react";
import { Download, FileText, FileSpreadsheet } from "lucide-react";
import { exportCSV, exportJSON } from "../../lib/export";
import { useAuth } from "../../../modulos/auth/AuthContext";
import { accionHabilitada } from "../../lib/permisos";
import "./ExportButton.css";

/*
 * Componente: ExportButton
 * Props:
 *   data     - Datos a exportar (array de objetos)
 *   filename - Nombre base del archivo sin extension
 *   headers  - Array opcional de nombres de columna para CSV
 *   label    - Texto del boton (default: "Exportar")
 *   onExport - Función opcional (formato) => {}. Cuando existe, el archivo lo
 *              arma el servidor con el listado completo; "data" queda sin usar.
 *
 * Boton de exportacion con menu desplegable que permite descargar
 * los datos en formato CSV o JSON. Utiliza las utilidades de exportacion
 * definidas en ../../lib/export.
 */

export default function ExportButton({ data, filename = "export", headers = null, label = "Exportar", onExport = null }) {
  const { user } = useAuth();
  // Exportar un listado entra en "Imprimir y exportar vistas": cuando el archivo lo
  // arma el navegador el botón es el control; con onExport el servidor también
  // filtra la descarga por la misma clave (historial.exportar).
  const puedeExportar = accionHabilitada(user, 'impresion.usar', true);
  const [open, setOpen] = useState(false);
  const ref = useRef();

  useEffect(() => {
    const handler = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  if (!puedeExportar) return null;

  const elegir = (formato) => {
    setOpen(false);
    if (onExport) return onExport(formato);
    if (formato === "json") return exportJSON(data, `${filename}.json`);
    return exportCSV(data, `${filename}.csv`, headers);
  };

  return (
    <div className="exportbtn" ref={ref}>
      <button
        onClick={() => setOpen(!open)}
        className="exportbtn-trigger"
      >
        <Download size={16} /> {label}
      </button>
      {open && (
        <div className="exportbtn-menu">
          <button
            onClick={() => elegir("csv")}
            className="exportbtn-option"
          >
            <FileSpreadsheet size={16} className="exportbtn-icon-csv" /> CSV
          </button>
          <button
            onClick={() => elegir("json")}
            className="exportbtn-option"
          >
            <FileText size={16} className="exportbtn-icon-json" /> JSON
          </button>
        </div>
      )}
    </div>
  );
}
