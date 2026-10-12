<?php

/*
| Cabeceras de seguridad HTTP. La CSP se puede pasar a modo "solo reporte" o desactivar desde .env si hiciera falta
| depurar algo en producción (CSP_MODE=enforce|report|off).
|
| script-src lleva 'unsafe-eval' porque Alpine.js evalúa las expresiones de los atributos x-*; no hay <script> ni
| manejadores on*="" en línea. style-src lleva 'unsafe-inline' por los estilos de impresión/PDF y x-show.
*/
return [
    'csp_mode' => env('CSP_MODE', 'enforce'),

    'csp' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-eval'"],
        'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'],
        'font-src' => ["'self'", 'https://fonts.bunny.net', 'data:'],
        'img-src' => ["'self'", 'data:', 'blob:'],
        'connect-src' => ["'self'"],
        'worker-src' => ["'self'"],
        'manifest-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ],
];
