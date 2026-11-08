import React, { useEffect, useState, useRef } from "react";
import { FileText, Upload, Download, Printer, Trash2, Search, Loader2, File, FileSpreadsheet, FileImage, FileArchive, Check, Pencil, X } from "lucide-react";
import { useAuth } from "../auth/AuthContext";
import Breadcrumbs from "../../compartido/componentes/Common/Breadcrumbs";
import EmptyState from "../../compartido/componentes/Common/EmptyState";
import ErrorState from "../../compartido/componentes/Common/ErrorState";
import { useToast } from "../../compartido/componentes/Common/Toast";
import { apiGet, apiUpload, apiBlob, apiDelete, apiPut } from "../../compartido/lib/api";
import { descargarBlob, mensajeDescarga } from "../../compartido/lib/descarga";
import { mensajeAmable } from "../../compartido/lib/errores";
import { formatearFecha } from "../../compartido/lib/fechas";
import useAviso from "../../compartido/componentes/modals/useAviso";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import useAvisoDeCambios from "../../compartido/hooks/useAvisoDeCambios";
import { esGestion } from "../auth/roles";
import { accionHabilitada } from "../../compartido/lib/permisos";
import "./Documentos.css";

const fileIcon = (mime) => {
  if (!mime) return <File size={20} />;
  if (mime.includes("pdf")) return <FileText size={20} className="doc-icon-pdf" />;
  if (mime.includes("sheet") || mime.includes("excel") || mime.includes("csv")) return <FileSpreadsheet size={20} className="doc-icon-sheet" />;
  if (mime.includes("image")) return <FileImage size={20} className="doc-icon-image" />;
  if (mime.includes("zip") || mime.includes("rar")) return <FileArchive size={20} className="doc-icon-archive" />;
  return <File size={20} className="doc-icon-generic" />;
};

const MAX_DOCUMENTO_MB = 50;
const MAX_DOCUMENTO_BYTES = MAX_DOCUMENTO_MB * 1048576;

const formatSize = (bytes) => {
  if (!bytes) return "—";
  if (bytes < 1024) return bytes + " B";
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
  return (bytes / 1048576).toFixed(1) + " MB";
};

const formatDate = (date) => {
  if (!date) return "—";
  const d = new Date(date);
  if (Number.isNaN(d.getTime())) return "—";
  return `${formatearFecha(date)} ${d.toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit" })}`;
};

// Roles que pueden ver un documento + etiquetas para la UI
const ROLES_DOC = [
  { id: "admin", label: "Administradores" },
  { id: "directivo", label: "Directivos" },
  { id: "jefe_preceptores", label: "Jefe de preceptores" },
  { id: "prosecretaria", label: "Prosecretaria" },
  { id: "preceptor", label: "Preceptores" },
  { id: "profesor", label: "Profesores" },
];
const ROLES_DOC_DEFAULT = ["admin", "jefe_preceptores", "preceptor"];
const rolDocLabel = (id) => ROLES_DOC.find((r) => r.id === id)?.label || id;

// Caché en memoria (a nivel de módulo) para no refetchiar al reingresar al módulo.
const DOCS_CACHE_TTL = 5 * 60 * 1000; // 5 minutos
const docsCache = new Map(); // qs -> { docs, ts }

export default function Documentos() {
  const { user } = useAuth();
  const showToast = useToast();
  const [, pedirConfirmacion, avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const fileRef = useRef(null);
  // Cada botón de la fila tiene su propia casilla del catálogo. Sin lista
  // guardada en el rol manda la regla de siempre: preceptor para arriba.
  const rangoGestionaDocumentos = esGestion(user?.role) || user?.role === "preceptor";
  const puedeSubir = accionHabilitada(user, 'documentos.subir', rangoGestionaDocumentos);
  const puedeEditarDoc = accionHabilitada(user, 'documentos.editar', rangoGestionaDocumentos);
  const puedeEliminarDoc = accionHabilitada(user, 'documentos.eliminar', rangoGestionaDocumentos);
  const puedeDescargar = accionHabilitada(user, 'documentos.descargar', true);
  const puedeImprimir = accionHabilitada(user, 'documentos.imprimir', true);

  const [docs, setDocs] = useState(docsCache.get("")?.docs || []);
  const [loading, setLoading] = useState(!docsCache.has(""));
  const [search, setSearch] = useState("");
  const [subiendo, setSubiendo] = useState(false);
  const [showUpload, setShowUpload] = useState(false);
  const [uploadName, setUploadName] = useState("");
  const [uploadFile, setUploadFile] = useState(null);
  // Roles con visibilidad del documento a subir (por defecto: admins, jefe y preceptores)
  const [uploadRoles, setUploadRoles] = useState(ROLES_DOC_DEFAULT);
  const [editando, setEditando] = useState(null); // doc en edicion
  const [editName, setEditName] = useState("");
  const [editRoles, setEditRoles] = useState([]);
  const [guardando, setGuardando] = useState(false);
  // Aviso cuando la lista no pudo leerse (distinto de "todavía no hay documentos").
  const [errorCarga, setErrorCarga] = useState(null);

  const fetchDocs = async () => {
    const qs = search ? `?search=${encodeURIComponent(search)}` : "";
    const cached = docsCache.get(qs);
    if (cached && Date.now() - cached.ts < DOCS_CACHE_TTL) {
      setDocs(cached.docs);
      setErrorCarga(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      const data = await apiGet(`/documentos${qs}`);
      const docsData = data?.data || data || [];
      setDocs(docsData);
      docsCache.set(qs, { docs: docsData, ts: Date.now() });
      setErrorCarga(null);
    } catch (e) {
      setErrorCarga(mensajeAmable(e, "No se pudieron cargar los documentos."));
      showToast("No se pudieron cargar los documentos. Revisá tu conexión.", "error");
    }
    setLoading(false);
  };

  useEffect(() => {
    fetchDocs();
  }, []);

  useEffect(() => {
    const timer = setTimeout(fetchDocs, 400);
    return () => clearTimeout(timer);
  }, [search]);

  // Si otra persona subió, editó o borró un documento, al volver a esta
  // pantalla se actualiza sola; el botón queda para cuando se está editando.
  const actualizarDocs = async () => {
    docsCache.clear();
    await fetchDocs();
  };
  useAvisoDeCambios("documentos", ["documentos"], actualizarDocs);

  const toggleUploadRole = (id) =>
    setUploadRoles((prev) => (prev.includes(id) ? prev.filter((r) => r !== id) : [...prev, id]));

  const openEdit = (doc) => {
    // Docs sin roles definidos (legacy) se editan como visibles para todos
    setEditRoles(Array.isArray(doc.roles) && doc.roles.length ? [...doc.roles] : ROLES_DOC.map((r) => r.id));
    setEditName(doc.nombre || "");
    setEditando(doc);
  };

  const toggleEditRole = (id) =>
    setEditRoles((prev) => (prev.includes(id) ? prev.filter((r) => r !== id) : [...prev, id]));

  const handleSaveEdit = async (e) => {
    e.preventDefault();
    if (!editando || editRoles.length === 0 || !editName.trim()) return;

    setGuardando(true);
    try {
      await apiPut(`/documentos/${editando.id}`, { nombre: editName.trim(), roles: editRoles });
      docsCache.clear();
      showToast("Documento actualizado", "success");
      setEditando(null);
      fetchDocs();
    } catch (err) {
      const msg = err?.data?.message || err?.data?.errors?.nombre?.[0] || err?.data?.errors?.roles?.[0] || "Error al guardar";
      showToast(msg, "error");
    } finally {
      setGuardando(false);
    }
  };

  const handleUpload = async (e) => {
    e.preventDefault();
    if (!uploadFile || !uploadName.trim() || uploadRoles.length === 0) return;

    setSubiendo(true);
    try {
      // Se corta acá, no en el server: un archivo de 200 MB subirse para que
      // nginx lo rechace a mitad de camino tarda minutos y no deja mensaje.
      if (uploadFile.size > MAX_DOCUMENTO_BYTES) {
        showToast(`El documento pesa más de ${MAX_DOCUMENTO_MB} MB. Comprímilo o dividilo antes de subirlo.`, "error");
        return;
      }

      const formData = new FormData();
      formData.append("nombre", uploadName.trim());
      formData.append("archivo", uploadFile);
      uploadRoles.forEach((r) => formData.append("roles[]", r));

      await apiUpload("/documentos", formData);

      docsCache.clear();
      setShowUpload(false);
      setUploadName("");
      setUploadFile(null);
      setUploadRoles(ROLES_DOC_DEFAULT);
      fetchDocs();
    } catch (err) {
      const msg = err?.data?.errors?.archivo?.[0] || err?.message || "No se pudo subir el documento. Intentá de nuevo.";
      showToast(msg, "error");
    } finally {
      setSubiendo(false);
    }
  };

  // Descarga directa, sin ventanas emergentes: el archivo queda en Descargas
  // y se avisa claramente dónde buscarlo.
  const handleDownload = async (id, nombre) => {
    try {
      const blob = await apiBlob(`/documentos/${id}/download`);
      descargarBlob(blob, nombre);
      showToast(mensajeDescarga(nombre), "success");
    } catch (err) {
      showToast(mensajeAmable(err, "No se pudo descargar. Revisá tu conexión."), "error");
    }
  };

  // Imprimir sin pelear con el navegador: imprime desde un iframe oculto
  // (los popups suelen estar bloqueados). Si falla, descarga el PDF.
  const handlePrint = async (id, nombre) => {
    try {
      const blob = await apiBlob(`/documentos/${id}/print`);
      if (blob?.type === "application/pdf" || (nombre || "").toLowerCase().endsWith(".pdf")) {
        const url = window.URL.createObjectURL(blob);
        const iframe = document.createElement("iframe");
        iframe.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0;";
        iframe.onload = () => {
          try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
          } catch {
            descargarBlob(blob, nombre);
            showToast(mensajeDescarga(nombre), "success");
          }
          setTimeout(() => { window.URL.revokeObjectURL(url); iframe.remove(); }, 60000);
        };
        iframe.onerror = () => {
          iframe.remove();
          descargarBlob(blob, nombre);
          showToast(mensajeDescarga(nombre), "success");
        };
        iframe.src = url;
        document.body.appendChild(iframe);
      } else {
        descargarBlob(blob, nombre);
        showToast(mensajeDescarga(nombre), "success");
      }
    } catch (err) {
      showToast(mensajeAmable(err, "No se pudo imprimir. Probá descargando el archivo."), "error");
    }
  };

  const handleDelete = async (id, nombre) => {
    const ok = await pedirConfirmacion({
      icon: "warning",
      title: "Eliminar documento",
      text: `¿Eliminar "${nombre}"?`,
      confirmText: "Eliminar",
      cancelText: "Cancelar",
      danger: true,
    });
    if (!ok) return;
    // Borra fila + archivo físico: el servidor exige contraseña reciente.
    if (!(await confirmarContrasena())) return;
    try {
      await apiDelete(`/documentos/${id}`);
      docsCache.clear();
      fetchDocs();
    } catch (err) {
      showToast(mensajeAmable(err, "No se pudo borrar el documento."), "error");
    }
  };

  return (
    <div className="doc-page sys-pagina">
      <div className="doc-container">
        <div className="doc-header">
          <div>
            <Breadcrumbs items={[{ label: "Documentos" }]} />
            <h1 className="doc-title">
              <FileText className="doc-title-icon" size={28} />
              Documentos
            </h1>
          </div>
          {puedeSubir && (
            <button
              onClick={() => setShowUpload(true)}
              className="doc-btn-primary"
            >
              <Upload size={18} /> Subir documento
            </button>
          )}
        </div>

        <div className="doc-search-card">
          <div className="doc-search-wrap">
            <Search className="doc-search-icon" size={20} />
            <input
              type="text" placeholder="Buscar documentos..." value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="doc-search-input"
            />
          </div>
        </div>

        {loading ? (
          <div className="doc-loading">Cargando datos...</div>
        ) : errorCarga && docs.length === 0 ? (
          <ErrorState mensaje={errorCarga} onReintentar={fetchDocs} compact />
        ) : docs.length === 0 ? (
          <EmptyState
            icon={FileText}
            title={search ? "No encontramos ese documento" : "Todavía no hay documentos"}
            description={search
              ? "Probá con menos palabras o revisá cómo está escrito el nombre."
              : "Subí el primero con el botón de arriba (resoluciones, planillas, comunicados)."}
            action={search
              ? <button type="button" className="doc-btn-secondary" onClick={() => setSearch("")}>Limpiar búsqueda</button>
              : undefined}
            compact
          />
        ) : (
          <div className="doc-table-card">
            <table className="doc-table">
              <thead className="doc-thead">
                <tr className="doc-th-row">
                  <th className="doc-th doc-th-w5"></th>
                  <th className="doc-th doc-th-w30">Nombre</th>
                  <th className="doc-th doc-th-w10">Tamaño</th>
                  <th className="doc-th doc-th-w15">Subido por</th>
                  <th className="doc-th doc-th-w15">Fecha</th>
                  <th className="doc-th doc-th-w15">Visible para</th>
                  <th className="doc-th doc-th-right doc-th-w10">Acciones</th>
                </tr>
              </thead>
              <tbody className="doc-tbody">
                {(Array.isArray(docs) ? docs : []).map((doc) => (
                  <tr key={doc.id} className="doc-tr">
                    <td className="doc-td">{fileIcon(doc.mime)}</td>
                    <td className="doc-td">
                      <span className="doc-name">{doc.nombre}</span>
                      <span className="doc-filename">{doc.nombre_archivo}</span>
                    </td>
                    <td className="doc-td doc-td-muted">{formatSize(doc.tamano)}</td>
                    <td className="doc-td doc-td-user">{doc.user?.name || "—"}</td>
                    <td className="doc-td doc-td-muted">{formatDate(doc.created_at)}</td>
                    <td className="doc-td">
                      {Array.isArray(doc.roles) && doc.roles.length ? (
                        <div className="doc-roles-wrap" tabIndex={0}>
                          <span className="doc-roles-count">
                            {doc.roles.length === 1 ? "1 rol" : `${doc.roles.length} roles`}
                          </span>
                          <div className="doc-roles-pop">
                            {doc.roles.map((r) => (
                              <span key={r} className={`doc-roles-chip${r === user?.role ? " doc-roles-chip-me" : ""}`}>
                                {rolDocLabel(r)}
                              </span>
                            ))}
                          </div>
                        </div>
                      ) : (
                        <span className="doc-td-muted">Todos</span>
                      )}
                    </td>
                    <td className="doc-td doc-td-right">
                      <div className="doc-actions">
                        {puedeDescargar && (
                          <button onClick={() => handleDownload(doc.id, doc.nombre_archivo)} className="doc-action doc-action-download" title="Descargar">
                            <Download size={16} />
                          </button>
                        )}
                        {puedeImprimir && doc.mime === "application/pdf" && (
                          <button onClick={() => handlePrint(doc.id, doc.nombre_archivo)} className="doc-action doc-action-print" title="Imprimir">
                            <Printer size={16} />
                          </button>
                        )}
                        {puedeEditarDoc && (
                          <button onClick={() => openEdit(doc)} className="doc-action doc-action-edit" title="Editar quién puede verlo">
                            <Pencil size={16} />
                          </button>
                        )}
                        {puedeEliminarDoc && (
                          <button onClick={() => handleDelete(doc.id, doc.nombre)} className="doc-action doc-action-delete" title="Eliminar">
                            <Trash2 size={16} />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {showUpload && (
        <div className="doc-modal-backdrop">
          <div className="doc-modal" onClick={(e) => e.stopPropagation()}>
            <div className="doc-modal-header">
              <h2 className="doc-modal-title">Subir documento</h2>
              <button
                type="button"
                onClick={() => setShowUpload(false)}
                className="doc-modal-close"
                aria-label="Cerrar"
              >
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleUpload} className="doc-form">
              <div>
                <label className="doc-label">Nombre del documento</label>
                <input
                  type="text" value={uploadName}
                  onChange={(e) => setUploadName(e.target.value)}
                  className="doc-input"
                  placeholder="Ej: Resolución N° 1234"
                  autoFocus
                  required
                />
              </div>
              <div>
                <label className="doc-label">Archivo (PDF, DOC, XLS, etc. — max {MAX_DOCUMENTO_MB}MB)</label>
                <input
                  ref={fileRef}
                  type="file"
                  onChange={(e) => setUploadFile(e.target.files[0])}
                  className="doc-file-input"
                  required
                />
              </div>
              <div>
                <label className="doc-label">¿Quién puede verlo?</label>
                <div className="doc-role-toggles">
                  {ROLES_DOC.map((r) => (
                    <button
                      key={r.id}
                      type="button"
                      onClick={() => toggleUploadRole(r.id)}
                      className={`doc-role-toggle${uploadRoles.includes(r.id) ? " doc-role-toggle-on" : ""}`}
                    >
                      {uploadRoles.includes(r.id) && <Check size={13} />}
                      {r.label}
                    </button>
                  ))}
                </div>
                {uploadRoles.length === 0 && (
                  <span className="doc-role-hint">Elegí al menos un rol, si no el documento no será visible.</span>
                )}
              </div>
              <div className="doc-form-actions">
                <button
                  type="submit"
                  disabled={subiendo || uploadRoles.length === 0}
                  className="doc-btn-primary doc-btn-submit"
                >
                  {subiendo && <Loader2 size={16} className="doc-spin" />}
                  {subiendo ? "Subiendo..." : "Subir"}
                </button>
                <button type="button" onClick={() => setShowUpload(false)} className="doc-btn-secondary">
                  Cancelar
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {editando && (
        <div className="doc-modal-backdrop" onClick={() => setEditando(null)}>
          <div className="doc-modal" onClick={(e) => e.stopPropagation()}>
            <h2 className="doc-modal-title">Editar documento</h2>
            <form onSubmit={handleSaveEdit} className="doc-form">
              <div>
                <label className="doc-label">Nombre del documento</label>
                <input
                  type="text"
                  value={editName}
                  onChange={(e) => setEditName(e.target.value)}
                  className="doc-input"
                  autoFocus
                  required
                />
              </div>
              <div>
                <label className="doc-label">¿Quién puede verlo?</label>
                <div className="doc-role-toggles">
                  {ROLES_DOC.map((r) => (
                    <button
                      key={r.id}
                      type="button"
                      onClick={() => toggleEditRole(r.id)}
                      className={`doc-role-toggle${editRoles.includes(r.id) ? " doc-role-toggle-on" : ""}`}
                    >
                      {editRoles.includes(r.id) && <Check size={13} />}
                      {r.label}
                    </button>
                  ))}
                </div>
                {editRoles.length === 0 && (
                  <span className="doc-role-hint">Elegí al menos un rol, si no el documento no será visible.</span>
                )}
              </div>
              <div className="doc-form-actions">
                <button
                  type="submit"
                  disabled={guardando || editRoles.length === 0 || !editName.trim()}
                  className="doc-btn-primary doc-btn-submit"
                >
                  {guardando && <Loader2 size={16} className="doc-spin" />}
                  {guardando ? "Guardando..." : "Guardar cambios"}
                </button>
                <button type="button" onClick={() => setEditando(null)} className="doc-btn-secondary">
                  Cancelar
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      {avisoModal}
      {modalPwd}
    </div>
  );
}
