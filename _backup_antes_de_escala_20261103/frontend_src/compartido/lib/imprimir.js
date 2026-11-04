import { apiPost } from "./api";

// Pide un ticket de impresión (60s, reutilizable) para abrir vistas
// imprimibles en iframe/ventana. El api_token real nunca viaja en la URL.
export async function pedirTicketImpresion() {
  try {
    const res = await apiPost("/print/ticket", {});
    return res?.ticket || null;
  } catch {
    return null;
  }
}

// Imprime una URL en un iframe oculto (no abre ventanas: los bloqueadores
// de popups no la frenan). La URL debe llevar ?ticket= (un solo uso).
export function imprimirUrl(url) {
  return new Promise((resolve) => {
    try {
      const viejo = document.getElementById("iframe-impresion");
      if (viejo) viejo.remove();
      const f = document.createElement("iframe");
      f.id = "iframe-impresion";
      f.style.position = "fixed";
      f.style.right = "0";
      f.style.bottom = "0";
      f.style.width = "0";
      f.style.height = "0";
      f.style.border = "0";
      f.setAttribute("aria-hidden", "true");
      f.onload = () => {
        try {
          f.contentWindow.focus();
          f.contentWindow.print();
        } catch { /* el usuario imprime manual */ }
        setTimeout(() => { f.remove(); resolve(true); }, 4000);
      };
      f.onerror = () => { f.remove(); resolve(false); };
      f.src = url;
      document.body.appendChild(f);
      // Red de seguridad por si onload no dispara.
      setTimeout(() => { f.remove(); resolve(true); }, 15000);
    } catch {
      resolve(false);
    }
  });
}
