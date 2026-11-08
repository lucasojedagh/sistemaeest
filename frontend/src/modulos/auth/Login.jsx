// Pagina de inicio de sesion del sistema.
// FILE: src/pages/Login.jsx
import React, { useEffect, useRef, useState } from "react";
import { useAuth } from "../auth/AuthContext";
import { useNavigate, useSearchParams, Link } from "react-router-dom";
import { Mail, Lock, Eye, EyeOff } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";
import { mensajeAmable } from "../../compartido/lib/errores";
import useAviso from "../../compartido/componentes/modals/useAviso";
import "./Login.css";

// Destino seguro tras login: solo rutas internas (evita redirección abierta).
// El navegador lee "/\evil.com" igual que "//evil.com", así que la barra
// invertida también corta el desvío.
const destinoSeguro = (to) => {
  if (typeof to !== "string") return "/";
  if (!to.startsWith("/") || to.includes("\\") || to.startsWith("//")) return "/";
  return to;
};

const page = {
  initial: { opacity: 0, y: 12 },
  animate: { opacity: 1, y: 0 },
  exit: { opacity: 0, y: -12 },
  transition: { duration: 0.25, ease: "easeOut" },
};

// Componente principal de inicio de sesion
export default function Login() {
  const { login, errorSesion, verificarSesion } = useAuth();
  const nav = useNavigate();
  const [params] = useSearchParams();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPwd, setShowPwd] = useState(false);
  const [pwdFocused, setPwdFocused] = useState(false);
  const [err, setErr] = useState("");
  const [loading, setLoading] = useState(false);

  const [, , avisoModal, mostrarToast] = useAviso();

  const pwdRef = useRef(null);

  // Muestra notificaciones al usuario segun los parametros de URL (cierre de sesion, registro)
  useEffect(() => {
    if (params.get("logged_out") === "1") {
      mostrarToast({ icon: "success", title: "Sesión cerrada" });
      nav("/login", { replace: true });
    }
    if (params.get("registrado") === "1") {
      mostrarToast({ icon: "success", title: "Registro exitoso. Esperá la verificación del administrador." });
      nav("/login", { replace: true });
    }
    if (params.get("expired") === "1") {
      mostrarToast({ icon: "info", title: "Tu sesión terminó. Ingresá de nuevo." });
      nav("/login", { replace: true });
    }
  }, [params, nav, mostrarToast]);

  // Envia credenciales al backend para iniciar sesion
  const onSubmit = async (e) => {
    e.preventDefault();
    if (loading) return;
    setErr("");
    setLoading(true);
    try {
      await login(email, password);
      nav(destinoSeguro(params.get("to")), { replace: true });
    } catch (e) {
      setErr(mensajeAmable(e, "No pudimos iniciar sesión. Revisá tu email y contraseña."));
    } finally {
      setLoading(false);
    }
  };

  // Alterna la visibilidad de la contrasena preservando la posicion del cursor
  const toggleEye = () => {
    const el = pwdRef.current;
    const pos = el ? el.selectionStart : null;
    setShowPwd((v) => !v);
    requestAnimationFrame(() => {
      if (!el) return;
      el.focus({ preventScroll: true });
      try { if (pos != null) el.setSelectionRange(pos, pos); } catch { /* inputs no textuales no soportan selección */ }
    });
  };

  return (
    <>
    <motion.div
      className="auth-page"
      initial={page.initial}
      animate={page.animate}
      exit={page.exit}
      transition={page.transition}
    >
      {/* Fondo */}
      <div className="auth-bg" aria-hidden="true">
        <div className="auth-blob-top" />
        <div className="auth-blob-bottom" />
        <div className="auth-bg-dots" />
      </div>

      <div className="auth-wrap">
        <div className="auth-card">
          <div className="auth-grid">
            {/* Marca */}
            <div className="auth-brand">
              <div className="auth-brand-dots" />
              <div className="auth-brand-inner">
                <img
                  src="/EscudoENRO.png"
                  alt="Escuela de Educación Secundaria Técnica N°2"
                  className="auth-brand-img"
                />
                <h2 className="auth-brand-title">Escuela de Educación Secundaria Técnica</h2>
                <p className="auth-brand-subtitle">E.E.S.T N°2</p>
                <div className="auth-brand-divider" />
                <p className="auth-brand-tagline">Sistema de Gestión Escolar</p>
              </div>
            </div>

            {/* Form */}
            <form onSubmit={onSubmit} className="auth-form">
              <div className="auth-form-inner">
                <div className="auth-heading">
                  <h1 className="auth-heading-title">Iniciar sesión</h1>
                  <div className="auth-heading-bar" />
                </div>

                {/* La sesión sigue guardada pero la API no respondió: se avisa
                    acá en vez de borrar el token y exigir la contraseña. */}
                {errorSesion && (
                  <div className="auth-session-note" role="status">
                    <div>{errorSesion}</div>
                    <button type="button" onClick={verificarSesion}>Reintentar</button>
                  </div>
                )}

                <div className="auth-fields">
                  {/* Email */}
                  <label className="group auth-field">
                    <span className="auth-field-sr">Email</span>
                    <div className="auth-field-icon-wrap">
                      <Mail size={24} className="auth-field-icon auth-field-icon-blue" />
                    </div>
                    <input id="login-email" name="email"
                      className="auth-input auth-input-blue"
                      placeholder="Email" type="email" value={email}
                      onChange={(e) => setEmail(e.target.value)} autoComplete="email" required
                      disabled={loading}
                    />
                  </label>

                  {/* Password + ojo */}
                  <label className="group auth-field">
                    <span className="auth-field-sr">Contraseña</span>
                    <div className="auth-field-icon-wrap">
                      <Lock size={24} className="auth-field-icon auth-field-icon-indigo" />
                    </div>
                    <input id="login-password" name="password"
                      ref={pwdRef}
                      className="auth-input auth-input-indigo auth-input-has-eye"
                      placeholder="Contraseña"
                      type={showPwd ? "text" : "password"}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      onFocus={() => setPwdFocused(true)}
                      onBlur={() => setPwdFocused(false)}
                      autoComplete="current-password"
                      required
                      disabled={loading}
                    />
                    <motion.button
                      type="button"
                      onMouseDown={(e) => e.preventDefault()}
                      onClick={toggleEye}
                      aria-label={showPwd ? "Ocultar contraseña" : "Mostrar contraseña"}
                      className="group auth-eye-btn"
                      animate={{
                        scale: pwdFocused ? 1.05 : 1,
                        boxShadow: pwdFocused ? "0 0 0 6px rgba(99,102,241,0.08)" : "0 0 0 0px rgba(0,0,0,0)",
                      }}
                      transition={{ type: "spring", stiffness: 360, damping: 22 }}
                    >
                      <span className="auth-eye-icon-wrap">
                        <motion.span className="auth-eye-icon-abs" initial={false}
                          animate={{ opacity: showPwd ? 1 : 0, rotate: showPwd ? 0 : -8, scale: showPwd ? 1 : 0.95 }}
                          transition={{ duration: 0.16, ease: "easeOut" }}>
                          <Eye size={20} />
                        </motion.span>
                        <motion.span className="auth-eye-icon-abs" initial={false}
                          animate={{ opacity: showPwd ? 0 : 1, rotate: showPwd ? 8 : 0, scale: showPwd ? 0.95 : 1 }}
                          transition={{ duration: 0.16, ease: "easeOut" }}>
                          <EyeOff size={20} />
                        </motion.span>
                      </span>
                    </motion.button>
                  </label>

                  {/* Error */}
                  <div className="auth-error-slot">
                    <AnimatePresence initial={false}>
                      {err && (
                        <motion.div initial={{ opacity: 0, y: -6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -6 }}
                          transition={{ duration: 0.22 }} className="auth-error-anim">
                          <div className="auth-error-box">
                            {err}
                          </div>
                        </motion.div>
                      )}
                    </AnimatePresence>
                  </div>

                  {/* Botón Entrar */}
                  <button
                    type="submit"
                    className="group auth-btn"
                    disabled={loading}
                    aria-busy={loading}
                  >
                    {loading ? (
                      <span className="auth-btn-loading">
                        <span className="auth-btn-spinner" aria-hidden="true" />
                        <span className="auth-btn-label">Cargando…</span>
                      </span>
                    ) : (
                      <>
                        <span className="auth-btn-label">Entrar</span>
                        <svg className="auth-btn-arrow" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                          <path d="M12.293 3.293a1 1 0 011.414 0l4.999 5a1 1 0 010 1.414l-5 5a1 1 0 01-1.415-1.414L14.586 11H3a1 1 0 110-2h11.586l-2.293-2.293a1 1 0 010-1.414z" />
                        </svg>
                      </>
                    )}
                  </button>

                  {/* Links */}
                  <div className="auth-links">
                    <Link
                      to="/recuperar"
                      className="auth-link-block"
                    >
                      ¿Olvidaste tu contraseña?
                    </Link>
                    <Link
                      to="/register"
                      className="auth-link-block auth-link-indigo"
                    >
                      ¿No tenés cuenta? Registrate
                    </Link>
                  </div>
                </div>
              </div>
            </form>

          </div>
        </div>
      </div>
    </motion.div>
    {avisoModal}
    </>
  );
}