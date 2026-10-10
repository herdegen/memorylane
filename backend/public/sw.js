/**
 * Service worker minimal de MemoryLane.
 *
 * Son rôle : rendre l'app installable (icône sur l'écran d'accueil) et
 * activer le Web Share Target (« Partager → MemoryLane » depuis la galerie
 * du téléphone). Les requêtes passent au réseau sans mise en cache — les
 * médias sont servis par URLs signées à durée limitée, les cacher serait
 * contre-productif.
 */

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', () => {
    // Passthrough réseau : ne pas appeler respondWith laisse le navigateur
    // gérer la requête normalement (y compris le POST du share target).
});

// ---- Notifications push ----------------------------------------------------
// Payload JSON produit par WebPushMessage côté serveur :
// { title, body, icon, badge, tag, renotify, requireInteraction, data: { url } }
self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'MemoryLane', {
            body: payload.body || '',
            icon: payload.icon || '/icons/icon-192.png',
            badge: payload.badge || '/icons/icon-192.png',
            tag: payload.tag || undefined,
            renotify: Boolean(payload.renotify),
            requireInteraction: Boolean(payload.requireInteraction),
            lang: payload.lang || 'fr',
            data: payload.data || {},
        })
    );
});

// Clic : on réutilise une fenêtre de l'app déjà ouverte (navigation vers le
// lien profond), sinon on en ouvre une.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL((event.notification.data && event.notification.data.url) || '/dashboard', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const existing = windows.find((w) => new URL(w.url).origin === self.location.origin);
            if (existing) {
                const navigated = 'navigate' in existing ? existing.navigate(target) : Promise.resolve(existing);
                return navigated.then((w) => (w || existing).focus());
            }
            return self.clients.openWindow(target);
        })
    );
});
