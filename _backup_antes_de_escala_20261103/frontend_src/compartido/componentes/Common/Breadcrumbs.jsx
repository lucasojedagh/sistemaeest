import React from "react";
import { ChevronRight, Home } from "lucide-react";
import { useNavigate } from "react-router-dom";
import "./Breadcrumbs.css";

/*
 * Componente: Breadcrumbs
 * Props:
 *   items - Array de objetos { label, path? }
 *           Cada item representa un nivel del breadcrumb.
 *           Si tiene path, es un enlace clickeable; si no, es texto plano.
 *
 * Barra de navegacion secundaria (migas de pan) que muestra la ruta
 * actual dentro de la aplicacion. Incluye un boton de inicio que
 * redirige al dashboard.
 */

export default function Breadcrumbs({ items = [] }) {
  const navigate = useNavigate();

  if (!items || items.length === 0) return null;

  return (
    <nav className="breadcrumbs" aria-label="Breadcrumb">
      <button onClick={() => navigate("/dashboard")} className="breadcrumbs-home breadcrumbs-link">
        <Home size={14} />
      </button>
      {items.map((item, i) => (
        <React.Fragment key={i}>
          <ChevronRight size={14} className="breadcrumbs-sep" />
          {item.path ? (
            <button onClick={() => navigate(item.path)} className="breadcrumbs-link">
              {item.label}
            </button>
          ) : (
            <span className="breadcrumbs-current">{item.label}</span>
          )}
        </React.Fragment>
      ))}
    </nav>
  );
}
