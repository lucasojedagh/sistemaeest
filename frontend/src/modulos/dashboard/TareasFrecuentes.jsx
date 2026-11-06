// Atajos por tarea (no por módulo): lleva directo a lo que la gente
// quiere hacer todos los días.
import React, { useMemo } from "react";
import { useNavigate } from "react-router-dom";
import {
  CalendarCheck,
  PenLine,
  Clock,
  UserPlus,
  CalendarDays,
  FileText,
} from "lucide-react";
import "./TareasFrecuentes.css";

function cursoQuery() {
  return "";
}

function cursoTexto() {
  return null;
}

export default function TareasFrecuentes({ userRole }) {
  const navigate = useNavigate();
  const cursoTxt = cursoTexto();

  const tareas = useMemo(() => {
    const q = cursoQuery();
    const todas = [
      {
        id: "pasar-lista",
        titulo: "Pasar lista",
        subtitulo: cursoTxt ? `Tomar asistencia de ${cursoTxt}` : "Tomar asistencia de mi curso",
        icon: CalendarCheck,
        color: "tf-green",
        roles: ["admin", "directivo", "jefe_preceptores", "preceptor", "prosecretaria"],
        to: `/asistencia${q ? `${q}&tab=tomar` : "?tab=tomar"}`,
      },
      {
        id: "cargar-notas",
        titulo: "Cargar notas",
        subtitulo: cursoTxt ? `Calificaciones de ${cursoTxt}` : "Notas por curso y materia",
        icon: PenLine,
        color: "tf-blue",
        roles: ["admin", "directivo", "jefe_preceptores", "prosecretaria", "profesor"],
        to: `/calificaciones${q}`,
      },
      {
        id: "inasistencias",
        titulo: "Ver inasistencias",
        subtitulo: "Quiénes faltan mucho",
        icon: Clock,
        color: "tf-amber",
        roles: ["admin", "directivo", "jefe_preceptores", "preceptor", "prosecretaria"],
        to: `/asistencia${q ? `${q}&tab=inasistencias` : "?tab=inasistencias"}`,
      },
      {
        id: "inscribir",
        titulo: "Inscribir un alumno",
        subtitulo: "Cargar el primero o uno nuevo",
        icon: UserPlus,
        color: "tf-violet",
        roles: ["admin", "directivo", "jefe_preceptores", "preceptor", "prosecretaria"],
        to: "/alumnos?agregar=1",
      },
      {
        id: "horario",
        titulo: "Ver horario de un curso",
        subtitulo: cursoTxt ? `Horario de ${cursoTxt}` : "Horario semanal por curso",
        icon: CalendarDays,
        color: "tf-cyan",
        roles: ["admin", "directivo", "jefe_preceptores", "preceptor", "profesor", "prosecretaria"],
        to: `/cursos${q}`,
      },
      {
        id: "buscar",
        titulo: "Buscar un alumno",
        subtitulo: "Por nombre o DNI",
        icon: FileText,
        color: "tf-slate",
        roles: ["admin", "directivo", "jefe_preceptores", "preceptor", "profesor", "prosecretaria"],
        to: "/alumnos",
      },
    ];
    if (!userRole) return todas;
    return todas.filter((t) => t.roles.includes(userRole));
  }, [userRole, cursoTxt]);

  return (
    <section className="tf-card" aria-label="¿Qué querés hacer hoy?">
      <div className="tf-header">
        <h2 className="tf-title">¿Qué querés hacer hoy?</h2>
        {cursoTxt && <span className="tf-curso">Mi curso: {cursoTxt}</span>}
      </div>
      <div className="tf-grid">
        {tareas.map((t) => {
          const Icon = t.icon;
          return (
            <button key={t.id} type="button" className={`tf-btn ${t.color}`} onClick={() => navigate(t.to)}>
              <span className="tf-icon">
                <Icon size={22} />
              </span>
              <span className="tf-text">
                <span className="tf-titulo">{t.titulo}</span>
                <span className="tf-sub">{t.subtitulo}</span>
              </span>
            </button>
          );
        })}
      </div>
    </section>
  );
}
