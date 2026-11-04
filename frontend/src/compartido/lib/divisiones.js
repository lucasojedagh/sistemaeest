// Mapa anio -> [divisiones], compartido por toda la app.
// Antes cada pantalla pedía /divisiones por su cuenta (13 pedidos por recorrido
// normal) y con la latencia del servidor eso se notaba en cada navegación.
// Acá se reutiliza la copia de pageCache y, si dos componentes lo piden a la
// vez, comparten el mismo pedido en vuelo.
import { apiGet } from "./api";
import { getCached, setCache } from "./pageCache";

const CLAVE = "divisiones";

let enVuelo = null;

const normalizar = (mapa) =>
  Object.fromEntries(Object.entries(mapa || {}).map(([k, v]) => [Number(k), v || []]));

export async function cargarDivisiones({ forzar = false } = {}) {
  if (!forzar) {
    const copia = getCached(CLAVE);
    if (copia && Object.keys(copia).length) return normalizar(copia);
  }
  if (!enVuelo) {
    enVuelo = apiGet("/divisiones")
      .then((mapa) => {
        const normal = normalizar(mapa);
        // No cachear un mapa vacío: un fallo transitorio quedaría sirviendo
        // dropdowns sin opciones hasta el próximo cambio.
        if (Object.keys(normal).length) setCache(CLAVE, normal);
        return normal;
      })
      .finally(() => { enVuelo = null; });
  }
  return enVuelo;
}
