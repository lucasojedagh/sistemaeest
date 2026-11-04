// Archivo con funciones utilitarias para exportar datos a formatos CSV y JSON.

// Exporta un arreglo de objetos a un archivo CSV y lo descarga en el navegador.
// Permite personalizar los encabezados y el nombre del archivo.
export function exportCSV(data, filename = "export.csv", headers = null) {
  if (!data || data.length === 0) return;

  // Determina las claves a incluir: desde headers personalizados o desde el primer elemento.
  const keys = headers ? Object.keys(headers) : Object.keys(data[0]);
  // Usa las etiquetas personalizadas o los nombres de propiedad como encabezados del CSV.
  const labels = headers || keys.reduce((acc, k) => ({ ...acc, [k]: k }), {});

  const csvRows = [];
  csvRows.push(keys.map((k) => `"${(labels[k] || k).replace(/"/g, '""')}"`).join(","));

  for (const row of data) {
    const values = keys.map((k) => {
      const val = row[k] ?? "";
      return `"${String(val).replace(/"/g, '""')}"`;
    });
    csvRows.push(values.join(","));
  }

  // BOM al inicio: sin esto Excel en Windows abre el CSV como ANSI y rompe las tildes.
  const blob = new Blob(["\uFEFF" + csvRows.join("\n")], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(link.href);
}

// Exporta un objeto o arreglo a un archivo JSON formateado y lo descarga en el navegador.
export function exportJSON(data, filename = "export.json") {
  if (!data) return;
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(link.href);
}
