import { ref } from 'vue';

/**
 * Détection de visages 100 % côté navigateur avec face-api.js
 * (@vladmandic/face-api). Aucune photo n'est envoyée vers un service tiers.
 *
 * - Les ~12 Mo de modèles sont importés dynamiquement (chunk Vite séparé,
 *   jamais chargé sur les autres pages) et chargés une seule fois.
 * - La détection lit les pixels de l'image sur un canvas : l'image doit donc
 *   être servie EN MÊME ORIGINE (endpoint proxy /vision/media/{id}/image),
 *   sinon le canvas est « tainted » → SecurityError.
 */

// Seuil de confiance du détecteur (exposé en constante, cf. plan).
// Abaissé à 0.2 pour capter davantage de visages (profils, petits, flous) ;
// contrepartie : plus de faux positifs, corrigeables manuellement (ignorer/retirer).
const MIN_CONFIDENCE = 0.2;

// Singletons partagés entre toutes les instances du composable.
let faceapi = null;
let modelsPromise = null;

async function loadFaceApi() {
  if (!faceapi) {
    faceapi = await import('@vladmandic/face-api');
  }
  return faceapi;
}

function ensureModelsLoaded() {
  if (!modelsPromise) {
    modelsPromise = (async () => {
      const api = await loadFaceApi();
      await Promise.all([
        api.nets.ssdMobilenetv1.loadFromUri('/models'),
        api.nets.faceLandmark68Net.loadFromUri('/models'),
        api.nets.faceRecognitionNet.loadFromUri('/models'),
      ]);
      return api;
    })().catch((err) => {
      // Ne pas mettre en cache un échec de chargement : autoriser un retry.
      modelsPromise = null;
      throw err;
    });
  }
  return modelsPromise;
}

/**
 * Charge une image (URL proxy même-origine) dans un <img> décodé, prêt pour
 * la détection.
 */
function loadImage(url) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error("Impossible de charger l'image pour la détection"));
    img.src = url;
  });
}

export function useFaceDetection() {
  const loading = ref(false);
  const progress = ref('');
  const error = ref(null);

  /**
   * Détecte les visages d'une image proxy et renvoie un tableau prêt pour
   * l'endpoint storeFaces : bounding_box en %, confidence, embedding[128].
   */
  async function detectFaces(imageUrl) {
    loading.value = true;
    error.value = null;
    let img = null;

    try {
      progress.value = 'Chargement des modèles…';
      const api = await ensureModelsLoaded();

      progress.value = "Chargement de l'image…";
      img = await loadImage(imageUrl);

      progress.value = 'Détection des visages…';
      const results = await api
        .detectAllFaces(img, new api.SsdMobilenetv1Options({ minConfidence: MIN_CONFIDENCE }))
        .withFaceLandmarks()
        .withFaceDescriptors();

      const nw = img.naturalWidth || img.width;
      const nh = img.naturalHeight || img.height;
      const clamp = (v) => Math.max(0, Math.min(100, v));

      return results.map((r) => {
        const box = r.detection.box;
        return {
          bounding_box: {
            x: clamp((box.x / nw) * 100),
            y: clamp((box.y / nh) * 100),
            width: clamp((box.width / nw) * 100),
            height: clamp((box.height / nh) * 100),
          },
          confidence: r.detection.score,
          embedding: Array.from(r.descriptor),
        };
      });
    } catch (err) {
      error.value = err.message || String(err);
      throw err;
    } finally {
      // Libère la mémoire GPU/CPU des tenseurs entre deux images (utile en batch).
      if (faceapi?.tf) {
        faceapi.tf.disposeVariables?.();
      }
      progress.value = '';
      loading.value = false;
    }
  }

  /**
   * Calcule l'embedding d'une région dessinée à la main (bounding_box en %),
   * pour ajouter un visage que la détection auto a raté. Renvoie un tableau de
   * 128 floats, ou null si le réseau ne peut rien produire.
   */
  async function computeDescriptorForRegion(imageUrl, box) {
    loading.value = true;
    error.value = null;
    try {
      progress.value = 'Chargement des modèles…';
      const api = await ensureModelsLoaded();

      const img = await loadImage(imageUrl);
      const nw = img.naturalWidth || img.width;
      const nh = img.naturalHeight || img.height;

      const sx = Math.max(0, (box.x / 100) * nw);
      const sy = Math.max(0, (box.y / 100) * nh);
      const sw = Math.min(nw - sx, (box.width / 100) * nw);
      const sh = Math.min(nh - sy, (box.height / 100) * nh);
      if (sw < 8 || sh < 8) return null;

      const canvas = document.createElement('canvas');
      canvas.width = Math.round(sw);
      canvas.height = Math.round(sh);
      canvas.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

      progress.value = 'Calcul de l’empreinte…';
      const descriptor = await api.computeFaceDescriptor(canvas);
      const arr = Array.isArray(descriptor) ? descriptor[0] : descriptor;
      return arr ? Array.from(arr) : null;
    } catch (err) {
      // Pas de visage exploitable dans la zone : on stockera sans embedding.
      return null;
    } finally {
      if (faceapi?.tf) faceapi.tf.disposeVariables?.();
      progress.value = '';
      loading.value = false;
    }
  }

  return { detectFaces, computeDescriptorForRegion, ensureModelsLoaded, loading, progress, error };
}
