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
//
// Garde-fous (le scan coûte face-api ~325 ko gz + ~12 Mo de modèles + du
// calcul TF.js) :
//  - ordinateur uniquement (pointeur fin) et jamais en mode « économie de
//    données » : sur téléphone, batterie et forfait passent avant ;
//  - démarrage quand le navigateur est inactif (requestIdleCallback) ;
//  - en pause tant que l'onglet est masqué ;
//  - au plus MAX_PHOTOS_PER_SESSION photos par chargement de l'appli.

const MAX_PHOTOS_PER_SESSION = 20;

// Requêtes de fond : l'intercepteur axios (bootstrap.js) ne doit ni recharger
// la page (419) ni rediriger vers /login (401) pendant que l'utilisateur
// fait autre chose.
const BACKGROUND = { background: true };

let started = false;
let processed = 0;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Attend un moment d'inactivité du navigateur (repli : simple délai).
const whenIdle = () => new Promise((resolve) => {
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(() => resolve(), { timeout: 10000 });
  } else {
    setTimeout(resolve, 2000);
  }
});

// Attend que l'onglet soit visible : aucun calcul quand il est en arrière-plan.
const whenVisible = () => new Promise((resolve) => {
  if (!document.hidden) return resolve();
  const onChange = () => {
    if (document.hidden) return;
    document.removeEventListener('visibilitychange', onChange);
    resolve();
  };
  document.addEventListener('visibilitychange', onChange);
});

// Ordinateur (pointeur fin : souris/pavé) et pas d'économie de données.
function deviceAllowsScan() {
  if (!window.matchMedia?.('(pointer: fine)').matches) return false;
  if (navigator.connection?.saveData) return false;
  return true;
}

async function run() {
  const { detectFaces } = useFaceDetection();
  const attempted = new Set(); // évite de re-tenter en boucle une photo qui échoue

  await sleep(3000); // laisse la page finir de se charger avant de mobiliser le CPU
  await whenIdle();

  for (;;) {
    if (processed >= MAX_PHOTOS_PER_SESSION) return;
    await whenVisible();

    let ids = [];
    try {
      const { data } = await axios.get('/vision/pending', BACKGROUND);
      ids = (data.media_ids || []).filter((id) => !attempted.has(id));
    } catch {
      return; // non connecté / erreur → on abandonne pour cette session
    }

    if (!ids.length) return; // plus rien à analyser

    for (const id of ids) {
      if (processed >= MAX_PHOTOS_PER_SESSION) return; // plafond : la suite au prochain chargement
      await whenVisible();
      await whenIdle();

      attempted.add(id);
      processed++;
      try {
        const faces = await detectFaces(`/vision/media/${id}/image?conversion=medium`);
        await axios.post(`/vision/media/${id}/faces`, { faces }, BACKGROUND);

        // Auto-association des visages très proches d'une personne déjà connue.
        const { data: list } = await axios.get(`/vision/media/${id}/faces`, BACKGROUND);
        for (const face of list) {
          if (face.status === 'unmatched') {
            try {
              await axios.post(`/vision/faces/${face.id}/auto-match`, null, BACKGROUND);
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
 * composant global (AppLayout). Sans effet sur téléphone/tablette ou en mode
 * « économie de données ».
 */
export function startBackgroundFaceScan() {
  if (started) return;
  started = true;
  if (!deviceAllowsScan()) return;
  run().catch(() => {});
}
