// Archivo con componentes de proteccion de rutas: RequireAuth, RequireGuest y RequireRole.
// Se utilizan en el enrutador para controlar el acceso segun autenticacion y rol.
import React from "react";
import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "./AuthContext";
import { puede, estaFiltrado, moduloVisible } from "../../compartido/lib/permisos";
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
 *   <RequireRole role={['admin']} permiso="roles.configurar" modulo="usuarios">...</RequireRole>
 * Los roles con la lista de permisos filtrada (personalizados, o de sistema con
 * quitas) entran por su permiso: la lista que manda /me es la que manda aca.
 * `modulo` aplica lo mismo que en RequirePermiso: oculto en el menu es inaccesible.
 */
export function RequireRole({ role, permiso, modulo, children }) {
  const { user, ready } = useAuth();
  const loc = useLocation();

  if (!ready) return <div className="guard-loading">Cargando…</div>;

  if (!user) {
    const to = encodeURIComponent(loc.pathname + loc.search);
    return <Navigate to={`/login?to=${to}`} replace />;
  }

  if (!moduloVisible(user, modulo)) return <Navigate to="/" replace />;

  const allowed = Array.isArray(role) ? role.includes(user.role) : user.role === role;
  if (allowed) return children;

  if (permiso && estaFiltrado(user) && puede(user, permiso)) return children;

  return <Navigate to="/" replace />;
}

/**
 * Ruta que solo se abre si el rol tiene ese permiso, sin mirar el nombre del rol.
 * Para los roles de sistema sin quitas la lista llega como null: entra igual que
 * antes. Solo filtra a los roles que el backend trae con lista de permisos.
 *
 * `modulo` es el id del módulo en "Módulos visibles en el menú". Sin él, ocultar
 * un módulo solo borraba la entrada del lateral y la pantalla seguía abriendo a
 * mano desde la URL; con él, ocultar y bloquear son la misma cosa.
 *   <RequirePermiso permiso="alumnos.ver" modulo="alumnos">...</RequirePermiso>
 */
export function RequirePermiso({ permiso, modulo, children }) {
  const { user, ready } = useAuth();
  const loc = useLocation();

  if (!ready) return <div className="guard-loading">Cargando…</div>;

  if (!user) {
    const to = encodeURIComponent(loc.pathname + loc.search);
    return <Navigate to={`/login?to=${to}`} replace />;
  }

  const permisoOk = !estaFiltrado(user) || puede(user, permiso);
  if (permisoOk && moduloVisible(user, modulo)) return children;

  // El panel de inicio es el destino de las redirecciones: ahi no se redirige,
  // se avisa, para no quedarse dando vueltas.
  if (loc.pathname === "/dashboard") {
    return <div className="guard-loading">No tenés permiso para abrir este módulo.</div>;
  }
  return <Navigate to="/dashboard" replace />;
}
