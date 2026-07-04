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
