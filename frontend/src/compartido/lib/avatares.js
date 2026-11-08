// Fotos de perfil. Se piden de a lotes y quedan en memoria como data URI.
//
// Por qué un lote y no una petición por foto: la API cobra 60 pedidos por
// minuto y usuario y reparte esa cuenta entre toda la app. La lista de cuentas
// son hasta 100 filas; una request por foto habría agotado el cupo en la primera
// pantalla y el resto de la API habría empezado a fallar con 429.
import { useEffect, useState } from "react";
import { apiGet } from "./api";

const claves = new Map();      // "id@version" -> data URI | null (null = sin foto)
const enVuelo = new Map();     // "id@version" -> Promise
const suscriptores = new Set();

const claveDe = (id, version) => `${id}@${version}`;

function notificar() {
  for (const f of Array.from(suscriptores)) f();
}

let encola = [];
let temporizador = null;

/** Pide la foto (o la reused si ya bajó). Resuelve null si no hay. */
function pedirAvatar(id, version) {
  const k = claveDe(id, version);
  if (claves.has(k)) return Promise.resolve(claves.get(k));
  if (enVuelo.has(k)) return enVuelo.get(k);

  const p = new Promise((res) => {
    encola.push({ id, version, res });
    // Se agrupan en el mismo tick todos los componentes que se montaron juntos.
    if (!temporizador) temporizador = setTimeout(volar, 0);
  });
  enVuelo.set(k, p);
  return p;
}

async function volar() {
  temporizador = null;
  const lote = encola;
  encola = [];

  // De a 100: cada respuesta trae las fotos en base64, mejor no juntar de más.
  for (let i = 0; i < lote.length; i += 100) {
    const parte = lote.slice(i, i + 100);
    const ids = parte.map(({ id, version }) => `${id}@${version}`).join(",");

    let datos = null;
    let ok = false;
    try {
      datos = await apiGet(`/avatars?ids=${encodeURIComponent(ids)}`);
      ok = true;
    } catch {
      ok = false;
    }

    for (const { id, version, res } of parte) {
      const k = claveDe(id, version);
      enVuelo.delete(k);
      if (!ok) {
        // Sin foto y con error se ve lo mismo (las iniciales), pero el error no
        // se guarda: la próxima pantalla lo reintenta.
        res(null);
        continue;
      }
      const foto = datos?.[String(id)];
      const src = foto?.src ?? null;
      claves.set(k, src);
      // La pantalla puede pedir la versión de una foto que ya no existe en el
      // servidor (se borra al subir la siguiente): el servidor responde la
      // actual, así que se guarda también bajo la versión que realmente trajo.
      // Sin eso, cada pantalla que recuerde la versión vieja volvería a pedir
      // los mismos bytes.
      if (foto?.v && String(foto.v) !== String(version)) {
        claves.set(claveDe(id, foto.v), src);
      }
      res(src);
    }
    notificar();
  }
}

/** Se llama al cambiar de cuenta: nadie debe ver las fotos de la sesión anterior. */
export function limpiarAvatares() {
  claves.clear();
  enVuelo.clear();
  encola = [];
  notificar();
}

/** Fuente de la <img>: null mientras no está, así el llamador muestra iniciales. */
export function useAvatarSrc(id, version) {
  const k = id && version ? claveDe(id, version) : null;
  const [src, setSrc] = useState(() => (k && claves.has(k) ? claves.get(k) : null));

  useEffect(() => {
    if (!k) {
      setSrc(null);
      return undefined;
    }
    let vivo = true;
    const leer = () => (claves.has(k) ? claves.get(k) : null);
    setSrc(leer());

    if (!claves.has(k)) {
      pedirAvatar(id, version).then((s) => {
        if (vivo) setSrc(s);
      });
    }

    const cerrar = suscribirAvatar(() => {
      if (vivo) setSrc(leer());
    });
    return () => {
      vivo = false;
      cerrar();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [k]);

  return src;
}

function suscribirAvatar(cb) {
  suscriptores.add(cb);
  return () => suscriptores.delete(cb);
}
