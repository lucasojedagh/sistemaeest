import React from "react";
import { motion } from "framer-motion";
import { Inbox } from "lucide-react";
import "./EmptyState.css";

/*
 * Componente: EmptyState
 * Props:
 *   icon        - Componente de icono a mostrar (default: Inbox)
 *   title       - Titulo del estado vacio
 *   description - Descripcion del estado vacio
 *   action      - Elemento React opcional para acciones (botones, enlaces)
 *   compact     - Booleano para version compacta (menos padding)
 *
 * Estado visual para cuando no hay datos que mostrar en una lista o
 * seccion. Incluye icono animado, titulo, descripcion y accion opcional.
 */

export default function EmptyState({ icon: Icon = Inbox, title = "Sin datos", description = "No hay información para mostrar.", action, compact = false }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      className={`emptystate ${compact ? "emptystate-compact" : "emptystate-default"}`}
    >
      <div className="emptystate-icon-wrap">
        <Icon size={28} className="emptystate-icon" />
      </div>
      <h3 className="emptystate-title">{title}</h3>
      <p className="emptystate-desc">{description}</p>
      {action && <div className="emptystate-action">{action}</div>}
    </motion.div>
  );
}
