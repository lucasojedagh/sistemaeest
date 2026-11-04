import React, { useState, useRef, useEffect } from 'react';
import { getToken } from "../../compartido/lib/authToken";
import { pedirTicketImpresion } from "../../compartido/lib/imprimir";
import { X } from 'lucide-react';
import { cursoLabel } from '../../compartido/lib/cursos';
import useConfirmAction from '../../compartido/hooks/useConfirmAction';
import { useToast } from '../../compartido/componentes/Common/Toast';
import "./PlanillaModal.css";

const RAW_API_BASE = import.meta?.env?.VITE_API_BASE || 'http://localhost:8000/api';
const BACKEND_BASE = RAW_API_BASE.replace(/\/api\/?$/, '');
// La planilla se abre en un iframe servido por el backend, así que hace falta
// el origen absoluto. Si VITE_API_BASE fuera relativo se usa el del frontend.
const BACKEND_ORIGIN = (() => {
  try { return new URL(BACKEND_BASE).origin; } catch { return window.location.origin; }
})();

export default function PlanillaModal({ anio, division, materiaId, materiaNombre, ciclo, onClose, onSaved, editable = true }) {
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [loadErrorMsg, setLoadErrorMsg] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);
  const iframeRef = useRef(null);
  const onSavedRef = useRef(onSaved);
  const loadedRef = useRef(false);
  const [confirmAction, confirmModal] = useConfirmAction();
  const showToast = useToast();
  onSavedRef.current = onSaved;

  // Ticket de corta duración para el iframe (el api_token real nunca va en la URL).
  // Vale 60s y es reutilizable: se renueva al reintentar por las dudas.
  const [ticket, setTicket] = useState(null);
  useEffect(() => {
    let vivo = true;
    setTicket(null);
    setLoading(true);
    setLoadError(false);
    setLoadErrorMsg(null);
    loadedRef.current = false;
    pedirTicketImpresion().then((t) => {
      if (!vivo) return;
      if (t) setTicket(t);
      else {
        setLoadErrorMsg('Tu sesión expiró o no tenés permiso para ver esta planilla. Volvé a intentar.');
        setLoadError(true);
        setLoading(false);
      }
    });
    return () => { vivo = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [anio, division, materiaId, ciclo, editable, reloadKey]);

  const src = ticket
    ? `${BACKEND_BASE}/calificaciones/planilla?${new URLSearchParams({ anio, division, materia_id: materiaId, ciclo, editable: editable ? '1' : '0', ticket }).toString()}`
    : null;

  // Inyecta mejoras de UX dentro del iframe (mismo origen que el backend).
  // Las mejoras de lectura (columnas fijas y resaltado de la final) aplican en
  // los dos modos; las de escritura (autofoco, navegación, validación, Ctrl+S)
  // solo cuando la planilla es editable, porque dependen de guardarPlanilla().
  const injectEnhancements = () => {
    const doc = iframeRef.current?.contentDocument;
    const win = iframeRef.current?.contentWindow;
    if (!doc || !win) return;
    const esEditable = typeof win.guardarPlanilla === 'function';

    // Evita doble inyección.
    if (doc.getElementById('opencode-planilla-enh')) return;

    const style = doc.createElement('style');
    style.id = 'opencode-planilla-enh';
    style.textContent = `
      /* Al editar, el N° y el nombre quedan fijos al hacer scroll lateral. */
      #planilla-table col.c1, #planilla-table td.p-nro, #planilla-table th.p-cabecera-nro,
      #planilla-table col.cn, #planilla-table td.p-alumno, #planilla-table th.p-cabecera-nombre {
        position: sticky; z-index: 5; background: #fff;
      }
      #planilla-table col.c1, #planilla-table td.p-nro, #planilla-table th.p-cabecera-nro {
        left: 0;
      }
      #planilla-table col.cn, #planilla-table td.p-alumno, #planilla-table th.p-cabecera-nombre {
        left: 5.8mm;
      }
      #planilla-table thead th.p-cabecera-nro, #planilla-table thead th.p-cabecera-nombre { z-index: 6; }
      /* La calificación final se resalta para encontrarla rápido. */
      #planilla-table col.cfin, #planilla-table td.p-final, #planilla-table th.p-cabecera-final {
        background: #fffbeb !important;
      }
      #planilla-table td.p-final .p-celda-input { font-weight: bold; }
      .p-celda-input.p-input-invalido { background: #fef2f2 !important; box-shadow: inset 0 0 0 1.5px #dc2626 !important; }
    `;
    doc.head.appendChild(style);

    // Marca visual de la columna final en el thead.
    const finalTh = doc.querySelector('#planilla-table thead th.p-cabecera-final');
    if (finalTh) { finalTh.style.background = '#fef3c7'; }

    if (!esEditable) return;

    // Recolecta las celdas editables en orden de lectura.
    const getEditable = () => Array.from(doc.querySelectorAll('#planilla-table .p-celda-input:not(:disabled)'));

    // Autoenfoque en la primera celda vacía (o la primera).
    const focusFirstEmpty = () => {
      const cells = getEditable();
      if (!cells.length) return;
      const empty = cells.find((c) => c.value.trim() === '');
      (empty || cells[0]).focus();
      (empty || cells[0]).select?.();
    };
    setTimeout(focusFirstEmpty, 150);

    // Navegación con flechas del teclado.
    doc.addEventListener('keydown', (e) => {
      const el = doc.activeElement;
      if (!el || !el.classList.contains('p-celda-input')) return;
      const cells = getEditable();
      const idx = cells.indexOf(el);
      if (idx < 0) return;
      const row = el.closest('tr');
      const rowInputs = Array.from(row.querySelectorAll('.p-celda-input:not(:disabled)'));
      const colIdx = rowInputs.indexOf(el);
      let next = null;
      if (e.key === 'ArrowRight') next = cells[idx + 1] || null;
      else if (e.key === 'ArrowLeft') next = cells[idx - 1] || null;
      else if (e.key === 'ArrowDown') next = rowInputs[colIdx + 1] ? cells[idx + 1] : cells[idx + (rowInputs.length)] || null;
      else if (e.key === 'ArrowUp') next = cells[idx - (rowInputs.length)] || null;
      else if (e.key === 'Enter') { e.preventDefault(); next = cells[idx + 1] || null; }
      if (next) { e.preventDefault(); next.focus(); next.select?.(); }
    }, true);

    // Validación en vivo de celdas numéricas.
    const esNumerica = (c) => /int-|nota-final|-num$/.test(c.className) || c.classList.contains('p1-val-prelim') || c.classList.contains('p2-val-prelim');
    doc.addEventListener('input', (e) => {
      const el = e.target;
      if (!el.classList || !el.classList.contains('p-celda-input')) return;
      if (esNumerica(el)) {
        const v = el.value.trim();
        const ok = v === '' || /^\d{1,2}(\.\d)?$/.test(v) || /^(TEA|TEP|TED|TEA\/TEP)$/i.test(v);
        el.classList.toggle('p-input-invalido', !ok);
      }
    }, true);

    // Atajo Ctrl+S para guardar (pide motivo primero).
    doc.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        win.handleGuardarCambios();
      }
    });

    // Detecta guardado exitoso para avisar al modal padre.
    const observer = new MutationObserver(() => {
      const status = doc.getElementById('save-status');
      if (status && status.classList.contains('success')) {
        win.parent.postMessage({ type: 'planilla-guardada' }, window.location.origin);
      }
    });
    const statusEl = doc.getElementById('save-status');
    if (statusEl) observer.observe(statusEl, { attributes: true, attributeFilter: ['class'] });
  };

  // El iframe pide el motivo del cambio vía postMessage.
  // Muestra el modal de confirmación y, al confirmar, envía el token + detalles al iframe.
  const handleSolicitarMotivo = async () => {
    const detalles = await confirmAction('Describí los cambios realizados en la planilla de calificaciones.');
    const win = iframeRef.current?.contentWindow;
    if (!win) return;

    if (detalles === null) {
      try { win.postMessage({ type: 'motivo-cancelado' }, BACKEND_ORIGIN); } catch { /* cross-origin */ }
      return;
    }
    const token = getToken();
    try {
      win.postMessage({ type: 'motivo-confirmado', token: token || null, detalles }, BACKEND_ORIGIN);
    } catch { /* cross-origin */ }
  };

  useEffect(() => {
    const t = setTimeout(() => {
      if (!loadedRef.current) { setLoadErrorMsg('La planilla tardó demasiado en responder. Intentá de nuevo.'); setLoadError(true); setLoading(false); }
    }, 15000);
    const onMsg = (e) => {
      if (!e.data) return;
      if (e.origin !== BACKEND_ORIGIN) return;
      if (e.data.type === 'planilla-guardada') {
        showToast('Cambios guardados correctamente', 'success');
        onSavedRef.current?.();
      } else if (e.data.type === 'solicitar-motivo') {
        handleSolicitarMotivo();
      }
    };
    window.addEventListener('message', onMsg);
    return () => { clearTimeout(t); window.removeEventListener('message', onMsg); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleIframeLoad = () => {
    // Si el iframe trajo un error en JSON (ej. 401) en vez de la planilla,
    // mostrar la pantalla de reintentar en lugar del JSON crudo.
    // Solo funciona mismo origen; en cross-origin se ignora (try/catch).
    try {
      const txt = iframeRef.current?.contentDocument?.body?.innerText?.trim() || '';
      if (txt.startsWith('{"message"') || txt.startsWith('{"ok":false') || txt.startsWith('{"error"')) {
        loadedRef.current = true;
        setLoading(false);
        let mensaje = null;
        try { mensaje = JSON.parse(txt)?.message || null; } catch { /* no era JSON */ }
        setLoadErrorMsg(mensaje || 'La planilla no se pudo abrir.');
        setLoadError(true);
        return;
      }
    } catch { /* cross-origin: no se puede inspeccionar */ }
    loadedRef.current = true;
    setLoading(false);
    setLoadError(false);
    try { injectEnhancements(); } catch { /* mismo origen requerido */ }
    // Envía el token de autenticación al iframe para que registre el usuario real en el historial.
    const win = iframeRef.current?.contentWindow;
    const token = getToken();
    if (win && token) {
      try { win.postMessage({ type: 'auth', token }, BACKEND_ORIGIN); } catch { /* cross-origin: ignorar */ }
    }
  };

  return (
    <div className="planilla-backdrop">
      <div className="planilla-modal">
        {/* Header del modal */}
        <div className="planilla-header">
          <div>
            <h2 className="planilla-title">
              {cursoLabel({ anio, division })} — {materiaNombre}
            </h2>
            <p className="planilla-subtitle">
              Ciclo lectivo {ciclo} · {editable ? 'Editar calificaciones' : 'Solo lectura — visualizar y descargar'}
            </p>
          </div>
          <button
            onClick={onClose}
            className="planilla-close"
          >
            <X size={22} />
          </button>
        </div>

        {/* Iframe con la planilla */}
        <div className="planilla-frame">
          {loading && !loadError && (
            <div className="planilla-overlay">
              <div className="planilla-overlay-inner">
                <div className="planilla-progress-track">
                  <div className="planilla-progress-bar animate-loading-bar" />
                </div>
                <p className="planilla-loading-text">Cargando...</p>
              </div>
            </div>
          )}
          {loadError && (
            <div className="planilla-overlay">
              <div className="planilla-error-inner">
                <p className="planilla-error-title">No se pudo cargar la planilla</p>
                <p className="planilla-error-text">{loadErrorMsg || 'Verificá tu conexión e intentá de nuevo.'}</p>
                 <button
                  onClick={() => { setLoadError(false); setLoading(true); setReloadKey((k) => k + 1); }}
                  className="planilla-retry"
                >
                  Reintentar
                </button>
              </div>
            </div>
          )}
          {src && (
            <iframe
              key={reloadKey}
              ref={iframeRef}
              src={src}
              className="planilla-iframe"
              title="Planilla de calificaciones"
              onLoad={handleIframeLoad}
            />
          )}
        </div>
      </div>

      {confirmModal}

      <style>{`
        @keyframes loading-bar {
          0% { transform: translateX(-100%); }
          40% { transform: translateX(0%); }
          60% { transform: translateX(0%); }
          100% { transform: translateX(100%); }
        }
        .animate-loading-bar {
          animation: loading-bar 1.8s ease-in-out infinite;
        }
      `}</style>
    </div>
  );
}
