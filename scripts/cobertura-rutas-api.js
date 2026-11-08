#!/usr/bin/env node
// Cobertura de la API: qué rutas no golpea ninguna prueba del backend.
//
// Cruza `php artisan route:list --json` contra el texto de backend/tests. Una
// ruta está cubierta si algún test la pide con el verbo correcto. No juzga si
// el test verifica el contenido de la respuesta: sólo si la ruta se pide, que
// es el hueco que deja una pantalla entera sin probar.
//
// Uso:  node scripts/cobertura-rutas-api.js [--json]
// Sale con código 1 si queda alguna ruta (o un verbo de esa ruta) sin prueba,
// para usarlo de guarda antes de publicar.

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const RAIZ = path.resolve(__dirname, '..');
const BACKEND = path.join(RAIZ, 'backend');
const TESTS = path.join(BACKEND, 'tests');

// Cómo se escribe cada verbo en las pruebas: helper del harness, llamada
// cruda de Laravel o el helper del frontend cuando se prueba por HTTP.
const VERBO = {
  GET: /getJson\s*\(|->get\s*\(|,\s*'GET'|apiGet/,
  POST: /postJson\s*\(|->post\s*\(|,\s*'POST'|apiPost|apiUpload/,
  PUT: /putJson\s*\(|->put\s*\(|,\s*'PUT'|apiPut/,
  PATCH: /patchJson\s*\(|->patch\s*\(|,\s*'PATCH'|apiPatch/,
  DELETE: /deleteJson\s*\(|->delete\s*\(|,\s*'DELETE'|apiDelete/,
};

function archivosDeTest(dir, acumulo = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const completo = path.join(dir, e.name);
    if (e.isDirectory()) archivosDeTest(completo, acumulo);
    else if (e.name.endsWith('.php')) acumulo.push(completo);
  }
  return acumulo;
}

function escapar(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// api/alumnos/{id}/promedios -> /api/alumnos<puente>/promedios
// El puente acepta las tres formas en que una prueba escribe la ruta:
//   '/api/alumnos/123/promedios'
//   '/api/alumnos/' . $this->alumno->id . '/promedios'
//   "/api/alumnos/{$this->alumno->id}/promedios"
// No salta líneas, así que el tramo final de la ruta tiene que estar en la
// misma línea del llamado.
function patronDeUri(uri) {
  const tramos = uri.split(/\{[^}]+\}/).map(escapar);
  const puente = '.{0,80}';
  const cuerpo = tramos.join(puente);
  // Las pruebas escriben la ruta con la barra inicial y a veces sin el prefijo.
  return new RegExp('[/\']' + cuerpo, 'g');
}

function main() {
  const salida = execFileSync('php', ['artisan', 'route:list', '--json'], {
    cwd: BACKEND,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });
  const rutas = JSON.parse(salida).filter((r) => r.uri && r.uri.startsWith('api/'));
  const corpus = archivosDeTest(TESTS)
    .map((a) => fs.readFileSync(a, 'utf8'))
    .join('\n');

  const sinCubrir = [];
  const cubiertas = [];

  for (const r of rutas) {
    const verbos = String(r.method).split('|').filter((v) => v !== 'HEAD');
    const re = patronDeUri(r.uri);
    const golpeados = [];
    const noGolpeados = [];

    let m;
    const ocurrencias = [];
    while ((m = re.exec(corpus)) !== null) ocurrencias.push(m.index);

    for (const v of verbos) {
      // El verbo se escribe antes de la ruta (getJson('/api/...')), así que
      // alcanza con mirar 120 caracteres previos a cada ocurrencia.
      const encontrado = ocurrencias.some((i) =>
        VERBO[v].test(corpus.slice(Math.max(0, i - 120), i + 1))
      );
      (encontrado ? golpeados : noGolpeados).push(v);
    }

    const registro = {
      uri: r.uri,
      verbos,
      golpeados,
      noGolpeados,
      accion: String(r.action).replace(/^App\\(Dominios|Http)\\/, ''),
    };
    (noGolpeados.length === 0 ? cubiertas : sinCubrir).push(registro);
  }

  const sinNada = sinCubrir.filter((r) => r.golpeados.length === 0);
  const parciales = sinCubrir.filter((r) => r.golpeados.length > 0);

  if (process.argv.includes('--json')) {
    process.stdout.write(
      JSON.stringify({ total: rutas.length, cubiertas: cubiertas.length, sinNada, parciales }, null, 2)
    );
    process.exitCode = sinCubrir.length > 0 ? 1 : 0;
    return;
  }

  console.log(`rutas de la API          : ${rutas.length}`);
  console.log(`  cubiertas del todo     : ${cubiertas.length}`);
  console.log(`  con un verbo sin probar: ${parciales.length}`);
  console.log(`  sin ninguna prueba     : ${sinNada.length}`);

  if (parciales.length) {
    console.log('\nCON VERBO SIN PRUEBA');
    for (const r of parciales) {
      console.log(`  /${r.uri}  — falta ${r.noGolpeados.join(',')}   ← ${r.accion}`);
    }
  }
  if (sinNada.length) {
    console.log('\nSIN NINGUNA PRUEBA');
    for (const r of sinNada) {
      console.log(`  ${r.verbos.join(',')} /${r.uri}   ← ${r.accion}`);
    }
  }

  process.exitCode = sinCubrir.length > 0 ? 1 : 0;
}

main();
