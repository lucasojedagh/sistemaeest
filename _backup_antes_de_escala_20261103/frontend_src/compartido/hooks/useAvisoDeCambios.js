import { useCallback, useEffect, useRef, useState } from "react";
import { apiGet } from "../lib/api";
import { guardarSello, hayCambios, cambioPropioVigente } from "../lib/dataStamp";

// Avisa, igual que el historial, que hay datos nuevos de otras personas.
//
// Al entrar o al volver a la pantalla (o al volver a la pestaña del navegador)
// se consulta un numero por grupo de datos. Si alguno de los que mira esta
// pantalla subio, aparece el boton "Actualizar": la persona decide cuando
// recargar. La copia guardada se sigue mostrando al instante (no se pierde la
// rapidez), pero ya no se recarga sola a escondidas: avisa y listo.
//
// El sello se guarda con el nombre de la pantalla, para que una pantalla no se
// coma la novedad que corresponde a otra.
//
// Uso:
//   const { hayNovedades } = useAvisoDeCambios('alumnos', ['alumnos', 'cursos'], actualizar);
//   ...<BotonNovedades visible={hayNovedades} onClick={actualizar} />
export default function useAvisoDeCambios(pantalla, categorias, alActualizar) {
  const [hayNovedades, setHayNovedades] = useState(false);
  const categoriasRef = useRef(categorias);
  categoriasRef.current = categorias;
  const fnRef = useRef(alActualizar);
  fnRef.current = alActualizar;
  // Al volver se disparan los dos eventos juntos (visibilitychange y focus):
  // con esta marca se hace una sola consulta.
  const ultimaConsulta = useRef(0);

  const comprobar = useCallback(async () => {
    if (!pantalla) return;
    try {
      const sello = await apiGet("/datos/sello");
      if (!sello || typeof sello !== "object") return;
      const lista = categoriasRef.current;
      // Un cambio hecho por esta misma cuenta ya se ve en pantalla: no avisa.
      if (cambioPropioVigente(lista)) {
        guardarSello(pantalla, sello, lista);
        setHayNovedades(false);
        return;
      }
      if (hayCambios(pantalla, sello, lista)) {
        setHayNovedades(true);
      }
      guardarSello(pantalla, sello, lista);
    } catch { /* sin red: se reintenta al volver otra vez */ }
  }, [pantalla]);

  // El botón "Actualizar": recarga y deja de avisar.
  const actualizar = useCallback(async () => {
    setHayNovedades(false);
    try {
      await comprobar();
    } catch { /* si falla, se reintenta al volver */ }
    try {
      const r = fnRef.current?.();
      if (r && typeof r.catch === "function") await r;
    } catch { /* la pantalla conserva lo que tenia */ }
  }, [comprobar]);

  useEffect(() => {
    // Al entrar también se comprueba: si hay copia guardada, puede haber
    // cambios de otro equipo mientras esta pantalla estaba cerrada.
    comprobar();

    const alVolver = () => {
      if (document.visibilityState !== "visible") return;
      const ahora = Date.now();
      if (ahora - ultimaConsulta.current < 2000) return;
      ultimaConsulta.current = ahora;
      comprobar();
    };
    document.addEventListener("visibilitychange", alVolver);
    window.addEventListener("focus", alVolver);
    return () => {
      document.removeEventListener("visibilitychange", alVolver);
      window.removeEventListener("focus", alVolver);
    };
  }, [comprobar]);

  return { hayNovedades, actualizar };
}