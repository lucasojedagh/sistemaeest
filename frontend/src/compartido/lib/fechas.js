// Fechas en un solo lugar: formato DD/MM/AAAA y sin sorpresas de zona
// horaria ("2026-09-03" solo no debe mostrarse como el día anterior).
export function formatearFecha(iso) {
  if (!iso) return "—";
  const s = String(iso);
  try {
    const d = new Date(s.length === 10 ? `${s}T00:00:00` : s);
    if (Number.isNaN(d.getTime())) return s;
    return d.toLocaleDateString("es-AR", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
    });
  } catch {
    return s;
  }
}

// Fecha de hoy en hora local (toISOString es UTC y en Argentina
// puede devolver otro día según la hora).
export function hoyLocal() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const dia = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${dia}`;
}
