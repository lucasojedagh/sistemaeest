// Revisión de cobertura: los errores que la base de datos no rechaza.
// Un curso puede tener materias, horario y alumnos y aun así nadie poder
// pasarle la lista porque ninguna cuenta lo tiene asignado. El backend cruza
// cursos, horarios, fichas de profesor, cuentas y la matriz de alcance de cada
// rol, y devuelve sólo los huecos reales. Esta tarjeta los lista y explica cómo
// cerrarlos.
import React, { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { AlertTriangle, RefreshCw, ShieldCheck, ChevronDown, Users2, Stethoscope } from "lucide-react";
import { apiGet } from "../../compartido/lib/api";
import { useAuth } from "../auth/AuthContext";
import { puede } from "../../compartido/lib/permisos";
import { esGestion, rolLabel } from "../auth/roles";
import "./RevisionSistema.css";

// Columnas de cada problema: [clave del item, rótulo]. Si una clave no está acá
// se dibujan todas las propiedades del item, para que un chequeo nuevo del
// backend no quede vacío.
const COLUMNAS = {
  cursos_sin_cobertura: [["curso", "Curso"], ["alumnos", "Alumnos"], ["celdas", "Franjas de horario"]],
  cursos_sin_responsable: [["curso", "Curso"], ["alumnos", "Alumnos"]],
  cuentas_sin_curso: [["usuario", "Cuenta"], ["rol", "Rol"]],
  docentes_sin_cuenta: [["docente", "Docente"], ["email", "Email"], ["celdas", "Franjas de horario"]],
  horario_fuera_de_ficha: [["curso", "Curso"], ["materia", "Materia"], ["docente", "Docente"], ["celdas", "Franjas"]],
  celdas_sin_docente: [["curso", "Curso"], ["celdas", "Franjas sin docente"]],
  cursos_fuera_del_catalogo: [["curso", "Curso"], ["alumnos", "Alumnos"]],
  alumnos_sin_curso: [["curso", "Curso"], ["alumnos", "Alumnos"]],
  cursos_sin_alumnos: [["curso", "Curso"], ["celdas", "Franjas de horario"]],
  asistencias_fuera_de_curso: [["curso", "Curso"], ["clase", "Clase"], ["fecha", "Fecha"], ["marcas", "Marcas fuera del curso"]],
};

// Dónde se corrige cada problema (null = no hay una pantalla directa).
const IR_A = {
  cuentas_sin_curso: { ruta: "/admin/usuarios", texto: "Asignar cursos a las cuentas" },
  docentes_sin_cuenta: { ruta: "/admin/usuarios", texto: "Crear la cuenta del docente" },
  horario_fuera_de_ficha: { ruta: "/profesores", texto: "Completar la ficha del profesor" },
  celdas_sin_docente: { ruta: "/cursos", texto: "Completar el horario" },
  cursos_fuera_del_catalogo: { ruta: "/cursos", texto: "Dar de alta la división" },
  alumnos_sin_curso: { ruta: "/cursos", texto: "Crear el curso" },
};

const NIVEL_LABEL = { alto: "Prioridad alta", medio: "Para revisar", bajo: "Informativo" };

function celdasDelItem(item, claves) {
  return claves.map(([k]) => {
    const v = item[k];
    if (k === "rol") return rolLabel(v) || v || "—";
    if (v === null || v === undefined || v === "") return "—";
    return typeof v === "number" ? v.toLocaleString("es-AR") : String(v);
  });
}

export default function RevisionSistema() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [estado, setEstado] = useState("idle"); // idle | cargando | listo | error
  const [abierto, setAbierto] = useState(null);

  const permitido = !!user && esGestion(user.role) && puede(user, "sistema.cobertura");

  const cargar = useCallback(async () => {
    if (!permitido) return;
    setEstado("cargando");
    try {
      const res = await apiGet("/cobertura");
      setData(res);
      setEstado("listo");
    } catch {
      setEstado("error");
    }
  }, [permitido]);

  useEffect(() => {
    cargar();
  }, [cargar]);

  if (!permitido) return null;

  const problemas = data?.problemas || [];
  const porNivel = problemas.reduce((acc, p) => {
    acc[p.nivel] = (acc[p.nivel] || 0) + p.items.length;
    return acc;
  }, {});

  return (
    <section className="rev-card">
      <header className="rev-head">
        <div className={`rev-icon ${data && data.total > 0 ? "rev-icon-alerta" : "rev-icon-ok"}`}>
          {data && data.total > 0 ? <AlertTriangle size={18} /> : <ShieldCheck size={18} />}
        </div>
        <div className="rev-titulos">
          <h3 className="rev-titulo">Revisión del sistema</h3>
          <p className="rev-subtitulo">
            {estado === "cargando" && "Cruzando cursos, horarios, fichas y cuentas…"}
            {estado === "error" && "No se pudo revisar el sistema. Recargá la página o volvé a intentar."}
            {estado === "listo" && (data.total === 0
              ? "No encontramos huecos: todos los cursos tienen alguien que pueda trabajarlos."
              : `${data.total} ${data.total === 1 ? "situación" : "situaciones"} que la base de datos no rechaza pero dejan datos sin poder cargarse.`)}
          </p>
        </div>
        <div className="rev-acciones">
          {estado === "listo" && problemas.length > 0 && (
            <div className="rev-chips">
              {["alto", "medio", "bajo"].filter((n) => porNivel[n]).map((n) => (
                <span key={n} className={`rev-chip rev-chip-${n}`}>
                  {porNivel[n]} · {NIVEL_LABEL[n]}
                </span>
              ))}
            </div>
          )}
          <button type="button" className="rev-btn" onClick={cargar} disabled={estado === "cargando"} title="Volver a revisar">
            <RefreshCw size={15} className={estado === "cargando" ? "rev-girando" : undefined} />
            <span>Revisar</span>
          </button>
        </div>
      </header>

      {estado === "listo" && problemas.length > 0 && (
        <div className="rev-lista">
          {problemas.map((p) => {
            const claves = COLUMNAS[p.clave] || Object.keys(p.items[0] || {}).map((k) => [k, k]);
            const dest = IR_A[p.clave];
            const abiertoAqui = abierto === p.clave;
            return (
              <div key={p.clave} className={`rev-bloque rev-bloque-${p.nivel}`}>
                <button
                  type="button"
                  className="rev-bloque-head"
                  onClick={() => setAbierto(abiertoAqui ? null : p.clave)}
                  aria-expanded={abiertoAqui}
                >
                  <ChevronDown size={16} className={`rev-flecha ${abiertoAqui ? "rev-flecha-abierta" : ""}`} />
                  <span className="rev-bloque-titulo">{p.titulo}</span>
                  <span className="rev-bloque-cantidad">{p.items.length}</span>
                </button>

                {abiertoAqui && (
                  <div className="rev-bloque-cuerpo">
                    <p className="rev-bloque-detalle">{p.detalle}</p>
                    <div className="rev-tabla-envoltura">
                      <table className="rev-tabla">
                        <thead>
                          <tr>
                            {claves.map(([k, etiqueta]) => <th key={k}>{etiqueta}</th>)}
                          </tr>
                        </thead>
                        <tbody>
                          {p.items.map((item, i) => (
                            <tr key={i}>
                              {celdasDelItem(item, claves).map((celda, j) => <td key={j}>{celda}</td>)}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    {dest && (
                      <button type="button" className="rev-enlace" onClick={() => navigate(dest.ruta)}>
                        {p.clave === "docentes_sin_cuenta" ? <Stethoscope size={15} /> : <Users2 size={15} />}
                        {dest.texto}
                      </button>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {data?.generado && estado === "listo" && (
        <footer className="rev-pie">Revisado el {data.generado.slice(0, 10)}.</footer>
      )}
    </section>
  );
}
