// Pagina para solicitar restablecimiento de contrasena via email.
// FILE: src/pages/ForgotPassword.jsx
import React, { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Mail } from "lucide-react";
import { motion } from "framer-motion";
import useAviso from "../../compartido/componentes/modals/useAviso";
import { apiForgotPassword } from "../../compartido/api/auth";
import { mensajeAmable } from "../../compartido/lib/errores";
import "./ForgotPassword.css";

const page = {
  initial: { opacity: 0, y: 12 },
  animate: { opacity: 1, y: 0 },
  exit: { opacity: 0, y: -12 },
  transition: { duration: 0.25, ease: "easeOut" },
};

// Componente principal de la pagina de recuperacion de contrasena
export default function ForgotPassword() {
  const [email, setEmail] = useState("");
  const [sending, setSending] = useState(false);
  const [mostrarAviso, , avisoModal] = useAviso();
  const nav = useNavigate();

  // Envia la solicitud de restablecimiento de contrasena al backend
  const onSubmit = async (e) => {
    e.preventDefault();
    if (!email) return;

    try {
      setSending(true);
      await apiForgotPassword({ email });

      await mostrarAviso({
        icon: "info",
        title: "Revisá tu correo",
        text: "Si el correo existe, recibirás un enlace de restablecimiento.",
        confirmText: "OK",
      });
      nav("/login", { replace: true });
    } catch (e) {
      await mostrarAviso({
        icon: "error",
        title: "No se pudo enviar el correo",
        text: mensajeAmable(e, "No pudimos enviar el correo. Revisá tu conexión e intentá de nuevo."),
      });
    } finally {
      setSending(false);
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
                <h2 className="auth-brand-title">Recuperar contraseña</h2>
                <p className="auth-brand-desc">Te enviaremos un enlace para restablecerla.</p>
              </div>
            </div>

            {/* Form */}
            <form onSubmit={onSubmit} className="auth-form">
              <div className="auth-form-inner">
                <label className="group auth-field">
                  <span className="auth-field-sr">Email</span>
                  <div className="auth-field-icon-wrap">
                    <Mail
                      size={24}
                      className={`auth-field-icon ${
                        sending ? "auth-field-icon-disabled" : "auth-field-icon-blue"
                      }`}
                    />
                  </div>
                  <input
                    className="auth-input auth-input-blue"
                    placeholder="Tu email"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    autoComplete="email"
                    required
                    disabled={sending}
                  />
                </label>

                <button
                  type="submit"
                  disabled={sending}
                  className="group auth-btn"
                >
                  {sending ? "Enviando…" : "Enviarme el enlace"}
                </button>

                <button
                  type="button"
                  onClick={() => nav("/login")}
                  disabled={sending}
                  className="auth-back-btn"
                >
                  Volver al inicio de sesión
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
      {avisoModal}
    </motion.div>
  );
}