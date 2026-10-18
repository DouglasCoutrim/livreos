// Caminho: /service-worker.js
const CACHE_NAME = 'livreos-cache-v1';
const OFFLINE_URL = './offline.html';

const ASSETS_TO_CACHE = [
    OFFLINE_URL,
    './assets/css/theme.css',
    './assets/js/theme.js',
    './assets/js/app.js',
    './assets/livreos-logo.png',
    './assets/livreos-icone.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS_TO_CACHE))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.map((key) => (key !== CACHE_NAME ? caches.delete(key) : null)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cachedResponse) => {
            return cachedResponse || fetch(event.request);
        })
    );
});