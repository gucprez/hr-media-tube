/*
 * Service worker de HR Media System.
 * - Solo guarda en caché archivos estáticos públicos (assets versionados de /build y /icons) y la página /offline.
 * - NUNCA guarda páginas, respuestas JSON ni archivos privados: los datos de la empresa solo viajan por la red.
 * - Si no hay conexión al navegar, muestra /offline.
 * Subir VERSION cuando cambie este archivo.
 */
const VERSION = 'v1';
const STATIC = `hr-static-${VERSION}`;
const PRECACHE = ['/offline', '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(STATIC).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('hr-static-') && k !== STATIC).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match('/offline')));
        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(
            caches.match(req).then((hit) => hit || fetch(req).then((res) => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(STATIC).then((cache) => cache.put(req, copy));
                }
                return res;
            })),
        );
    }
    // todo lo demás: red directa, sin caché
});
