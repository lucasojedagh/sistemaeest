// Lenguaje en criollo para errores y confirmaciones.
// Nunca mostrar códigos (500, 422, 409) ni tecnicismos al usuario.
const MENSAJES_POR_ESTADO = {
  400: "Revisá los datos ingresados e intentá de nuevo.",
  401: "Tu sesión terminó. Ingresá de nuevo para continuar.",
  403: "No tenés permiso para hacer eso. Hablá con la dirección si creés que es un error.",
  404: "No encontramos eso que buscabas. Puede que se haya borrado.",
  409: "Eso ya estaba cargado. Revisá los datos antes de duplicarlos.",
  422: "Revisá los datos ingresados: hay algo incompleto o repetido.",
  429: "Demasiados intentos seguidos. Esperá un momento e intentá de nuevo.",
};

// Convierte cualquier error de la API en un mensaje en criollo,
// sin códigos ni palabras técnicas.
export function mensajeAmable(err, respaldo) {
  const data = err?.data;
  const status = err?.status;
  const crudo = data?.message || err?.message || "";
  // Si el mensaje ya es legible (sin codigos ni ingles tecnico), usarlo.
  if (crudo && !/\b(500|422|409|404|403|SQL|Exception|Undefined|Trying to|null)\b/i.test(crudo) && crudo.length < 220) {
    return crudo;
  }
  // Errores de validación de Laravel: mostrar el primero en criollo.
  const errors = data?.errors;
  if (errors && typeof errors === "object") {
    const primero = Object.values(errors).flat().find(Boolean);
    if (primero && String(primero).length < 220) return String(primero);
  }
  if (status && MENSAJES_POR_ESTADO[status]) {
    return MENSAJES_POR_ESTADO[status];
  }
  return respaldo || "Algo salió mal. Revisá tu conexión e intentá de nuevo.";
}

// Texto de confirmación para borrar un alumno, con consecuencias claras.
export function textoBorrarAlumno(nombreCompleto) {
  const quien = nombreCompleto || "este alumno";
  return `Se va a borrar a ${quien} y todas sus notas y asistencias. No se puede deshacer.`;
}

// Texto de confirmación para borrar un documento.
export function textoBorrarDocumento(nombre) {
  return `Se va a borrar el documento "${nombre || "elegido"}". No se puede deshacer.`;
}

// Texto para borrar una clase de asistencia.
export function textoBorrarClase(fecha) {
  return fecha
    ? `Se va a borrar la clase del ${fecha} con la asistencia de todos los alumnos. No se puede deshacer.`
    : "Se va a borrar esta clase con la asistencia de todos los alumnos. No se puede deshacer.";
}
