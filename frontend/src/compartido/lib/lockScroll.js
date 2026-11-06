// Candado de scroll del <body> con contador de referencias.
// Cada modal pide su propio bloqueo y lo libera al desmontarse; el scroll vuelve
// recién cuando no queda ninguno. Con prev/restore a mano, un modal que sigue
// montado durante su animación de salida liberaba el bloqueo de otro y el body
// se quedaba en hidden (pantalla sin scroll).
let bloqueados = 0;

export function bloquearScroll() {
  bloqueados += 1;
  document.body.style.overflow = "hidden";
  let liberado = false;
  return () => {
    if (liberado) return;
    liberado = true;
    bloqueados = Math.max(0, bloqueados - 1);
    if (bloqueados === 0) document.body.style.overflow = "";
  };
}
