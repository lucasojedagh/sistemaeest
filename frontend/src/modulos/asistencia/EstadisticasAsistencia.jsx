import { useEffect, useRef, useState } from "react";
import { Award, BarChart3, Loader, TrendingDown } from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { fmtPct, cursoLabel } from "../../compartido/lib/asistencia";
import CoursePicker from "./CoursePicker";
import { mensajeAmable } from "../../compartido/lib/errores";
import "./EstadisticasAsistencia.css";

export default function EstadisticasAsistencia({ maps }) {
  const [curso, setCurso] = useState({ anio: "", division: "" });
  const [year, setYear] = useState(String(new Date().getFullYear()));
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  // La respuesta que llega de un curso o año anterior se descarta.
  const version = useRef(0);

  useEffect(() => {
    const mia = ++version.current;
    setLoading(true);
    setError(null);
    asistenciaApi
      .estadisticas({
        anio: curso.anio,
        division: curso.division !== "*" ? curso.division : "",
        year,
      })
      .then((d) => { if (mia === version.current) setData(d); })
      .catch((e) => {
        // Sin esto la pantalla quedaba en blanco y no se sabía si fue un error
        // o si realmente no hay datos.
        if (mia !== version.current) return;
        setError(mensajeAmable(e, "No se pudieron calcular las estadísticas. Revisá tu conexión."));
        setData(null);
      })
      .finally(() => { if (mia === version.current) setLoading(false); });
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
        <p className="estad-criterio">
          Se cuenta cada marca de asistencia: llegar tarde suma como presencia y el
          justificativo suma como falta (aunque tenga motivo aprobado). Sin curso
          elegido, las cifras son de todos los cursos que ves.
        </p>
      </div>

      {loading && (
        <div className="estad-loading">
          <Loader className="estad-loading-icon" /> Calculando estadísticas…
        </div>
      )}

      {!loading && error && (
        <div className="estad-error">{error}</div>
      )}

      {!loading && data && (
        <>
          <div className="estad-totales-grid">
            {[
              ["% de asistencia", fmtPct(t?.porcentaje)],
              ["Clases relevadas", t?.clases ?? 0],
              ["Marcas de asistencia", t?.asistencias ?? 0],
              ["Presentes", t?.presente ?? 0],
              ["Ausentes", t?.ausente ?? 0],
              ["Con justificativo", t?.justificado ?? 0],
              ["Con tardanza", t?.tarde ?? 0],
            ].map(([label, val]) => (
              <div key={label} className="estad-total-card">
                <div className="estad-total-label">{label}</div>
                <div className="estad-total-value">{val}</div>
              </div>
            ))}
          </div>

          {!t?.asistencias && (
            <div className="estad-sin-datos">
              Todavía no hay ninguna marca de asistencia cargada en {year || "ese año"}.
              Pasá la lista en la pestaña "Pasar lista" y volvé acá: los números se calculan solos.
            </div>
          )}

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
            <Ranking
              title="Alumnos con mejor asistencia"
              icon={Award}
              rows={(data.mejores_alumnos || []).map((a) => ({ ...a, sub: `${a.curso || "—"} · ${a.registros} marcas` }))}
              empty="Sin datos en el año seleccionado."
            />
            <Ranking
              title="Alumnos con menor asistencia"
              icon={TrendingDown}
              rows={(data.peores_alumnos || []).map((a) => ({ ...a, sub: `${a.registros} marcas · ${a.faltas} faltas` }))}
              empty="Sin datos en el año seleccionado."
              danger
            />
          </div>

          {(data.mejores_cursos?.length > 0 || data.peores_cursos?.length > 0) && (
            <div className="estad-rank-grid">
              <Ranking
                title="Cursos con mejor asistencia (todos los que ves)"
                icon={Award}
                rows={(data.mejores_cursos || []).map((c) => ({ alumno_id: `${c.anio}-${c.division}`, label: cursoLabel(c.anio, c.division), porcentaje: c.porcentaje, sub: `${c.registros} marcas` }))}
                empty="Sin datos en el año seleccionado."
              />
              <Ranking
                title="Cursos con menor asistencia (todos los que ves)"
                icon={TrendingDown}
                rows={(data.peores_cursos || []).map((c) => ({ alumno_id: `${c.anio}-${c.division}`, label: cursoLabel(c.anio, c.division), porcentaje: c.porcentaje, sub: `${c.registros} marcas` }))}
                empty="Sin datos en el año seleccionado."
                danger
              />
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
              <span className="estad-rank-nombre">
                <span className="estad-rank-name">{r.label}</span>
                {r.sub ? <span className="estad-rank-sub">{r.sub}</span> : null}
              </span>
              <span className={`estad-rank-pct ${danger ? "estad-rank-pct-danger" : "estad-rank-pct-success"}`}>{fmtPct(r.porcentaje)}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}