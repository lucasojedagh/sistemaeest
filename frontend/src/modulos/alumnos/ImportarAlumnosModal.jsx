// ===============================
// FILE: src/modulos/alumnos/ImportarAlumnosModal.jsx
// ===============================
import React, { useEffect, useRef, useState } from "react";
import {
  AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Loader2, Upload, X,
} from "lucide-react";
import { apiBlob, apiUpload, apiUploadBlob } from "../../compartido/lib/api";
import { descargarBlob, mensajeDescarga } from "../../compartido/lib/descarga";
import { mensajeAmable } from "../../compartido/lib/errores";
import useCierreAnimado from "../../compartido/lib/useCierreAnimado";
import { bloquearScroll } from "../../compartido/lib/lockScroll";
import { useToast } from "../../compartido/componentes/Common/Toast";
import "./ImportarAlumnosModal.css";

// Componente: ImportarAlumnosModal
// Proposito: dar de alta el padrón desde un Excel en dos pasos: primero se
//            revisa el archivo (no escribe nada) y después se importa.
//            Los alumnos que ya existen por DNI se omiten: nunca se pisan datos.
// Props:
//   visible     - Si el modal está abierto.
//   onClose     - Cerrar.
//   onTerminado - Se llama cuando la importación escribió algo, para refrescar la lista.

const ETIQUETAS = {
  nueva: { texto: "Se da de alta", clase: "iam-ok" },
  existente: { texto: "Ya estaba", clase: "iam-aviso" },
  rechazada: { texto: "Rechazada", clase: "iam-mal" },
};

const ImportarAlumnosModal = ({ visible, onClose, onTerminado }) => {
  const showToast = useToast();
  const inputRef = useRef(null);
  const [paso, setPaso] = useState("archivo");
  const [archivo, setArchivo] = useState(null);
  const [ocupado, setOcupado] = useState("");
  const [error, setError] = useState("");
  const [previa, setPrevia] = useState(null);
  const [resultado, setResultado] = useState(null);
  const { montado, cerrando } = useCierreAnimado(visible);

  /* Al cerrar se vuelve al principio: la próxima vez no muestra datos viejos. */
  useEffect(() => {
    if (!visible) return;
    setPaso("archivo");
    setArchivo(null);
    setPrevia(null);
    setResultado(null);
    setError("");
    setOcupado("");
  }, [visible]);

  useEffect(() => {
    if (!visible) return;
    const onKey = (e) => e.key === "Escape" && onClose?.();
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("keydown", onKey);
    };
  }, [visible, onClose]);

  useEffect(() => {
    if (!montado) return;
    return bloquearScroll();
  }, [montado]);

  const mensajeError = (e, cta) => {
    const datos = e && e.data && e.data.errors && e.data.errors.archivo;
    if (datos && datos.length) return datos[0];
    return mensajeAmable(e, cta);
  };

  const descargarPlantilla = async () => {
    setOcupado("plantilla");
    try {
      const blob = await apiBlob("/alumnos/importar/plantilla");
      descargarBlob(blob, "plantilla_alumnos.xlsx");
      showToast(mensajeDescarga("plantilla_alumnos.xlsx"), "success");
    } catch (e) {
      showToast(mensajeError(e, "No se pudo bajar la plantilla."), "error");
    } finally {
      setOcupado("");
    }
  };

  const elegirArchivo = (f) => {
    if (!f) return;
    setArchivo(f);
    setPrevia(null);
    setResultado(null);
    setError("");
    setPaso("archivo");
  };

  const revisar = async () => {
    if (!archivo) return;
    setOcupado("preview");
    setError("");
    try {
      const fd = new FormData();
      fd.append("archivo", archivo);
      const data = await apiUpload("/alumnos/importar/preview", fd);
      // Archivo inservible (sin encabezado o sin columnas obligatorias): no hay
      // nada que revisar, así que el aviso se muestra en el paso 1, junto al
      // botón para elegir otro archivo, en vez de una pantalla llena de ceros.
      const t = data?.totales || {};
      if (data?.errores_generales?.length && !t.nuevas && !t.existentes && !t.rechazadas) {
        setError(data.errores_generales.join(" "));
        return;
      }
      setPrevia(data);
      setPaso("previa");
    } catch (e) {
      setError(mensajeError(e, "No se pudo leer la planilla. Fijate que esté guardada como .xlsx."));
    } finally {
      setOcupado("");
    }
  };

  const importar = async () => {
    if (!archivo) return;
    setOcupado("aplicar");
    setError("");
    try {
      const fd = new FormData();
      fd.append("archivo", archivo);
      fd.append("confirmar", "1");
      const data = await apiUpload("/alumnos/importar/aplicar", fd);
      setResultado(data);
      setPaso("listo");
      if (data.creados > 0) {
        showToast(`${data.creados} alumnos dados de alta`, "success");
        onTerminado?.();
      }
    } catch (e) {
      setError(mensajeError(e, "No se pudo completar la importación. Probá de nuevo."));
    } finally {
      setOcupado("");
    }
  };

  const bajarReporte = async () => {
    if (!archivo) return;
    setOcupado("reporte");
    try {
      const fd = new FormData();
      fd.append("archivo", archivo);
      const blob = await apiUploadBlob("/alumnos/importar/reporte", fd);
      descargarBlob(blob, "reporte_importacion_alumnos.xlsx");
      showToast(mensajeDescarga("reporte_importacion_alumnos.xlsx"), "success");
    } catch (e) {
      showToast(mensajeError(e, "No se pudo generar el reporte."), "error");
    } finally {
      setOcupado("");
    }
  };

  const rechazadas = (previa?.filas || []).filter((f) => f.estado === "rechazada");
  const nuevas = previa?.totales?.nuevas ?? 0;

  return (
    <>
      {montado && (
        <div className={`iam-backdrop sys-capa${cerrando ? " sys-cerrando" : ""}`}>
          <div
            className={`iam-dialog sys-dialogo${cerrando ? " sys-cerrando" : ""}`}
            role="dialog"
            aria-modal="true"
            aria-label="Importar alumnos desde Excel"
          >
            <div className="iam-header">
              <div className="iam-header-icon">
                <FileSpreadsheet size={22} />
              </div>
              <div className="iam-header-texto">
                <h2 className="iam-title">Importar alumnos desde Excel</h2>
                <p className="iam-subtitle">
                  Paso {paso === "archivo" ? 1 : paso === "previa" ? 2 : 3} de 3:
                  planilla, revisión y alta
                </p>
              </div>
              <button onClick={onClose} aria-label="Cerrar" className="iam-close">
                <X size={20} />
              </button>
            </div>

            <div className="iam-scroll">
              {paso === "archivo" && (
                <>
                  <p className="iam-texto">
                    Bajá la plantilla, completá una fila por alumno y subila.
                    El sistema primero te muestra qué va a pasar y{" "}
                    <strong>no escribe nada hasta que confirmes</strong>.
                  </p>

                  <button
                    type="button"
                    className="iam-btn iam-btn-secundario"
                    onClick={descargarPlantilla}
                    disabled={ocupado === "plantilla"}
                  >
                    {ocupado === "plantilla" ? <Loader2 size={16} className="iam-spin" /> : <Download size={16} />}
                    <span>Descargar plantilla (.xlsx)</span>
                  </button>

                  <div className="iam-zona">
                    <input
                      ref={inputRef}
                      type="file"
                      accept=".xlsx,.xls,.ods"
                      className="iam-input-archivo"
                      onChange={(e) => elegirArchivo(e.target.files?.[0] || null)}
                    />
                    <div className="iam-zona-contenido">
                      <Upload size={22} />
                      <div className="iam-zona-titulo">
                        {archivo ? archivo.name : "Elegí la planilla con los alumnos"}
                      </div>
                      <div className="iam-zona-nota">
                        Formatos: .xlsx (recomendado), .xls o .ods. Máximo 20 MB.
                      </div>
                      <button
                        type="button"
                        className="iam-btn iam-btn-secundario"
                        onClick={() => inputRef.current?.click()}
                      >
                        <span>Buscar archivo</span>
                      </button>
                    </div>
                  </div>

                  {error && <div className="iam-error">{error}</div>}

                  <button
                    type="button"
                    className="iam-btn iam-btn-primario"
                    onClick={revisar}
                    disabled={!archivo || ocupado === "preview"}
                  >
                    {ocupado === "preview" ? <Loader2 size={16} className="iam-spin" /> : <CheckCircle2 size={16} />}
                    <span>{ocupado === "preview" ? "Revisando la planilla..." : "Revisar planilla"}</span>
                  </button>
                </>
              )}

              {paso === "previa" && previa && (
                <>
                  <div className="iam-resumen">
                    <div className="iam-dato iam-dato-ok">
                      <div className="iam-dato-valor">{previa.totales.nuevas}</div>
                      <div className="iam-dato-label">se dan de alta</div>
                    </div>
                    <div className="iam-dato">
                      <div className="iam-dato-valor">{previa.totales.existentes}</div>
                      <div className="iam-dato-label">ya estaban (se omiten)</div>
                    </div>
                    <div className="iam-dato iam-dato-mal">
                      <div className="iam-dato-valor">{previa.totales.rechazadas}</div>
                      <div className="iam-dato-label">con errores</div>
                    </div>
                  </div>

                  {previa.errores_generales?.length > 0 && (
                    <div className="iam-error">
                      {previa.errores_generales.map((t) => (
                        <div key={t}>{t}</div>
                      ))}
                    </div>
                  )}

                  {previa.avisos?.length > 0 && (
                    <div className="iam-avisos">
                      {previa.avisos.map((t) => (
                        <div key={t}>{t}</div>
                      ))}
                    </div>
                  )}

                  {rechazadas.length > 0 && (
                    <div className="iam-bloque">
                      <div className="iam-bloque-titulo">
                        <AlertTriangle size={16} />
                        <span>Filas con errores</span>
                      </div>
                      <div className="iam-tabla-wrap">
                        <table className="iam-tabla">
                          <thead>
                            <tr>
                              <th>Fila</th>
                              <th>Alumno</th>
                              <th>Qué falta o qué está mal</th>
                            </tr>
                          </thead>
                          <tbody>
                            {rechazadas.slice(0, 100).map((f) => (
                              <tr key={f.fila}>
                                <td>{f.fila}</td>
                                <td>{`${f.apellido || "—"}, ${f.nombre || "—"}`}</td>
                                <td>{f.errores.join(" · ")}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                      {rechazadas.length > 100 && (
                        <p className="iam-nota">
                          Se muestran las primeras 100; el reporte en Excel tiene todas.
                        </p>
                      )}
                    </div>
                  )}

                  {nuevas > 0 && (
                    <div className="iam-bloque">
                      <div className="iam-bloque-titulo">
                        <CheckCircle2 size={16} />
                        <span>Cómo vienen las altas</span>
                      </div>
                      <div className="iam-tabla-wrap iam-tabla-wrap-corta">
                        <table className="iam-tabla">
                          <tbody>
                            {(previa.filas || [])
                              .filter((f) => f.estado !== "rechazada")
                              .slice(0, 40)
                              .map((f) => (
                                <tr key={`${f.fila}-${f.estado}`}>
                                  <td>{f.fila}</td>
                                  <td>{`${f.apellido}, ${f.nombre}`}</td>
                                  <td>{f.curso}</td>
                                  <td>
                                    <span className={`iam-chip ${ETIQUETAS[f.estado].clase}`}>
                                      {ETIQUETAS[f.estado].texto}
                                    </span>
                                  </td>
                                </tr>
                              ))}
                          </tbody>
                        </table>
                      </div>
                      {previa.filas.length > 40 && (
                        <p className="iam-nota">Vista previa de las primeras 40 filas de {previa.leyo}.</p>
                      )}
                    </div>
                  )}

                  {error && <div className="iam-error">{error}</div>}

                  <div className="iam-acciones">
                    <button
                      type="button"
                      className="iam-btn iam-btn-secundario"
                      onClick={() => setPaso("archivo")}
                    >
                      <span>Elegir otro archivo</span>
                    </button>
                    {rechazadas.length > 0 && (
                      <button
                        type="button"
                        className="iam-btn iam-btn-secundario"
                        onClick={bajarReporte}
                        disabled={ocupado === "reporte"}
                      >
                        {ocupado === "reporte" ? <Loader2 size={16} className="iam-spin" /> : <Download size={16} />}
                        <span>Reporte de errores</span>
                      </button>
                    )}
                    <button
                      type="button"
                      className="iam-btn iam-btn-primario"
                      onClick={importar}
                      disabled={nuevas === 0 || ocupado === "aplicar"}
                    >
                      {ocupado === "aplicar" ? <Loader2 size={16} className="iam-spin" /> : <CheckCircle2 size={16} />}
                      <span>
                        {ocupado === "aplicar"
                          ? "Dando de alta..."
                          : `Dar de alta ${nuevas} ${nuevas === 1 ? "alumno" : "alumnos"}`}
                      </span>
                    </button>
                  </div>
                  <p className="iam-nota">
                    Los alumnos que ya están en el sistema se dejan intactos: la
                    importación solo agrega los que faltan.
                  </p>
                </>
              )}

              {paso === "listo" && resultado && (
                <>
                  <div className="iam-exito">
                    <CheckCircle2 size={22} />
                    <div>
                      <div className="iam-exito-titulo">
                        {resultado.creados === 0
                          ? "No hubo nada para dar de alta"
                          : `${resultado.creados} ${resultado.creados === 1 ? "alumno dado" : "alumnos dados"} de alta`}
                      </div>
                      <div className="iam-exito-detalle">
                        {resultado.creados === 0
                          ? "Todos los alumnos de la planilla ya estaban en el sistema."
                          : `Se omitieron ${resultado.totales.existentes} que ya existían y ${resultado.totales.rechazadas} con errores.`}
                      </div>
                    </div>
                  </div>

                  {resultado.avisos?.length > 0 && (
                    <div className="iam-avisos">
                      {resultado.avisos.map((t) => (
                        <div key={t}>{t}</div>
                      ))}
                    </div>
                  )}

                  {resultado.ejemplos?.length > 0 && (
                    <p className="iam-nota">Por ejemplo: {resultado.ejemplos.join(", ")}.</p>
                  )}

                  {resultado.rechazadas?.length > 0 && (
                    <div className="iam-bloque">
                      <div className="iam-bloque-titulo">
                        <AlertTriangle size={16} />
                        <span>{resultado.rechazadas.length} filas no se importaron</span>
                      </div>
                      <div className="iam-tabla-wrap iam-tabla-wrap-corta">
                        <table className="iam-tabla">
                          <tbody>
                            {resultado.rechazadas.slice(0, 40).map((r) => (
                              <tr key={r.fila}>
                                <td>{r.fila}</td>
                                <td>{r.alumno || "—"}</td>
                                <td>{(r.motivos || []).join(" · ")}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                      <button
                        type="button"
                        className="iam-btn iam-btn-secundario"
                        onClick={bajarReporte}
                        disabled={ocupado === "reporte"}
                      >
                        {ocupado === "reporte" ? <Loader2 size={16} className="iam-spin" /> : <Download size={16} />}
                        <span>Descargar reporte completo</span>
                      </button>
                    </div>
                  )}

                  <div className="iam-acciones">
                    <button
                      type="button"
                      className="iam-btn iam-btn-secundario"
                      onClick={() => { setPaso("archivo"); setArchivo(null); setPrevia(null); setResultado(null); }}
                    >
                      <span>Importar otra planilla</span>
                    </button>
                    <button type="button" className="iam-btn iam-btn-primario" onClick={onClose}>
                      <span>Listo</span>
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default ImportarAlumnosModal;
