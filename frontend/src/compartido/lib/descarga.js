// Descarga directa de archivos sin ventanas emergentes.
// Los navegadores suelen bloquear los popups y el usuario cree que
// "no anda": por eso todo se baja con un <a download> y se avisa
// dónde quedó el archivo (carpeta Descargas).
export function descargarBlob(blob, nombre) {
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.setAttribute("download", nombre || "archivo");
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  setTimeout(() => window.URL.revokeObjectURL(url), 10000);
  return url;
}

// Mensaje estándar para avisar dónde quedó la descarga.
export function mensajeDescarga(nombre) {
  return nombre
    ? `Se descargó "${nombre}". Búscalo en tu carpeta Descargas.`
    : "Se descargó el archivo. Búscalo en tu carpeta Descargas.";
}

// Abre un PDF en una pestaña nueva SIN que lo bloquee el popup:
// se hace dentro del clic del usuario y con el blob ya descargado.
// Si igual lo bloquea, se baja como archivo para no dejar al usuario
// con las manos vacías.
export function verPdfBlob(blob, nombre) {
  const url = window.URL.createObjectURL(blob);
  const w = window.open(url, "_blank");
  if (!w) {
    descargarBlob(blob, nombre || "documento.pdf");
    return { bloqueado: true, url };
  }
  setTimeout(() => window.URL.revokeObjectURL(url), 60000);
  return { bloqueado: false, url };
}
