// ===============================
// FILE: src/components/AccountModal.jsx
// ===============================
import React, { useEffect, useRef, useState } from "react";
import { motion } from "framer-motion";
import useCierreAnimado from "../../lib/useCierreAnimado";
import { bloquearScroll } from "../../lib/lockScroll";
import { iniciales } from "../../lib/iniciales";

// Componente: AccountModal
// Proposito: Modal de informacion de la cuenta del usuario. Muestra datos personales,
//            rol, timestamps (creado, actualizado, ultimo acceso), opcion de copiar
//            email y boton para cerrar sesion con confirmacion.
// Props:
//   open    - Booleano que controla la visibilidad del modal.
//   onClose - Funcion para cerrar el modal.
import {
  X, Mail, Shield, Copy, LogOut, CheckCircle2, Clock, CalendarDays, AtSign, GraduationCap, Loader2, Monitor, Camera, Image as ImageIcon, LogOut as SalirEquipo, KeyRound
} from "lucide-react";
import useAviso from "./useAviso";
import CambioClaveForm from "../Common/CambioClaveForm";
import { useAuth } from "../../../modulos/auth/AuthContext";
import { useNavigate } from "react-router-dom";
import { apiAmbito, apiSesiones, apiCerrarSesiones } from "../../api/auth";
import { getCached, setCache } from "../../lib/pageCache";
import { cursoLabel } from "../../lib/cursos";
import Avatar from "../Common/Avatar";
import "./AccountModal.css";

/* ---------- Helpers fecha ---------- */
function pad(n) { return String(n).padStart(2, "0"); }
function formatFechaHumana(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  const now = new Date();
  const sameDay = d.toDateString() === now.toDateString();
  const ayer = new Date(now); ayer.setDate(now.getDate() - 1);
  const isAyer = d.toDateString() === ayer.toDateString();
  const hora = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
  if (sameDay) return `hoy ${hora}`;
  if (isAyer) return `ayer ${hora}`;
  const meses = ["ene","feb","mar","abr","may","jun","jul","ago","sep","oct","nov","dic"];
  return `${d.getDate()} ${meses[d.getMonth()]} ${d.getFullYear()} ${hora}`;
}

/* ---------- Traducción de roles ---------- */
const roleLabel = (r) =>
  r === "admin" ? "Administrador/a" :
  r === "director" ? "Director/a" :
  r === "directivo" ? "Directivo/a" :
  r === "jefe_preceptores" ? "Jefe de preceptores" :
  r === "preceptor" ? "Preceptor/a" :
  r === "prosecretaria" ? "Prosecretaria" :
  r === "profesor" ? "Profesor/a" : (r || "—");

const AccountModal = ({ open, onClose }) => {
  const { user, logout, subirAvatar, quitarAvatar } = useAuth();
  const dialogRef = useRef(null);
  const navigate = useNavigate();

  // Datos del usuario
  const nombre      = user?.name || "Usuario";
  const email       = user?.email || "sin-email@escuela.edu";
  const rolText     = roleLabel(user?.role);
  const creado      = user?.created_at || null;
  const actualizado = user?.updated_at || null;
  const ultimoAcc   = user?.last_login_at || null;

  // Fallback local de última sesión si no hay last_login_at aún
  const [ultimaSesionLocal, setUltimaSesionLocal] = useState(
    () => { try { return localStorage.getItem("lastSessionAt") || ""; } catch { return ""; } }
  );

  const [copiado, setCopiado] = useState(false);

  // Confirmacion de cierre de sesion (modal propio, mismo estilo que la cuenta)
  const [confirmLogout, setConfirmLogout] = useState(false);
  const [saliendo, setSaliendo] = useState(false);
  const [mostrarAviso, , avisoModal, mostrarToast] = useAviso();
  // Cierre animado por CSS: el diálogo queda montado mientras termina la salida,
  // sin el frame opaco que Framer dejaba justo antes de desmontar.
  const { montado, cerrando } = useCierreAnimado(open);
  const { montado: montadoConfirm, cerrando: cerrandoConfirm } = useCierreAnimado(confirmLogout);

  // Cursos asignados al usuario (desde /me/ambito). Se muestra en el modal:
  // asignaciones null = sin restriccion (todos los cursos), lista vacia = sin cursos.
  const [ambitoData, setAmbitoData] = useState(() => {
    const cached = getCached('ambito');
    return cached?.role ? cached : null;
  });

  useEffect(() => {
    if (!open) return;
    let cancel = false;
    apiAmbito()
      .then((data) => {
        if (cancel || !data?.role) return;
        setAmbitoData(data);
        setCache('ambito', data);
      })
      .catch(() => { /* se mantiene el valor cacheado si existe */ });
    return () => { cancel = true; };
  }, [open]);

  /* Copia el email al portapapeles y muestra toast de confirmacion */
  const copiar = async () => {
    try {
      await navigator.clipboard.writeText(email);
      setCopiado(true);
      setTimeout(() => setCopiado(false), 1200);
      mostrarToast({ icon: "success", title: "Correo copiado" });
    } catch {
      mostrarToast({ icon: "error", title: "No se pudo copiar" });
    }
  };

  /* ---------- Foto de perfil ---------- */
  // La sube el usuario mismo; el backend la recorta y la achica. La version
  // nueva llega en la respuesta y AuthContext la guarda en `user`.
  const inputFoto = useRef(null);
  const [subiendoFoto, setSubiendoFoto] = useState(false);
  const [errorFoto, setErrorFoto] = useState("");
  const [claveAbierta, setClaveAbierta] = useState(false);

  const cambiarFoto = async (archivo) => {
    if (!archivo) return;
    setSubiendoFoto(true);
    setErrorFoto("");
    try {
      await subirAvatar(archivo);
      mostrarToast({ icon: "success", title: "Foto actualizada" });
    } catch (e) {
      setErrorFoto(e?.message || "No se pudo subir la foto.");
    } finally {
      setSubiendoFoto(false);
    }
  };

  const elegirFoto = (e) => {
    const archivo = e.target.files?.[0];
    e.target.value = ""; // para poder elegir el mismo archivo otra vez
    cambiarFoto(archivo);
  };

  const quitarFoto = async () => {
    setSubiendoFoto(true);
    setErrorFoto("");
    try {
      await quitarAvatar();
      mostrarToast({ icon: "success", title: "Se quitó tu foto" });
    } catch (e) {
      setErrorFoto(e?.message || "No se pudo quitar la foto.");
    } finally {
      setSubiendoFoto(false);
    }
  };

  /* Cierra el modal con la tecla Escape (confirma logout primero) */
  useEffect(() => {
    if (!open) return;
    const onKey = (e) => {
      if (e.key !== "Escape") return;
      if (confirmLogout && !saliendo) { setConfirmLogout(false); return; }
      onClose?.();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose, confirmLogout, saliendo]);

  /* Bloquea el scroll del body mientras el modal esta montado (incluida la
     animación de salida): liberarlo antes hacía volver la barra de scroll con
     el diálogo todavía a media opacidad y la pantalla saltaba de lado. */
  useEffect(() => {
    if (!montado) return;
    return bloquearScroll();
  }, [montado]);

  const initials = iniciales(nombre, user?.apellido);

  /* Maneja el cierre de sesion con confirmacion, guardando la fecha y redirigiendo al login */
  const handleLogout = async () => {
    setSaliendo(true);
    try {
      const nowIso = new Date().toISOString();
      try { localStorage.setItem("lastSessionAt", nowIso); } catch { /* storage no disponible */ }
      setUltimaSesionLocal(nowIso);

      await logout();
      setConfirmLogout(false);
      onClose?.();
      navigate("/login?logged_out=1", { replace: true });
    } catch {
      await mostrarAviso({ icon: "error", title: "No se pudo cerrar sesión" });
    } finally {
      setSaliendo(false);
    }
  };

  /* Equipos con sesion abierta: se pueden cerrar uno por uno sin perder los demas */
  const [sesiones, setSesiones] = useState(null);
  const [cerrandoSesion, setCerrandoSesion] = useState(false);

  const cargarSesiones = async () => {
    try {
      const r = await apiSesiones();
      setSesiones(r?.sesiones ?? []);
    } catch {
      setSesiones([]);
    }
  };

  useEffect(() => {
    if (open) cargarSesiones();
  }, [open]);

  /* Cierra la sesion de UN equipo, o de todos los otros */
  const cerrarEquipo = async (id, todas) => {
    setCerrandoSesion(true);
    try {
      const r = await apiCerrarSesiones(todas ? [] : [id], todas);
      await cargarSesiones();
      mostrarToast({
        icon: "success",
        title: todas
          ? `Se cerraron ${r?.cerradas ?? 0} sesión(es)`
          : "Se cerró la sesión de ese equipo",
      });
    } catch {
      mostrarToast({ icon: "error", title: "No se pudo cerrar la sesión" });
    } finally {
      setCerrandoSesion(false);
    }
  };

  const otras = (sesiones || []).filter((s) => !s.es_esta);

  // ORDEN: 1) Rol, 2) Último acceso, 3) Creado, 4) Actualizado
  const infoCards = [
    { icon: Shield,       color: "acc-ic-blue",    label: "Rol",            value: rolText },
    {
      icon: Clock,        color: "acc-ic-pink",    label: "Último acceso",
      value: ultimoAcc ? formatFechaHumana(ultimoAcc)
            : (ultimaSesionLocal ? `${formatFechaHumana(ultimaSesionLocal)} (local)` : "—")
    },
    { icon: CalendarDays, color: "acc-ic-emerald", label: "Creado",         value: formatFechaHumana(creado) },
    { icon: Clock,        color: "acc-ic-purple",  label: "Actualizado",    value: formatFechaHumana(actualizado) },
  ];

  return (
    <>
    {montado && (
        <div className={`acc-backdrop sys-capa${cerrando ? " sys-cerrando" : ""}`}>
          <div
            ref={dialogRef}
            className={`acc-dialog sys-dialogo${cerrando ? " sys-cerrando" : ""}`}
            role="dialog"
            aria-modal="true"
          >
            {/* Top Accent */}
            <div className="acc-top-accent" />

            {/* HERO HEADER */}
            <div className="acc-hero">
              {/* halos suaves */}
              <div className="acc-halo acc-halo-blue" />
              <div className="acc-halo acc-halo-purple" />

              <div className="acc-hero-bar">
                <div className="acc-hero-left">
                  {/* La foto se cambia desde acá o desde la tarjeta de abajo. */}
                  <button
                    type="button"
                    className="acc-avatar-btn"
                    onClick={() => inputFoto.current?.click()}
                    disabled={subiendoFoto}
                    title="Cambiar tu foto de perfil"
                  >
                    <Avatar
                      id={user?.id}
                      version={user?.avatar_version}
                      titulo={nombre}
                      className="acc-avatar"
                    >
                      {initials}
                    </Avatar>
                    <span className="acc-avatar-cam">
                      {subiendoFoto ? <Loader2 size={12} className="acc-spin" /> : <Camera size={12} />}
                    </span>
                  </button>
                  <input
                    ref={inputFoto}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="acc-file"
                    onChange={elegirFoto}
                  />

                  <div className="acc-hero-text">
                    <h3 className="acc-hero-name">{nombre}</h3>
                    <div className="acc-hero-email">
                      <AtSign size={14} className="acc-hero-email-icon" />
                      {email}
                    </div>
                  </div>

                  {/* ✕ con hover suave */}
                  <button
                    onClick={onClose}
                    aria-label="Cerrar"
                    className="acc-close"
                  >
                    <X size={20} />
                  </button>
                </div>
              </div>
            </div>

            {/* Body */}
            <motion.div 
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ delay: 0.08, duration: 0.16 }}
              className="acc-body"
            >
              {/* Correo con copiar */}
              <motion.div
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.12, duration: 0.2 }}
                className="acc-card"
              >
                <div className="acc-card-label-row">
                  <Mail size={18} className="acc-ic-blue" />
                  <span className="acc-card-label">Correo</span>
                </div>
                <div className="acc-email-row">
                  <span className="acc-email-text">{email}</span>
                  <button
                    onClick={copiar}
                    title="Copiar email"
                    className="acc-copy"
                  >
                    {copiado ? <CheckCircle2 size={14} className="acc-ic-green" /> : <Copy size={14} />}
                    {copiado ? "Copiado" : "Copiar"}
                  </button>
                </div>
              </motion.div>

              {/* Foto de perfil */}
              <motion.div
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.14, duration: 0.2 }}
                className="acc-card"
              >
                <div className="acc-card-label-row">
                  <ImageIcon size={18} className="acc-ic-blue" />
                  <span className="acc-card-label">Foto de perfil</span>
                </div>
                <div className="acc-foto-fila">
                  <span className="acc-foto-texto">
                    {user?.avatar_version
                      ? "Ya tienes foto: es la que ven tus compañeros."
                      : "Sin foto: se muestran tus iniciales."}
                  </span>
                  <span className="acc-foto-acciones">
                    <button
                      onClick={() => inputFoto.current?.click()}
                      disabled={subiendoFoto}
                      className="acc-foto-btn"
                    >
                      {subiendoFoto ? "Subiendo..." : (user?.avatar_version ? "Cambiar foto" : "Subir foto")}
                    </button>
                    {!!user?.avatar_version && (
                      <button
                        onClick={quitarFoto}
                        disabled={subiendoFoto}
                        className="acc-foto-btn acc-foto-btn-quiet"
                      >
                        Quitar
                      </button>
                    )}
                  </span>
                </div>
                {errorFoto && <div className="acc-foto-error">{errorFoto}</div>}
                <div className="acc-foto-pista">
                  JPG, PNG o WebP de hasta 4 MB. Se recorta al centro y se achica sola.
                </div>
              </motion.div>

              {/* Clave de acceso: se cambia acá, sin depender del correo */}
              <motion.div
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.15, duration: 0.2 }}
                className="acc-card"
              >
                <div className="acc-card-label-row">
                  <KeyRound size={18} className="acc-ic-blue" />
                  <span className="acc-card-label">Clave de acceso</span>
                </div>
                <div className="acc-foto-fila">
                  <span className="acc-foto-texto">
                    {user?.debe_cambiar_password
                      ? "Estás con una clave provisoria: poné una propia."
                      : "Cambiá tu clave cuando quieras, sin pedirle nada a nadie."}
                  </span>
                  <button
                    onClick={() => setClaveAbierta((v) => !v)}
                    className="acc-foto-btn"
                  >
                    {claveAbierta ? "Ocultar" : "Cambiar clave"}
                  </button>
                </div>
                {claveAbierta && (
                  <div className="acc-clave-form">
                    <CambioClaveForm compacto onExito={() => setClaveAbierta(false)} />
                  </div>
                )}
              </motion.div>

              {/* Grid 2x2 simétrico */}
              <div className="acc-grid">
                {infoCards.map(({ icon:Icon, color, label, value }, i) => (
                  <motion.div
                    key={label}
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.16 + i * 0.04, duration: 0.2 }}
                    className="acc-card"
                  >
                    <div className="acc-card-label-row">
                      <Icon size={20} className={color} />
                      <span className="acc-card-label">{label}</span>
                    </div>
                    <div className="acc-card-value">{value}</div>
                  </motion.div>
                ))}
              </div>

              {/* Cursos asignados al usuario */}
              <motion.div
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.32, duration: 0.2 }}
                className="acc-card"
              >
                <div className="acc-card-label-row">
                  <GraduationCap size={18} className="acc-ic-blue" />
                  <span className="acc-card-label">Cursos asignados</span>
                </div>
                {ambitoData == null ? (
                  <div className="acc-cursos-empty">Cargando...</div>
                ) : !Array.isArray(ambitoData.asignaciones) ? (
                  <div className="acc-cursos-todos">Todos los cursos</div>
                ) : ambitoData.asignaciones.length === 0 ? (
                  <div className="acc-cursos-empty">Sin cursos asignados</div>
                ) : (
                  <div className="acc-cursos-chips">
                    {ambitoData.asignaciones.map((a, i) => (
                      <span key={`${a.anio}-${a.division ?? 'todas'}-${i}`} className="acc-curso-chip">
                        {cursoLabel(a) || `Año ${a.anio}`}
                      </span>
                    ))}
                  </div>
                )}
              </motion.div>

              {/* Equipos conectados: permite cerrar la sesion de uno sin perder la de otros */}
              <motion.div
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.36, duration: 0.2 }}
                className="acc-card"
              >
                <div className="acc-card-label-row">
                  <Monitor size={18} className="acc-ic-blue" />
                  <span className="acc-card-label">Equipos conectados</span>
                </div>
                {sesiones == null ? (
                  <div className="acc-cursos-empty">Cargando...</div>
                ) : otras.length === 0 ? (
                  <div className="acc-cursos-empty">
                    Solo estás conectado en este equipo.
                  </div>
                ) : (
                  <>
                    <ul className="acc-sesiones">
                      {sesiones.map((s) => (
                        <li key={s.id} className="acc-sesion">
                          <div className="acc-sesion-info">
                            <div className="acc-sesion-nombre">
                              {s.dispositivo}
                              {s.es_esta && <span className="acc-sesion-actual">este equipo</span>}
                            </div>
                            <div className="acc-sesion-ultima">
                              {s.es_esta
                                ? "Conectado ahora"
                                : `Última conexión: ${formatFechaHumana(s.ultimo_uso_at)}`}
                            </div>
                          </div>
                          {!s.es_esta && (
                            <button
                              onClick={() => cerrarEquipo(s.id, false)}
                              disabled={cerrandoSesion}
                              className="acc-sesion-btn"
                              title="Cerrar la sesión de ese equipo"
                            >
                              <SalirEquipo size={14} />
                              Cerrar
                            </button>
                          )}
                        </li>
                      ))}
                    </ul>
                    <button
                      onClick={() => cerrarEquipo(null, true)}
                      disabled={cerrandoSesion}
                      className="acc-sesion-cerrar-todas"
                    >
                      {cerrandoSesion
                        ? "Cerrando..."
                        : `Cerrar las otras ${otras.length} sesión(es)`}
                    </button>
                  </>
                )}
              </motion.div>
            </motion.div>

            {/* Footer */}
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ delay: 0.24, duration: 0.18 }}
              className="acc-footer"
            >
              <div className="acc-footer-actions">
                <button
                  onClick={() => setConfirmLogout(true)}
                  disabled={saliendo}
                  className="acc-logout"
                >
                  <LogOut size={16} /> Cerrar sesión
                </button>
              </div>
            </motion.div>

          </div>

          {/* Confirmacion de cierre de sesion */}
          {montadoConfirm && (
              <div className={`acc-backdrop acc-backdrop-confirm sys-capa${cerrandoConfirm ? " sys-cerrando" : ""}`}>
                <div
                  className={`acc-confirm sys-dialogo${cerrandoConfirm ? " sys-cerrando" : ""}`}
                  role="dialog"
                  aria-modal="true"
                  onMouseDown={(e) => e.stopPropagation()}
                >
                  <div className="acc-confirm-icon">
                    <LogOut size={24} />
                  </div>
                  <h3 className="acc-confirm-title">¿Cerrar sesión?</h3>
                  <p className="acc-confirm-text">Vas a salir de tu cuenta actual.</p>
                  <div className="acc-confirm-actions">
                    <button
                      className="acc-confirm-cancel"
                      onClick={() => setConfirmLogout(false)}
                      disabled={saliendo}
                    >
                      Cancelar
                    </button>
                    <button
                      className="acc-confirm-danger"
                      onClick={handleLogout}
                      disabled={saliendo}
                    >
                      {saliendo && <Loader2 size={16} className="acc-spin" />}
                      {saliendo ? "Cerrando sesión..." : "Sí, cerrar sesión"}
                    </button>
                  </div>
                </div>
              </div>
          )}

        </div>
      )}
    {avisoModal}
    </>
  );
};

export default AccountModal;