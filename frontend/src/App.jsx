// Componente raiz de la aplicacion. Define las rutas publicas y protegidas,
// el layout con Navbar condicional y animaciones de transicion.
// FILE: src/App.jsx
import React, { Suspense, lazy } from "react";
import { Routes, Route, Navigate, useLocation } from "react-router-dom";
import { AnimatePresence, motion } from "framer-motion";

import { RequireAuth, RequireGuest, RequireRole, RequirePermiso } from "./modulos/auth/guards";
import { useAuth } from "./modulos/auth/AuthContext";

import Navbar from "./compartido/componentes/layout/Navbar";
import "./App.css";
// Cada página se carga solo al visitarla (code-splitting): el inicio
// descarga ~1/15 del JS en vez del paquete entero de 1,2 MB.
const Alumnos = lazy(() => import("./modulos/alumnos/Alumnos"));
const Cursos = lazy(() => import("./modulos/cursos/Cursos"));
const Dashboard = lazy(() => import("./modulos/dashboard/Dashboard"));
const Profesores = lazy(() => import("./modulos/profesores/Profesores"));
const Preceptores = lazy(() => import("./modulos/preceptores/Preceptores"));
const JefePreceptores = lazy(() => import("./modulos/preceptores/JefePreceptores"));
const Calificaciones = lazy(() => import("./modulos/calificaciones/Calificaciones"));
const Asistencia = lazy(() => import("./modulos/asistencia/Asistencia"));
const Login = lazy(() => import("./modulos/auth/Login"));
const Register = lazy(() => import("./modulos/auth/Register"));
const ForgotPassword = lazy(() => import("./modulos/auth/ForgotPassword"));
const ResetPassword = lazy(() => import("./modulos/auth/ResetPassword"));
const AdminUsers = lazy(() => import("./modulos/usuarios/AdminUsers"));
const Historial = lazy(() => import("./modulos/historial/Historial"));
const Documentos = lazy(() => import("./modulos/documentos/Documentos"));
import { ToastProvider } from "./compartido/componentes/Common/Toast";
import { UndoProvider } from "./compartido/lib/deshacer.jsx";
import DeshacerHost from "./compartido/componentes/Common/DeshacerHost";
import OfflineBanner from "./compartido/componentes/Common/OfflineBanner";
import BloqueoCambioClave from "./modulos/auth/BloqueoCambioClave";

export default function App() {
  const { user } = useAuth();
  const location = useLocation();

  // Variantes de animacion para transiciones entre estados de autenticacion
  // Sin "exit": al terminar su animación Framer suelta el estilo inline y deja
  // un frame del shell entero opaco otra vez antes de desmontar (parpadeo de
  // pantalla al entrar/salir). La entrada igual se ve porque el estado en
  // reposo del CSS es opacidad 1.
  const shellVariants = {
    initial: { opacity: 0, scale: 0.985, filter: "blur(6px)" },
    animate: { opacity: 1, scale: 1,     filter: "blur(0px)" },
  };
  const shellTransition = { duration: 0.26, ease: [0.22, 1, 0.36, 1] };

  // Clave de animacion: cambia solo al alternar entre invitado y autenticado
  const shellKey = user ? "auth-shell" : "guest-shell";

  return (
    <ToastProvider>
    <AnimatePresence mode="wait" initial={false}>
      <motion.div
        key={shellKey}
        initial="initial"
        animate="animate"
        variants={shellVariants}
        transition={shellTransition}
        className="app-shell"
      >
      <UndoProvider>
        {/* Navbar (sidebar) visible solo cuando hay sesion activa */}
        {user && <Navbar />}

        {/* Cartel de conexión: visible siempre que haya sesión */}
        {user && <OfflineBanner />}

        {/* Clave temporal entregada en mano: no se usa el sistema hasta poner una propia */}
        {user?.debe_cambiar_password && <BloqueoCambioClave />}

        <main className="app-main">
          <div className="app-content">
            {/* Las rutas quedan igual. No animamos entre páginas internas */}
            <Suspense fallback={<div className="app-loading">Cargando…</div>}>
            <Routes location={location}>
              {/* públicas (solo invitados) */}
              <Route
                path="/login"
                element={
                  <RequireGuest>
                    <Login />
                  </RequireGuest>
                }
              />

              {/* registro público */}
              <Route
                path="/register"
                element={
                  <RequireGuest>
                    <Register />
                  </RequireGuest>
                }
              />

              {/* recuperar/restablecer contraseña (públicas) */}
              <Route
                path="/recuperar"
                element={
                  <RequireGuest>
                    <ForgotPassword />
                  </RequireGuest>
                }
              />
              <Route
                path="/restablecer"
                element={
                  <RequireGuest>
                    <ResetPassword />
                  </RequireGuest>
                }
              />

              {/* redirect raíz */}
              <Route path="/" element={<Navigate to="/dashboard" replace />} />

              {/* protegidas */}
              <Route
                path="/dashboard"
                element={
                  <RequireAuth>
                    <RequirePermiso permiso="dashboard.ver" modulo="dashboard">
                      <Dashboard />
                    </RequirePermiso>
                  </RequireAuth>
                }
              />
              <Route
                path="/alumnos"
                element={
                  <RequireAuth>
                    <RequirePermiso permiso="alumnos.ver" modulo="alumnos">
                      <Alumnos />
                    </RequirePermiso>
                  </RequireAuth>
                }
              />
              <Route
                path="/cursos"
                element={
                  <RequireAuth>
                    <RequirePermiso permiso="cursos.ver" modulo="cursos">
                      <Cursos />
                    </RequirePermiso>
                  </RequireAuth>
                }
              />
              <Route
                path="/profesores"
                element={
                  <RequireAuth>
                    <RequirePermiso permiso="profesores.ver" modulo="profesores">
                      <Profesores />
                    </RequirePermiso>
                  </RequireAuth>
                }
              />
              <Route
                path="/preceptores"
                element={
                  <RequireAuth>
                    <RequireRole role={["admin", "directivo", "jefe_preceptores", "preceptor", "prosecretaria"]} permiso="usuarios.ver" modulo="preceptores">
                      <Preceptores />
                    </RequireRole>
                  </RequireAuth>
                }
              />
              <Route
                path="/jefe-preceptores"
                element={
                  <RequireAuth>
                    <RequireRole role={["admin", "directivo", "jefe_preceptores", "prosecretaria"]} permiso="usuarios.crear" modulo="jefe-preceptores">
                      <JefePreceptores />
                    </RequireRole>
                  </RequireAuth>
                }
              />
              <Route
                path="/calificaciones"
                element={
                  <RequireAuth>
                    <RequirePermiso permiso="calificaciones.ver" modulo="calificaciones">
                      <Calificaciones />
                    </RequirePermiso>
                  </RequireAuth>
                }
              />
              <Route
                path="/asistencia"
                element={
                  <RequireAuth>
                    <RequirePermiso permiso="asistencia.ver" modulo="asistencia">
                      <Asistencia />
                    </RequirePermiso>
                  </RequireAuth>
                }
              />

              {/* admin/directivo/jefe_preceptores/prosecretaria */}
              <Route
                path="/admin/usuarios"
                element={
                  <RequireAuth>
                    <RequireRole role={["admin", "directivo", "jefe_preceptores", "prosecretaria"]} permiso="usuarios.ver" modulo="usuarios">
                      <AdminUsers />
                    </RequireRole>
                  </RequireAuth>
                }
              />
              <Route
                path="/historial"
                element={
                  <RequireAuth>
                    <RequireRole role={["admin", "directivo", "jefe_preceptores", "prosecretaria"]} permiso="historial.ver" modulo="historial">
                      <Historial />
                    </RequireRole>
                  </RequireAuth>
                }
              />
              <Route
                path="/documentos"
                element={
                  <RequireAuth>
                    <RequirePermiso permiso="documentos.ver" modulo="documentos">
                      <Documentos />
                    </RequirePermiso>
                  </RequireAuth>
                }
              />
              {/* fallback */}
              <Route path="*" element={<Navigate to="/dashboard" replace />} />
            </Routes>
            </Suspense>
          </div>
        </main>
        {user && <DeshacerHost />}
      </UndoProvider>
      </motion.div>
    </AnimatePresence>
    </ToastProvider>
  );
}
