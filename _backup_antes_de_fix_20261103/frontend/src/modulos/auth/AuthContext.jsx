// Archivo que define el contexto de autenticacion y el proveedor AuthProvider.
// Expone el hook useAuth para acceder al estado de sesion desde cualquier componente.
import React, { createContext, useContext, useEffect, useState } from "react";
import {
  apiLogin,
  apiRegister,
  apiMe,
  apiLogout,
  apiUpdateProfile,
} from "../../compartido/api/auth";
import { getToken, setToken, clearToken } from "../../compartido/lib/authToken";
import { clearCache } from "../../compartido/lib/pageCache";
import { borrarSellos } from "../../compartido/lib/dataStamp";

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null); // {id,name,email,role}
  const [ready, setReady] = useState(false);

  // Si la sesion expira (401 global), limpia el estado local del usuario.
  useEffect(() => {
    const onExpired = () => setUser(null);
    window.addEventListener("auth:expired", onExpired);
    return () => window.removeEventListener("auth:expired", onExpired);
  }, []);

  // Al montar el componente, verifica si existe un token guardado y restaura la sesion.
  useEffect(() => {
    if (!getToken()) {
      setReady(true);
      return;
    }
    apiMe()
      .then(setUser)
      .catch(() => {
        clearToken();
        setUser(null);
      })
      .finally(() => setReady(true));
  }, []);

  // Inicia sesion, guarda el token y actualiza el estado del usuario.
  const login = async (email, password) => {
    const { user, token } = await apiLogin({ email, password });
    setToken(token);
    setUser(user);
    clearCache(); // invalida cache anterior (ambito, listas, etc.)
    borrarSellos(); // otra persona no debe ver avisos de esta sesion
  };

  // Registra un nuevo usuario y devuelve la respuesta del servidor.
  const register = async (name, email, password, password_confirmation, rolSolicitado) => {
    const res = await apiRegister({ name, email, password, password_confirmation, rol_solicitado: rolSolicitado });
    return res; // { message: '...' }
  };

  // Cierra la sesion y elimina el token del estado local.
  // todos=true cierra tambien los demas equipos donde esta conectada.
  const logout = async (todos = false) => {
    try {
      await apiLogout(todos);
    } catch { /* el token puede ya estar invalidado */ }
    clearToken();
    setUser(null);
    borrarSellos();
  };

  // Actualiza el perfil del usuario (nombre, email y contrasena opcional).
  const updateProfile = async (patch) => {
    const updated = await apiUpdateProfile(patch);
    setUser(updated); // {id,name,email,role}
    return updated;
  };

  return (
    <AuthCtx.Provider
      value={{
        user,
        ready,
        login,
        register,
        logout,
        updateProfile,
      }}
    >
      {children}
    </AuthCtx.Provider>
  );
}
