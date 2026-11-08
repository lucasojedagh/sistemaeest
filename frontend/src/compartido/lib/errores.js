// Lenguaje en criollo para errores y confirmaciones.
// Nunca mostrar códigos (500, 422, 409) ni tecnicismos al usuario.
const MENSAJES_POR_ESTADO = {
  400: "Revisá los datos ingresados e intentá de nuevo.",
  401: "Tu sesión terminó. Ingresá de nuevo para continuar.",
  403: "No tenés permiso para hacer eso. Hablá con la dirección si creés que es un error.",
  404: "No encontramos eso que buscabas. Puede que se haya borrado.",
  // Un bundle viejo que pide un método que la ruta ya no admite: sin esta fila
  // cae en el respaldo y le dice "revisá tu conexión", que no es el problema.
  405: "Esa acción no está disponible desde esta pantalla. Recargá e intentá de nuevo.",
  409: "Eso ya estaba cargado. Revisá los datos antes de duplicarlos.",
  422: "Revisá los datos ingresados: hay algo incompleto o repetido.",
  429: "Demasiados intentos seguidos. Esperá un momento e intentá de nuevo.",
  // 413 lo corta el server antes de leer el archivo (nginx o post_max_size de
  // PHP) y suele responder HTML sin texto: sin esta fila la pantalla decía
  // "revisá tu conexión", que no es el problema.
  413: "El archivo es demasiado grande para el servidor. Bajale el peso o dividilo y volvé a subirlo; si es un respaldo, avisá al administrador para que suba ese límite.",
  502: "El servidor no pudo completar la operación. Esperá unos minutos e intentá de nuevo.",
  504: "El servidor tardó demasiado y cortó la operación. Probá de nuevo, con un archivo más chico o en un horario de menor uso.",
};

// El texto del estado HTTP, o null si no está en la tabla. Lo usa la capa de red
// para que el Error que lanza ya traiga el mensaje correcto, incluso en las
// pantallas que muestran `e.message` directo.
export function mensajeDeEstado(status) {
  return MENSAJES_POR_ESTADO[status] || null;
}

// El navegador no llegó al servidor (corte de red, API caída, equipo apagado).
// Se reconoce aparte porque el texto que elige cada pantalla suele dar por
// sentado que el pedido sí llegó: "revisá tu contraseña" no sirve si no hay conexión.
const SIN_CONEXION = /Failed to fetch|Load failed|Network ?Error|Network request failed|ERR_[A-Z_]*CONNECTION|ERR_INTERNET|ERR_NAME/i;
export function esFalloDeRed(err) {
  if (typeof navigator !== "undefined" && navigator.onLine === false) return true;
  const crudo = String(err?.data?.message || err?.message || err || "");
  return SIN_CONEXION.test(crudo);
}

// Texto único para el corte de conexión, para que todas las pantallas digan lo mismo.
export const MENSAJE_SIN_CONEXION = "No se pudo conectar con el servidor. Revisá tu conexión e intentá de nuevo.";

// Convierte cualquier error de la API en un mensaje en criollo,
// sin códigos ni palabras técnicas.
export function mensajeAmable(err, respaldo) {
  if (esFalloDeRed(err)) return MENSAJE_SIN_CONEXION;
  const data = err?.data;
  const status = err?.status;
  const crudo = data?.message || err?.message || "";
  // Si el mensaje ya es legible (sin codigos ni ingles tecnico), usarlo.
  // Se descartan los que trae Laravel o el navegador en ingles (throttle,
  // token vencido, ruta inexistente, método equivocado, sin conexión) y los
  // detalles internos como SQLSTATE, que aparece pegado a "42S02" y por eso no
  // lo atrapa un \bSQL\b.
  const tecnico = /(SQLSTATE|\b(500|422|409|404|403|429)\b|Exception|Undefined|Trying to access|\bnull\b|Too many|Too Many|Unauthenticated|Not Found|Forbidden|Method Not Allowed|not supported for route|Server error|Failed to fetch|Network ?Error|ERR_[A-Z_]+|given data was invalid)/i;
  if (crudo && !tecnico.test(crudo) && crudo.length < 220) {
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
