// ===============================
// FILE: src/components/Common/FichaLayout.jsx
// ===============================
import React, { useEffect, useRef, useState } from "react";
import { X } from "lucide-react";
import Avatar from "./Avatar";
import { iniciales } from "../../lib/iniciales";
import "./FichaLayout.css";

/*
 * Componente: FichaLayout
 * Props:
 *   open        - Booleano que controla la visibilidad.
 *   onClose     - Cierra el modal.
 *   titulo      - Texto del encabezado (ej.: "Ficha del Profesor").
 *   children    - Contenido (cards de la ficha).
 *   avatarId    - id de la cuenta cuya foto se muestra en el encabezado.
 *   avatarVersion - versión de esa foto (cambia cuando el usuario sube otra).
 *   avatarFallback - iniciales explícitas cuando la cuenta no tiene foto.
 *   headerRight - Nodo opcional a la derecha del encabezado (chips de estado, etc).
 *
 * Shell visual idéntico al modal de "Mi cuenta" (AccountModal):
 * glassmorphism, backdrop radial oscuro, modal blanco/80 con blur,
 * barra superior con gradiente, halos de color y avatar de iniciales.
 *
 * Animación 100% CSS (keyframes): el modal tiene backdrop-filter, y animar su
 * opacidad con Framer Motion provocaban un parpadeo de ~250ms al abrir.
 */

const DURACION_CIERRE_MS = 190;

const field = "ficha-field";
const valBig = "ficha-val-big";
const card = "ficha-card";
const chipBase = "ficha-chip";
const chipGray = `${chipBase} ficha-chip-gray`;
const chipBlue = `${chipBase} ficha-chip-blue`;
const chipGreen = `${chipBase} ficha-chip-green`;
const tag = "ficha-tag";

/* Convierte cualquier valor a string seguro */
const safe = (s) => (s == null ? "" : String(s));
/* Iniciales del avatar: la regla es una sola en todo el sistema (ver lib/iniciales) */
const initials = iniciales;

export default function FichaLayout({
  open,
  onClose,
  titulo = "Ficha",
  subtitulo,
  avatarId,
  avatarVersion,
  avatarFallback,
  children,
}) {
  // Desmontamos recién cuando termina la animación de salida.
  const [visible, setVisible] = useState(open);
  const [cerrando, setCerrando] = useState(false);

  // Ajustamos el estado durante el render: esperar al pintado siguiente dejaría
  // un frame sin modal al abrir, que es justo el parpadeo que se veía.
  if (open) {
    if (!visible) setVisible(true);
    if (cerrando) setCerrando(false);
  } else if (visible && !cerrando) {
    setCerrando(true);
  }

  // Al cerrar, el padre limpia los datos (alumno/profesor/preceptor = null).
  // Congelamos la última versión abierta para que el fade de salida muestre
  // la ficha real y no un encabezado vacío.
  const ultimaAperturaRef = useRef(null);
  const apertura = { children, titulo, subtitulo, avatarId, avatarVersion, avatarFallback };
  useEffect(() => {
    if (open) ultimaAperturaRef.current = apertura;
  });
  const contenido = open || !ultimaAperturaRef.current ? apertura : ultimaAperturaRef.current;

  useEffect(() => {
    if (!cerrando) return;
    const timer = window.setTimeout(() => {
      setVisible(false);
      setCerrando(false);
    }, DURACION_CIERRE_MS);
    return () => window.clearTimeout(timer);
  }, [cerrando]);

  if (!visible) return null;

  const {
    children: contenidoFicha,
    titulo: tituloFicha,
    subtitulo: subtituloFicha,
    avatarId: idFoto,
    avatarVersion: versionFoto,
    avatarFallback: inicialesFoto,
  } = contenido;

  // Sin foto de la cuenta quedan las iniciales: las que pide la ficha o, si no
  // las indica, las que se pueden deducir de los datos que ya trae el contenido.
  const iniciales =
    inicialesFoto ||
    ((safe(tituloFicha).includes("Alumno")
      ? initials(contenidoFicha?.props?.alumno?.nombre, contenidoFicha?.props?.alumno?.apellido)
      : initials(
          (contenidoFicha?.props?.profesor || contenidoFicha?.props?.preceptor)?.nombre ??
            (contenidoFicha?.props?.profesor || contenidoFicha?.props?.preceptor)?.name,
          (contenidoFicha?.props?.profesor || contenidoFicha?.props?.preceptor)?.apellido ??
            (contenidoFicha?.props?.profesor || contenidoFicha?.props?.preceptor)?.lastname
        )) ||
      "•");

  return (
    <div className={`ficha-backdrop${cerrando ? " is-closing" : ""}`}>
      <div
        className={`ficha-modal${cerrando ? " is-closing" : ""}`}
        role="dialog"
        aria-modal="true"
        aria-label={safe(tituloFicha)}
      >
        {/* Barra superior con gradiente */}
        <div className="ficha-top-bar" />

        {/* Hero header */}
        <div className="ficha-hero">
          <div className="ficha-halo ficha-halo-blue" />
          <div className="ficha-halo ficha-halo-purple" />

          <div className="ficha-header">
            <div className="ficha-header-row">
              <Avatar
                id={idFoto}
                version={versionFoto}
                className="ficha-avatar"
                titulo={tituloFicha}
              >
                {iniciales}
              </Avatar>

              <div className="ficha-header-text">
                <h3 className="ficha-title">{tituloFicha}</h3>
                <div className="ficha-subtitle">{subtituloFicha ?? contenidoFicha?.props?.subtitulo ?? ""}</div>
              </div>

              <button
                onClick={onClose}
                aria-label="Cerrar"
                className="ficha-close"
              >
                <X size={20} />
              </button>
            </div>
          </div>
        </div>

        {/* Contenido */}
        <div className="ficha-content">{contenidoFicha}</div>
      </div>
    </div>
  );
}

export { field, valBig, card, chipBase, chipGray, chipBlue, chipGreen, tag, safe, initials };
