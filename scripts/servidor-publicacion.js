// Simulador del server de publicación: sirve frontend/dist y reenvía al backend
// las mismas rutas que enruta docker/nginx/default.conf. Sirve para probar el
// build real (npm run build) antes de llevar el dist al colegio.
// Uso: node scripts/servidor-publicacion.js [puerto]
const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const DIST = path.resolve(__dirname, '..', 'frontend', 'dist');
const BACKEND = { host: '127.0.0.1', port: 8000 };
const PUERTO = Number(process.argv[2] || 4173);

// Reglas copiadas de docker/nginx/default.conf
const A_BACKEND = [
  p => p === '/api' || p.startsWith('/api/'),
  p => p.startsWith('/print/'),
  p => p.startsWith('/storage/'),
  p => p.startsWith('/sanctum/'),
  p => p === '/calificaciones/planilla' || p.startsWith('/calificaciones/planilla/'),
  p => p === '/horarios/planilla',
];
const MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon',
  '.json': 'application/json', '.webmanifest': 'application/manifest+json', '.woff2': 'font/woff2',
  '.pdf': 'application/pdf',
};

function alBackend(req, res) {
  const opts = { ...BACKEND, path: req.url, method: req.method, headers: { ...req.headers, host: `${BACKEND.host}:${BACKEND.port}` } };
  const up = http.request(opts, up_res => {
    res.writeHead(up_res.statusCode, up_res.headers);
    up_res.pipe(res);
  });
  up.on('error', e => { res.writeHead(502, { 'content-type': 'application/json' }); res.end(JSON.stringify({ error: 'backend no responde', detalle: e.message })); });
  req.pipe(up);
}

function delDist(req, res, pathname) {
  let p = decodeURIComponent(pathname);
  if (p.endsWith('/')) p += 'index.html';
  // Nunca salir de DIST (bloquea ../).
  const archivo = path.join(DIST, path.normalize(p).replace(/^(\.\.[/\\])+/, ''));
  if (!archivo.startsWith(DIST)) { res.writeHead(403); return res.end('prohibido'); }
  fs.readFile(archivo, (err, buf) => {
    if (err) {
      // Fallback SPA: si no es un archivo, entrega index.html y React Router resuelve.
      if (path.extname(p)) { res.writeHead(404); return res.end('no existe'); }
      return fs.readFile(path.join(DIST, 'index.html'), (e2, i2) => {
        if (e2) { res.writeHead(500); return res.end('dist sin index.html'); }
        res.writeHead(200, { 'content-type': MIME['.html'], 'cache-control': 'no-cache' });
        res.end(i2);
      });
    }
    res.writeHead(200, { 'content-type': MIME[path.extname(archivo)] || 'application/octet-stream' });
    res.end(buf);
  });
}

http.createServer((req, res) => {
  const { pathname } = url.parse(req.url);
  if (A_BACKEND.some(f => f(pathname))) return alBackend(req, res);
  return delDist(req, res, pathname);
}).listen(PUERTO, () => console.log(`dist servido en http://localhost:${PUERTO} (API -> :${BACKEND.port})`));
