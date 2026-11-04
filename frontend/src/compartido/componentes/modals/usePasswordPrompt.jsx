import React from "react";
import useAviso from "./useAviso";
import { apiConfirmPassword } from "../../api/auth";

// Hook de confirmación de contraseña del usuario logueado.
// Reemplaza el viejo requirePasswordConfirmation() de confirmPasswordPrompt.js.
//
//   const [confirmarContrasena, modalPwd] = usePasswordPrompt();
//   ...
//   if (!(await confirmarContrasena())) return; // pide la clave y la valida contra el backend
//   ...
//   {modalPwd}  // hay que renderizarlo una vez
export default function usePasswordPrompt() {
  const [mostrarAviso, , avisoModal, , pedirDato] = useAviso();

  const confirmarContrasena = async () => {
    const pwd = await pedirDato({
      title: "Confirmá tu contraseña",
      text: "Necesitamos verificar tu identidad para continuar.",
      confirmText: "Continuar",
      cancelText: "Cancelar",
      input: {
        type: "password",
        placeholder: "Ingresá tu contraseña",
        validate: (v) => v.trim().length > 0,
        errorText: "Ingresá tu contraseña",
      },
    });
    if (pwd == null) return false;
    try {
      await apiConfirmPassword(pwd);
      return true;
    } catch (e) {
      await mostrarAviso({
        icon: "error",
        title: "Acceso denegado",
        text: e?.data?.message || e?.message || "No se pudo verificar la contraseña.",
      });
      return false;
    }
  };

  return [confirmarContrasena, avisoModal];
}