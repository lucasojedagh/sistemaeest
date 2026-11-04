// Pagina de registro de nuevos usuarios del sistema escolar.
import React, { useState } from "react";
import { useAuth } from "../auth/AuthContext";
import { useNavigate, Link } from "react-router-dom";
import { Mail, Lock, User, Eye, EyeOff } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";
import { mensajeAmable } from "../../compartido/lib/errores";
import "../../compartido/styles/Register.css";

const page = {
  initial: { opacity: 0, y: 12 },
  animate: { opacity: 1, y: 0 },
  exit: { opacity: 0, y: -12 },
  transition: { duration: 0.25, ease: "easeOut" },
};

// Componente principal de la pagina de registro
export default function Register() {
  const { register } = useAuth();
  const nav = useNavigate();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [rolSolicitado, setRolSolicitado] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [showPwd, setShowPwd] = useState(false);
  const [pwdFocused, setPwdFocused] = useState(false);
  const [err, setErr] = useState("");
  const [loading, setLoading] = useState(false);

    // Envia los datos del formulario para registrar un nuevo usuario
    const onSubmit = async (e) => {
      e.preventDefault();
      if (loading) return;
      setErr("");
      setLoading(true);

      if (!name.trim()) {
        setErr("El nombre es obligatorio");
        setLoading(false);
        return;
      }
      if (!email.trim()) {
        setErr("El email es obligatorio");
        setLoading(false);
        return;
      }
      if (!email.toLowerCase().endsWith("@abc.gob.ar")) {
        setErr("Usá tu correo de la escuela (termina en @abc.gob.ar). Si no tenés uno, pedilo en dirección.");
        setLoading(false);
        return;
      }
      if (!rolSolicitado) {
        setErr("Elegí qué sos en la escuela");
        setLoading(false);
        return;
      }

      if (password !== passwordConfirmation) {
        setErr("Las contraseñas no coinciden");
        setLoading(false);
        return;
      }


    try {
      await register(name.trim(), email.trim(), password, passwordConfirmation, rolSolicitado);
      nav("/login?registrado=1", { replace: true });
    } catch (e) {
      setErr(mensajeAmable(e, "No pudimos registrarte. Revisá los datos e intentá de nuevo."));
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
                <p className="auth-brand-tagline">Crear cuenta nueva</p>
              </div>
            </div>

            <form onSubmit={onSubmit} className="auth-form">
              <div className="auth-form-inner">
                <div className="auth-heading">
                  <h1 className="auth-heading-title">Registrarse</h1>
                  <div className="auth-heading-bar" />
                </div>

                <div className="auth-fields">
                  <label className="group auth-field">
                    <span className="auth-field-sr">Nombre</span>
                    <div className="auth-field-icon-wrap">
                      <User size={24} className="auth-field-icon auth-field-icon-blue" />
                    </div>
                    <input id="reg-name" name="name"
                      className="auth-input auth-input-blue"
                      placeholder="Nombre" type="text" value={name}
                      onChange={(e) => setName(e.target.value)} autoComplete="given-name" required
                    />
                  </label>

                  <label className="group auth-field">
                    <span className="auth-field-sr">Email</span>
                    <div className="auth-field-icon-wrap">
                      <Mail size={24} className="auth-field-icon auth-field-icon-blue" />
                    </div>
                    <input id="reg-email" name="email"
                      className="auth-input auth-input-blue"
                      placeholder="Email de la escuela (@abc.gob.ar)" type="email" value={email}
                      onChange={(e) => setEmail(e.target.value)} autoComplete="email" required
                    />
                  </label>
                  <p className="auth-hint">Tiene que terminar en @abc.gob.ar. Si no tenés uno, pedilo en dirección.</p>

                  <label className="group auth-field">
                    <span className="auth-field-sr">Contraseña</span>
                    <div className="auth-field-icon-wrap">
                      <Lock size={24} className="auth-field-icon auth-field-icon-indigo" />
                    </div>
                    <input id="reg-password" name="password"
                      className="auth-input auth-input-indigo auth-input-has-eye"
                      placeholder="Contraseña"
                      type={showPwd ? "text" : "password"}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      onFocus={() => setPwdFocused(true)}
                      onBlur={() => setPwdFocused(false)}
                      autoComplete="new-password"
                      required
                    />
                    <motion.button
                      type="button"
                      onMouseDown={(e) => e.preventDefault()}
                      onClick={() => setShowPwd((v) => !v)}
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

                  <label className="group auth-field">
                    <span className="auth-field-sr">Confirmar contraseña</span>
                    <div className="auth-field-icon-wrap">
                      <Lock size={24} className="auth-field-icon auth-field-icon-indigo" />
                    </div>
                    <input id="reg-password-confirm" name="password_confirmation"
                      className="auth-input auth-input-indigo"
                      placeholder="Confirmar contraseña"
                      type={showPwd ? "text" : "password"}
                      value={passwordConfirmation}
                      onChange={(e) => setPasswordConfirmation(e.target.value)}
                      autoComplete="new-password"
                      required
                    />
                  </label>

                  <label className="group auth-field">
                    <span className="auth-field-sr">Qué sos en la escuela</span>
                    <div className="auth-field-icon-wrap">
                      <User size={24} className="auth-field-icon auth-field-icon-blue" />
                    </div>
                    <select id="reg-rol-solicitado" name="rol_solicitado"
                      className="auth-input auth-input-blue"
                      value={rolSolicitado}
                      onChange={(e) => setRolSolicitado(e.target.value)} required
                    >
                      <option value="">¿Qué sos en la escuela? (elegí)</option>
                      <option value="preceptor">Preceptor/a</option>
                      <option value="profesor">Profesor/a</option>
                      <option value="directivo">Directivo</option>
                    </select>
                  </label>

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

                  <button
                    type="submit"
                    className="group auth-btn"
                    disabled={loading}
                    aria-busy={loading}
                  >
                    {loading ? (
                      <span className="auth-spinner" aria-hidden="true" />
                    ) : (
                      <span className="auth-btn-label">Crear cuenta</span>
                    )}
                    {!loading && (
                      <svg className="auth-btn-arrow" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M12.293 3.293a1 1 0 011.414 0l4.999 5a1 1 0 010 1.414l-5 5a1 1 0 01-1.415-1.414L14.586 11H3a1 1 0 110-2h11.586l-2.293-2.293a1 1 0 010-1.414z" />
                      </svg>
                    )}
                  </button>

                  <div className="auth-form-footer">
                    <Link
                      to="/login"
                      className="auth-link-register"
                    >
                      ¿Ya tenés cuenta? Iniciar sesión
                    </Link>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </motion.div>
  );
}