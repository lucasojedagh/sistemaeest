// ===============================
// FILE: src/components/Preceptores/PreceptorFicha.jsx
// ===============================
import React from "react";
import FichaLayout, { field, valBig, card, chipBlue, tag } from "../../compartido/componentes/Common/FichaLayout";
import { cursoLabel } from "../../compartido/lib/cursos";
import { useAuth } from "../../modulos/auth/AuthContext";
import { esGestion, ROLE_LABELS } from "../../modulos/auth/roles";

/*
 * Componente: PreceptorFicha
 * Props:
 *   open     - Booleano que controla la visibilidad del modal.
 *   onClose  - Cierra el modal.
 *   preceptor - Objeto con los datos del preceptor/jefe a mostrar.
 *
 * Vista de solo lectura con la informacion completa del preceptor:
 * datos personales, email, estado y asignaciones (anio/division).
 * Disenio idéntico a AlumnoFicha / ProfesorFicha / modal "Mi cuenta".
 */

/* ---- Helpers ---- */
const safe = (s) => (s == null ? "" : String(s));
const onlyDigits = (s) => safe(s).replace(/\D/g, "");

const STATUS_LABELS = {
  active: "Activo",
  pending: "Pendiente",
  rejected: "Rechazado",
};

/* Etiqueta de año: "5" -> "5.º" */
const labelAnio = (v) => cursoLabel({ anio: v, division: null });

/* Normaliza asignaciones a arrays consistentes */
function asAsignaciones(x) {
  if (!Array.isArray(x)) return [];
  return x
    .map((a) => ({
      anio: safe(a.anio ?? a.curso?.anio ?? ""),
      division: safe(a.division ?? a.curso?.division ?? ""),
    }))
    .filter((a) => a.anio || a.division);
}

export default function PreceptorFicha({ open, onClose, preceptor }) {
  const { user: currentUser } = useAuth();
  // Ficha completa solo para gestión (admin/directivo/jefe/prosecretaria). Rangos
  // bajos ven únicamente contacto (nombre/email) y cursos asignados.
  const puedeVerTodo = esGestion(currentUser?.role);
  const p = preceptor || {};
  const nombre = safe(p.name ?? p.nombre);
  const apellido = safe(p.apellido ?? p.lastname);
  const email = safe(p.email);
  const dni = onlyDigits(p.dni);
  const status = safe(p.status || "active");
  const estado = STATUS_LABELS[status] || status;
  const rolLabel = p.rolLabel || ROLE_LABELS[p.role] || "Preceptor";

  const asigs = asAsignaciones(p.asignaciones);
  const obs = safe(p.observaciones ?? p.observaciones).trim();
  const titulo = `${apellido}, ${nombre}`.replace(/^, |, $/g, "");

  return (
    <FichaLayout open={open} onClose={onClose} titulo={titulo || "Ficha del Preceptor"} subtitulo={email}>
      {/* Encabezado: DNI / Email / Rol (DNI y rol solo gestión) */}
      <div className={card}>
        <div className="ficha-grid">
          {puedeVerTodo && (
            <div className="ficha-col">
              <span className={field}>DNI</span>
              <span className={valBig}>{dni || "—"}</span>
            </div>
          )}
          <div className="ficha-col">
            <span className={field}>Correo electrónico</span>
            <span className={`${valBig} ficha-break`}>{email || "—"}</span>
          </div>
          {puedeVerTodo && (
            <div className="ficha-col">
              <span className={field}>Rol</span>
              <span className={valBig}>{rolLabel}</span>
            </div>
          )}
        </div>
        {puedeVerTodo && (
          <div className="ficha-tagrow">
            <span
              className={`${tag} ficha-tag-lg ${
                status === "active"
                  ? "ficha-cond--activo"
                  : status === "rejected"
                  ? "ficha-cond--rechazado"
                  : "ficha-cond--pendiente"
              }`}
            >
              {estado}
            </span>
          </div>
        )}
      </div>

      {/* Asignaciones */}
      <div className={card}>
        <div className="ficha-sec-head">
          <span className="ficha-sec-title ficha-sec-title-blue">Asignaciones (Año / División)</span>
          <span className="ficha-sec-count">{asigs.length || 0}</span>
        </div>
        {asigs.length ? (
          <div className="ficha-chips-wrap">
            {asigs.map((a, i) => (
              <span
                key={i}
                className={chipBlue}
                title={`${labelAnio(a.anio)} ${a.division || "(todas)"}`}
              >
                {labelAnio(a.anio)} {a.division ? `${a.division}` : "(todas)"}
              </span>
            ))}
          </div>
        ) : (
          <div className="ficha-empty">—</div>
        )}
      </div>

      {/* Observaciones (solo gestión) */}
      {puedeVerTodo && obs && (
        <div className={card}>
          <div className="ficha-sec-title ficha-sec-title-block">Observaciones</div>
          <p className="ficha-obs-text">{obs}</p>
        </div>
      )}
    </FichaLayout>
  );
}
