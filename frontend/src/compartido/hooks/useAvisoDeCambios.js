import { useCallback, useEffect, useRef } from "react";
import { apiGet } from "../lib/api";
import { guardarSello, hayCambios, cambioPropioVigente } from "../lib/dataStamp";

// Las pantallas se actualizan solas, sin recargar la pagina y sin avisar nada.
//
// Mientras la pestana esta visible se pregunta cada 12 segundos por un numero
// por grupo de datos (alumnos, cursos, asistencia...). Si alguno subio, se
// vuelve a cargar la informacion de ESA pantalla: los filtros, el scroll y lo
// que se esta viendo se mantienen, solo cambian los datos.
//
// Lo unico que no se pisa es el trabajo sin guardar: con un modal abierto, un
// campo con el cursor o marcas de asistencia pendientes, el cambio ajeno queda
// en espera y entra solo en cuanto la pantalla se desocupa (se cerro el modal,
// se salio del campo). Por eso no hay boton que pulsar.
//
// Un cambio hecho por esta misma cuenta nunca se aplica por arriba: ya se ve.
//
// Uso: useAvisoDeCambios('alumnos', ['alumnos','cursos'], actualizarTodo);
const MS_SONDEO = 12000;

// Cuanto se espera despues de cerrar un modal o salir de un campo: la animacion
// de salida tiene que terminar para que el DOM refleje la pantalla desocupada.
const MS_AL_DESOCUPARSE = 350;

// La pantalla esta ocupada: modal abierto, campo en edicion o marcas pendientes.
function pantallaOcupada() {
  if (document.querySelector('[role="dialog"], [aria-modal="true"]')) return true;
  if (document.body && document.body.hasAttribute("data-cambios-sin-guardar")) return true;
  const el = document.activeElement;
  if (!el || el === document.body) return false;
  const tag = el.tagName;
  return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || el.isContentEditable === true;
}

export default function useAvisoDeCambios(pantalla, categorias, alActualizar) {
  const categoriasRef = useRef(categorias);
  categoriasRef.current = categorias;
  const fnRef = useRef(alActualizar);
  fnRef.current = alActualizar;
  // Al volver a la pestana se disparan visibilitychange y focus juntos: con esta
  // marca se hace una sola consulta.
  const ultimaConsulta = useRef(0);
  // Cambios ajenos que esperan a que esta pantalla se desocupe para aplicarse.
  const enEspera = useRef(false);
  const alDesocupar = useRef(null);

  const comprobar = useCallback(async () => {
    if (!pantalla) return;
    try {
      const sello = await apiGet("/datos/sello");
      if (!sello || typeof sello !== "object") return;
      const lista = categoriasRef.current;

      // Un cambio hecho por esta misma cuenta ya se ve en pantalla: no se aplica.
      if (cambioPropioVigente(lista)) {
        guardarSello(pantalla, sello, lista);
        enEspera.current = false;
        return;
      }
      if (!hayCambios(pantalla, sello, lista)) {
        enEspera.current = false;
        return;
      }
      // Con trabajo sin guardar no se toca nada: queda en espera y se reintenta
      // al cerrar el modal, al salir del campo o en el proximo sondeo.
      if (pantallaOcupada()) {
        enEspera.current = true;
        return;
      }
      enEspera.current = false;
      guardarSello(pantalla, sello, lista);
      const r = fnRef.current?.();
      if (r && typeof r.catch === "function") await r;
    } catch { /* sin red: se reintenta en el proximo sondeo */ }
  }, [pantalla]);

  useEffect(() => {
    // Al entrar tambien se comprueba: si hay copia guardada, puede haber cambios
    // de otro equipo mientras esta pantalla estaba cerrada.
    comprobar();

    const alVolver = () => {
      if (document.visibilityState !== "visible") return;
      const ahora = Date.now();
      if (ahora - ultimaConsulta.current < 2000) return;
      ultimaConsulta.current = ahora;
      comprobar();
    };

    // Sin nada en espera este listener no hace nada, asi que no cuesta tenerlo.
    const alDesocuparse = () => {
      if (!enEspera.current) return;
      if (alDesocupar.current) clearTimeout(alDesocupar.current);
      alDesocupar.current = setTimeout(() => {
        alDesocupar.current = null;
        if (!pantallaOcupada()) comprobar();
      }, MS_AL_DESOCUPARSE);
    };

    const timer = setInterval(alVolver, MS_SONDEO);
    document.addEventListener("visibilitychange", alVolver);
    window.addEventListener("focus", alVolver);
    document.addEventListener("click", alDesocuparse, true);
    document.addEventListener("focusout", alDesocuparse);
    return () => {
      clearInterval(timer);
      if (alDesocupar.current) clearTimeout(alDesocupar.current);
      document.removeEventListener("visibilitychange", alVolver);
      window.removeEventListener("focus", alVolver);
      document.removeEventListener("click", alDesocuparse, true);
      document.removeEventListener("focusout", alDesocuparse);
    };
  }, [comprobar]);
}
