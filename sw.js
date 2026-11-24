/* Service worker: so existe para receber os avisos (Web Push). Nao guarda nada em cache. */
'use strict';

async function sha256(texto) {
    const b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(texto));
    return Array.from(new Uint8Array(b)).map(function (x) { return x.toString(16).padStart(2, '0'); }).join('');
}

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

// O push chega sem texto: o aviso e buscado pelo hash da inscricao deste aparelho.
self.addEventListener('push', function (e) {
    e.waitUntil((async function () {
        let m = { titulo: 'Alpha Market', corpo: 'Há avisos novos.', url: '/' };
        try {
            const sub = await self.registration.pushManager.getSubscription();
            if (sub) {
                const r = await fetch('/api/push/mensagem?h=' + await sha256(sub.endpoint), { cache: 'no-store' });
                if (r.ok) m = await r.json();
            }
        } catch (err) { /* fica o texto generico */ }
        await self.registration.showNotification(m.titulo, {
            body: m.corpo,
            icon: '/assets/icone-192.png',
            badge: '/assets/favicon.png',
            data: { url: m.url || '/' },
        });
    })());
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    const alvo = (e.notification.data && e.notification.data.url) || '/';
    e.waitUntil((async function () {
        const abertas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of abertas) {
            if ('focus' in c) { await c.focus(); if ('navigate' in c) await c.navigate(alvo); return; }
        }
        await self.clients.openWindow(alvo);
    })());
});
