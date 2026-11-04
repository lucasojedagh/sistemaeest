// Archivo con componentes de proteccion de rutas: RequireAuth, RequireGuest y RequireRole.
// Se utilizan en el enrutador para controlar el acceso segun autenticacion y rol.
import React from "react";
import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "./AuthContext";
import "./guards.css";

// Componente que protege rutas que requieren autenticacion.
// Mientras se verifica la sesion, muestra un indicador de carga.
// Si no hay usuario, redirige al login conservando la URL de destino.
export function RequireAuth({ children }) {
  const { user, ready } = useAuth();
  const loc = useLocation();

  if (!ready) return <div className="guard-loading">Cargando…</div>;

  if (!user) {
    const to = encodeURIComponent(loc.pathname + loc.search);
    return <Navigate to={`/login?to=${to}`} replace />;
  }

  return children;
}

// Componente que protege rutas para invitados (login, registro).
// Si ya hay sesion activa, redirige al inicio.
export function RequireGuest({ children }) {
  const { user, ready } = useAuth();
  if (!ready) return <div className="guard-loading">Cargando…</div>;
  return user ? <Navigate to="/" replace /> : children;
}

/**
 * Componente que protege rutas segun el rol del usuario.
 * Acepta un rol unico o un array de roles permitidos.
 * Si no esta autenticado, redirige al login. Si no tiene el rol adecuado, redirige al inicio.
 *   <RequireRole role="admin">...</RequireRole>
 *   <RequireRole role={['director','preceptor']}>...</RequireRole>
 */
export function RequireRole({ role, children }) {
  const { user, ready } = useAuth();
  const loc = useLocation();

  if (!ready) return <div className="guard-loading">Cargando…</div>;

  if (!user) {
    const to = encodeURIComponent(loc.pathname + loc.search);
    return <Navigate to={`/login?to=${to}`} replace />;
  }

  const allowed = Array.isArray(role) ? role.includes(user.role) : user.role === role;
  return allowed ? children : <Navigate to="/" replace />;
}
