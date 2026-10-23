/**
 * Service worker minimal de MemoryLane.
 *
 * Son rôle : rendre l'app installable (icône sur l'écran d'accueil),
 * recevoir les fichiers du Web Share Target (« Partager → MemoryLane »
 * depuis la galerie du téléphone) et les notifications push. Les autres
 * requêtes passent au réseau sans mise en cache — les
 * médias sont servis par URLs signées à durée limitée, les cacher serait
 * contre-productif.
 */

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// ---- « Partager → MemoryLane » (Web Share Target) ---------------------------
// Les fichiers partagés depuis Google Photos / la Galerie sont gardés dans
// IndexedDB puis la page « Ajouter des photos » les reprend : choix de
// l'album (dont un nouvel album), partage au cercle, envoi direct vers le
// stockage avec progression. Sans service worker actif, le POST arrive au
// serveur (ShareTargetController), qui range les fichiers dans la galerie.
const SHARE_DB = 'ml-share';

function shareDb() {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open(SHARE_DB, 1);
        open.onupgradeneeded = () => open.result.createObjectStore('files', { autoIncrement: true });
        open.onsuccess = () => resolve(open.result);
        open.onerror = () => reject(open.error);
    });
}

async function keepSharedFiles(files) {
    const db = await shareDb();
    await new Promise((resolve, reject) => {
        const tx = db.transaction('files', 'readwrite');
        const store = tx.objectStore('files');
        store.clear();
        const at = Date.now();
        for (const file of files) store.add({ name: file.name, type: file.type, blob: file, at });
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
    db.close();
}

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'POST' || url.origin !== self.location.origin || url.pathname !== '/share-target') {
        // Passthrough réseau : ne pas appeler respondWith laisse le
        // navigateur gérer la requête normalement.
        return;
    }
    const fallback = event.request.clone();
    event.respondWith((async () => {
        try {
            const form = await event.request.formData();
            const files = form.getAll('media').filter((f) => f && typeof f === 'object' && f.size > 0);
            if (!files.length) throw new Error('aucun fichier');
            await keepSharedFiles(files);
            return Response.redirect('/media/upload?partage=1', 303);
        } catch (e) {
            return fetch(fallback);
        }
    })());
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
