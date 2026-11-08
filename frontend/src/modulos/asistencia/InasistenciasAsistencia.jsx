import { useEffect, useRef, useState } from "react";
import { AlertTriangle, History, Loader, Search } from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { fmtFecha, fmtPct, cursoLabel } from "../../compartido/lib/asistencia";
import CoursePicker from "./CoursePicker";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import { mensajeAmable } from "../../compartido/lib/errores";
import "./InasistenciasAsistencia.css";

export default function InasistenciasAsistencia({ maps, onVerFaltas }) {
  const [curso, setCurso] = useState({ anio: "", division: "" });
  const [minimo, setMinimo] = useState(20);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const cursoOk = curso.anio && curso.division && curso.division !== "*";
  const umbral = Number.isFinite(Number(minimo)) ? Math.min(100, Math.max(0, Number(minimo))) : 0;

  // Mismo criterio que en Pasar lista: la respuesta que llega tarde de un curso
  // anterior no pisa la pantalla.
  const version = useRef(0);

  useEffect(() => {
    const mia = ++version.current;
    setLoading(true);
    setError(null);
    asistenciaApi
      .inasistencias({
        anio: curso.anio,
        division: curso.division !== "*" ? curso.division : "",
        minimo: umbral,
      })
      .then((d) => { if (mia === version.current) setData(d); })
      .catch((e) => { if (mia === version.current) setError(mensajeAmable(e, "No se pudieron cargar las inasistencias. Revisá tu conexión.")); })
      .finally(() => { if (mia === version.current) setLoading(false); });
  }, [curso?.anio, curso?.division, umbral]);

  function verFaltas(a) {
    if (!a.dni) return;
    onVerFaltas?.({ dni: a.dni });
  }

  return (
    <div className="inas-container">
      <div className="inas-filters-card">
        <CoursePicker maps={maps} value={curso} onChange={setCurso} allowEmpty />
        <div className="inas-umbral">
          <label className="inas-label">
            Mostrar alumnos con más del {umbral}% de inasistencia
          </label>
          <input
            type="number"
            min={0}
            max={100}
            value={minimo}
            onChange={(e) => setMinimo(e.target.value)}
            className="inas-input"
            aria-label="Porcentaje mínimo de inasistencia para mostrar"
          />
          <p className="inas-umbral-nota">
            La inasistencia suma ausentes y faltas justificadas: el justificativo explica la
            falta, pero no la borra.
          </p>
        </div>
        <div className="inas-summary">
          {data ? <b>{data.total_alumnos}</b> : 0} {data?.total_alumnos === 1 ? "alumno supera" : "alumnos superan"} el umbral
          {cursoOk ? ` · ${cursoLabel(curso.anio, curso.division)}` : " · todos los cursos"}
        </div>
      </div>

      {loading && (
        <div className="inas-loading">
          <Loader className="inas-loading-icon" /> Calculando…
        </div>
      )}
      {error && !loading && (
        <div className="inas-error">{error}</div>
      )}

      {!loading && data?.recortado && (
        <div className="asist-nota">
          {`Son ${data.total_alumnos} los que superan el umbral y se listan los ${(data.alumnos || []).length} con más inasistencia. Subí el porcentaje o filtrá por curso para ver el resto.`}
        </div>
      )}

      {!loading && !error && data && (
        <>
          {(data.alumnos || []).length === 0 ? (
            <EmptyState
              icon={Search}
              title="Sin alertas"
              description={`Ningún alumno supera el ${data.minimo ?? umbral}% de inasistencia${cursoOk ? ` en ${cursoLabel(curso.anio, curso.division)}` : " en los cursos que ves"}.`}
            />
          ) : (
            <div className="inas-table-card">
              <div className="inas-table-wrap">
                <table className="inas-table">
                  <thead>
                    <tr className="inas-thead-row">
                      <th className="inas-th">Alumno</th>
                      <th className="inas-th">Curso</th>
                      <th className="inas-th">Clases relevadas</th>
                      <th className="inas-th">Faltas sin justificar</th>
                      <th className="inas-th">Con justificativo</th>
                      <th className="inas-th">Con tardanza</th>
                      <th className="inas-th">% inasistencia</th>
                      <th className="inas-th">Faltas al hilo</th>
                      <th className="inas-th">Última falta</th>
                      <th className="inas-th inas-th-right">Faltas de este alumno</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(data.alumnos || []).map((a) => (
                      <tr key={a.alumno_id} className="inas-row">
                        <td className="inas-td">
                          <div className="inas-alumno-name">{a.apellido}, {a.nombre}</div>
                          <div className="inas-alumno-id">{a.dni ? `DNI ${a.dni}` : `#${a.alumno_id}`}</div>
                        </td>
                        <td className="inas-td">{cursoLabel(a.anio, a.division)}</td>
                        <td className="inas-td inas-td-muted">{a.clases}</td>
                        <td className="inas-td">
                          <span className="inas-faltas">
                            <AlertTriangle className="inas-faltas-icon" /> {a.inasistencias}
                          </span>
                        </td>
                        <td className="inas-td inas-td-muted">{a.justificados}</td>
                        <td className="inas-td inas-td-muted">{a.tardanzas}</td>
                        <td className="inas-td">
                          <span className={`inas-chip ${
                            a.porcentaje_inasistencia >= 30
                              ? "inas-chip-alta"
                              : "inas-chip-media"
                          }`}>
                            {fmtPct(a.porcentaje_inasistencia)}
                          </span>
                        </td>
                        <td className="inas-td">
                          {a.faltas_consecutivas > 0
                            ? `${a.faltas_consecutivas} ${a.faltas_consecutivas === 1 ? "clase" : "clases"}`
                            : "—"}
                        </td>
                        <td className="inas-td inas-td-muted">{fmtFecha(a.ultima_falta)}</td>
                        <td className="inas-td">
                          {a.dni ? (
                            <button
                              type="button"
                              onClick={() => verFaltas(a)}
                              title="Ver en el Historial las clases a las que faltó"
                              className="inas-btn-ver"
                            >
                              <History className="inas-btn-ver-icon" /> Ver faltas
                            </button>
                          ) : (
                            <span className="inas-sin-dni">Sin DNI cargado</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
