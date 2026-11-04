import { useEffect, useState } from "react";
import { AlertTriangle, Loader, Search } from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { fmtFecha, fmtPct, cursoLabel } from "../../compartido/lib/asistencia";
import CoursePicker from "./CoursePicker";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import { mensajeAmable } from "../../compartido/lib/errores";
import "./InasistenciasAsistencia.css";

export default function InasistenciasAsistencia({ maps }) {
  const [curso, setCurso] = useState({ anio: "", division: "" });
  const [minimo, setMinimo] = useState(20);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const cursoOk = curso.anio && curso.division && curso.division !== "*";

  useEffect(() => {
    setLoading(true);
    setError(null);
    asistenciaApi
      .inasistencias({
        anio: curso.anio,
        division: curso.division !== "*" ? curso.division : "",
        minimo,
      })
      .then(setData)
      .catch((e) => setError(mensajeAmable(e, "No se pudieron cargar las inasistencias. Revisá tu conexión.")))
      .finally(() => setLoading(false));
  }, [curso?.anio, curso?.division, minimo]);

  return (
    <div className="inas-container">
      <div className="inas-filters-card">
        <CoursePicker maps={maps} value={curso} onChange={setCurso} allowEmpty />
        <div>
          <label className="inas-label">
            Mín. % de inasistencia
          </label>
          <input
            type="number"
            min={0}
            max={100}
            value={minimo}
            onChange={(e) => setMinimo(Number(e.target.value))}
            className="inas-input"
          />
        </div>
        <div className="inas-summary">
          {data ? <b>{data.total_alumnos}</b> : 0} alumnos superan el umbral
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

      {!loading && !error && data && (
        <>
          {(data.alumnos || []).length === 0 ? (
            <EmptyState
              icon={Search}
              title="Sin alertas"
              description={`No hay alumnos con más de ${data.minimo ?? minimo}% de inasistencia${cursoOk ? ` en ${cursoLabel(curso.anio, curso.division)}` : ""}.`}
            />
          ) : (
            <div className="inas-table-card">
              <div className="inas-table-wrap">
                <table className="inas-table">
                  <thead>
                    <tr className="inas-thead-row">
                      <th className="inas-th">Alumno</th>
                      <th className="inas-th">Curso</th>
                      <th className="inas-th">Clases</th>
                      <th className="inas-th">Inasistencias</th>
                      <th className="inas-th">Justificadas</th>
                      <th className="inas-th">Tardanzas</th>
                      <th className="inas-th">% inasistencia</th>
                      <th className="inas-th">Faltas consecutivas</th>
                      <th className="inas-th">Última falta</th>
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
                        <td className="inas-td inas-td-amber">{a.justificados}</td>
                        <td className="inas-td inas-td-blue">{a.tardanzas}</td>
                        <td className="inas-td">
                          <span className={`inas-chip ${
                            a.porcentaje_inasistencia >= 30
                              ? "inas-chip-alta"
                              : "inas-chip-media"
                          }`}>
                            {fmtPct(a.porcentaje_inasistencia)}
                          </span>
                        </td>
                        <td className="inas-td">{a.faltas_consecutivas > 0 ? `${a.faltas_consecutivas} ` : "—"}{a.faltas_consecutivas > 0 && "clases"}</td>
                        <td className="inas-td inas-td-muted">{fmtFecha(a.ultima_falta)}</td>
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