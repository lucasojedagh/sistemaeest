// "Deshacer": registro de acciones reversibles. Cada flujo que guarda un
// cambio ofrece un botón "Deshacer" dentro de la notificación de éxito.
// Cada entrada es { etiqueta, deshacer, exigePassword? } donde deshacer es
// una función async que revierte ESE cambio (no el último de la pila).
// El botón se enlaza a su propia entrada: si se guardaron varias cosas, cada
// notificación deshace la suya.
import React, { createContext, useCallback, useContext, useMemo, useRef, useState } from "react";
import { useToast } from "../componentes/Common/Toast";
import useAviso from "../componentes/modals/useAviso";
import usePasswordPrompt from "../componentes/modals/usePasswordPrompt";

const MAX = 30;
// Cuánto dura visible la notificación con Deshacer.
const DESHACER_MS = 5000;

const UndoCtx = createContext(null);

let contador = 0;

export function UndoProvider({ children }) {
  const [entradas, setEntradas] = useState([]);
  const entradasRef = useRef([]);
  entradasRef.current = entradas;

  // Modales compartidos (confirmación de contraseña + avisos) para el flujo
  // Deshacer. useAviso/usePasswordPrompt son de estado LOCAL por instancia:
  // si cada useDeshacerAviso() creara los suyos, ningún componente los
  // renderizaría (solo DeshacerHost monta modalesDeshacer). Por eso viven acá
  // en el proveedor y se comparten por contexto: cualquier ofrecerDeshacer
  // dispara este único set de modales, renderizado una vez en <DeshacerHost/>.
  const [mostrarAviso, , avisoModal] = useAviso();
  const [confirmarContrasena, modalPwd] = usePasswordPrompt();
  const modales = (
    <>
      {avisoModal}
      {modalPwd}
    </>
  );

  // Guarda una acción reversible. Se llama DESPUÉS de que el cambio anduvo.
  // Devuelve el id para poder deshacer exactamente esa acción más tarde.
  const pushDeshacer = useCallback((entrada) => {
    const id = `${Date.now()}-${++contador}`;
    setEntradas((prev) => [...prev.slice(-(MAX - 1)), { ...entrada, id }]);
    return id;
  }, []);

  // Ejecuta (y quita) la acción con ese id. Devuelve { ok } o { ok:false }.
  const deshacerPorId = useCallback(async (entradaId) => {
    const encontrada = entradasRef.current.find((e) => e.id === entradaId);
    if (!encontrada) return { ok: false };
    await encontrada.deshacer();
    setEntradas((prev) => prev.filter((e) => e.id !== entradaId));
    return { ok: true };
  }, []);

  // Compatibilidad con el flujo viejo: deshace la última entrada vigente.
  const deshacerUltimo = useCallback(async () => {
    const top = entradasRef.current[entradasRef.current.length - 1];
    if (!top) return false;
    const r = await deshacerPorId(top.id);
    return r.ok;
  }, [deshacerPorId]);

  const ultimo = useMemo(
    () => entradas[entradas.length - 1] || null,
    [entradas]
  );

  return (
    <UndoCtx.Provider
      value={{ pushDeshacer, deshacerPorId, deshacerUltimo, ultimo, fijarVisible: () => {}, ocultar: () => {}, verUltimo: () => null, mostrarAviso, confirmarContrasena, modales }}
    >
      {children}
    </UndoCtx.Provider>
  );
}

export function useDeshacer() {
  const ctx = useContext(UndoCtx);
  if (!ctx) return { pushDeshacer: () => null, deshacerPorId: async () => ({ ok: false }), deshacerUltimo: async () => false, ultimo: null, fijarVisible: () => {}, ocultar: () => {}, verUltimo: () => null, mostrarAviso: async () => {}, confirmarContrasena: async () => false, modales: null };
  return ctx;
}

export { DESHACER_MS };

// Ofrece el botón "Deshacer" dentro de la notificación de éxito del guardado.
//   ofrecerDeshacer({ etiqueta, deshacer, exigePassword? }, mensaje)
// - etiqueta: qué se va a revertir (para avisos y títulos).
// - deshacer: async, revierte ESE cambio.
// - exigePassword: true si la reversa termina borrando (el servidor pide
//   contraseña reciente igual que al borrar).
// Al tocar Deshacer: el botón muestra un "cargando" y la notificación solo
// se cierra cuando la reversa termina. Al completar aparece otra
// notificación verde "Cambios deshechos". Devuelve además los modales
// (contraseña y avisos) para renderizar una vez en un componente siempre
// montado (DeshacerHost).
export function useDeshacerAviso() {
  const { pushDeshacer, deshacerPorId, mostrarAviso, confirmarContrasena, modales } = useDeshacer();
  const showToast = useToast();
  const enVueloRef = useRef(new Set());

  const ofrecerDeshacer = useCallback((entrada, mensaje) => {
    const id = pushDeshacer(entrada);
    showToast(mensaje, "success", {
      duration: DESHACER_MS,
      action: {
        label: "Deshacer",
        onClick: async () => {
          // El botón deshace su PROPIA acción, no la última de la pila.
          if (enVueloRef.current.has(id)) return;
          if (entrada.exigePassword && !(await confirmarContrasena())) return;
          enVueloRef.current.add(id);
          try {
            const r = await deshacerPorId(id);
            if (r.ok) showToast("Cambios deshechos", "success");
            else showToast("Esta acción ya no se puede deshacer.", "info");
          } catch (e) {
            mostrarAviso({
              icon: "error",
              title: "No se pudo deshacer",
              text: e?.data?.message || e?.message || "Intentá de nuevo.",
            });
          } finally {
            enVueloRef.current.delete(id);
          }
        },
      },
    });
  }, [pushDeshacer, deshacerPorId, showToast, confirmarContrasena, mostrarAviso]);

  return { ofrecerDeshacer, modalesDeshacer: modales };
}