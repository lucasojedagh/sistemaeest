// =============================== 
// FILE: src/components/Alumnos/AlumnoFicha.jsx
// ===============================
import React, { useEffect, useState } from "react";
import FichaLayout, { field, valBig, card, chipBlue, chipGreen, tag, initials } from "../../compartido/componentes/Common/FichaLayout";
import { cursoLabel, parseCurso } from "../../compartido/lib/cursos";
import { apiGet } from "../../compartido/lib/api";
import "./AlumnoFicha.css";

/*
 * Componente: AlumnoFicha
 * Props:
 *   open    - Booleano que controla la visibilidad del modal (si se omite, render embebido).
 *   onClose - Cierra el modal.
 *   alumno  - Objeto con los datos del alumno a mostrar
 *
 * Vista de solo lectura con la informacion completa del alumno.
 * Disenio idéntico a ProfesorFicha / PreceptorFicha / modal "Mi cuenta".
 */

/* ---- Helpers ---- */
const safe = (s) => (s == null ? "" : String(s));
const onlyDigits = (s) => safe(s).replace(/\D/g, "");

function calcEdad(fechaStr) {
  try {
    const s = safe(fechaStr);
    if (!s) return null;
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return null;
    const today = new Date();
    let edad = today.getFullYear() - d.getFullYear();
    const m = today.getMonth() - d.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < d.getDate())) edad--;
    return edad >= 0 && edad < 120 ? edad : null;
  } catch { return null; }
}

function asList(x) {
  if (!Array.isArray(x)) return [];
  return x.map((m) => {
    if (typeof m === "string") return { anio: "", division: "", materia: m };
    return {
      anio: safe(m.anio),
      division: safe(m.division),
      materia: safe(m.materia ?? m.nombre ?? ""),
    };
  }).filter((m) => m.materia);
}
function asDirecciones(x) {
  if (!Array.isArray(x)) return [];
  return x.map((d) => ({
    titular: safe(d.titular || "Tutor/a"),
    direccion: safe(d.direccion || d.calle || d.texto || ""),
  })).filter((d) => d.direccion);
}
function asTelefonos(x) {
  if (!Array.isArray(x)) return [];
  return x.map((t) => ({
    titular: safe(t.titular || "Tutor/a"),
    telefono: onlyDigits(t.telefono ?? t.numero ?? t.value ?? t.val ?? t.nro),
  })).filter((t) => t.telefono);
}
function asResponsables(x) {
  if (!Array.isArray(x)) return [];
  return x.map((r) => ({
    nombre: safe(r.nombre),
    apellido: safe(r.apellido),
    parentesco: safe(r.parentesco || r.vinculo || r.relacion || ""),
    convive: Boolean(r.convive),
    tutor_legal: Boolean(r.tutor_legal || r.tutor),
  })).filter((r) => r.nombre || r.apellido || r.parentesco || r.convive || r.tutor_legal);
}

function cursoLabelFromAlumno(a) {
  if (safe(a?.anio).trim()) return cursoLabel(a?.anio);
  const parsed = parseCurso({ anio: a?.curso?.anio, division: a?.curso?.division });
  if (parsed.anio) return cursoLabel({ anio: parsed.anio, division: parsed.division });
  return "—";
}

function generoCompleto(g) {
  const s = safe(g).trim();
  if (!s) return "";
  if (s.length > 1) return s;
  if (s === "F") return "Femenino";
  if (s === "M") return "Masculino";
  if (s === "O") return "Otro";
  return s;
}

export default function AlumnoFicha({ open, onClose, alumno }) {
  const a = alumno || {};
  const dni = onlyDigits(a?.dni);
  const fecha = safe(a?.fechaNacimiento || a?.fecha_nacimiento);
  const edad = calcEdad(fecha);
  const curso = cursoLabelFromAlumno(a);
  const estado = safe(a?.estado || "Activo");
  const tiene_ppi = Boolean(a?.tiene_ppi);
  const tiene_ae = Boolean(a?.tiene_ae);

  const rec = asList(a?.recursando);
  const ints = asList(a?.intensificacion);
  const dirs = asDirecciones(a?.direcciones);
  const tels = asTelefonos(a?.telefonos);
  const resp = asResponsables(a?.responsables);
  const obs = safe(a?.observaciones).trim();

  const nombreCompleto = `${safe(a?.apellido)}, ${safe(a?.nombre)}`.replace(/^, |, $/g, "");

  // Resumen de una mirada: promedio general e inasistencias (API, fail-safe),
  // más lo que ya trae el alumno (recursadas e intensificación).
  const [resPromedio, setResPromedio] = useState(null);
  const [resFaltas, setResFaltas] = useState(null);
  useEffect(() => {
    let cancel = false;
    setResPromedio(null);
    setResFaltas(null);
    const id = a?.id;
    if (!id) return;
    apiGet(`/alumnos/${id}/promedios`)
      .then((r) => {
        if (cancel) return;
        const vals = [];
        for (const m of r?.materias || []) {
          for (const p of m?.promedios || []) {
            if (p?.promedio != null && !Number.isNaN(Number(p.promedio))) vals.push(Number(p.promedio));
          }
        }
        setResPromedio(vals.length ? (vals.reduce((x, y) => x + y, 0) / vals.length).toFixed(1) : null);
      })
      .catch(() => {});
    const pc = parseCurso(a?.anio_num ?? a?.anio);
    const qp = new URLSearchParams();
    if (pc.anio) qp.set("anio", String(pc.anio));
    const qs = qp.toString() ? `?${qp.toString()}` : "";
    apiGet(`/asistencia/inasistencias${qs}`)
      .then((r) => {
        if (cancel) return;
        const lista = Array.isArray(r) ? r : r?.data || r?.alumnos || [];
        const mio = (lista || []).find((x) => Number(x.alumno_id) === Number(id));
        setResFaltas(mio ? mio.inasistencias ?? mio.faltas ?? mio.ausentes ?? null : 0);
      })
      .catch(() => {});
    return () => { cancel = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [a?.id]);

  const contenido = (
    <>
      <div className="ficha-resumen" aria-label="Resumen del alumno">
        <div className="ficha-resumen-item">
          <span className="ficha-resumen-valor">{resPromedio ?? "—"}</span>
          <span className="ficha-resumen-etiqueta">Promedio</span>
        </div>
        <div className="ficha-resumen-item">
          <span className="ficha-resumen-valor">{resFaltas ?? "—"}</span>
          <span className="ficha-resumen-etiqueta">Inasistencias</span>
        </div>
        <div className="ficha-resumen-item">
          <span className="ficha-resumen-valor">{rec.length}</span>
          <span className="ficha-resumen-etiqueta">Recursa</span>
        </div>
        <div className="ficha-resumen-item">
          <span className="ficha-resumen-valor">{ints.length}</span>
          <span className="ficha-resumen-etiqueta">Intensifica</span>
        </div>
      </div>

      <div className={card}>
        <div className="ficha-alumno-grid-3">
          <div className="ficha-alumno-col">
            <div className="ficha-alumno-col-inner">
              <span className={field}>DNI</span>
              <span className={valBig}>{dni || "—"}</span>
            </div>
            <div className="ficha-alumno-col-inner">
              <span className={field}>Género</span>
              <span className={valBig}>{generoCompleto(a?.genero) || "—"}</span>
            </div>
            <div className="ficha-alumno-col-inner">
              <span className={field}>N° de matrícula</span>
              <span className={valBig}>{safe(a?.matricula) || "—"}</span>
            </div>
            <div className="ficha-alumno-col-inner">
              <span className={field}>Correo electrónico</span>
              <span className={`${valBig} ficha-break`}>{safe(a?.email) || "—"}</span>
            </div>
          </div>

          <div className="ficha-alumno-col-inner">
            <span className={field}>Fecha de nacimiento</span>
            <span className={`${valBig} ficha-break`}>
              {fecha || "—"}
              {edad != null ? `  ${edad} años` : ""}
            </span>
          </div>

          <div className="ficha-alumno-col-inner">
            <span className={field}>Curso</span>
            <span className={valBig}>{curso || "—"}</span>
          </div>

          <div className="ficha-alumno-col-inner">
            <span className={field}>Nacionalidad</span>
            <span className={`${valBig} ficha-break`}>{safe(a?.nacionalidad) || "—"}</span>
          </div>
        </div>

        <div className="ficha-alumno-chips-row">
          {estado && (
            <span className={`${tag} ficha-alumno-chip ${estado === "Inactivo" ? "ficha-alumno-chip-inactivo" : estado === "Egresado" ? "ficha-alumno-chip-egresado" : "ficha-alumno-chip-activo"}`}>
              {estado}
            </span>
          )}
          <span className={`${tag} ficha-alumno-chip ${tiene_ppi ? "ficha-alumno-chip-ppi" : "ficha-alumno-chip-sin"}`} title="Proyecto Pedagógico de Inclusión">
            {tiene_ppi ? "Con P.P.I" : "Sin P.P.I"}
          </span>
          <span className={`${tag} ficha-alumno-chip ${tiene_ae ? "ficha-alumno-chip-ae" : "ficha-alumno-chip-sin"}`} title="Acompañante Externo">
            {tiene_ae ? "Con A.E" : "Sin A.E"}
          </span>
        </div>
      </div>

      <div className="ficha-alumno-grid-12">
        <div className="ficha-alumno-col-span-6">
          <div className={card}>
            <div className="ficha-sec-head">
              <span className="ficha-sec-title ficha-sec-title-blue">Materias que recursa</span>
              <span className="ficha-sec-count">{rec.length || 0}</span>
            </div>
            {rec.length ? (
              <div className="ficha-chips-wrap">
                {rec.map((m, i) => (
                  <span key={i} className={chipBlue} title={`${m.anio} ${m.division} - ${m.materia}`}>
                    {m.anio} {m.division && `${m.division} · `} {m.materia}
                  </span>
                ))}
              </div>
            ) : <div className="ficha-empty">—</div>}
          </div>
        </div>

        <div className="ficha-alumno-col-span-6">
          <div className={card}>
            <div className="ficha-sec-head">
              <span className="ficha-sec-title ficha-sec-title-emerald">Materias de intensificación</span>
              <span className="ficha-sec-count ficha-sec-count-emerald">{ints.length || 0}</span>
            </div>
            {ints.length ? (
              <div className="ficha-chips-wrap">
                {ints.map((m, i) => (
                  <span key={i} className={chipGreen} title={`${m.anio} ${m.division} - ${m.materia}`}>
                    {m.anio} {m.division && `${m.division} · `} {m.materia}
                  </span>
                ))}
              </div>
            ) : <div className="ficha-empty">—</div>}
          </div>
        </div>

        <div className="ficha-alumno-col-span-12">
          <div className={card}>
            <div className="ficha-sec-head">
              <span className="ficha-sec-title">Responsables / Tutores</span>
              <span className="ficha-sec-count ficha-sec-count-gray">{resp.length || 0}</span>
            </div>
            {resp.length ? (
              <div className="ficha-alumno-grid-2-gap2">
                {resp.map((r, i) => {
                  const extras = [r.tutor_legal ? "Tutor legal" : null, r.convive ? "Convive" : null].filter(Boolean).join(" • ");
                  return (
                    <div key={i} className="ficha-alumno-resp-card" title={`${r.parentesco || ""}${(r.nombre || r.apellido) ? " — " : ""}${[r.nombre, r.apellido].filter(Boolean).join(" ")}`}>
                      <div className="ficha-alumno-resp-row">
                        <div className="ficha-alumno-resp-main">
                          <div className="ficha-alumno-resp-parentesco">{r.parentesco || "—"}</div>
                          <div className="ficha-alumno-resp-nombre">{[r.nombre, r.apellido].filter(Boolean).join(" ") || "—"}</div>
                        </div>
                        {extras && (
                          <span className="ficha-alumno-resp-extras">{extras}</span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : <div className="ficha-empty">—</div>}
          </div>
        </div>

        <div className="ficha-alumno-col-span-6">
          <div className={card}>
            <div className="ficha-sec-title ficha-sec-title-block">Direcciones</div>
            {dirs.length ? (
              <div className="ficha-chips-wrap">
                {dirs.map((d, i) => (
                  <span key={i} className="ficha-contact-chip" title={`${d.titular}: ${d.direccion}`}>
                    <strong className="ficha-contact-strong">{d.titular}:</strong> {d.direccion}
                  </span>
                ))}
              </div>
            ) : <div className="ficha-empty">—</div>}
          </div>
        </div>

        <div className="ficha-alumno-col-span-6">
          <div className={card}>
            <div className="ficha-sec-title ficha-sec-title-block">Teléfonos</div>
            {tels.length ? (
              <div className="ficha-chips-wrap">
                {tels.map((t, i) => (
                  <span key={i} className="ficha-contact-chip" title={`${t.titular}: ${t.telefono}`}>
                    <strong className="ficha-contact-strong">{t.titular}:</strong> {t.telefono}
                  </span>
                ))}
              </div>
            ) : <div className="ficha-empty">—</div>}
          </div>
        </div>

        <div className="ficha-alumno-col-span-6">
          <div className={card}>
            <div className="ficha-sec-title ficha-sec-title-block">Observaciones</div>
            {obs ? (
              <p className="ficha-obs-text">{obs}</p>
            ) : <p className="ficha-empty">—</p>}
          </div>
        </div>

        <div className="ficha-alumno-col-span-6">
          <div className={card}>
            <div className="ficha-sec-title ficha-sec-title-block">Inclusión y acompañamiento</div>
            <div className="ficha-chips-wrap">
              <span className={`ficha-alumno-inclusion-chip ${tiene_ppi ? "ficha-alumno-inclusion-chip-ppi" : "ficha-alumno-inclusion-chip-sin"}`} title="Proyecto Pedagógico de Inclusión">
                {tiene_ppi ? "Con P.P.I" : "Sin P.P.I"}
              </span>
              <span className={`ficha-alumno-inclusion-chip ${tiene_ae ? "ficha-alumno-inclusion-chip-ae" : "ficha-alumno-inclusion-chip-sin"}`} title="Acompañante Externo">
                {tiene_ae ? "Con A.E" : "Sin A.E"}
              </span>
            </div>
          </div>
        </div>
      </div>
    </>
  );

  if (open !== undefined) {
    return (
      <FichaLayout
        open={open}
        onClose={onClose}
        titulo={nombreCompleto || "Ficha del Alumno"}
        subtitulo={curso}
        avatarFallback={initials(a?.nombre, a?.apellido)}
      >
        {contenido}
      </FichaLayout>
    );
  }

  return contenido;
}
