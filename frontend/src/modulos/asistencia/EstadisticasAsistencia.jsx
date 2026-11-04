import { useEffect, useState } from "react";
import { Award, BarChart3, Loader, TrendingDown } from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { fmtPct, cursoLabel } from "../../compartido/lib/asistencia";
import CoursePicker from "./CoursePicker";
import "./EstadisticasAsistencia.css";

export default function EstadisticasAsistencia({ maps }) {
  const [curso, setCurso] = useState({ anio: "", division: "" });
  const [year, setYear] = useState(String(new Date().getFullYear()));
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    setLoading(true);
    asistenciaApi
      .estadisticas({
        anio: curso.anio,
        division: curso.division !== "*" ? curso.division : "",
        year,
      })
      .then(setData)
      .catch(() => setData(null))
      .finally(() => setLoading(false));
  }, [curso?.anio, curso?.division, year]);

  const t = data?.totales;

  return (
    <div className="estad-container">
      <div className="estad-filters-card">
        <CoursePicker maps={maps} value={curso} onChange={setCurso} allowEmpty />
        <div>
          <label className="estad-label">Año lectivo</label>
          <input
            type="number"
            min={2020}
            max={2100}
            value={year}
            onChange={(e) => setYear(e.target.value)}
            className="estad-year-input"
          />
        </div>
      </div>

      {loading && (
        <div className="estad-loading">
          <Loader className="estad-loading-icon" /> Calculando estadísticas…
        </div>
      )}

      {!loading && data && (
        <>
          <div className="estad-totales-grid">
            {[
              ["Clases", t?.clases ?? 0],
              ["Asistencias", t?.asistencias ?? 0],
              ["Presentes", t?.presente ?? 0],
              ["Ausentes", t?.ausente ?? 0],
              ["Justificadas", t?.justificado ?? 0],
              ["Tardanzas", t?.tarde ?? 0],
            ].map(([label, val]) => (
              <div key={label} className="estad-total-card">
                <div className="estad-total-label">{label}</div>
                <div className="estad-total-value">{val}</div>
              </div>
            ))}
          </div>

          {data.evolucion_mensual?.length > 0 && (
            <div className="estad-evolucion-card">
              <div className="estad-evolucion-heading">
                <BarChart3 className="estad-evolucion-icon" /> Evolución de asistencia mensual
              </div>
              <div className="estad-evolucion-list">
                {data.evolucion_mensual.map((m) => (
                  <div key={m.mes} className="estad-evolucion-row">
                    <span className="estad-evolucion-mes">{m.label}</span>
                    <div className="estad-evolucion-bar">
                      <div
                        className="estad-evolucion-fill"
                        style={{ width: `${Math.min(100, m.porcentaje || 0)}%` }}
                      />
                    </div>
                    <span className="estad-evolucion-value">
                      {m.clases} {m.clases === 1 ? "clase" : "clases"} · <strong>{fmtPct(m.porcentaje)}</strong>
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="estad-rank-grid">
            <Ranking title="Mayor asistencia" icon={Award} rows={data.mejores_alumnos || []} empty="Sin datos" />
            <Ranking title="Menor asistencia" icon={TrendingDown} rows={data.peores_alumnos || []} empty="Sin datos" danger />
          </div>

          {data.mejores_cursos?.length > 0 && (
            <div className="estad-rank-grid">
              <Ranking title="Cursos con mejor asistencia (todos)" icon={Award} rows={(data.mejores_cursos || []).map((c) => ({ label: cursoLabel(c.anio, c.division), porcentaje: c.porcentaje }))} empty="Sin datos" />
              <Ranking title="Cursos con menor asistencia (todos)" icon={TrendingDown} rows={(data.peores_cursos || []).map((c) => ({ label: cursoLabel(c.anio, c.division), porcentaje: c.porcentaje }))} empty="Sin datos" danger />
            </div>
          )}
        </>
      )}
    </div>
  );
}

function Ranking({ title, icon, rows, empty, danger = false }) {
  const Icon = icon;
  return (
    <div className="estad-rank-card">
      <div className="estad-rank-heading">
        <Icon className={`estad-rank-icon ${danger ? "estad-rank-icon-danger" : "estad-rank-icon-success"}`} /> {title}
      </div>
      {rows.length === 0 ? (
        <div className="estad-rank-empty">{empty}</div>
      ) : (
        <div className="estad-rank-list">
          {rows.slice(0, 10).map((r, i) => (
            <div key={`${r.label}-${r.alumno_id}`} className="estad-rank-item">
              <span className={`estad-rank-pos ${i < 3 ? "estad-rank-pos-top" : "estad-rank-pos-rest"}`}>
                {i + 1}
              </span>
              <span className="estad-rank-name">{r.label}</span>
              <span className={`estad-rank-pct ${danger ? "estad-rank-pct-danger" : "estad-rank-pct-success"}`}>{fmtPct(r.porcentaje)}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}