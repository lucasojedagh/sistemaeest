<!doctype html>
<html lang="es">
  <head><meta charset="utf-8"></head>
  <body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;">
    <p>Hola,</p>
    <p>Te envío el backup de <strong>{{ $app }}</strong> (formato: <strong>{{ strtoupper($mode) }}</strong>).</p>
    <p>Adjunto encontrarás el archivo correspondiente.</p>
    <p style="color:#6b7280">Mensaje automático — {{ now()->format('Y-m-d H:i') }}</p>
  </body>
</html>
