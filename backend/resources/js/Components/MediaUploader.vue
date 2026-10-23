<template>
  <div class="media-uploader">
    <!-- ================= 1. Choix des photos ================= -->
    <div
      v-if="phase === 'select' && items.length === 0"
      class="rounded-2xl border-2 border-dashed px-5 py-10 sm:py-12 text-center transition-colors duration-200"
      :class="isDragging ? 'border-brand-500 bg-brand-50' : 'border-surface-300'"
      @dragover.prevent="isDragging = true"
      @dragleave="isDragging = false"
      @drop.prevent="handleDrop"
    >
      <div class="mx-auto w-16 h-16 rounded-2xl bg-brand-100 flex items-center justify-center">
        <svg class="w-9 h-9 text-brand-600" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21zm9-12h.008v.008H12.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
        </svg>
      </div>

      <label
        for="file-upload"
        data-tour="upload-pick"
        class="mt-6 inline-flex items-center justify-center gap-2 w-full sm:w-auto px-7 py-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-lg font-semibold shadow-warm-md cursor-pointer transition"
      >
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        {{ isTouch ? $t('Choisir dans mes photos') : $t('Choisir des photos') }}
      </label>
      <input
        id="file-upload"
        ref="fileInput"
        type="file"
        class="sr-only"
        multiple
        accept="image/*,video/*"
        @change="handleFileSelect"
      />

      <p v-if="!isTouch" class="mt-3 text-surface-500">{{ $t('ou glissez-les directement ici') }}</p>
      <p class="mt-4 text-sm text-surface-500">
        {{ $t('Photos et vidéos, autant que vous voulez.') }}
        <label for="doc-upload" class="text-brand-700 dark:text-brand-300 underline underline-offset-2 cursor-pointer">{{ $t('Un document (PDF, Word) ?') }}</label>
      </p>
      <input
        id="doc-upload"
        type="file"
        class="sr-only"
        multiple
        accept="application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        @change="handleFileSelect"
      />
    </div>

    <!-- ================= 2. Sélection / envoi en cours ================= -->
    <div v-else-if="phase !== 'done'">
      <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
        <h2 class="text-lg font-semibold text-surface-900">
          <template v-if="phase === 'uploading'">{{ $t('Envoi en cours… :done / :total', { done: doneCount, total: items.length }) }}</template>
          <template v-else>{{ chosenText(items.length) }}</template>
        </h2>
        <label v-if="phase === 'select'" for="file-upload-more" class="text-sm font-medium text-brand-700 dark:text-brand-300 cursor-pointer">
          {{ $t('+ En ajouter') }}
        </label>
        <input id="file-upload-more" type="file" class="sr-only" multiple accept="image/*,video/*" @change="handleFileSelect" />
      </div>

      <!-- Barre de progression globale -->
      <div v-if="phase === 'uploading'" class="mb-4">
        <div class="w-full bg-surface-200 rounded-full h-2.5 overflow-hidden">
          <div class="bg-brand-600 h-2.5 rounded-full transition-all duration-300" :style="{ width: `${globalProgress}%` }"></div>
        </div>
        <p class="mt-2 text-sm text-surface-600 flex items-center gap-1.5">
          <svg class="w-4 h-4 text-brand-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
          {{ $t("Gardez cette page ouverte jusqu'à la fin de l'envoi.") }}
        </p>
      </div>

      <!-- Vignettes -->
      <div class="grid grid-cols-4 sm:grid-cols-6 lg:grid-cols-8 gap-1.5 sm:gap-2 max-h-[52vh] overflow-y-auto pr-0.5">
        <div
          v-for="item in items"
          :key="item.id"
          class="relative aspect-square rounded-lg overflow-hidden bg-surface-100"
        >
          <img
            v-if="item.previewUrl && !item.previewFailed"
            :src="item.previewUrl"
            :alt="item.name"
            loading="lazy"
            decoding="async"
            class="w-full h-full object-cover"
            @error="item.previewFailed = true"
          />
          <div v-else class="w-full h-full flex flex-col items-center justify-center gap-1 p-1"
               :class="isVideo(item.type) ? 'bg-violet-50 dark:bg-violet-500/10' : 'bg-surface-50'">
            <svg v-if="isVideo(item.type)" class="w-7 h-7 text-violet-500" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
            <svg v-else class="w-7 h-7 text-surface-400" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
            <span class="text-[10px] leading-tight text-surface-500 text-center line-clamp-2 break-all">{{ item.name }}</span>
          </div>

          <!-- Retirer (avant envoi) -->
          <button
            v-if="phase === 'select'"
            type="button"
            class="absolute top-1 right-1 w-6 h-6 rounded-full bg-black/55 text-white flex items-center justify-center"
            :aria-label="$t('Retirer :name', { name: item.name })"
            @click="removeItem(item.id)"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>

          <!-- État pendant l'envoi -->
          <div v-if="item.status === 'uploading' || item.status === 'pending'" class="absolute inset-0 bg-white/45 dark:bg-black/40 flex items-end">
            <div class="w-full h-1.5 bg-black/10">
              <div class="h-1.5 bg-brand-600 transition-all duration-200" :style="{ width: `${Math.round(item.progress * 100)}%` }"></div>
            </div>
          </div>
          <div v-else-if="item.status === 'done'" class="absolute top-1 right-1 w-6 h-6 rounded-full bg-green-500 flex items-center justify-center shadow-xs">
            <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
          </div>
          <div v-else-if="item.status === 'error'" class="absolute inset-0 bg-red-600/45 flex items-center justify-center" :title="item.error">
            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          </div>
        </div>
      </div>

      <!-- Options avant envoi -->
      <div v-if="phase === 'select'" class="mt-5 space-y-3">
        <label
          v-if="shareCircle"
          class="flex items-start gap-3 rounded-xl border border-surface-200 px-4 py-3 cursor-pointer"
          :class="shareWithCircle ? 'bg-brand-50 border-brand-200 dark:bg-brand-500/10 dark:border-brand-500/30' : ''"
        >
          <input v-model="shareWithCircle" type="checkbox" class="mt-0.5 h-5 w-5 rounded border-surface-300 text-brand-600 focus:ring-brand-500" />
          <span>
            <span class="block font-medium text-surface-900">{{ $t('Partager avec « :name »', { name: shareCircle.name }) }}</span>
            <span class="block text-sm text-surface-500">{{ $t('Les membres pourront les voir. Décochez pour les garder pour vous.') }}</span>
          </span>
        </label>

        <!-- Album (facultatif) -->
        <div v-if="!targetAlbumId">
          <button
            v-if="!showAlbumOptions"
            type="button"
            class="text-sm font-medium text-brand-700 dark:text-brand-300"
            @click="showAlbumOptions = true"
          >
            {{ $t('+ Ranger dans un album (facultatif)') }}
          </button>
          <div v-else class="rounded-xl border border-surface-200 px-4 py-3">
            <p class="text-sm font-medium text-surface-700 mb-2">{{ $t('Ranger dans un album') }}</p>
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
              class="form-input mt-2 w-full"
            >
              <option :value="null" disabled>{{ $t('Choisir un album…') }}</option>
              <option v-for="album in ownedAlbums" :key="album.id" :value="album.id">
                {{ album.name }} ({{ album.media_count }})
              </option>
            </select>
            <p v-if="albumMode === 'existing' && !loadingAlbums && ownedAlbums.length === 0" class="mt-2 text-xs text-surface-500">
              {{ $t("Aucun album pour l'instant : choisissez « Nouvel album ».") }}
            </p>
            <input
              v-else-if="albumMode === 'new'"
              v-model="newAlbumName"
              type="text"
              :placeholder="$t('Nom du nouvel album (ex. Noël 2025)')"
              class="form-input mt-2 w-full"
            />
          </div>
        </div>

        <div class="flex flex-col-reverse sm:flex-row gap-3 pt-1">
          <button type="button" class="btn-secondary sm:w-auto" @click="clearAll">{{ $t('Annuler') }}</button>
          <button
            type="button"
            class="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-base font-semibold shadow-warm-sm transition disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="!canUpload"
            @click="startUpload"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
            {{ sendText(items.length) }}
          </button>
        </div>
      </div>
    </div>

    <!-- ================= 3. Bilan ================= -->
    <div v-else class="text-center py-6">
      <div class="mx-auto w-16 h-16 rounded-full flex items-center justify-center"
           :class="failedItems.length && !doneItems.length ? 'bg-red-100 dark:bg-red-500/15' : 'bg-green-100 dark:bg-green-500/15'">
        <svg v-if="doneItems.length" class="w-9 h-9 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
        <svg v-else class="w-9 h-9 text-red-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
      </div>
      <h2 class="mt-4 font-display text-2xl text-surface-900">
        <template v-if="doneItems.length">{{ addedText(doneItems.length) }}</template>
        <template v-else>{{ $t("L'envoi n'a pas abouti") }}</template>
      </h2>
      <ul class="mt-3 space-y-1 text-surface-600">
        <li v-if="summary.sharedWith">{{ sharedText(doneItems.length, summary.sharedWith) }}</li>
        <li v-if="summary.albumName">{{ filedText(doneItems.length, summary.albumName) }}</li>
        <li v-if="summary.warning" class="text-amber-700 dark:text-amber-300">{{ summary.warning }}</li>
        <li v-if="doneItems.length" class="text-sm text-surface-500">{{ $t('Dates, lieux et visages sont reconnus automatiquement dans les minutes qui suivent.') }}</li>
      </ul>

      <div v-if="failedItems.length" class="mt-5 mx-auto max-w-md rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-4 py-3 text-left">
        <p class="text-sm font-medium text-red-800 dark:text-red-200">
          {{ failedText(failedItems.length) }}
        </p>
        <button type="button" class="mt-2 btn-primary" @click="retryFailed">{{ $t('Réessayer') }}</button>
      </div>

      <div class="mt-6 flex flex-col sm:flex-row justify-center gap-3">
        <Link v-if="doneItems.length && !targetAlbumId" href="/media" class="btn-primary">{{ $t('Voir mes photos') }}</Link>
        <button v-if="doneItems.length && !targetAlbumId && !summary.albumName" type="button" class="btn-secondary" @click="emit('organize', doneItems.map((i) => i.media.id))">
          {{ $t('Ranger dans un album') }}
        </button>
        <button type="button" class="btn-secondary" @click="clearAll">{{ $t("Ajouter d'autres photos") }}</button>
      </div>
    </div>

    <!-- Fichiers refusés à la sélection -->
    <div v-if="rejected.length" class="mt-4 rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
      {{ $tc(':count fichier ignoré : :list|:count fichiers ignorés : :list', rejected.length, { list: rejected.slice(0, 3).join(' · ') }) }}<span v-if="rejected.length > 3"> …</span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { fetchOwnedAlbums, createAlbumWithMedia, addMediaToAlbum } from '@/utils/albums';
import { useCircle } from '@/Composables/useCircle';
import { useConfirm } from '@/Composables/useConfirm';
import { apiError } from '@/utils/apiError';
import { takePendingFiles } from '@/utils/pendingUpload';
import { t, tc } from '@/i18n';

// `album-attached` : { albumId, albumName, count, isNew } ; `organize` : ids
// envoyés, pour proposer un rangement en album après coup.
const emit = defineEmits(['upload-complete', 'album-attached', 'organize']);

const props = defineProps({
  // Album cible imposé (ex. modale « Ajouter des médias » d'un album) :
  // masque le choix de destination et rattache automatiquement après upload.
  targetAlbumId: {
    type: String,
    default: null,
  },
});

// ---------------------------------------------------------------- appareil
const isTouch = typeof window !== 'undefined' && window.matchMedia?.('(pointer: coarse)').matches;

// ---------------------------------------------------------------- partage
// Cercle courant : les photos y sont partagées par défaut (sinon personne
// d'autre ne les voit). Sans objet dans la modale d'un album.
const { current: currentCircle } = useCircle();
const shareCircle = computed(() => (props.targetAlbumId ? null : currentCircle.value));
const shareWithCircle = ref(true);

// ---------------------------------------------------------------- album
const albumModeOptions = [
  { value: 'none', label: t('Aucun') },
  { value: 'existing', label: t('Album existant') },
  { value: 'new', label: t('Nouvel album') },
];
const showAlbumOptions = ref(false);
const albumMode = ref('none');
const selectedAlbumId = ref(null);
const newAlbumName = ref('');
const ownedAlbums = ref([]);
const loadingAlbums = ref(false);

onMounted(async () => {
  // Fichiers choisis depuis la feuille « Ajouter » (barre d'onglets) :
  // on les prend en charge directement, sans second sélecteur.
  const pending = takePendingFiles();
  if (pending.length) addFiles(pending);

  if (props.targetAlbumId) return;
  loadingAlbums.value = true;
  try {
    ownedAlbums.value = await fetchOwnedAlbums({ circleId: currentCircle.value?.id });
  } catch (e) {
    console.error('Chargement des albums impossible :', e);
  } finally {
    loadingAlbums.value = false;
  }
});

// ---------------------------------------------------------------- éléments
const fileInput = ref(null);
const isDragging = ref(false);
const items = ref([]);
const rejected = ref([]);
const phase = ref('select'); // select | uploading | done
const summary = ref({});
let idCounter = 0;

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
const VIDEO_TYPES = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'];
const DOC_TYPES = [
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
// Types dont file.type peut être vide selon le navigateur.
const EXT_MIME = {
  mp4: 'video/mp4', mov: 'video/quicktime', avi: 'video/x-msvideo',
  mkv: 'video/x-matroska', webm: 'video/webm', m4v: 'video/mp4',
  heic: 'image/heic', heif: 'image/heif',
};

const resolveMime = (file) => {
  if (file.type) return file.type;
  const ext = file.name.split('.').pop()?.toLowerCase();
  return EXT_MIME[ext] || '';
};
const isVideo = (mime) => mime.startsWith('video/');

// « 3 photos choisies », « 2 vidéos ajoutées », « 4 éléments ajoutés » :
// une phrase complète par nature (photo, vidéo, élément) pour les accords.
const mediaKind = computed(() => {
  const all = items.value;
  if (all.length && all.every((i) => i.type.startsWith('image/'))) return 'photo';
  if (all.length && all.every((i) => isVideo(i.type))) return 'video';
  return 'item';
});
const byKind = (texts) => texts[mediaKind.value];
const chosenText = (n) => byKind({
  photo: tc(':count photo choisie|:count photos choisies', n),
  video: tc(':count vidéo choisie|:count vidéos choisies', n),
  item: tc(':count élément choisi|:count éléments choisis', n),
});
const sendText = (n) => byKind({
  photo: tc('Envoyer :count photo|Envoyer :count photos', n),
  video: tc('Envoyer :count vidéo|Envoyer :count vidéos', n),
  item: tc('Envoyer :count élément|Envoyer :count éléments', n),
});
const addedText = (n) => byKind({
  photo: tc(':count photo ajoutée !|:count photos ajoutées !', n),
  video: tc(':count vidéo ajoutée !|:count vidéos ajoutées !', n),
  item: tc(':count élément ajouté !|:count éléments ajoutés !', n),
});
const sharedText = (n, name) => (mediaKind.value === 'item'
  ? tc('Partagé avec « :name ».|Partagés avec « :name ».', n, { name })
  : tc('Partagée avec « :name ».|Partagées avec « :name ».', n, { name }));
const filedText = (n, name) => (mediaKind.value === 'item'
  ? tc("Rangé dans l'album « :name ».|Rangés dans l'album « :name ».", n, { name })
  : tc("Rangée dans l'album « :name ».|Rangées dans l'album « :name ».", n, { name }));
const failedText = (n) => byKind({
  photo: tc(":count photo n'a pas pu être envoyée (connexion coupée ?).|:count photos n'ont pas pu être envoyées (connexion coupée ?).", n),
  video: tc(":count vidéo n'a pas pu être envoyée (connexion coupée ?).|:count vidéos n'ont pas pu être envoyées (connexion coupée ?).", n),
  item: tc(":count élément n'a pas pu être envoyé (connexion coupée ?).|:count éléments n'ont pas pu être envoyés (connexion coupée ?).", n),
});

// Au-delà de ce seuil (ou pour toute vidéo), upload multipart direct vers S3
// (contourne les limites 2 Go de PHP/nginx, reprise part par part).
const DIRECT_UPLOAD_THRESHOLD = 100 * 1024 * 1024; // 100 Mo
const MAX_VIDEO_SIZE = 20 * 1024 * 1024 * 1024; // 20 Go
const MAX_DEFAULT_SIZE = 2097152000; // 2 Go

const needsDirectUpload = (file) => isVideo(resolveMime(file)) || file.size > DIRECT_UPLOAD_THRESHOLD;

const handleDrop = (e) => {
  isDragging.value = false;
  addFiles(Array.from(e.dataTransfer.files));
};

const handleFileSelect = (e) => {
  addFiles(Array.from(e.target.files));
  e.target.value = '';
};

const addFiles = (files) => {
  rejected.value = [];
  for (const file of files) {
    const mime = resolveMime(file);
    const ok = IMAGE_TYPES.includes(mime) || VIDEO_TYPES.includes(mime) || DOC_TYPES.includes(mime);
    if (!ok) {
      rejected.value.push(t(':name (format non pris en charge)', { name: file.name }));
      continue;
    }
    if (file.size > (isVideo(mime) ? MAX_VIDEO_SIZE : MAX_DEFAULT_SIZE)) {
      rejected.value.push(t(':name (trop lourd)', { name: file.name }));
      continue;
    }
    items.value.push({
      id: ++idCounter,
      file,
      name: file.name,
      size: file.size,
      type: mime,
      previewUrl: mime.startsWith('image/') ? URL.createObjectURL(file) : null,
      previewFailed: false,
      status: 'pending',
      progress: 0,
      error: null,
      media: null,
    });
  }
};

const revoke = (item) => item.previewUrl && URL.revokeObjectURL(item.previewUrl);

const removeItem = (id) => {
  const item = items.value.find((i) => i.id === id);
  if (item) revoke(item);
  items.value = items.value.filter((i) => i.id !== id);
};

const clearAll = () => {
  items.value.forEach(revoke);
  items.value = [];
  rejected.value = [];
  summary.value = {};
  phase.value = 'select';
  albumMode.value = 'none';
  showAlbumOptions.value = false;
  selectedAlbumId.value = null;
  newAlbumName.value = '';
  if (fileInput.value) fileInput.value.value = '';
};

onBeforeUnmount(() => items.value.forEach(revoke));

const canUpload = computed(() => {
  if (!items.value.length) return false;
  if (albumMode.value === 'existing') return !!selectedAlbumId.value;
  if (albumMode.value === 'new') return newAlbumName.value.trim().length > 0;
  return true;
});

const doneItems = computed(() => items.value.filter((i) => i.status === 'done'));
const failedItems = computed(() => items.value.filter((i) => i.status === 'error'));
const doneCount = computed(() => doneItems.value.length + failedItems.value.length);
const globalProgress = computed(() => {
  if (!items.value.length) return 0;
  const sum = items.value.reduce((acc, i) => acc + (i.status === 'done' || i.status === 'error' ? 1 : i.progress), 0);
  return Math.round((sum / items.value.length) * 100);
});

// ---------------------------------------------------------------- envoi
// Garde l'écran allumé pendant l'envoi (sinon le téléphone se verrouille et
// coupe tout) et prévient avant de quitter la page.
let wakeLock = null;
const requestWakeLock = async () => {
  try {
    if ('wakeLock' in navigator && document.visibilityState === 'visible') {
      wakeLock = await navigator.wakeLock.request('screen');
    }
  } catch (_) { /* non supporté ou refusé : sans gravité */ }
};
const releaseWakeLock = () => {
  wakeLock?.release?.().catch(() => {});
  wakeLock = null;
};
const onVisibility = () => {
  if (phase.value === 'uploading' && document.visibilityState === 'visible') requestWakeLock();
};
const onBeforeUnload = (e) => {
  if (phase.value === 'uploading') {
    e.preventDefault();
    e.returnValue = '';
  }
};
let removeRouterGuard = null;
const { confirm } = useConfirm();
// Départ confirmé : la visite relancée ne doit pas redemander.
let leaveConfirmed = false;

const guardOn = () => {
  requestWakeLock();
  document.addEventListener('visibilitychange', onVisibility);
  window.addEventListener('beforeunload', onBeforeUnload);
  leaveConfirmed = false;
  removeRouterGuard = router.on('before', (event) => {
    const visit = event.detail.visit;
    if (phase.value !== 'uploading' || leaveConfirmed || visit.prefetch) return;
    // Le garde Inertia est synchrone : on suspend la navigation le temps de
    // la confirmation, puis on la relance à l'identique si l'on confirme.
    event.preventDefault();
    confirm({
      title: t('Envoi en cours'),
      message: t("L'envoi n'est pas terminé. Quitter quand même ?"),
      confirmLabel: t('Quitter'),
      cancelLabel: t('Rester'),
      danger: true,
    }).then((ok) => {
      if (!ok) return;
      leaveConfirmed = true;
      const { id, url, completed, cancelled, interrupted, ...options } = visit;
      // Visite échouée ou annulée : on resterait sur la page, le garde reprend.
      router.visit(url.href, { ...options, onFinish: () => { leaveConfirmed = false; } });
    });
  });
};
const guardOff = () => {
  releaseWakeLock();
  document.removeEventListener('visibilitychange', onVisibility);
  window.removeEventListener('beforeunload', onBeforeUnload);
  removeRouterGuard?.();
  removeRouterGuard = null;
};
onBeforeUnmount(guardOff);

const CONCURRENCY = 3;

const runQueue = async (queue) => {
  let next = 0;
  const worker = async () => {
    while (next < queue.length) {
      const item = queue[next++];
      item.status = 'uploading';
      item.progress = 0;
      try {
        item.media = needsDirectUpload(item.file)
          ? await uploadViaMultipart(item.file, (f) => { item.progress = Math.min(f, 1); })
          : await uploadViaPost(item.file, (f) => { item.progress = Math.min(f, 1); });
        item.status = 'done';
        item.progress = 1;
      } catch (err) {
        item.status = 'error';
        // Jamais de message technique (« Network Error », exception S3…) :
        // seul un refus de validation (422) est détaillé, en français.
        console.error(`Envoi de « ${item.file?.name} » impossible :`, err);
        item.error = apiError(err, t('Envoi impossible'));
      }
    }
  };
  await Promise.all(Array.from({ length: Math.min(CONCURRENCY, queue.length) }, worker));
};

const startUpload = async () => {
  await sendItems(items.value);
};

const retryFailed = async () => {
  const failed = failedItems.value;
  failed.forEach((i) => { i.status = 'pending'; i.progress = 0; i.error = null; });
  await sendItems(failed, { retry: true });
};

const sendItems = async (queue, { retry = false } = {}) => {
  phase.value = 'uploading';
  guardOn();
  const before = new Set(doneItems.value.map((i) => i.id));
  try {
    await runQueue(queue);
  } finally {
    guardOff();
  }

  const fresh = doneItems.value.filter((i) => !before.has(i.id));
  const freshIds = fresh.map((i) => i.media.id);
  const result = { ...(retry ? summary.value : {}) };

  if (freshIds.length) {
    await shareFresh(freshIds, result);
    await attachToChosenAlbum(freshIds, result);
    emit('upload-complete', fresh.map((i) => i.media));
  }

  summary.value = result;
  phase.value = 'done';
};

const shareFresh = async (ids, result) => {
  if (!shareCircle.value || !shareWithCircle.value) return;
  try {
    for (let i = 0; i < ids.length; i += 500) {
      await axios.post('/media/bulk/household', {
        media_ids: ids.slice(i, i + 500),
        household_id: shareCircle.value.id,
      });
    }
    result.sharedWith = shareCircle.value.name;
  } catch (e) {
    result.warning = t('Photos envoyées, mais le partage avec le cercle a échoué : réessayez depuis « Mes photos ».');
  }
};

// Rattache les médias tout juste envoyés à l'album choisi (créé si « nouveau »),
// ou directement à l'album cible quand il est imposé par le parent.
const attachToChosenAlbum = async (mediaIds, result) => {
  try {
    if (props.targetAlbumId) {
      await addMediaToAlbum(props.targetAlbumId, mediaIds);
      emit('album-attached', { albumId: props.targetAlbumId, albumName: '', count: mediaIds.length, isNew: false });
      return;
    }
    if (albumMode.value === 'new') {
      const album = await createAlbumWithMedia(newAlbumName.value.trim(), mediaIds);
      // Les envois suivants (réessai) vont dans le même album.
      albumMode.value = 'existing';
      selectedAlbumId.value = album.id;
      ownedAlbums.value.push({ id: album.id, name: album.name, media_count: mediaIds.length });
      result.albumName = album.name;
      emit('album-attached', { albumId: album.id, albumName: album.name, count: mediaIds.length, isNew: true });
    } else if (albumMode.value === 'existing' && selectedAlbumId.value) {
      const album = ownedAlbums.value.find((a) => a.id === selectedAlbumId.value);
      await addMediaToAlbum(selectedAlbumId.value, mediaIds);
      result.albumName = album?.name ?? t('choisi');
      emit('album-attached', { albumId: selectedAlbumId.value, albumName: album?.name ?? '', count: mediaIds.length, isNew: false });
    }
  } catch (e) {
    console.error("Ajout à l'album impossible :", e);
    result.warning = t("Photos envoyées, mais l'ajout à l'album a échoué.");
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
