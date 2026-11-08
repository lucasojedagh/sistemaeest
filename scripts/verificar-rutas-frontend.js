// Script: scripts/verificar-rutas-frontend.js
// Propósito: cruzar las rutas que llama el frontend contra las rutas reales del
//            backend. Si una pantalla pide /api/lo-que-falta, el usuario ve "sin
//            datos" o un error raro y en el server nadie se entera; acá aparece
//            enseguida y con el archivo donde está el pedido.
//
// Uso:  node scripts/verificar-rutas-frontend.js          # chequeo
//       node scripts/verificar-rutas-frontend.js --reves  # además, qué endpoints
//                                                         #   del backend nadie usa
//
// Sale con código 1 si hay algún pedido del frontend sin ruta, para que sirva
// como guarda antes de publicar el dist.
//
// Lee el código fuente, no el build: así marca también las rutas que se arman
// con variables (`/documentos/${id}/download`, joinUrl, `+ qs(params)`).
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const RAIZ = path.resolve(__dirname, '..');
const SRC = path.join(RAIZ, 'frontend', 'src');
const BACKEND = path.join(RAIZ, 'backend');

const REVES = process.argv.includes('--reves');

// ---------- 1. rutas del backend ----------
/** php.exe de Laragon: en Git Bash no está en el PATH, igual que en generar-sql.sh. */
function binarioPhp() {
  if (process.env.PHP_BIN) return process.env.PHP_BIN;
  const base = 'C:\\laragon\\bin\\php';
  try {
    const versiones = fs.readdirSync(base).filter((d) => /^php-/.test(d)).sort();
    const exe = path.join(base, versiones[versiones.length - 1], 'php.exe');
    if (fs.existsSync(exe)) return exe;
  } catch { /* sin Laragon: se intenta con el php del PATH */ }
  return 'php';
}

function rutasBackend() {
  const salida = execFileSync(binarioPhp(), ['artisan', 'route:list', '--json'], {
    cwd: BACKEND, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, shell: false,
  });
  return JSON.parse(salida);
}

function aRegex(uri) {
  const cuerpo = uri
    .split('/')
    .map((s) => (/^\{.*\}$/.test(s) ? '[^/]+' : s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
    .join('/');
  return new RegExp('^' + cuerpo + '$');
}

// ---------- 2. pedidos del frontend ----------
function archivos(dir) {
  const out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...archivos(p));
    else if (/\.(js|jsx)$/.test(e.name)) out.push(p);
  }
  return out;
}

/** El primer argumento de una llamada, con paréntesis y llaves balanceados. */
function primerArgumento(src, desde) {
  let profundidad = 1;
  let i = desde;
  for (; i < src.length; i++) {
    const c = src[i];
    if (c === '(' || c === '[' || c === '{') profundidad++;
    else if (c === ')' || c === ']' || c === '}') {
      profundidad--;
      if (profundidad === 0) break;
    } else if (c === ',' && profundidad === 1) break;
  }
  return src.slice(desde, i);
}

/**
 * Rutas dentro de la expresión del primer argumento.
 * Un ${...} que arma la query (qs, query, params) se corta ahí: no es parte
 * de la ruta. Los demás tramos variables se leen como parámetro (:p).
 */
function rutasDe(expr) {
  const limpia = expr.replace(/\/\/[^\n]*/g, '');
  const trozos = [];
  for (const m of limpia.matchAll(/(`[^`]*`|'[^']*'|"[^"]*")/g)) {
    let ruta = m[1].slice(1, -1);
    ruta = ruta.replace(/\$\{[^}]*\}/g, (c) => (/qs|query|search|params|token/.test(c) ? '?' : c));
    ruta = ruta.replace(/\$\{[^}]*\}/g, ':p').split('?')[0];
    if (ruta.endsWith('/') && /\+\s*[^,;)]+$/.test(limpia)) ruta += ':p';
    trozos.push(ruta);
  }

  // joinUrl(`/anios/${anio}`, 'materias') arma la ruta por tramos.
  if (/^\s*joinUrl\(/.test(limpia) && trozos.length > 1) {
    const [primero, ...demas] = trozos;
    return [primero.replace(/\/$/, '') + '/' + demas.filter((d) => d && !d.startsWith('/')).join('/')];
  }

  return trozos.filter((r) => r.startsWith('/'));
}

const METODOS = { Get: 'GET', Blob: 'GET', Upload: 'POST', UploadBlob: 'POST' };

function pedidosFrontend() {
  const pedidos = [];
  for (const f of archivos(SRC)) {
    const src = fs.readFileSync(f, 'utf8');
    const base = path.relative(SRC, f).replace(/\\/g, '/');
    const lineaDe = (index) => src.slice(0, index).split('\n').length;

    const agregar = (metodo, expr, index) => {
      for (const ruta of rutasDe(expr)) pedidos.push({ metodo, ruta, donde: base + ':' + lineaDe(index) });
    };

    for (const m of src.matchAll(/api(Get|Post|Put|Patch|Delete|Upload|UploadBlob|Blob)\(/g)) {
      agregar(METODOS[m[1]] || m[1].toUpperCase(), primerArgumento(src, m.index + m[0].length), m.index);
    }
    for (const m of src.matchAll(/apiUrl\(/g)) {
      agregar('GET', primerArgumento(src, m.index + m[0].length), m.index);
    }
  }
  return pedidos;
}

// ---------- 3. cruzar ----------
let rutas;
try {
  rutas = rutasBackend();
} catch (e) {
  console.error('No se pudo leer el route:list del backend. Verificar PHP y estar en la raíz del repo.');
  console.error(String(e.stderr || e.message).split('\n')[0]);
  process.exit(2);
}

const patrones = [];
for (const r of rutas) {
  for (const m of r.method.split('|')) {
    if (m === 'HEAD' || m === 'OPTIONS') continue;
    patrones.push({ metodo: m, uri: r.uri, re: aRegex(r.uri) });
  }
}

const pedidos = pedidosFrontend();
const huerfanas = [];
for (const p of pedidos) {
  const objetivo = p.ruta.replace(/^\/+/, '');
  const opciones = ['api/' + objetivo, objetivo];
  const existe = patrones.some((x) => x.metodo === p.metodo && opciones.some((o) => x.re.test(o)));
  if (!existe) huerfanas.push(p);
}

console.log(`frontend: ${pedidos.length} pedidos   backend: ${rutas.length} rutas`);

if (huerfanas.length === 0) {
  console.log('OK: todo lo que llama la pantalla existe en la API');
} else {
  const unicas = new Map();
  for (const h of huerfanas) {
    const k = `${h.metodo} ${h.ruta}`;
    if (!unicas.has(k)) unicas.set(k, new Set());
    unicas.get(k).add(h.donde);
  }
  console.log('PEDIDOS SIN RUTA EN EL BACKEND:');
  for (const [k, donde] of [...unicas].sort()) console.log('  ' + k.padEnd(44) + ' <- ' + [...donde].join(', '));
}

if (REVES) {
  const llamadas = new Set(pedidos.map((p) => `${p.metodo} ${p.ruta}`));
  const todos = archivos(SRC);
  const sinUso = [];
  for (const r of rutas) {
    if (!r.uri.startsWith('api/')) continue;
    for (const m of r.method.split('|')) {
      if (m === 'HEAD' || m === 'OPTIONS') continue;
      const forma = m + ' /' + r.uri.replace(/^api\//, '').split('/')
        .map((s) => (/^\{.*\}$/.test(s) ? ':p' : s)).join('/');
      if (llamadas.has(forma)) continue;
      const tramos = r.uri.split('/').filter((s) => s !== 'api' && !/^\{.*\}$/.test(s));
      const ultimo = tramos[tramos.length - 1] ?? '';
      // Se da por usado si en algún archivo aparece el último tramo junto con el
      // primero: la firma de la ruta aunque esté armada con variables.
      const visto = ultimo !== '' && todos.some((f) => {
        const s = fs.readFileSync(f, 'utf8');
        return s.includes(ultimo) && s.includes(tramos[0]);
      });
      if (visto) continue;
      sinUso.push(forma + '   [' + (r.action || 'closure') + ']');
    }
  }
  console.log('\nENDPOINTS DEL BACKEND QUE LA PANTALLA NO USA (' + sinUso.length + '):');
  for (const s of sinUso.sort()) console.log('  ' + s);
  console.log('  (los que se prueban por API o son de mantenimiento están bien acá)');
}

process.exit(huerfanas.length === 0 ? 0 : 1);
