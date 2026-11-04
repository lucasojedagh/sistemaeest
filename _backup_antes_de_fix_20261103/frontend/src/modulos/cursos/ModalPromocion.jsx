// ===============================
// FILE: src/components/Curso/ModalPromocion.jsx
// ===============================
import React, { useEffect, useState } from "react";
import { authHeaders } from "../../compartido/lib/authToken";
import { apiGet } from "../../compartido/lib/api";
import { AnimatePresence, motion } from "framer-motion";
import { ArrowUpCircle, ArrowDownCircle, ShieldAlert, X } from "lucide-react";
import useConfirmAction from "../../compartido/hooks/useConfirmAction";
import useAviso from "../../compartido/componentes/modals/useAviso";
import usePasswordPrompt from "../../compartido/componentes/modals/usePasswordPrompt";
import { mensajeAmable } from "../../compartido/lib/errores";
import { useToast } from "../../compartido/componentes/Common/Toast";
import "./ModalPromocion.css";

// Componente: ModalPromocion
// Proposito: Modal que permite promocionar (subir de ano) o revertir (bajar de ano)
//            a todos los alumnos activos. Utiliza el ano tope configurado en el
//            parametro de divisiones para determinar quienes egresan.
// Props:
//   visible  - Booleano que controla la visibilidad del modal.
//   onClose  - Funcion para cerrar el modal.
//   onDone   - Callback que se ejecuta al completar la accion con el resultado.

/* Helpers API (idénticos a los que usás en Cursos.jsx) */
const RAW_API_BASE = import.meta?.env?.VITE_API_BASE || "http://localhost:8000/api";
const API_BASE = /^\/?api\/?$/i.test(RAW_API_BASE) ? "http://localhost:8000/api" : RAW_API_BASE;

const isAbsolute = (u) => /^https?:\/\//i.test(u);
const joinUrl = (base, path) => {
  const cleanBase = (base || "").replace(/\/+$/g, "");
  const cleanPath = String(path || "").replace(/^\/+/g, "");
  if (isAbsolute(cleanBase)) return `${cleanBase}/${cleanPath}`;
  const relBase = `/${cleanBase.replace(/^\/+/g, "")}`;
  return `${relBase}/${cleanPath}`;
};

// Intenta varias rutas conocidas de tu back:
// 1) /alumnos/promocionar/aplicar  (tu PromotionController)
// 2) /alumnos/promocionar          (alias viejo)
// 3) /ciclo/promocion              (CicloLectivoController)
async function postPromocion(payload) {
  const attempts = [
    "/alumnos/promocionar/aplicar",
    "/alumnos/promocionar",
    "/ciclo/promocion",
  ];

  let lastErr = null;
  for (const p of attempts) {
    const url = joinUrl(API_BASE, p);
    try {
      const res = await fetch(url, {
        method: "POST",
        headers: { ...authHeaders(), "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(payload || {}),
      });
      if (!res.ok) {
        if (res.status === 404) throw new Error(`404 ${p}`);
        let msg = `POST ${p} ${res.status}`;
        try {
          const j = await res.json();
          if (j?.message) msg = j.message;
        } catch { /* respuesta sin cuerpo JSON: conservar mensaje por defecto */ }
        const err = new Error(msg);
        err.status = res.status;
        throw err;
      }
      return await res.json();
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr || new Error("No se pudo contactar el servidor de promoción.");
}

export default function ModalPromocion({ visible, onClose, onDone }) {
  const [confirmAction, confirmModal] = useConfirmAction();
  const [mostrarAviso, pedirConfirmacion, avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const showToast = useToast();
  const [loading, setLoading] = useState(false);

  /* Resetea el estado de carga al cerrar el modal */
  useEffect(() => {
    if (!visible) setLoading(false);
  }, [visible]);

  /* Ejecuta la accion de promocion (up) o reversion (down) con confirmacion previa */
  const run = async (modo) => {
    const isUp = modo === "up";
    const title = isUp ? "¿Promocionar a todos?" : "¿Revertir promoción?";
    const text = isUp
      ? "Subirá 1 año a todos los alumnos activos y egresará a quienes estén en el año tope activo."
      : "Bajará 1 año a todos los alumnos activos (>1) y devolverá a los egresados a su último año/división.";

    const ok = await pedirConfirmacion({
      icon: "question",
      title,
      text,
      confirmText: isUp ? "Promocionar ahora" : "Revertir ahora",
      cancelText: "Cancelar",
    });
    if (!ok) return;

    // Mueve a TODOS los alumnos: el servidor exige contraseña reciente.
    if (!(await confirmarContrasena())) return;

    try {
      const label = isUp ? 'promocionar' : 'revertir';
      let data = null;
const detalles = await confirmAction(`¿Por qué vas a ${label} los alumnos?`, async (d) => {
          setLoading(true);
          const payload = { sentido: modo, _detalles: d };
          data = await postPromocion(payload);
        });
        if (detalles === null) return;

        let divisionesRefrescadas = null;
        try { divisionesRefrescadas = await apiGet("/divisiones"); } catch { /* refresco en segundo plano: no bloquea el cierre */ }

        const upLabels = ['Promovidos', 'Egresados'];
        const downLabels = ['Bajados', 'Reingresados'];
        const [lblPromo, lblEgreso] = isUp ? upLabels : downLabels;

        showToast(
          `${isUp ? 'Promoción aplicada' : 'Reversión aplicada'}: ${data?.promovidos ?? 0} ${lblPromo.toLowerCase()}, ${data?.egresados ?? 0} ${lblEgreso.toLowerCase()}`,
          'success'
        );

        onDone?.({ resultado: data, divisiones: divisionesRefrescadas });
        onClose?.();
    } catch (e) {
      console.error("[ModalPromocion] Error:", e);
      await mostrarAviso({
        icon: "error",
        title: "No se pudo completar la acción",
        text: mensajeAmable(e, "No se pudo completar la promoción. Revisá tu conexión."),
      });
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
    <AnimatePresence>
      {visible && (
        <motion.div
          className="mpromo-overlay"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
        >
          <motion.div
            className="mpromo-dialog"
            role="dialog"
            aria-modal="true"
            initial={{ opacity: 0, y: -10, scale: 0.98 }}
            animate={{ opacity: 1, y: 0, scale: 1, transition: { type: "spring", stiffness: 260, damping: 22 } }}
            exit={{ opacity: 0, y: -10, scale: 0.98, transition: { duration: 0.18 } }}
          >
            {/* Header */}
            <div className="mpromo-header">
              <div>
                <h3 className="mpromo-title">Cambio de ciclo lectivo</h3>
                <p className="mpromo-subtitle">
                  Elegí una acción. Las divisiones se mantienen; el año tope activo define quién egresa.
                </p>
              </div>
              <button
                onClick={() => !loading && onClose?.()}
                className="mpromo-close"
                aria-label="Cerrar"
                disabled={loading}
              >
                <X size={18} />
              </button>
            </div>

            {/* Body */}
            <div className="mpromo-body">
              <div className="mpromo-grid">
                {/* Promocionar */}
                <button
                  disabled={loading}
                  onClick={() => run("up")}
                  className="mpromo-card mpromo-card--up group"
                >
                  <div className="mpromo-card-inner">
                    <div className="mpromo-card-icon mpromo-card-icon--up">
                      <ArrowUpCircle className="mpromo-icon-up" size={24} />
                    </div>
                    <div>
                      <div className="mpromo-card-title">Promocionar (subir de año)</div>
                      <div className="mpromo-card-text">
                        Sube 1 año a todos los alumnos activos. Quienes estén en el año tope activo pasan a <b>Egresado</b>.
                      </div>
                    </div>
                  </div>
                </button>

                {/* Revertir */}
                <button
                  disabled={loading}
                  onClick={() => run("down")}
                  className="mpromo-card mpromo-card--down group"
                >
                  <div className="mpromo-card-inner">
                    <div className="mpromo-card-icon mpromo-card-icon--down">
                      <ArrowDownCircle className="mpromo-icon-down" size={24} />
                    </div>
                    <div>
                      <div className="mpromo-card-title mpromo-card-title--down">Revertir (bajar de año)</div>
                      <div className="mpromo-card-text">
                        Devuelve a los egresados a su último curso y baja 1 año a los activos. Útil si te arrepentiste.
                      </div>
                    </div>
                  </div>
                </button>
              </div>

              {/* Aviso */}
              <div className="mpromo-aviso">
                <ShieldAlert size={18} className="mpromo-aviso-icon" />
                <div className="mpromo-aviso-text">
                  El <b>año tope activo</b> se toma de <i>Parámetro de Divisiones</i> (lo que configurás en “Gestionar años / Editar divisiones”).
                </div>
              </div>
            </div>

            {/* (Footer removido: sin línea inferior y sin botón "Cerrar") */}
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
      {confirmModal}
      {avisoModal}
      {modalPwd}
    </>
  );
}
