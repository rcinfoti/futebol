// Service worker mínimo (spec §8): páginas em network-first (dados sempre frescos
// quando há rede, com cache de leitura como reserva offline); demais GETs cache-first.
const CACHE = 'pelada-v2';

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((chaves) =>
      Promise.all(chaves.filter((c) => c !== CACHE).map((c) => caches.delete(c)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') {
    return; // POST (confirmar/pagamento) sempre vai à rede
  }

  const ehPagina = req.mode === 'navigate';
  if (ehPagina) {
    event.respondWith(
      fetch(req)
        .then((resp) => {
          // só guarda página boa (sem erro/redirect de outro domínio) como reserva offline
          if (resp.ok && resp.type === 'basic') {
            const copia = resp.clone();
            caches.open(CACHE).then((c) => c.put(req, copia));
          }
          return resp;
        })
        .catch(() => caches.match(req))
    );
    return;
  }

  event.respondWith(
    caches.match(req).then((cacheada) =>
      cacheada ||
      fetch(req).then((resp) => {
        if (resp.ok) {
          const copia = resp.clone();
          caches.open(CACHE).then((c) => c.put(req, copia));
        }
        return resp;
      })
    )
  );
});
