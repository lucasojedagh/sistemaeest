<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Horario {{ $anio }}° {{ $division }}{{ $grupo ? ' (Grupo '.$grupo.')' : '' }}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: Arial, Helvetica, sans-serif;
      color: #111;
      margin: 24px;
    }
    h1 { font-size: 20px; margin: 0 0 2px; }
    .sub { font-size: 13px; color: #444; margin: 0 0 18px; }
    table { border-collapse: collapse; width: 100%; }
    th, td {
      border: 1px solid #333;
      padding: 7px 9px;
      text-align: center;
      vertical-align: middle;
    }
    thead th {
      background: #0b3d91;
      color: #fff;
      font-size: 14px;
    }
    td.hora {
      background: #eef2ff;
      font-weight: 700;
      font-size: 12px;
      width: 120px;
      white-space: nowrap;
    }
    td .materia { font-weight: 700; font-size: 14px; }
    td .profesor { font-size: 11px; color: #333; margin-top: 3px; }
    td .vacio { height: 24px; }
    @media print { body { margin: 10mm; } }
  </style>
</head>
<body>
  <h1>Horario — {{ $anio }}° {{ $division }}{{ $grupo ? ' (Grupo '.$grupo.')' : '' }}</h1>
  <p class="sub">{{ $ciclo ? 'Ciclo Lectivo '.$ciclo : 'Colegio ENRO' }}</p>

  <table>
    <thead>
      <tr>
        <th>Hora</th>
        @foreach ($dias as $dia)
          <th>{{ $dia }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach ($grid as $slot)
        <tr>
          <td class="hora">{{ $slot['label'] }}</td>
          @foreach ($dias as $dia)
            @php $cell = $slot['cells'][$dia] ?? null; @endphp
            @if ($cell && !empty($cell['materia']))
              <td>
                <div class="materia">{{ $cell['materia'] }}</div>
                @if (!empty($cell['profesor']))
                  <div class="profesor">{{ $cell['profesor'] }}</div>
                @endif
              </td>
            @else
              <td><div class="vacio"></div></td>
            @endif
          @endforeach
        </tr>
      @endforeach
    </tbody>
  </table>

  <script>
    window.onload = function () { window.print(); };
  </script>
</body>
</html>
