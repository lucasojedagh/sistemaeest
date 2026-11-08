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
import { mensajeAmable } from "../../compartido/lib/errores";

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null); // {id,name,email,role}
  const [ready, setReady] = useState(false);
  // Cambia cuando se (re)descarga la lista de roles: avisa a las pantallas que
  // dependen del rango o la etiqueta de un rol personalizado.
  const [rolVersion, setRolVersion] = useState(0);
  // Aviso de la verificación de sesión al abrir la app: se muestra en el login
  // cuando el token sigue servido pero la API no respondió (corte, proxy caído).
  const [errorSesion, setErrorSesion] = useState(null);

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
  const verificarSesion = () => {
    if (!getToken()) {
      setReady(true);
      return;
    }
    apiMe()
      .then((u) => {
        setUser(u);
        setErrorSesion(null);
      })
      .catch((e) => {
        // Sólo una respuesta del servidor que diga "esta sesión no sirve"
        // (401/403) borra el token. Un corte de luz, el proxy caído o un 500
        // expulsaban al usuario al login sin explicación y había que volver a
        // escribir la contraseña.
        const status = e?.status;
        if (status === 401 || status === 403) {
          clearToken();
          setUser(null);
          setErrorSesion(null);
          return;
        }
        setErrorSesion(mensajeAmable(e, "No se pudo verificar tu sesión. Revisá la conexión e intentá de nuevo."));
      })
      .finally(() => setReady(true));
  };

  useEffect(() => {
    verificarSesion();
  }, []);

  // Inicia sesion, guarda el token y actualiza el estado del usuario.
  const login = async (email, password) => {
    const { user, token } = await apiLogin({ email, password });
    setToken(token);
    setUser(user);
    setErrorSesion(null);
    clearCache(); // invalida cache anterior (ambito, listas, etc.)
    borrarSellos(); // otra persona no debe ver avisos de esta sesion
    limpiarAvatares(); // tampoco las fotos descargadas por la cuenta anterior

    // El login entrega el usuario recortado: sin permisos, sin alcance y sin la
    // lista de módulos del lateral. Si la pantalla se queda con ese objeto, el
    // recién entrado ve los botones que le da su rango y no los que le dejó su
    // rol configurado (y con F5 se le corrigen solos). Se pide /me antes de
    // seguir; si falla, se deja el usuario del login y la app sigue andando.
    try {
      const completo = await apiMe();
      if (completo?.id === user.id) setUser(completo);
    } catch { /* sin /me la sesión igual entra: manda el servidor en cada pedido */ }
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
    setErrorSesion(null);
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
        errorSesion,
        verificarSesion,
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
