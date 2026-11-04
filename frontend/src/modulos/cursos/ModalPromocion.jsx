// ===============================
// FILE: src/components/Curso/ModalPromocion.jsx
// ===============================
import React, { useEffect, useState } from "react";
import { apiPost } from "../../compartido/lib/api";
import { cargarDivisiones } from "../../compartido/lib/divisiones";
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
// Ruta canónica: PromotionController, que sí interpreta "sentido" (up | down).
// Antes se probaban varias rutas "por las dudas" y la última (/ciclo/promocion)
// promociona hacia adelante sin leer sentido: un "Revertir" que fallaba por
// cualquier motivo terminaba ejecutando una promoción destructiva. Ahora el
// único fallback es el alias de la MISMA acción, y solo si la ruta no existe.
async function postPromocion(payload) {
  try {
    return await apiPost("/alumnos/promocionar/aplicar", payload || {});
  } catch (e) {
    if (e?.status === 404) return await apiPost("/alumnos/promocionar", payload || {});
    throw e;
  }
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

    // Primero se simula: el servidor solo deja aplicar con el salvoconducto que
    // entrega el preview, y así el confirmador ve números reales y no genéricos.
    setLoading(true);
    let sim = null;
    try {
      sim = await apiPost("/alumnos/promocionar/preview", { sentido: modo });
    } catch (e) {
      console.error("[ModalPromocion] Preview:", e);
      await mostrarAviso({
        icon: "error",
        title: "No se pudo simular el cambio",
        text: mensajeAmable(e, "No se pudo calcular el impacto. Revisá tu conexión e intentá de nuevo."),
      });
      return;
    } finally {
      setLoading(false);
    }

    const tot = sim?.resumen?.totales || {};
    const nPromocion = tot.promocion ?? 0;
    const nEgresan = tot.egresan ?? 0;
    const text = isUp
      ? `Subirá 1 año a ${nPromocion} alumno${nPromocion === 1 ? "" : "s"} y pasará a Egresado a ${nEgresan} que están en el año tope (${sim?.maxAnio ?? "-"}°).`
      : `Bajará 1 año a ${nPromocion} alumno${nPromocion === 1 ? "" : "s"} activos y devolverá a ${nEgresan} egresado${nEgresan === 1 ? "" : "s"} a su último curso.`;

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
          const payload = { sentido: modo, confirmacion: sim?.confirmacion, _detalles: d };
          data = await postPromocion(payload);
        });
        if (detalles === null) return;

        let divisionesRefrescadas = null;
        try { divisionesRefrescadas = await cargarDivisiones({ forzar: true }); } catch { /* refresco en segundo plano: no bloquea el cierre */ }

        const upLabels = ['Promovidos', 'Egresados'];
        const downLabels = ['Bajados', 'Reingresados'];
        const [lblPromo, lblEgreso] = isUp ? upLabels : downLabels;

        // En la reversión el servidor cuenta los reingresados en "corregidos"
        // ("egresados" es solo de la promoción hacia adelante).
        const segundo = isUp ? (data?.egresados ?? 0) : (data?.corregidos ?? 0);

        showToast(
          `${isUp ? 'Promoción aplicada' : 'Reversión aplicada'}: ${data?.promovidos ?? 0} ${lblPromo.toLowerCase()}, ${segundo} ${lblEgreso.toLowerCase()}`,
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
          initial={false}
          exit={{ opacity: 0 }}
        >
          <motion.div
            className="mpromo-dialog"
            role="dialog"
            aria-modal="true"
            initial={{ y: -10, scale: 0.98 }}
            animate={{ y: 0, scale: 1, transition: { type: "spring", stiffness: 260, damping: 22 } }}
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
