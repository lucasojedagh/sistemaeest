// ===============================
// FILE: src/compartido/componentes/modals/ManualModal.jsx
// ===============================
import React, { useEffect, useState } from "react";
import { BookOpen, Download, ExternalLink, Loader2, X } from "lucide-react";
import { apiGet, apiBlob } from "../../lib/api";
import { descargarBlob, mensajeDescarga, verPdfBlob } from "../../lib/descarga";
import { mensajeAmable } from "../../lib/errores";
import { formatearFecha } from "../../lib/fechas";
import useCierreAnimado from "../../lib/useCierreAnimado";
import { bloquearScroll } from "../../lib/lockScroll";
import { puede } from "../../lib/permisos";
import { useToast } from "../Common/Toast";
import { useAuth } from "../../../modulos/auth/AuthContext";
import "./ManualModal.css";

// Componente: ManualModal
// Proposito: abrir los manuales del sistema (PDF) sin salir de la app ni pedirles
//            la ruta a nadie. La lista la decide el servidor segun el rol.
// Props:
//   open    - Booleano que controla la visibilidad del modal.
//   onClose - Funcion para cerrar el modal.

// El build copia el manual de usuario y su índice dentro del dist (ver
// vite.config.js). Si el server no tiene la carpeta docs/, la lista se lee de
// ahí y el PDF se pide como archivo estático.
const urlEstatica = (ruta) => `${import.meta.env.BASE_URL || "/"}${ruta}`;

async function listaDelDist() {
  const res = await fetch(urlEstatica("manuales/indice.json"), { cache: "no-cache" });
  if (!res.ok) return [];
  const data = await res.json();
  return ((data && data.manuales) || []).map((m) => ({ ...m, estatico: true }));
}

const ManualModal = ({ open, onClose }) => {
  const showToast = useToast();
  const { user } = useAuth();
  const [manuales, setManuales] = useState(null);
  const [error, setError] = useState("");
  const [aviso, setAviso] = useState("");
  const [ocupado, setOcupado] = useState(null);
  const { montado, cerrando } = useCierreAnimado(open);

  useEffect(() => {
    if (!open) return;
    let vivo = true;
    setManuales(null);
    setError("");
    setAviso("");
    (async () => {
      let lista = [];
      let falloApi = null;
      try {
        const data = await apiGet("/manuales");
        lista = (data && data.manuales) || [];
      } catch (e) {
        falloApi = e;
      }
      // Sin respuesta o sin PDFs en el server: se usa lo que trae el propio dist
      // (solo el manual de usuario; el técnico no sale del backend).
      if (!lista.length && puede(user, "manuales.ver")) {
        try {
          lista = await listaDelDist();
        } catch {
          lista = [];
        }
      }
      if (!vivo) return;
      if (!lista.length) {
        if (falloApi) setError(mensajeAmable(falloApi, "No se pudo leer la lista de manuales."));
        else setAviso("Todavía no hay manuales cargados en el servidor.");
        setManuales([]);
        return;
      }
      setManuales(lista);
    })();
    return () => {
      vivo = false;
    };
  }, [open, user]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e) => e.key === "Escape" && onClose?.();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  useEffect(() => {
    if (!montado) return;
    return bloquearScroll();
  }, [montado]);

  const pedirPdf = async (m) => {
    setOcupado(m.clave);
    try {
      if (m.estatico) {
        const res = await fetch(urlEstatica(m.url), { cache: "no-cache" });
        if (!res.ok) throw new Error(`El manual ${m.clave} no está en el servidor.`);
        const raw = await res.blob();
        // Algunos servers entregan el PDF como octet-stream y el navegador lo
        // descarga en vez de abrirlo: el tipo lo ponemos nosotros.
        return raw.type === "application/pdf" ? raw : new Blob([raw], { type: "application/pdf" });
      }
      return await apiBlob(`/manuales/${m.clave}`);
    } catch (e) {
      showToast(mensajeAmable(e, "No se pudo abrir el manual. Revisá tu conexión."), "error");
      return null;
    } finally {
      setOcupado(null);
    }
  };

  const handleVer = async (m) => {
    const blob = await pedirPdf(m);
    if (!blob) return;
    const r = verPdfBlob(blob, m.archivo);
    if (r.bloqueado) showToast(mensajeDescarga(m.archivo), "success");
  };

  const handleDescargar = async (m) => {
    const blob = await pedirPdf(m);
    if (!blob) return;
    descargarBlob(blob, m.archivo);
    showToast(mensajeDescarga(m.archivo), "success");
  };

  return (
    <>
      {montado && (
        <div className={`mm-backdrop sys-capa${cerrando ? " sys-cerrando" : ""}`}>
          <div
            className={`mm-dialog sys-dialogo${cerrando ? " sys-cerrando" : ""}`}
            role="dialog"
            aria-modal="true"
            aria-label="Manuales del sistema"
          >
            <div className="mm-header">
              <div className="mm-header-icon">
                <BookOpen size={22} />
              </div>
              <div className="mm-header-texto">
                <h2 className="mm-title">Manuales del sistema</h2>
                <p className="mm-subtitle">Guías en PDF, sin salir de la app</p>
              </div>
              <button onClick={onClose} aria-label="Cerrar" className="mm-close">
                <X size={20} />
              </button>
            </div>

            <div className="mm-scroll">
              {!manuales && !error && (
                <div className="mm-estado">
                  <Loader2 size={18} className="mm-spin" />
                  <span>Buscando los manuales...</span>
                </div>
              )}

              {error && <div className="mm-error">{error}</div>}

              {manuales && manuales.length === 0 && !error && (
                <div className="mm-vacio">{aviso}</div>
              )}

              {manuales && manuales.map((m) => (
                <div key={m.clave} className="mm-card">
                  <div className="mm-card-titulo">{m.titulo}</div>
                  <p className="mm-card-desc">{m.descripcion}</p>
                  <div className="mm-card-meta">
                    <span>PDF</span>
                    <span className="mm-meta-sep">•</span>
                    <span>{m.tamano_kb} KB</span>
                    <span className="mm-meta-sep">•</span>
                    <span>Actualizado {formatearFecha(m.actualizado)}</span>
                  </div>
                  <div className="mm-card-botones">
                    <button
                      type="button"
                      className="mm-btn mm-btn-primario"
                      onClick={() => handleVer(m)}
                      disabled={ocupado === m.clave}
                    >
                      {ocupado === m.clave ? <Loader2 size={16} className="mm-spin" /> : <ExternalLink size={16} />}
                      <span>Ver</span>
                    </button>
                    <button
                      type="button"
                      className="mm-btn mm-btn-secundario"
                      onClick={() => handleDescargar(m)}
                      disabled={ocupado === m.clave}
                    >
                      <Download size={16} />
                      <span>Descargar</span>
                    </button>
                  </div>
                </div>
              ))}

              <p className="mm-nota">
                Si falta algo o el manual no está actualizado, avisá a dirección:
                los PDF se cargan en la carpeta de documentación del sistema.
              </p>
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default ManualModal;
