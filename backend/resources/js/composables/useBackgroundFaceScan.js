import axios from 'axios';
import { useFaceDetection } from './useFaceDetection';

// Worker discret : pendant que l'utilisateur navigue, analyse en tâche de fond
// les photos non encore scannées (détection 100% client) puis auto-associe les
// personnes très proches (endpoint auto-match, seuil strict). Silencieux.
//
// Singleton pour toute la session SPA (AppLayout est monté/démonté à chaque
// navigation Inertia, mais ce module persiste) : une seule boucle à la fois,
// aucun coût si rien n'est en attente (les modèles ne se chargent que s'il y a
// des photos à traiter).

let started = false;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function run() {
  const { detectFaces } = useFaceDetection();
  const attempted = new Set(); // évite de re-tenter en boucle une photo qui échoue

  await sleep(3000); // laisse la page finir de se charger avant de mobiliser le CPU

  for (;;) {
    let ids = [];
    try {
      const { data } = await axios.get('/vision/pending');
      ids = (data.media_ids || []).filter((id) => !attempted.has(id));
    } catch {
      return; // non connecté / erreur → on abandonne pour cette session
    }

    if (!ids.length) return; // plus rien à analyser

    for (const id of ids) {
      attempted.add(id);
      try {
        const faces = await detectFaces(`/vision/media/${id}/image?conversion=medium`);
        await axios.post(`/vision/media/${id}/faces`, { faces });

        // Auto-association des visages très proches d'une personne déjà connue.
        const { data: list } = await axios.get(`/vision/media/${id}/faces`);
        for (const face of list) {
          if (face.status === 'unmatched') {
            try {
              await axios.post(`/vision/faces/${face.id}/auto-match`);
            } catch {
              /* pas de correspondance sûre : on laisse à confirmer manuellement */
            }
          }
        }
      } catch {
        /* photo ignorée (détection/réseau) — ne bloque pas la boucle */
      }
      await sleep(1500); // pause entre photos : reste discret
    }
  }
}

/**
 * Démarre le scan de fond (une seule fois par session). À appeler depuis un
 * composant global (AppLayout).
 */
export function startBackgroundFaceScan() {
  if (started) return;
  started = true;
  run().catch(() => {});
}
