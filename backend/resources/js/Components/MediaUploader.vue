<template>
  <div class="media-uploader">
    <div class="upload-area">
      <div
        ref="dropZone"
        class="border-2 border-dashed border-surface-300 rounded-lg p-8 text-center hover:border-brand-400 transition-colors duration-200"
        :class="{ 'border-brand-500 bg-brand-50': isDragging }"
        @dragover.prevent="handleDragOver"
        @dragleave="handleDragLeave"
        @drop.prevent="handleDrop"
      >
        <div v-if="!uploading && files.length === 0" class="space-y-4">
          <svg
            class="mx-auto h-12 w-12 text-surface-400"
            stroke="currentColor"
            fill="none"
            viewBox="0 0 48 48"
            aria-hidden="true"
          >
            <path
              d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <div>
            <label
              for="file-upload"
              class="cursor-pointer text-brand-600 hover:text-brand-500 font-medium"
            >
              Choisir des fichiers
            </label>
            <span class="text-surface-600"> ou glisser-déposer</span>
            <input
              id="file-upload"
              ref="fileInput"
              type="file"
              class="sr-only"
              multiple
              accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
              @change="handleFileSelect"
            />
          </div>
          <p class="text-xs text-surface-500">
            Photos/docs jusqu'à 2 Go · vidéos (MP4, MOV, AVI, MKV, WEBM) jusqu'à 20 Go
          </p>
        </div>

        <div v-else class="space-y-4">
          <div v-if="uploading" class="space-y-2">
            <div class="flex items-center justify-center space-x-2">
              <svg
                class="animate-spin h-5 w-5 text-brand-600"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  class="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  stroke-width="4"
                ></circle>
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
              </svg>
              <span class="text-surface-700 font-medium">Téléchargement en cours...</span>
            </div>
            <div class="w-full bg-surface-200 rounded-full h-2">
              <div
                class="bg-brand-600 h-2 rounded-full transition-all duration-300"
                :style="{ width: `${uploadProgress}%` }"
              ></div>
            </div>
            <p class="text-sm text-surface-600">
              {{ uploadedCount }} / {{ totalFiles }} fichier(s) téléchargé(s)
            </p>
            <p v-if="currentFileLabel" class="text-xs text-surface-500 truncate">
              {{ currentFileLabel }}
            </p>
          </div>

          <div v-else class="space-y-3">
            <div
              v-for="file in files"
              :key="file.id"
              class="flex items-center justify-between p-3 bg-white rounded-lg border border-surface-200"
            >
              <div class="flex items-center space-x-3 flex-1 min-w-0">
                <div class="shrink-0">
                  <svg
                    v-if="isImage(file.type)"
                    class="h-6 w-6 text-brand-500"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                    />
                  </svg>
                  <svg
                    v-else-if="isVideo(file.type)"
                    class="h-6 w-6 text-violet-500"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"
                    />
                  </svg>
                  <svg
                    v-else
                    class="h-6 w-6 text-surface-500"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                    />
                  </svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 truncate">
                    {{ file.name }}
                  </p>
                  <p class="text-xs text-surface-500">{{ formatFileSize(file.size) }}</p>
                </div>
              </div>
              <button
                type="button"
                class="ml-3 text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
                @click="removeFile(file.id)"
              >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </button>
            </div>

            <!-- Album de destination (choisi avant l'upload ; masqué quand
                 l'album cible est imposé par le parent) -->
            <div v-if="!targetAlbumId" class="pt-3 border-t border-surface-100 text-left">
              <label class="block text-sm font-medium text-surface-700 mb-2">
                Album de destination
              </label>
              <div class="flex rounded-lg bg-surface-100 p-1 text-sm font-medium">
                <button
                  v-for="opt in albumModeOptions"
                  :key="opt.value"
                  type="button"
                  class="flex-1 rounded-md px-3 py-1.5 transition"
                  :class="albumMode === opt.value ? 'bg-white text-surface-900 shadow-xs' : 'text-surface-500 hover:text-surface-700'"
                  @click="albumMode = opt.value"
                >
                  {{ opt.label }}
                </button>
              </div>
              <select
                v-if="albumMode === 'existing'"
                v-model="selectedAlbumId"
                class="mt-2 block w-full px-3 py-2 border border-surface-300 rounded-md focus:outline-hidden focus:ring-1 focus:ring-brand-500 focus:border-brand-500 sm:text-sm"
              >
                <option :value="null" disabled>Choisir un album…</option>
                <option v-for="album in ownedAlbums" :key="album.id" :value="album.id">
                  {{ album.name }} ({{ album.media_count }})
                </option>
              </select>
              <p v-if="albumMode === 'existing' && !loadingAlbums && ownedAlbums.length === 0" class="mt-2 text-xs text-surface-500">
                Aucun album existant. Choisissez « Nouvel album ».
              </p>
              <input
                v-else-if="albumMode === 'new'"
                v-model="newAlbumName"
                type="text"
                placeholder="Nom du nouvel album"
                class="mt-2 block w-full px-3 py-2 border border-surface-300 rounded-md focus:outline-hidden focus:ring-1 focus:ring-brand-500 focus:border-brand-500 sm:text-sm"
              />
            </div>

            <div class="flex space-x-3 pt-2">
              <button
                type="button"
                class="flex-1 px-4 py-2 bg-brand-600 text-white rounded-lg hover:bg-brand-700 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="!canUpload"
                @click="startUpload"
              >
                Télécharger {{ files.length }} fichier(s)
              </button>
              <button
                type="button"
                class="px-4 py-2 bg-surface-200 text-surface-700 rounded-lg hover:bg-surface-300 transition-colors"
                @click="clearFiles"
              >
                Annuler
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="error" class="mt-4 p-4 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 rounded-lg">
        <div class="flex">
          <svg
            class="h-5 w-5 text-red-400"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
          </svg>
          <div class="ml-3">
            <p class="text-sm text-red-700 dark:text-red-300">{{ error }}</p>
          </div>
        </div>
      </div>

      <div v-if="uploadedMedia.length > 0" class="mt-6">
        <h3 class="text-lg font-medium text-surface-900 mb-3">
          Fichiers téléchargés avec succès
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
          <div
            v-for="media in uploadedMedia"
            :key="media.id"
            class="relative aspect-square rounded-lg overflow-hidden bg-surface-100"
          >
            <!-- Photo : aperçu réel -->
            <img
              v-if="media.type === 'photo'"
              :src="media.url"
              :alt="media.original_filename"
              class="w-full h-full object-cover"
            />
            <!-- Vidéo : card violet avec icône play -->
            <div
              v-else-if="media.type === 'video'"
              class="w-full h-full flex flex-col items-center justify-center bg-violet-50 dark:bg-violet-500/10 p-3"
            >
              <div class="rounded-full bg-violet-100 dark:bg-violet-500/15 p-3 mb-2">
                <svg class="h-8 w-8 text-violet-500" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M8 5v14l11-7z" />
                </svg>
              </div>
              <p class="text-xs text-violet-700 dark:text-violet-300 text-center font-medium leading-tight line-clamp-2">
                {{ media.original_filename }}
              </p>
            </div>
            <!-- Document : card neutre avec icône fichier -->
            <div
              v-else
              class="w-full h-full flex flex-col items-center justify-center bg-surface-50 p-3"
            >
              <div class="rounded-full bg-surface-200 p-3 mb-2">
                <svg class="h-8 w-8 text-surface-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
              </div>
              <p class="text-xs text-surface-600 text-center font-medium leading-tight line-clamp-2">
                {{ media.original_filename }}
              </p>
            </div>
            <!-- Badge succès permanent -->
            <div class="absolute top-2 right-2 bg-green-500 rounded-full p-1 shadow-xs">
              <svg class="h-3 w-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { fetchOwnedAlbums, createAlbumWithMedia, addMediaToAlbum } from '@/utils/albums';
import { formatFileSize } from '@/utils/format';

// `album-attached` : { albumId, albumName, count, isNew } — pour un retour parent.
const emit = defineEmits(['upload-complete', 'album-attached']);

const props = defineProps({
  // Album cible imposé (ex. modale « Ajouter des médias » d'un album) :
  // masque le choix de destination et rattache automatiquement après upload.
  targetAlbumId: {
    type: String,
    default: null,
  },
});

// Album de destination choisi AVANT l'upload (rattaché une fois tout monté).
const albumModeOptions = [
  { value: 'none', label: 'Aucun' },
  { value: 'existing', label: 'Album existant' },
  { value: 'new', label: 'Nouvel album' },
];
const albumMode = ref('none');
const selectedAlbumId = ref(null);
const newAlbumName = ref('');
const ownedAlbums = ref([]);
const loadingAlbums = ref(false);

// Empêche de lancer l'upload avec une destination album incomplète.
const canUpload = computed(() => {
  if (albumMode.value === 'existing') return !!selectedAlbumId.value;
  if (albumMode.value === 'new') return newAlbumName.value.trim().length > 0;
  return true;
});

onMounted(async () => {
  if (props.targetAlbumId) return; // destination imposée, rien à charger
  loadingAlbums.value = true;
  try {
    ownedAlbums.value = await fetchOwnedAlbums();
  } catch (e) {
    console.error('Chargement des albums impossible :', e);
  } finally {
    loadingAlbums.value = false;
  }
});

// Rattache les médias tout juste uploadés à l'album choisi (créé si « nouveau »),
// ou directement à l'album cible quand il est imposé par le parent.
const attachToChosenAlbum = async (mediaIds) => {
  if (mediaIds.length === 0) return;

  if (props.targetAlbumId) {
    try {
      await addMediaToAlbum(props.targetAlbumId, mediaIds);
      emit('album-attached', { albumId: props.targetAlbumId, albumName: '', count: mediaIds.length, isNew: false });
    } catch (e) {
      error.value = "Fichiers téléchargés, mais l'ajout à l'album a échoué.";
    }
    return;
  }

  if (albumMode.value === 'none') return;
  try {
    if (albumMode.value === 'new') {
      const album = await createAlbumWithMedia(newAlbumName.value.trim(), mediaIds);
      emit('album-attached', { albumId: album.id, albumName: album.name, count: mediaIds.length, isNew: true });
    } else {
      const album = ownedAlbums.value.find((a) => a.id === selectedAlbumId.value);
      await addMediaToAlbum(selectedAlbumId.value, mediaIds);
      emit('album-attached', { albumId: selectedAlbumId.value, albumName: album?.name ?? '', count: mediaIds.length, isNew: false });
    }
    // Réinitialise le choix pour le prochain lot.
    albumMode.value = 'none';
    selectedAlbumId.value = null;
    newAlbumName.value = '';
  } catch (e) {
    error.value = "Fichiers téléchargés, mais l'ajout à l'album a échoué.";
    console.error('Ajout à l\'album impossible :', e);
  }
};

const fileInput = ref(null);
const dropZone = ref(null);
const isDragging = ref(false);
const files = ref([]);
const uploading = ref(false);
const uploadProgress = ref(0);
const uploadedCount = ref(0);
const totalFiles = ref(0);
const error = ref(null);
const uploadedMedia = ref([]);
const currentFileLabel = ref('');

let fileIdCounter = 0;

// Au-delà de ce seuil (ou pour toute vidéo), on passe par l'upload multipart
// direct vers S3 (contourne les limites 2 Go de PHP/nginx, reprise part/part).
const DIRECT_UPLOAD_THRESHOLD = 100 * 1024 * 1024; // 100 Mo
const MAX_VIDEO_SIZE = 20 * 1024 * 1024 * 1024; // 20 Go
const MAX_DEFAULT_SIZE = 2097152000; // 2 Go

// Types vidéo dont file.type peut être vide selon le navigateur.
const VIDEO_EXT_MIME = {
  mp4: 'video/mp4', mov: 'video/quicktime', avi: 'video/x-msvideo',
  mkv: 'video/x-matroska', webm: 'video/webm', m4v: 'video/mp4',
};

// Résout le type MIME (certains navigateurs renvoient '' pour .mkv).
const resolveMime = (file) => {
  if (file.type) return file.type;
  const ext = file.name.split('.').pop()?.toLowerCase();
  return VIDEO_EXT_MIME[ext] || '';
};

const needsDirectUpload = (file) => {
  const mime = resolveMime(file);
  return mime.startsWith('video/') || file.size > DIRECT_UPLOAD_THRESHOLD;
};

const isImage = (mimeType) => {
  return mimeType.startsWith('image/');
};

const isVideo = (mimeType) => {
  return mimeType.startsWith('video/');
};

const handleDragOver = (e) => {
  isDragging.value = true;
};

const handleDragLeave = (e) => {
  isDragging.value = false;
};

const handleDrop = (e) => {
  isDragging.value = false;
  const droppedFiles = Array.from(e.dataTransfer.files);
  addFiles(droppedFiles);
};

const handleFileSelect = (e) => {
  const selectedFiles = Array.from(e.target.files);
  addFiles(selectedFiles);
};

const addFiles = (newFiles) => {
  error.value = null;

  const validFiles = newFiles.filter(file => {
    const mime = resolveMime(file);
    const isVid = mime.startsWith('video/');
    const maxSize = isVid ? MAX_VIDEO_SIZE : MAX_DEFAULT_SIZE;

    if (file.size > maxSize) {
      const label = isVid ? '20 Go' : '2 Go';
      error.value = `Le fichier "${file.name}" dépasse la taille maximale de ${label}`;
      return false;
    }

    // Check file type
    const allowedTypes = [
      'image/jpeg',
      'image/png',
      'image/gif',
      'image/webp',
      'video/mp4',
      'video/quicktime',
      'video/x-msvideo',
      'video/x-matroska',
      'video/webm',
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    if (!allowedTypes.includes(mime)) {
      error.value = `Le type de fichier "${file.name}" n'est pas supporté`;
      return false;
    }

    return true;
  });

  const filesWithIds = validFiles.map(file => ({
    id: ++fileIdCounter,
    file: file,
    name: file.name,
    size: file.size,
    type: resolveMime(file)
  }));

  files.value.push(...filesWithIds);
};

const removeFile = (id) => {
  files.value = files.value.filter(f => f.id !== id);
};

const clearFiles = () => {
  files.value = [];
  error.value = null;
  if (fileInput.value) {
    fileInput.value.value = '';
  }
};

const startUpload = async () => {
  if (files.value.length === 0) return;

  uploading.value = true;
  uploadProgress.value = 0;
  uploadedCount.value = 0;
  totalFiles.value = files.value.length;
  error.value = null;
  uploadedMedia.value = [];

  try {
    const total = files.value.length;
    for (let i = 0; i < total; i++) {
      const fileData = files.value[i];
      currentFileLabel.value = total > 1 ? `${fileData.name} (${i + 1}/${total})` : fileData.name;

      // Progression globale = fichiers déjà finis + fraction du fichier courant.
      const onFraction = (frac) => {
        uploadProgress.value = Math.round(((i + Math.min(frac, 1)) / total) * 100);
      };
      onFraction(0);

      await uploadSingleFile(fileData.file, onFraction);
      uploadedCount.value++;
      uploadProgress.value = Math.round((uploadedCount.value / total) * 100);
    }

    currentFileLabel.value = '';

    // Success - clear the form
    clearFiles();

    // Emit event to notify parent component
    emit('upload-complete', uploadedMedia.value);

    // Rattachement à l'album de destination choisi avant l'upload.
    await attachToChosenAlbum(uploadedMedia.value.map((m) => m.id));

  } catch (err) {
    error.value = err.message || 'Une erreur est survenue lors du téléchargement';
  } finally {
    uploading.value = false;
    currentFileLabel.value = '';
  }
};

const uploadSingleFile = async (file, onFraction) => {
  try {
    const media = needsDirectUpload(file)
      ? await uploadViaMultipart(file, onFraction)
      : await uploadViaPost(file, onFraction);

    if (media) uploadedMedia.value.push(media);
  } catch (err) {
    const errorMessage = err.response?.data?.error || err.response?.data?.message || err.message;
    throw new Error(`Échec du téléchargement de "${file.name}": ${errorMessage}`);
  }
};

// Petit upload synchrone classique (photos, docs) : POST multipart vers PHP.
const uploadViaPost = async (file, onFraction) => {
  const formData = new FormData();
  formData.append('file', file);

  const response = await axios.post('/media', formData, {
    onUploadProgress: (e) => {
      if (e.total) onFraction(e.loaded / e.total);
    },
  });
  return response.data?.media || null;
};

// Clé de reprise en localStorage, propre à un fichier donné.
const LS_PREFIX = 'ml_upload_';
const fileKey = (file) => `${LS_PREFIX}${file.name}|${file.size}|${file.lastModified}`;

// Upload multipart direct vers S3 : chaque part est PUT directement sur le
// bucket via une URL présignée, sans passer par PHP/nginx. Reprend un upload
// interrompu du même fichier (les parts déjà sur S3 ne sont pas renvoyées).
const uploadViaMultipart = async (file, onFraction) => {
  const lsKey = fileKey(file);
  let sessionId = null;
  let partSize = null;
  let partCount = null;
  const uploadedMap = new Map(); // part_number -> etag déjà présents sur S3

  // Tentative de reprise d'un upload précédent de ce fichier.
  const savedId = localStorage.getItem(lsKey);
  if (savedId) {
    try {
      const { data } = await axios.post('/media/uploads/status', { upload_session_id: savedId });
      sessionId = data.upload_session_id;
      partSize = data.part_size;
      partCount = data.part_count;
      (data.uploaded_parts || []).forEach((p) => uploadedMap.set(p.part_number, p.etag));
    } catch (_) {
      localStorage.removeItem(lsKey); // session caduque -> on repart de zéro
    }
  }

  // Nouvel upload si pas de reprise possible.
  if (!sessionId) {
    const { data: init } = await axios.post('/media/uploads/initiate', {
      original_name: file.name,
      mime_type: resolveMime(file),
      size: file.size,
    });
    sessionId = init.upload_session_id;
    partSize = init.part_size;
    partCount = init.part_count;
    // Persiste AVANT d'envoyer les parts : une coupure reste reprenable.
    localStorage.setItem(lsKey, sessionId);
  }

  const parts = [];
  for (let n = 1; n <= partCount; n++) {
    if (uploadedMap.has(n)) {
      // Part déjà montée lors d'une tentative précédente : on la réutilise.
      parts.push({ part_number: n, etag: uploadedMap.get(n) });
      onFraction(n / partCount);
      continue;
    }
    const start = (n - 1) * partSize;
    const blob = file.slice(start, Math.min(start + partSize, file.size));
    const etag = await uploadPartWithRetry(sessionId, n, blob);
    parts.push({ part_number: n, etag });
    onFraction(n / partCount);
  }

  // On n'abandonne PAS l'upload en cas d'erreur : la session est conservée
  // (localStorage + multipart S3) pour permettre la reprise au prochain essai.
  const { data: done } = await axios.post('/media/uploads/complete', {
    upload_session_id: sessionId,
    parts,
  });
  localStorage.removeItem(lsKey); // terminé : plus rien à reprendre
  return done.media;
};

// PUT d'une part vers S3, avec quelques tentatives. On utilise fetch (et non
// axios) pour éviter d'envoyer les en-têtes/cookies de l'app à S3 (sinon le
// CORS en mode credentials échoue).
const uploadPartWithRetry = async (sessionId, partNumber, blob, attempts = 3) => {
  let lastErr;
  for (let i = 0; i < attempts; i++) {
    try {
      const { data } = await axios.post('/media/uploads/part-url', {
        upload_session_id: sessionId,
        part_number: partNumber,
      });

      const res = await fetch(data.url, { method: 'PUT', body: blob });
      if (!res.ok) throw new Error(`S3 a répondu ${res.status} pour la part ${partNumber}`);

      const etag = res.headers.get('ETag') || res.headers.get('etag');
      if (!etag) throw new Error('ETag manquant (CORS ExposeHeaders ETag ?)');
      return etag;
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr;
};
</script>
