// Aviso "ya se pueden cargar las notas finales" con tiempo restante.
// Solo aparece mientras la ventana está abierta. Se actualiza cada minuto.
import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { BellRing } from "lucide-react";
import { apiGet } from "../../compartido/lib/api";
import "./AvisoNotasFinales.css";

function textoRestante(finISO) {
  const fin = new Date(finISO).getTime();
  if (Number.isNaN(fin)) return "";
  const diff = fin - Date.now();
  if (diff <= 0) return "";
  const dias = Math.floor(diff / (24 * 60 * 60 * 1000));
  if (dias >= 2) return `Quedan ${dias} días`;
  if (dias === 1) return "Queda 1 día";
  const horas = Math.floor(diff / (60 * 60 * 1000));
  if (horas >= 2) return `Quedan ${horas} horas`;
  if (horas === 1) return "Queda 1 hora";
  return "Queda menos de 1 hora";
}

export default function AvisoNotasFinales() {
  const navigate = useNavigate();
  const [ventana, setVentana] = useState(null);
  const [, forzarReloj] = useState(0);

  useEffect(() => {
    let cancel = false;
    apiGet("/calificaciones/ventana")
      .then((v) => { if (!cancel) setVentana(v || null); })
      .catch(() => {});
    return () => { cancel = true; };
  }, []);

  // Refresca el "quedan ..." cada minuto mientras esté abierto.
  useEffect(() => {
    if (!ventana?.abierta) return;
    const t = setInterval(() => forzarReloj((x) => x + 1), 60000);
    return () => clearInterval(t);
  }, [ventana?.abierta]);

  if (!ventana?.abierta) return null;
  const resto = textoRestante(ventana.fecha_fin);
  if (!resto) return null;

  return (
    <button
      type="button"
      className="aviso-notas"
      onClick={() => navigate("/calificaciones")}
      title="Ir a cargar notas"
    >
      <span className="aviso-notas-icono">
        <BellRing size={18} />
      </span>
      <span className="aviso-notas-texto">
        <strong>Ya se pueden cargar las notas finales.</strong>
        <span> {resto}.</span>
      </span>
    </button>
  );
}
