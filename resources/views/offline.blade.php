<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f2a52">
    <title>Sin conexión</title>
    <link rel="icon" href="/icons/icon-192.png">
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f1f5f9; font-family: system-ui, sans-serif; color: #0f172a; text-align: center; padding: 24px; }
        .box { max-width: 420px; } img { width: 84px; height: 84px; border-radius: 20px; }
        h1 { font-size: 22px; margin: 18px 0 8px; } p { color: #475569; line-height: 1.5; }
        button { margin-top: 16px; background: #2563eb; color: #fff; border: 0; border-radius: 10px; padding: 12px 22px; font-size: 15px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <div class="box">
        <img src="/icons/icon-192.png" alt="">
        <h1>Sin conexión a internet</h1>
        <p>No se pudo conectar con el servidor. Por seguridad, los datos de la empresa no se guardan sin conexión: cuando vuelva la señal podrá continuar donde estaba.</p>
        <button type="button" id="retry">Reintentar</button>
    </div>
    <script src="{{ asset('offline.js') }}"></script>
</body>
</html>
