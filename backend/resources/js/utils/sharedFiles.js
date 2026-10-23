// Fichiers reçus par « Partager → MemoryLane » (Web Share Target) : le
// service worker (public/sw.js) les garde dans IndexedDB et ouvre la page
// d'envoi avec ?partage=1 ; on les reprend ici, une seule fois.
const SHARE_DB = 'ml-share';
const MAX_AGE_MS = 24 * 3600 * 1000;

export async function takeSharedFiles() {
  if (typeof indexedDB === 'undefined') return [];
  try {
    const db = await new Promise((resolve, reject) => {
      const open = indexedDB.open(SHARE_DB, 1);
      open.onupgradeneeded = () => open.result.createObjectStore('files', { autoIncrement: true });
      open.onsuccess = () => resolve(open.result);
      open.onerror = () => reject(open.error);
    });
    const rows = await new Promise((resolve, reject) => {
      const tx = db.transaction('files', 'readwrite');
      const store = tx.objectStore('files');
      const all = store.getAll();
      all.onsuccess = () => { store.clear(); resolve(all.result || []); };
      all.onerror = () => reject(all.error);
    });
    db.close();
    return rows
      .filter((r) => r?.blob && Date.now() - (r.at || 0) < MAX_AGE_MS)
      .map((r) => new File([r.blob], r.name || 'partage', { type: r.type || r.blob.type }));
  } catch {
    return [];
  }
}
