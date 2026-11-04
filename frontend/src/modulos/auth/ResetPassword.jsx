// Pagina para restablecer la contrasena usando token recibido por email.
// FILE: src/pages/ResetPassword.jsx
import React, { useMemo, useRef, useState } from "react";
import { Lock, Eye, EyeOff } from "lucide-react";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { apiResetPassword } from "../../compartido/api/auth";
import { mensajeAmable } from "../../compartido/lib/errores";
import { useNavigate, useSearchParams } from "react-router-dom";
import { motion } from "framer-motion";
import "./ResetPassword.css";

const page = {
  initial: { opacity: 0, y: 12 },
  animate: { opacity: 1, y: 0 },
  exit: { opacity: 0, y: -12 },
  transition: { duration: 0.25, ease: "easeOut" },
};

// Decodifica el email del link sin romper la página si viene mal formado.
const decodeEmailSeguro = (v) => {
  try {
    return decodeURIComponent(v || "");
  } catch {
    return v || "";
  }
};

// Componente principal de restablecimiento de contrasena
export default function ResetPassword() {
  const [params] = useSearchParams();
  const nav = useNavigate();

  const token = useMemo(() => params.get("token") || "", [params]);
  const email = useMemo(() => params.get("email") || "", [params]);

  const [mostrarAviso, , avisoModal] = useAviso();

  // Si el enlace viene sin código o sin correo, no se muestra el formulario:
  // se explica qué pasó y cómo pedir uno nuevo.
  const enlaceValido = Boolean(token && email);
  const [pwd, setPwd] = useState("");
  const [pwd2, setPwd2] = useState("");
  const [show1, setShow1] = useState(false);
  const [show2, setShow2] = useState(false);
  const [loading, setLoading] = useState(false);
  const ref1 = useRef(null);
  const ref2 = useRef(null);

  const toggle = (which) => {
    const el = which === 1 ? ref1.current : ref2.current;
    const pos = el ? el.selectionStart : null;
    which === 1 ? setShow1((v) => !v) : setShow2((v) => !v);
    requestAnimationFrame(() => {
      if (!el) return;
      el.focus({ preventScroll: true });
      try { if (pos != null) el.setSelectionRange(pos, pos); } catch { /* inputs no textuales no soportan selección */ }
    });
  };

  // Envia la nueva contrasena con el token al backend para restablecerla
  const onSubmit = async (e) => {
    e.preventDefault();
    if (!pwd || pwd.length < 8) {
      await mostrarAviso({ icon: "warning", title: "Contraseña inválida", text: "Debe tener al menos 8 caracteres." });
      return;
    }
    if (pwd !== pwd2) {
      await mostrarAviso({ icon: "warning", title: "No coinciden", text: "Las contraseñas no coinciden." });
      return;
    }

    if (loading) return;
    setLoading(true);
    try {
      await apiResetPassword({ token, email, password: pwd, password_confirmation: pwd2 });
      await mostrarAviso({ icon: "success", title: "Listo", text: "Tu contraseña fue restablecida." });
      nav("/login", { replace: true });
    } catch (e) {
      await mostrarAviso({ icon: "error", title: "No se pudo restablecer", text: mensajeAmable(e, "No pudimos cambiar tu contraseña. Pedí un enlace nuevo e intentá de nuevo.") });
    } finally {
      setLoading(false);
    }
  };

  return (
    <motion.div
      className="auth-page"
      initial={page.initial}
      animate={page.animate}
      exit={page.exit}
      transition={page.transition}
    >
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
                <img src="/EscudoENRO.png" alt="Escuela" className="auth-brand-img" />
                <h2 className="auth-brand-title">Restablecer contraseña</h2>
                <p className="auth-brand-desc">{decodeEmailSeguro(email)}</p>
              </div>
            </div>

            {/* Form */}
            <form onSubmit={onSubmit} className="auth-form">
              <div className="auth-form-inner">
                {!enlaceValido ? (
                  <>
                    <div className="auth-heading">
                      <h1 className="auth-heading-title">Este enlace no sirve</h1>
                      <div className="auth-heading-bar" />
                    </div>
                    <p className="auth-hint">Venció, ya se usó o llegó incompleto. Pedí uno nuevo y probá de nuevo.</p>
                    <button
                      type="button"
                      onClick={() => nav("/recuperar")}
                      className="group auth-btn"
                    >
                      Pedir un enlace nuevo
                    </button>
                    <button
                      type="button"
                      onClick={() => nav("/login")}
                      className="auth-back-btn"
                    >
                      Volver al inicio de sesión
                    </button>
                  </>
                ) : (
                  <>
                {/* Nueva contraseña */}
                <label className="group auth-field">
                  <span className="auth-field-sr">Nueva contraseña</span>
                  <div className="auth-field-icon-wrap">
                    <Lock size={24} className="auth-field-icon auth-field-icon-indigo" />
                  </div>
                  <input
                    ref={ref1}
                    className="auth-input auth-input-indigo auth-input-has-eye"
                    placeholder="Nueva contraseña"
                    type={show1 ? "text" : "password"}
                    value={pwd}
                    onChange={(e) => setPwd(e.target.value)}
                    autoComplete="new-password"
                    required
                  />
                  <motion.button
                    type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => toggle(1)}
                    className="auth-eye-btn"
                    whileTap={{ scale: 0.98 }}
                  >
                    <span className="auth-eye-icon-wrap">
                      <motion.span className="auth-eye-icon-abs" initial={false}
                        animate={{ opacity: show1 ? 1 : 0, rotate: show1 ? 0 : -8, scale: show1 ? 1 : 0.95 }}
                        transition={{ duration: 0.16, ease: "easeOut" }}>
                        <Eye size={20} />
                      </motion.span>
                      <motion.span className="auth-eye-icon-abs" initial={false}
                        animate={{ opacity: show1 ? 0 : 1, rotate: show1 ? 8 : 0, scale: show1 ? 0.95 : 1 }}
                        transition={{ duration: 0.16, ease: "easeOut" }}>
                        <EyeOff size={20} />
                      </motion.span>
                    </span>
                  </motion.button>
                </label>

                {/* Confirmar */}
                <label className="group auth-field">
                  <span className="auth-field-sr">Confirmar contraseña</span>
                  <div className="auth-field-icon-wrap">
                    <Lock size={24} className="auth-field-icon auth-field-icon-indigo" />
                  </div>
                  <input
                    ref={ref2}
                    className="auth-input auth-input-indigo auth-input-has-eye"
                    placeholder="Confirmar contraseña"
                    type={show2 ? "text" : "password"}
                    value={pwd2}
                    onChange={(e) => setPwd2(e.target.value)}
                    autoComplete="new-password"
                    required
                  />
                  <motion.button
                    type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => toggle(2)}
                    className="auth-eye-btn"
                    whileTap={{ scale: 0.98 }}
                  >
                    <span className="auth-eye-icon-wrap">
                      <motion.span className="auth-eye-icon-abs" initial={false}
                        animate={{ opacity: show2 ? 1 : 0, rotate: show2 ? 0 : -8, scale: show2 ? 1 : 0.95 }}
                        transition={{ duration: 0.16, ease: "easeOut" }}>
                        <Eye size={20} />
                      </motion.span>
                      <motion.span className="auth-eye-icon-abs" initial={false}
                        animate={{ opacity: show2 ? 0 : 1, rotate: show2 ? 8 : 0, scale: show2 ? 0.95 : 1 }}
                        transition={{ duration: 0.16, ease: "easeOut" }}>
                        <EyeOff size={20} />
                      </motion.span>
                    </span>
                  </motion.button>
                </label>

                <button
                  type="submit"
                  className="group auth-btn"
                  disabled={loading}
                >
                  {loading ? "Restableciendo…" : "Restablecer contraseña"}
                </button>

                <button
                  type="button"
                  onClick={() => nav("/login")}
                  className="auth-back-btn"
                >
                  Volver al inicio de sesión
                </button>
                  </>
                )}
              </div>
            </form>

          </div>
        </div>
      </div>
      {avisoModal}
    </motion.div>
  );
}