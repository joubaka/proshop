// Deliberately no caches: never store wallets, authentication or payment pages offline.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', event => {
    if (event.request.mode !== 'navigate') return;
    event.respondWith(fetch(event.request).catch(() => new Response('<!doctype html><meta name="viewport" content="width=device-width"><title>Court Lights offline</title><main style="font:18px system-ui;padding:30px"><h1>You are offline</h1><p>Reconnect to view your wallet or control the lights. Closing this page does not end a session. Its funded cutoff remains in place.</p><a href="/lights/">Try again</a></main>', { status: 503, headers: { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' } })));
});
