// Formulario para reemplazar una clave temporal por una propia.
// Se usa en dos lugares: el bloqueo de primer ingreso (BloqueoCambioClave) y
// "Mi cuenta", para que cualquiera pueda cambiar su clave sin pedir nada.
import React, { useState } from "react";
import { Eye, EyeOff, KeyRound, Loader2 } from "lucide-react";
import { useAuth } from "../../../modulos/auth/AuthContext";
import { mensajeAmable } from "../../lib/errores";
import "./CambioClaveForm.css";

export default function CambioClaveForm({ compacto = false, onExito }) {
  const { user, updateProfile, logout } = useAuth();
  const [clave, setClave] = useState("");
  const [confirmada, setConfirmada] = useState("");
  const [visible, setVisible] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState("");

  const enviar = async (e) => {
    e.preventDefault();
    if (enviando) return;
    if (clave.length < 8) {
      setError("La clave tiene que tener al menos 8 caracteres.");
      return;
    }
    if (clave !== confirmada) {
      setError("Las dos claves no coinciden.");
      return;
    }
    setError("");
    setEnviando(true);
    try {
      // El backend pide nombre y email además de la clave: se reenvían los
      // que ya tiene la cuenta, que en este paso nadie editó.
      await updateProfile({ name: user?.name ?? "", email: user?.email ?? "", password: clave });
      setClave("");
      setConfirmada("");
      onExito?.();
    } catch (err) {
      setError(mensajeAmable(err, "No pudimos cambiar tu clave. Intentá de nuevo."));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <form onSubmit={enviar} className={`ccf ${compacto ? "ccf-compacto" : ""}`}>
      {!compacto && (
        <div className="ccf-encabezado">
          <span className="ccf-icono"><KeyRound size={20} /></span>
          <div>
            <h2 className="ccf-titulo">Poné tu clave personal</h2>
            <p className="ccf-sub">
              La que te entregaron es temporal y sirve para este ingreso. Elegí una propia, de al
              menos 8 caracteres, y no la compartas.
            </p>
          </div>
        </div>
      )}

      <div className="ccf-campo">
        <label className="ccf-label" htmlFor="ccf-clave">Clave nueva</label>
        <div className="ccf-input-wrap">
          <input
            id="ccf-clave"
            type={visible ? "text" : "password"}
            value={clave}
            onChange={(e) => setClave(e.target.value)}
            autoComplete="new-password"
            placeholder="Mínimo 8 caracteres"
            className="ccf-input"
            disabled={enviando}
          />
          <button
            type="button"
            onClick={() => setVisible((v) => !v)}
            className="ccf-ojo"
            aria-label={visible ? "Ocultar la clave" : "Mostrar la clave"}
          >
            {visible ? <EyeOff size={16} /> : <Eye size={16} />}
          </button>
        </div>
      </div>

      <div className="ccf-campo">
        <label className="ccf-label" htmlFor="ccf-confirmar">Repetí la clave</label>
        <input
          id="ccf-confirmar"
          type={visible ? "text" : "password"}
          value={confirmada}
          onChange={(e) => setConfirmada(e.target.value)}
          autoComplete="new-password"
          className="ccf-input"
          disabled={enviando}
        />
      </div>

      {error && <p className="ccf-error">{error}</p>}

      <div className="ccf-acciones">
        <button type="submit" className="ccf-btn-primario" disabled={enviando}>
          {enviando ? <Loader2 size={16} className="ccf-gira" /> : <KeyRound size={16} />}
          {enviando ? "Guardando…" : "Guardar clave"}
        </button>
        <button type="button" onClick={() => logout()} className="ccf-btn-secundario">
          Cerrar sesión
        </button>
      </div>
    </form>
  );
}
