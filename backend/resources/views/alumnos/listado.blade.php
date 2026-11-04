<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>{{ $titulo }} — {{ $curso }}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 24px; }
    .membrete { text-align: center; margin-bottom: 4px; }
    .membrete h1 { font-size: 20px; margin: 0; }
    .membrete p { font-size: 12px; color: #444; margin: 2px 0 0; }
    .meta { display: flex; justify-content: space-between; font-size: 13px; margin: 16px 0 12px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #333; padding: 7px 9px; font-size: 13px; text-align: left; }
    thead th { background: #0b3d91; color: #fff; }
    td.num, th.num { width: 36px; text-align: center; }
    td.firma { width: 180px; }
    .no-print { margin: 0 0 16px; }
    .no-print button { font-size: 14px; padding: 8px 18px; border-radius: 8px; border: 1px solid #999; background: #fff; cursor: pointer; }
    @media print { body { margin: 10mm; } .no-print { display: none; } }
  </style>
</head>
<body>
  <div class="membrete">
    <h1>E.E.S.T N° 2 — Sistema Escolar</h1>
    <p>{{ $titulo }} · {{ $curso }} · {{ $fecha }}</p>
  </div>
  <div class="no-print"><button onclick="window.print()">Imprimir</button></div>
  <table>
    <thead>
      <tr><th class="num">N°</th><th>Apellido</th><th>Nombre</th><th>DNI</th><th>Estado</th><th>Firma</th></tr>
    </thead>
    <tbody>
      @forelse ($alumnos as $i => $a)
        <tr>
          <td class="num">{{ $i + 1 }}</td>
          <td>{{ $a->apellido }}</td>
          <td>{{ $a->nombre }}</td>
          <td>{{ $a->dni }}</td>
          <td>{{ $a->estado }}</td>
          <td class="firma"></td>
        </tr>
      @empty
        <tr><td colspan="6">Sin alumnos en este curso.</td></tr>
      @endforelse
    </tbody>
  </table>
</body>
</html>
