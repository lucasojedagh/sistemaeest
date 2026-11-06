// ===============================
// FILE: src/components/Profesores/ProfesorFicha.jsx
// ===============================
import React from "react";
import FichaLayout, { field, valBig, card, chipBlue, tag } from "../../compartido/componentes/Common/FichaLayout";
import { cursoLabel } from "../../compartido/lib/cursos";
import { useAuth } from "../../modulos/auth/AuthContext";
import { esGestion } from "../../modulos/auth/roles";

/*
 * Componente: ProfesorFicha
 * Props:
 *   open     - Booleano que controla la visibilidad del modal.
 *   onClose  - Cierra el modal.
 *   profesor - Objeto con los datos del profesor a mostrar.
 *
 * Vista de solo lectura con la informacion completa del profesor:
 * datos personales, email, estado, suplente/titular y asignaciones.
 * Disenio idéntico a AlumnoFicha / PreceptorFicha / modal "Mi cuenta".
 */

/* Helpers */
const onlyDigits = (s) => (s == null ? "" : String(s)).replace(/\D/g, "");
function asAsignaciones(x) {
  if (!Array.isArray(x)) return [];
  return x
    .map((m) => {
      if (typeof m === "string") return { anio: "", division: "", grupo: null, materia: m };
      return {
        anio: m.anio ?? m.curso?.anio ?? "",
        division: m.division ?? m.curso?.division ?? "",
        grupo: m.grupo ?? null,
        materia: m.materia ?? m.nombre ?? "",
      };
    })
    .filter((m) => m.materia);
}

export default function ProfesorFicha({ open, onClose, profesor }) {
  const { user: currentUser } = useAuth();
  const p = profesor || {};
  const nombre = p.nombre ?? p.name ?? "";
  const apellido = p.apellido ?? p.lastname ?? "";
  const email = p.email || "";
  const dni = onlyDigits(p.dni);
  const estado = p.estado || "Activo";
  const suplente = Boolean(p.suplente);
  const asigs = asAsignaciones(p.asignaciones);
  const obs = (p.observaciones || "").toString().trim();
  const titulo = `${apellido}, ${nombre}`.replace(/^, |, $/g, "");
  // Ficha completa solo para gestión (admin/directivo/jefe/prosecretaria). Rangos bajos
  // ven únicamente contacto (nombre/email) y cursos asignados.
  const puedeVerTodo = esGestion(currentUser?.role);
  // Un profesor no ve las observaciones de sus pares (solo las suyas).
  const esOtroProfesor =
    currentUser?.role === "profesor" &&
    String(email || "").toLowerCase() !== String(currentUser?.email || "").toLowerCase();

  return (
    <FichaLayout open={open} onClose={onClose} titulo={titulo || "Ficha del Profesor"} subtitulo={email}>
      {/* Encabezado: DNI / Email / Condicion (DNI y condición solo gestión) */}
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
              <span className={field}>Condición</span>
              <span className={valBig}>{suplente ? "Suplente" : "Titular"}</span>
            </div>
          )}
        </div>
        {puedeVerTodo && (
          <div className="ficha-tagrow">
            <span className={`${tag} ficha-tag-lg ${estado === "Licencia" ? "ficha-cond--licencia" : "ficha-cond--activo"}`}>
              {estado}
            </span>
          </div>
        )}
      </div>

      {/* Asignaciones */}
      <div className={card}>
        <div className="ficha-sec-head">
          <span className="ficha-sec-title ficha-sec-title-blue">Asignaciones (Año / División / Materia)</span>
          <span className="ficha-sec-count">{asigs.length || 0}</span>
        </div>
        {asigs.length ? (
          <div className="ficha-chips-wrap">
            {asigs.map((m, i) => (
              <span key={i} className={chipBlue} title={`${cursoLabel({ anio: m.anio, division: m.division })} - ${m.materia}`}>
                {cursoLabel({ anio: m.anio, division: m.division })} {m.materia}
                {m.grupo ? ` (${m.grupo})` : ""}
              </span>
            ))}
          </div>
        ) : (
          <div className="ficha-empty">—</div>
        )}
      </div>

      {/* Materias que cubre como suplente (no son asignaciones propias) */}
      {(p.suplencias_materias || []).length > 0 && (
      <div className={card}>
        <div className="ficha-sec-head">
          <span className="ficha-sec-title ficha-sec-title-blue">Suplencias que cubre</span>
          <span className="ficha-sec-count">{(p.suplencias_materias || []).length}</span>
        </div>
        <div className="ficha-chips-wrap">
          {(p.suplencias_materias || []).map((s, i) => (
            <span
              key={i}
              className={chipBlue}
              title={`Suplencia de ${s.titular_nombre || ''}${s.motivo ? ` (${s.motivo})` : ''}`}
            >
              {cursoLabel({ anio: s.anio, division: s.division })} {s.materia} (supl. de {s.titular_nombre || '—'})
            </span>
          ))}
        </div>
      </div>
      )}

      {/* Observaciones (solo gestión; un profesor solo ve las suyas) */}
      {puedeVerTodo && !esOtroProfesor && (
        <div className={card}>
          <div className="ficha-sec-title ficha-sec-title-block">Observaciones</div>
          {obs ? (
            <p className="ficha-obs-text">{obs}</p>
          ) : (
            <p className="ficha-empty">—</p>
          )}
        </div>
      )}
    </FichaLayout>
  );
}
