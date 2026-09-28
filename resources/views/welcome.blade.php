<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'API de facturación') }}</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; }
        main { max-width: 32rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { margin: .25rem 0; line-height: 1.5; }
    </style>
</head>
<body>
    <main>
        <h1>{{ config('app.name', 'API de facturación') }}</h1>
        <p>API REST. Los endpoints viven bajo <code>/api/v1</code>.</p>
        <p>Documentación interactiva: <a href="{{ url('/docs') }}">/docs</a>.</p>
    </main>
</body>
</html>
