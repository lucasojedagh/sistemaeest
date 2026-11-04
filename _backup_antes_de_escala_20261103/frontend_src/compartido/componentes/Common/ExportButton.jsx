import React, { useState, useRef, useEffect } from "react";
import { Download, FileText, FileSpreadsheet } from "lucide-react";
import { exportCSV, exportJSON } from "../../utils/export";
import "./ExportButton.css";

/*
 * Componente: ExportButton
 * Props:
 *   data     - Datos a exportar (array de objetos)
 *   filename - Nombre base del archivo sin extension
 *   headers  - Array opcional de nombres de columna para CSV
 *   label    - Texto del boton (default: "Exportar")
 *
 * Boton de exportacion con menu desplegable que permite descargar
 * los datos en formato CSV o JSON. Utiliza las utilidades de exportacion
 * definidas en ../../utils/export.
 */

export default function ExportButton({ data, filename = "export", headers = null, label = "Exportar" }) {
  const [open, setOpen] = useState(false);
  const ref = useRef();

  useEffect(() => {
    const handler = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

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
            onClick={() => { exportCSV(data, `${filename}.csv`, headers); setOpen(false); }}
            className="exportbtn-option"
          >
            <FileSpreadsheet size={16} className="exportbtn-icon-csv" /> CSV
          </button>
          <button
            onClick={() => { exportJSON(data, `${filename}.json`); setOpen(false); }}
            className="exportbtn-option"
          >
            <FileText size={16} className="exportbtn-icon-json" /> JSON
          </button>
        </div>
      )}
    </div>
  );
}
