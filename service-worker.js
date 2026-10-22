// Caminho: /service-worker.js
// v3: Correção do erro de Response em requisições POST e fallback offline.
const CACHE_NAME = 'livreos-cache-v3';
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
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS_TO_CACHE)));
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
    // 1. IGNORA requisições POST, PUT, DELETE (Deixa a Fetch API do nosso AJAX passar direto)
    if (event.request.method !== 'GET') {
        return;
    }

    // 2. Intercepta requisições GET (Para cache e modo offline)
    event.respondWith(
        caches.match(event.request).then((cachedResponse) => {
            // Se encontrou no cache, devolve o cache imediatamente
            if (cachedResponse) {
                return cachedResponse;
            }

            // Se não tem no cache, vai buscar na rede
            return fetch(event.request).then((networkResponse) => {
                // Opcional: você pode clonar e salvar no cache dinâmico aqui se quiser
                return networkResponse;
            }).catch(() => {
                // Se a rede falhar (usuário offline) e a requisição for de uma página HTML, mostra a offline.html
                if (event.request.headers.get('accept').includes('text/html')) {
                    return caches.match(OFFLINE_URL);
                }
                
                // Se for uma imagem, script, ou outra coisa falhando offline, devolve uma resposta "vazia" válida
                // Isso evita exatamente o erro "Failed to convert value to 'Response'"
                return new Response('', { status: 404, statusText: 'Offline' });
            });
        })
    );
});