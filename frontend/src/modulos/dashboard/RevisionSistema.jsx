// Revisión del sistema: la lista de cosas que hay que atender.
// Muestra sólo los problemas que la base de datos no rechaza por sí sola. El
// backend (/cobertura) redacta cada tema en palabras de la escuela: qué está
// pasando, cómo se arregla y en qué pantalla se arregla. Acá se ordenan por
// prioridad y se dibujan.
import React, { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { ArrowRight, ClipboardList, ListChecks, RefreshCw, ShieldCheck, ChevronDown } from "lucide-react";
import { apiGet } from "../../compartido/lib/api";
import { useAuth } from "../auth/AuthContext";
import { accionHabilitada } from "../../compartido/lib/permisos";
import { esGestion, rolLabel } from "../auth/roles";
import "./RevisionSistema.css";

// Columnas de cada tema: [clave del item, rótulo]. Si una clave no está acá se
// dibujan todas las propiedades del item, para que un chequeo nuevo del backend
// no quede vacío.
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

// La prioridad dicha en términos de trabajo, no de severidad.
const NIVEL_LABEL = {
  alto: "Traba la carga de datos",
  medio: "Puede dar errores",
  bajo: "Para tenerlo ordenado",
};
const NIVEL_ORDEN = { alto: 0, medio: 1, bajo: 2 };

const porPrioridad = (lista) =>
  [...lista].sort((a, b) => (NIVEL_ORDEN[a.nivel] ?? 9) - (NIVEL_ORDEN[b.nivel] ?? 9));

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

  const permitido = accionHabilitada(user, "sistema.cobertura", !!user && esGestion(user.role));

  const cargar = useCallback(async () => {
    if (!permitido) return;
    setEstado("cargando");
    try {
      const res = await apiGet("/cobertura");
      setData(res);
      setAbierto(porPrioridad(res?.problemas || [])[0]?.clave ?? null);
      setEstado("listo");
    } catch {
      setEstado("error");
    }
  }, [permitido]);

  useEffect(() => {
    cargar();
  }, [cargar]);

  const temas = useMemo(() => porPrioridad(data?.problemas || []), [data]);

  if (!permitido) return null;

  const total = data?.total || 0;
  const primero = temas[0];

  return (
    <section className="rev-card">
      <header className="rev-head">
        <div className={`rev-icon ${total > 0 ? "rev-icon-tareas" : "rev-icon-ok"}`}>
          {total > 0 ? <ListChecks size={18} /> : <ShieldCheck size={18} />}
        </div>
        <div className="rev-titulos">
          <h3 className="rev-titulo">Revisión del sistema</h3>
          <p className="rev-subtitulo">
            {estado === "cargando" && "Cruzando cursos, horarios, fichas de docentes y cuentas…"}
            {estado === "error" && "No se pudo hacer la revisión. Tocá Revisar para intentar de nuevo."}
            {estado === "listo" && total === 0 && (
              "Todo en orden: no hay cursos, cuentas ni horarios trabados."
            )}
            {estado === "listo" && total > 0 && (
              <>
                <b>{temas.length} {temas.length === 1 ? "tema" : "temas"} para atender</b>
                {` (${total} ${total === 1 ? "caso" : "casos"}). `}
                {primero && <>Empezá por: “{primero.titulo}”.</>}
              </>
            )}
          </p>
        </div>
        <div className="rev-acciones">
          <button type="button" className="rev-btn" onClick={cargar} disabled={estado === "cargando"} title="Volver a revisar">
            <RefreshCw size={15} className={estado === "cargando" ? "rev-girando" : undefined} />
            <span>{estado === "cargando" ? "Revisando…" : "Revisar"}</span>
          </button>
        </div>
      </header>

      {estado === "listo" && temas.length > 0 && (
        <ol className="rev-lista">
          {temas.map((p, i) => {
            const claves = COLUMNAS[p.clave] || Object.keys(p.items[0] || {}).map((k) => [k, k]);
            const abiertoAqui = abierto === p.clave;
            return (
              <li key={p.clave} className={`rev-tema rev-tema-${p.nivel}`}>
                <div className="rev-tema-cabecera">
                  <button
                    type="button"
                    className="rev-tema-titulo"
                    onClick={() => setAbierto(abiertoAqui ? null : p.clave)}
                    aria-expanded={abiertoAqui}
                  >
                    <ChevronDown size={16} className={`rev-flecha ${abiertoAqui ? "rev-flecha-abierta" : ""}`} />
                    <span className="rev-tema-num">{i + 1}</span>
                    <span className="rev-tema-nombre">{p.titulo}</span>
                    <span className="rev-tema-cantidad">{p.items.length} {p.items.length === 1 ? "caso" : "casos"}</span>
                    <span className={`rev-nivel rev-nivel-${p.nivel}`}>{NIVEL_LABEL[p.nivel] || "Para revisar"}</span>
                  </button>
                  {p.ir_a && (
                    <button type="button" className="rev-ir" onClick={() => navigate(p.ir_a)}>
                      {p.boton || "Ir a resolverlo"}
                      <ArrowRight size={15} />
                    </button>
                  )}
                </div>

                {abiertoAqui && (
                  <div className="rev-tema-cuerpo">
                    <p className="rev-parrafo"><b>Qué está pasando.</b> {p.que_pasa}</p>
                    <p className="rev-parrafo"><b>Cómo se arregla.</b> {p.arreglo}</p>
                    <div className="rev-tabla-envoltura">
                      <table className="rev-tabla">
                        <thead>
                          <tr>
                            {claves.map(([k, etiqueta]) => <th key={k}>{etiqueta}</th>)}
                          </tr>
                        </thead>
                        <tbody>
                          {p.items.map((item, j) => (
                            <tr key={j}>
                              {celdasDelItem(item, claves).map((celda, c) => <td key={c}>{celda}</td>)}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}
              </li>
            );
          })}
        </ol>
      )}

      {estado === "listo" && total === 0 && (
        <p className="rev-vacio">
          <ClipboardList size={15} />
          Cursos, horarios, fichas de docentes y cuentas están consistentes. Si cambiás algo, tocá Revisar.
        </p>
      )}

      {data?.generado && estado === "listo" && (
        <footer className="rev-pie">Revisado el {data.generado.slice(0, 10)}.</footer>
      )}
    </section>
  );
}
