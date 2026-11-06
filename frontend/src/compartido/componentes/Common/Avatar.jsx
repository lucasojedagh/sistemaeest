// Foto de perfil con las iniciales de respaldo.
// Sirve igual en la barra lateral, en "Mi cuenta" y en la lista de cuentas:
// se le pasa la clase que ya usaba el bloque de iniciales y, si la cuenta tiene
// foto, se reemplaza el contenido por la imagen (mismo tamaño y recorte circular).
import React from "react";
import { useAvatarSrc } from "../../lib/avatares";
import "./Avatar.css";

export default function Avatar({ id, version, className = "", titulo, children }) {
  const src = useAvatarSrc(id, version);

  if (!src) {
    return <span className={className}>{children}</span>;
  }

  return (
    <img
      src={src}
      alt={titulo ? `Foto de ${titulo}` : "Foto de perfil"}
      title={titulo || undefined}
      className={`${className} avatar-foto`.trim()}
      draggable={false}
    />
  );
}
