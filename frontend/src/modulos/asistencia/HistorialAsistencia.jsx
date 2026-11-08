import { useEffect, useRef, useState } from "react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { History, Loader, Search, User2, Users, Download } from "lucide-react";
import { asistenciaApi } from "../../compartido/api/asistencia";
import { fmtFecha, fmtPct, cursoLabel, hoyLocal } from "../../compartido/lib/asistencia";
import { exportCSV } from "../../compartido/lib/export";
import { mensajeDescarga } from "../../compartido/lib/descarga";
import { useToast } from "../../compartido/componentes/Common/Toast";
import EstadoChip from "./EstadoChip";
import CoursePicker from "./CoursePicker";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import { mensajeAmable } from "../../compartido/lib/errores";
import "./HistorialAsistencia.css";

export default function HistorialAsistencia({ maps, initial }) {
  const showToast = useToast();
  const [modo, setModo] = useState("curso");
  const [curso, setCurso] = useState({ anio: 1, division: "" });
  const [alumnoDni, setAlumnoDni] = useState("");
  const [desde, setDesde] = useState(`${new Date().getFullYear()}-01-01`);
  const [hasta, setHasta] = useState(() => hoyLocal());
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [buscado, setBuscado] = useState(false);
  // "Ver faltas" desde Inasistencias: se cargan los filtros y la búsqueda sola.
  const [buscarSolo, setBuscarSolo] = useState(false);
  const [mostrarAviso, , avisoModal] = useAviso();
  const version = useRef(0);

  const cursoOk = curso.anio && curso.division && curso.division !== "*";

  async function buscar() {
    const params = { desde, hasta };
    if (modo === "alumno") {
      const dni = alumnoDni.replace(/\D/g, "");
      if (!dni) { mostrarAviso({ icon: "warning", title: "Falta el alumno", text: "Ingresá el DNI del alumno." }); return; }
      if (!/^\d{7,8}$/.test(dni)) { mostrarAviso({ icon: "warning", title: "DNI inválido", text: "El DNI debe tener 7 u 8 números." }); return; }
      params.dni = dni;
    } else {
      if (!cursoOk) { mostrarAviso({ icon: "warning", title: "Falta el curso", text: "Elegí año y división." }); return; }
      params.anio = curso.anio;
      params.division = curso.division;
    }
    setLoading(true);
    setError(null);
    setBuscado(true);
    // Última respuesta gana: cambiando de curso rápido, el pedido del curso
    // anterior podía escribirse encima del nuevo resultado.
    const mia = ++version.current;
    try {
      const d = await asistenciaApi.historial(params);
      if (mia === version.current) setData(d);
    } catch (e) {
      if (mia !== version.current) return;
      setError(mensajeAmable(e, "No se pudo consultar el historial. Revisá tu conexión."));
      setData(null);
    } finally {
      if (mia === version.current) setLoading(false);
    }
  }

  // El pedido de Inasistencias se aplica una sola vez por alumno: si se vuelve
  // a la pestaña, no se pisa lo que se cambió a mano.
  const pedidoAplicado = useRef(null);
  useEffect(() => {
    if (!initial?.dni) return;
    const firma = `${initial.dni}|${initial.desde || ""}`;
    if (pedidoAplicado.current === firma) return;
    pedidoAplicado.current = firma;
    setModo("alumno");
    setAlumnoDni(String(initial.dni).replace(/\D/g, ""));
    if (initial.desde) setDesde(String(initial.desde));
    setHasta(hoyLocal());
    setBuscarSolo(true);
  }, [initial]);

  useEffect(() => {
    if (!buscarSolo) return;
    setBuscarSolo(false);
    buscar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [buscarSolo]);

  const resumenCards = (r) => [
    ["Clases dictadas", r?.clases_dictadas ?? 0],
    ["Presentes", r?.presentes ?? 0],
    ["Ausentes", r?.ausentes ?? 0],
    ["Con justificativo", r?.justificados ?? 0],
    ["Llegadas tarde", r?.tardanzas ?? 0],
    ["% asistencia", fmtPct(r?.porcentaje)],
  ];

  function descargarCSV() {
    if (!data) return;
    let filas = [];
    let nombre = `historial-asistencia-${desde}-${hasta}.csv`;
    if (data.tipo === "alumno" && data.alumno) {
      const a = data.alumno;
      filas = (data.detalle || []).map((r) => ({
        Apellido: a.apellido || "",
        Nombre: a.nombre || "",
        DNI: a.dni || r.dni || "",
        Fecha: r.fecha || "",
        "N° clase": r.numero_clase ?? "",
        Curso: r.curso || cursoLabel(a.anio, a.division),
        Estado: r.estado || "",
        Anotación: r.anotacion || "",
      }));
      nombre = `historial-${a.apellido}-${a.nombre}-${desde}-${hasta}.csv`
        .normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-zA-Z0-9._-]+/g, "-");
    } else {
      filas = (data.detalle || []).map((r) => ({
        Apellido: r.apellido || "",
        Nombre: r.nombre || "",
        DNI: r.dni || "",
        Fecha: r.fecha || "",
        "N° clase": r.numero_clase ?? "",
        Curso: r.curso || "",
        Estado: r.estado || "",
        Anotación: r.anotacion || "",
      }));
      const c = data.curso || {};
      nombre = `historial-${c.anio || ""}-${c.division || ""}-${desde}-${hasta}.csv`.replace(/[^a-zA-Z0-9._-]+/g, "-");
    }
    if (!filas.length) { showToast("No hay registros para descargar.", "info"); return; }
    exportCSV(filas, nombre);
    showToast(mensajeDescarga(nombre), "success");
  }

  // Cambiar de consulta descarta lo que está en vuelo: sin esto, la respuesta
  // del curso anterior terminaba escribiéndose encima de la pantalla nueva.
  const descartar = () => {
    version.current++;
    setData(null);
    setError(null);
    setLoading(false);
    setBuscado(false);
  };

  return (
    <div className="hist-container">
      <div className="hist-filters-card">
        <div className="hist-filters-row">
          <div>
            <label className="hist-label">Consultar</label>
            <div className="hist-segmented">
              <button
                type="button"
                onClick={() => { setModo("curso"); descartar(); }}
                className={`hist-seg-btn ${modo === "curso" ? "hist-seg-btn-active" : "hist-seg-btn-inactive"}`}
              >
                <Users className="hist-seg-icon" /> Por curso
              </button>
              <button
                type="button"
                onClick={() => { setModo("alumno"); descartar(); }}
                className={`hist-seg-btn ${modo === "alumno" ? "hist-seg-btn-active" : "hist-seg-btn-inactive"}`}
              >
                <User2 className="hist-seg-icon" /> Por alumno
              </button>
            </div>
          </div>

          {modo === "curso" ? (
            <CoursePicker maps={maps} value={curso} onChange={setCurso} />
          ) : (
            <div>
              <label className="hist-label">
                DNI del alumno
              </label>
              <input
                value={alumnoDni}
                onChange={(e) => setAlumnoDni(e.target.value.replace(/\D/g, "").slice(0, 8))}
                onKeyDown={(e) => e.key === "Enter" && buscar()}
                placeholder="Ej: 40123456"
                inputMode="numeric"
                className="hist-input-alumno"
              />
            </div>
          )}

          <div>
            <label className="hist-label">Desde</label>
            <input type="date" value={desde} onChange={(e) => setDesde(e.target.value)} className="hist-input-date" />
          </div>
          <div>
            <label className="hist-label">Hasta</label>
            <input type="date" value={hasta} onChange={(e) => setHasta(e.target.value)} className="hist-input-date" />
          </div>

          <button
            type="button"
            onClick={buscar}
            className="hist-btn-search"
          >
            <Search className="hist-btn-icon" /> Buscar
          </button>
          {!loading && data && (
            <button
              type="button"
              onClick={descargarCSV}
              className="hist-btn-search"
              title="Descargar lo que estás viendo"
            >
              <Download className="hist-btn-icon" /> Descargar CSV
            </button>
          )}
        </div>
      </div>

      {loading && (
        <div className="hist-loading">
          <Loader className="hist-loading-icon" /> Consultando…
        </div>
      )}
      {error && !loading && (
        <div className="hist-error">{error}</div>
      )}

      {!loading && !error && data && (
        <>
          {data.recortado && (
            <div className="asist-nota">
              {`Con ese filtro hay ${data.total_filas} marcas. Los totales y porcentajes están calculados sobre todas; la lista de abajo muestra las ${(data.detalle || []).length} más recientes.`}
            </div>
          )}
          {data.tipo === "alumno" ? (
            <>
              <div className="hist-alumno-card">
                <User2 className="hist-alumno-avatar" />
                <div>
                  <div className="hist-alumno-name">
                    {data.alumno?.apellido}, {data.alumno?.nombre}
                  </div>
                  <div className="hist-alumno-sub">
                    {data.alumno?.dni ? `DNI ${data.alumno.dni} · ` : ""}{cursoLabel(data.alumno?.anio, data.alumno?.division)}
                  </div>
                </div>
              </div>
              <RenderResumen cards={resumenCards(data.resumen)} />
              <DetalleTable rows={data.detalle || []} />
            </>
          ) : (
            <>
              <div className="hist-curso-card">
                <div className="hist-curso-name">{cursoLabel(data.curso?.anio, data.curso?.division)}</div>
                <div className="hist-curso-sub">{data.alumnos?.length ?? 0} alumnos · {data.totales?.clases_dictadas ?? 0} clases</div>
              </div>

              <div className="hist-table-card">
                <div className="hist-table-wrap">
                  <table className="hist-table">
                    <thead>
                      <tr className="hist-thead-row">
                        <th className="hist-th">Apellido</th>
                        <th className="hist-th">Nombre</th>
                        <th className="hist-th">DNI</th>
                        <th className="hist-th">Clases relevadas</th>
                        <th className="hist-th">Presentes</th>
                        <th className="hist-th">Ausentes</th>
                        <th className="hist-th">Con justificativo</th>
                        <th className="hist-th">Con tardanza</th>
                        <th className="hist-th">% asistencia</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(data.alumnos || []).map((a) => (
                        <tr key={a.alumno_id} className="hist-row">
                          <td className="hist-td hist-td-medium">{a.apellido}</td>
                          <td className="hist-td">{a.nombre}</td>
                          <td className="hist-td hist-td-muted">{a.dni || "—"}</td>
                          <td className="hist-td hist-td-muted">{a.clases}</td>
                          <td className="hist-td hist-td-green">{a.presentes}</td>
                          <td className="hist-td hist-td-red">{a.ausentes}</td>
                          <td className="hist-td hist-td-amber">{a.justificados}</td>
                          <td className="hist-td hist-td-blue">{a.tardanzas}</td>
                          <td className="hist-td hist-td-medium">{fmtPct(a.porcentaje)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              <RenderResumen cards={resumenCards(data.totales)} />
              <DetalleTable rows={data.detalle || []} />
            </>
          )}
        </>
      )}

      {!loading && !error && !data && !buscado && (
        <EmptyState
          icon={History}
          title="Historial de asistencia"
          description="Consultá el historial por curso o por alumno y filtrá por rango de fechas."
        />
      )}
      {avisoModal}
    </div>
  );
}

function RenderResumen({ cards }) {
  return (
    <div className="hist-resumen-grid">
      {cards.map(([label, val]) => (
        <div key={label} className="hist-resumen-card">
          <div className="hist-resumen-label">{label}</div>
          <div className="hist-resumen-value">{val}</div>
        </div>
      ))}
    </div>
  );
}

function DetalleTable({ rows }) {
  return (
    <div className="hist-table-card">
      <div className="hist-table-title">
        Detalle por fecha
      </div>
      <p className="hist-criterio">
        El % de asistencia es (presentes + tardanzas) sobre las clases relevadas: llegar
        tarde no es falta. El justificativo sí cuenta como falta, aunque tenga motivo aprobado.
      </p>
      <div className="hist-table-wrap">
        <table className="hist-table">
          <thead>
              <tr className="hist-thead-row">
              <th className="hist-th">Fecha</th>
              <th className="hist-th">N° clase</th>
              <th className="hist-th">Curso</th>
              <th className="hist-th">Alumno</th>
              <th className="hist-th">DNI</th>
              <th className="hist-th">Estado</th>
              <th className="hist-th">Anotación</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.asistencia_id ?? `${r.fecha}-${r.alumno_id}`} className="hist-row">
                <td className="hist-td">{fmtFecha(r.fecha)}</td>
                <td className="hist-td">{r.numero_clase ?? "—"}</td>
                <td className="hist-td">{cursoLabel(r.anio, r.division)}</td>
                <td className="hist-td">{r.apellido ? `${r.apellido}, ${r.nombre}` : "—"}</td>
                <td className="hist-td hist-td-muted">{r.dni || "—"}</td>
                <td className="hist-td"><EstadoChip estado={r.estado} /></td>
                <td className="hist-td hist-td-muted">{r.anotacion || "—"}</td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={7} className="hist-empty-row">
                  Sin registros en el período seleccionado.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}