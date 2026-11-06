// Archivo que define el contexto de autenticacion y el proveedor AuthProvider.
// Expone el hook useAuth para acceder al estado de sesion desde cualquier componente.
import React, { createContext, useContext, useEffect, useState } from "react";
import {
  apiLogin,
  apiRegister,
  apiMe,
  apiLogout,
  apiUpdateProfile,
  apiSubirAvatar,
  apiQuitarAvatar,
} from "../../compartido/api/auth";
import { getToken, setToken, clearToken } from "../../compartido/lib/authToken";
import { clearCache } from "../../compartido/lib/pageCache";
import { borrarSellos } from "../../compartido/lib/dataStamp";
import { limpiarAvatares } from "../../compartido/lib/avatares";
import { cargarRoleDetalle, semillarRol } from "./roles";

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null); // {id,name,email,role}
  const [ready, setReady] = useState(false);
  // Cambia cuando se (re)descarga la lista de roles: avisa a las pantallas que
  // dependen del rango o la etiqueta de un rol personalizado.
  const [rolVersion, setRolVersion] = useState(0);

  // Nombre y rango del rol propio disponibles desde el primer render; la lista
  // completa (para etiquetas y rangos de OTROS roles, incluidos personalizados)
  // llega de /roles/detalle.
  useEffect(() => {
    if (!user) return;
    semillarRol(user);
    let vivo = true;
    cargarRoleDetalle().then((d) => {
      if (vivo && d) setRolVersion((v) => v + 1);
    });
    return () => {
      vivo = false;
    };
  }, [user?.role]);

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
    limpiarAvatares(); // tampoco las fotos descargadas por la cuenta anterior
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
    clearCache(); // los datos de la sesión no pueden quedar legibles después
    borrarSellos();
    limpiarAvatares();
  };

  // Actualiza el perfil del usuario (nombre, email y contrasena opcional).
  const updateProfile = async (patch) => {
    const updated = await apiUpdateProfile(patch);
    setUser(updated); // {id,name,email,role}
    return updated;
  };

  // Foto de perfil propia: se guarda la version nueva en el usuario para que
  // "Mi cuenta" y la barra lateral la pidan enseguida, sin recargar.
  const subirAvatar = async (archivo) => {
    const r = await apiSubirAvatar(archivo);
    setUser((u) => (u ? { ...u, avatar_version: r?.avatar_version ?? null } : u));
    return r;
  };

  const quitarAvatar = async () => {
    const r = await apiQuitarAvatar();
    setUser((u) => (u ? { ...u, avatar_version: null } : u));
    return r;
  };

  return (
    <AuthCtx.Provider
      value={{
        user,
        ready,
        rolVersion,
        login,
        register,
        logout,
        updateProfile,
        subirAvatar,
        quitarAvatar,
      }}
    >
      {children}
    </AuthCtx.Provider>
  );
}
