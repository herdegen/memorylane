<template>
  <Head :title="media.title || media.original_name" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Retour : vers la page d'où l'on vient (fiche personne, album,
             galerie…), repli « Mes photos » en accès direct -->
        <div class="mb-6">
          <a
            :href="back.href"
            class="inline-flex items-center text-sm text-surface-600 hover:text-surface-900 transition"
            @click.prevent="goBack"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            {{ back.label }}
          </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Media preview (left side - 2 columns) -->
          <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-xs overflow-hidden">
              <!-- Image with face detection overlay -->
              <div v-if="media.type === 'photo'" class="relative bg-black">
                <FaceDetectionOverlay
                  :image-url="photoUrl"
                  :alt="media.original_name"
                  :faces="media.detected_faces || []"
                  :draw-mode="addingFace"
                  @face-click="handleFaceClick"
                  @region-drawn="handleRegionDrawn"
                />
              </div>

              <!-- Sous la photo : original en grand (tous), ajout manuel d'un
                   visage (admin et propriétaire). Toujours affichée pour une
                   photo : la chaîne v-else-if vidéo/document en dépend. -->
              <div v-if="media.type === 'photo'" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2 border-t border-surface-100">
                <button
                  type="button"
                  class="text-sm font-medium text-brand-600 hover:text-brand-800"
                  @click="viewingOriginal = true"
                >
                  🔍 Voir l’original
                  <span v-if="media.width && media.height" class="font-normal text-surface-500">({{ media.width }} × {{ media.height }})</span>
                </button>
                <template v-if="canEditFaces">
                  <button
                    type="button"
                    :disabled="savingFace"
                    class="text-sm font-medium text-brand-600 hover:text-brand-800 disabled:opacity-50"
                    @click="addingFace = !addingFace"
                  >
                    {{ addingFace ? 'Annuler' : '＋ Ajouter un visage' }}
                  </button>
                  <span v-if="addingFace && !savingFace" class="text-xs text-surface-500">
                    Cliquez-glissez sur la photo pour entourer le visage.
                  </span>
                  <span v-if="savingFace" class="text-xs text-brand-600">Ajout du visage…</span>
                </template>
              </div>

              <!-- Video -->
              <div v-else-if="media.type === 'video'">
                <VideoPlayer
                  ref="playerRef"
                  :src="videoUrl"
                  :poster="thumbnailUrl"
                />
              </div>

              <!-- Document -->
              <div v-else class="flex flex-col items-center justify-center p-12 bg-surface-50">
                <svg class="h-24 w-24 text-surface-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
                <h3 class="text-lg font-medium text-surface-900 mb-2">{{ media.original_name }}</h3>
                <p class="text-sm text-surface-500 mb-4">Document ({{ formatFileSize(media.size) }})</p>
                <a
                  :href="`/media/${media.id}/download`"
                  class="inline-flex items-center px-4 py-2 bg-brand-600 text-white rounded-lg hover:bg-brand-700 transition"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  Télécharger
                </a>
              </div>
            </div>

            <!-- Détection de visages sur l'image extraite (vidéos) -->
            <div v-if="media.type === 'video'" class="mt-4 bg-white rounded-lg shadow-xs overflow-hidden">
              <div class="px-4 py-3 border-b border-surface-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-surface-900">Visages sur l'image du clip</h2>
                <div v-if="canEditFaces" class="flex flex-wrap items-center justify-end gap-x-4 gap-y-1">
                  <button
                    type="button"
                    :disabled="settingFrame"
                    class="text-sm font-medium text-brand-600 hover:text-brand-800 disabled:opacity-50"
                    title="L'image affichée en ce moment dans le lecteur devient la miniature ; les visages y sont recherchés à nouveau"
                    @click="useCurrentFrame"
                  >
                    {{ settingFrame ? 'Préparation…' : '🖼 Utiliser l’image actuelle' }}
                  </button>
                  <button
                    type="button"
                    :disabled="savingFace"
                    class="text-sm font-medium text-brand-600 hover:text-brand-800 disabled:opacity-50"
                    @click="addingFace = !addingFace"
                  >
                    {{ addingFace ? 'Annuler' : '＋ Ajouter un visage' }}
                  </button>
                </div>
              </div>
              <div class="relative bg-black">
                <FaceDetectionOverlay
                  :image-url="faceImageUrl"
                  :alt="media.original_name"
                  :faces="media.detected_faces || []"
                  :draw-mode="addingFace"
                  @face-click="handleFaceClick"
                  @region-drawn="handleRegionDrawn"
                />
              </div>
              <p v-if="addingFace && !savingFace" class="px-4 py-2 text-xs text-surface-500">
                Cliquez-glissez sur l'image pour entourer le visage.
              </p>
              <p v-if="savingFace" class="px-4 py-2 text-xs text-brand-600">Ajout du visage…</p>
            </div>

            <!-- Provenance : ce média est un clip d'une vidéo source -->
            <div
              v-if="media.source_media"
              class="mt-4 flex items-center gap-3 rounded-lg bg-surface-50 border border-surface-200 px-4 py-3"
            >
              <svg class="w-5 h-5 text-surface-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243zm0-5.758a3 3 0 10-4.243-4.243 3 3 0 004.243 4.243z" />
              </svg>
              <p class="text-sm text-surface-600">
                Extrait de
                <Link :href="`/media/${media.source_media.id}`" class="font-medium text-brand-600 hover:text-brand-800">
                  {{ media.source_media.original_name }}
                </Link>
                <span v-if="media.clip_start != null && media.clip_end != null" class="text-surface-400">
                  ({{ formatDuration(media.clip_start) }} → {{ formatDuration(media.clip_end) }})
                </span>
              </p>
            </div>

            <!-- Éditeur de découpe : vidéo source (pas un clip) -->
            <div v-if="media.type === 'video' && !media.source_media_id" class="mt-4">
              <VideoClipEditor
                :media-id="media.id"
                :duration="media.duration"
                :get-current-time="playerGetCurrentTime"
                :seek-to="playerSeekTo"
              />
            </div>

            <!-- Clips déjà découpés à partir de cette vidéo -->
            <div v-if="media.clips && media.clips.length" class="mt-4 bg-white rounded-lg shadow-xs p-6">
              <h2 class="text-lg font-semibold text-surface-900 mb-4">Clips ({{ media.clips.length }})</h2>
              <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <Link
                  v-for="clip in media.clips"
                  :key="clip.id"
                  :href="`/media/${clip.id}`"
                  class="group block"
                >
                  <div class="relative aspect-video rounded-lg overflow-hidden bg-black">
                    <img
                      v-if="clipThumb(clip)"
                      :src="clipThumb(clip)"
                      :alt="clip.original_name"
                      class="w-full h-full object-cover group-hover:opacity-90"
                      loading="lazy"
                    />
                    <span
                      v-if="clip.duration"
                      class="absolute bottom-1 right-1 px-1.5 py-0.5 text-[10px] font-medium text-white bg-black/60 rounded"
                    >{{ formatDuration(clip.duration) }}</span>
                  </div>
                  <p class="mt-1 text-xs text-surface-700 truncate">{{ clip.title || clip.original_name }}</p>
                </Link>
              </div>
            </div>
          </div>

          <!-- Media info (right side - 1 column) -->
          <div class="space-y-6">
            <!-- Basic info with editor -->
            <MediaInfoEditor :media="media" @updated="handleMediaUpdated" />

            <!-- Mémos vocaux : la famille raconte la photo -->
            <VoiceNotesPanel
              v-if="media.type === 'photo'"
              :media-id="media.id"
              :initial-notes="voiceNotes"
            />

            <!-- Tags -->
            <div class="bg-white rounded-lg shadow-xs p-6">
              <h2 class="text-lg font-semibold text-surface-900 mb-4">Tags</h2>
              <TagInput :media-id="media.id" :initial-tags="media.tags || []" />
            </div>

            <!-- Détection de visages (100% navigateur, face-api.js) -->
            <div
              v-if="supportsFaces && canEditFaces && (detecting || detectError || notScanned)"
              class="bg-white rounded-lg shadow-xs p-4"
            >
              <div v-if="detecting" class="flex items-center gap-3">
                <svg class="animate-spin h-5 w-5 text-brand-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <div>
                  <p class="text-sm font-medium text-surface-900">Détection en cours…</p>
                  <p class="text-xs text-surface-500">{{ detectProgress || 'Analyse de la photo' }}</p>
                </div>
              </div>

              <div v-else>
                <div v-if="detectError" class="mb-3 text-sm text-red-600 dark:text-red-400">
                  {{ detectError }}
                </div>
                <button
                  @click="runDetection"
                  class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-brand-600 text-white rounded-lg text-sm font-medium hover:bg-brand-700 transition"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                  </svg>
                  {{ detectError ? 'Réessayer' : 'Détecter les visages' }}
                </button>
                <p class="mt-2 text-xs text-surface-400">
                  {{ serverFaces ? 'Analyse sur le serveur (InsightFace) : quelques secondes.' : 'Analyse locale dans votre navigateur (~12 Mo de modèles au premier lancement).' }}
                </p>
              </div>
            </div>

            <!-- Vision AI Status -->
            <VisionStatusBadge
              v-if="supportsFaces && !detecting && !detectError && !notScanned"
              :media-id="media.id"
              :initial-status="media.metadata?.vision_status"
              :initial-faces-count="media.metadata?.vision_faces_count || 0"
              :initial-error="media.metadata?.vision_error"
              :can-rerun="canEditFaces"
              @rerun="runDetection"
            />
            <!-- « Personne de connu ici » : visages d'arrière-plan d'un monument,
                 d'une foule… → tous en « personne inconnue » d'un clic. -->
            <button
              v-if="unidentifiedCount > 0 && !detecting"
              type="button"
              class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-surface-300 text-sm font-medium text-surface-700 hover:bg-surface-50"
              :disabled="markingStrangers"
              @click="markNoKnownFaces"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
              Personne de connu ici ({{ unidentifiedCount }} visage{{ unidentifiedCount > 1 ? 's' : '' }})
            </button>

            <!-- Admin : tout recommencer (cadres non identifiés effacés,
                 détection et identification refaites, identifiés conservés). -->
            <button
              v-if="supportsFaces && serverFaces && isAdmin && !detecting"
              type="button"
              class="w-full text-xs font-medium text-surface-500 hover:text-brand-700 underline underline-offset-2"
              @click="forceDetection"
            >
              Tout recommencer sur cette photo (admin)
            </button>

            <!-- Face Match Panel -->
            <FaceMatchPanel
              v-if="selectedFace"
              :key="selectedFace.id"
              :face="selectedFace"
              :media-id="media.id"
              @matched="handleFaceMatched"
              @dismissed="handleFaceDismissed"
              @reset="handleFaceReset"
              @close="selectedFace = null"
            />

            <!-- Vision AI Labels -->
            <VisionLabels
              v-if="media.type === 'photo' && media.metadata?.vision_labels"
              :labels="media.metadata.vision_labels"
            />

            <!-- People -->
            <PersonInput
              :media-id="media.id"
              :initial-people="media.people || []"
            />

            <!-- Geolocation -->
            <GeolocationEditor
              :media-id="media.id"
              :initial-latitude="media.metadata?.latitude"
              :initial-longitude="media.metadata?.longitude"
              :initial-altitude="media.metadata?.altitude"
            />

            <!-- Actions -->
            <div class="bg-white rounded-lg shadow-xs p-6">
              <h2 class="text-lg font-semibold text-surface-900 mb-4">Actions</h2>
              <div class="space-y-2">
                <a
                  :href="`/media/${media.id}/download`"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-surface-300 rounded-lg text-sm font-medium text-surface-700 bg-white hover:bg-surface-50 transition"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  Télécharger
                </a>

                <button
                  @click="deleteMedia"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-red-300 dark:border-red-500/40 rounded-lg text-sm font-medium text-red-700 dark:text-red-300 bg-white hover:bg-red-50 dark:hover:bg-red-500/10 transition"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                  Supprimer
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <OriginalViewer
      v-if="viewingOriginal"
      :original-url="media.url"
      :preview-url="photoUrl"
      :width="media.width"
      :height="media.height"
      :title="media.title || media.original_name"
      :faces="media.detected_faces || []"
      :can-edit="canEditFaces"
      @close="viewingOriginal = false"
      @face-click="openFaceFromViewer"
    />
  </AppLayout>
</template>

<script setup>
import { ref, computed, defineAsyncComponent } from 'vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TagInput from '@/Components/TagInput.vue';
import PersonInput from '@/Components/PersonInput.vue';
import GeolocationEditor from '@/Components/GeolocationEditor.vue';
import MediaInfoEditor from '@/Components/MediaInfoEditor.vue';
import FaceDetectionOverlay from '@/Components/FaceDetectionOverlay.vue';
import FaceMatchPanel from '@/Components/FaceMatchPanel.vue';
import OriginalViewer from '@/Components/OriginalViewer.vue';
import VisionStatusBadge from '@/Components/VisionStatusBadge.vue';
import VisionLabels from '@/Components/VisionLabels.vue';
import VoiceNotesPanel from '@/Components/VoiceNotesPanel.vue';
import VideoPlayer from '@/Components/VideoPlayer.vue';
import { useFaceDetection } from '@/Composables/useFaceDetection';
import { useToast } from '@/Composables/useToast';
import { useConfirm } from '@/Composables/useConfirm';
import { apiError } from '@/utils/apiError';
import { formatFileSize, formatDuration } from '@/utils/format';
import { backTarget } from '@/utils/navigation';
import { conversionUrl, displayUrl, webVideoUrl } from '@/utils/media';
import axios from 'axios';

// Éditeur de découpe : chargé seulement pour une vidéo. (VideoPlayer, lui,
// reste importé statiquement — il est minuscule et charge Plyr à son
// montage : un composant asynchrone ne dépendant que du cœur de Vue
// poussait Rolldown à scinder Vue dans un chunk à part, +7 ko gzip sur
// toutes les pages.)
const VideoClipEditor = defineAsyncComponent(() => import('@/Components/VideoClipEditor.vue'));

const toast = useToast();
const { confirm } = useConfirm();

// Bouton « Retour » : page précédente dans l'app (history.back() restaure
// le scroll de la galerie), sinon la galerie « Mes photos ».
const back = backTarget('/media', 'Retour à mes photos');
const goBack = () => {
  if (back.useHistory && window.history.length > 1) {
    window.history.back();
  } else {
    router.visit(back.href);
  }
};

const props = defineProps({
  media: {
    type: Object,
    required: true,
  },
  voiceNotes: {
    type: Array,
    default: () => [],
  },
});

const selectedFace = ref(null);

// Visionneuse de l'original (zoom) ; un clic sur un visage la ferme et ouvre
// le panneau d'identification.
const viewingOriginal = ref(false);
const openFaceFromViewer = (face) => {
  viewingOriginal.value = false;
  handleFaceClick(face);
};

// Identification des visages depuis cette page : admin et personne qui a
// envoyé la photo seulement (la famille contribue via les quêtes).
const isOwner = computed(() => props.media.user_id === usePage().props.auth?.user?.id);
const isAdmin = computed(() => usePage().props.auth?.user?.role === 'admin');
const canEditFaces = computed(() => isOwner.value || isAdmin.value);

// Référence au lecteur vidéo (pour capturer le temps courant dans l'éditeur de découpe).
const playerRef = ref(null);
const playerGetCurrentTime = () => playerRef.value?.getCurrentTime?.() ?? 0;

// Vidéo : l'image affichée dans le lecteur devient la miniature (et l'image
// où l'on cherche les visages) ; les cadres de l'ancienne image sont effacés.
const settingFrame = ref(false);
const useCurrentFrame = async () => {
  const time = playerGetCurrentTime();
  const ok = await confirm({
    title: 'Changer l’image de la vidéo ?',
    message: `L’image à ${formatDuration(time)} devient la miniature. Les cadres de visages actuels sont effacés et les visages sont recherchés sur la nouvelle image ; les personnes déjà identifiées restent sur la vidéo.`,
    confirmLabel: 'Utiliser cette image',
  });
  if (!ok) return;
  settingFrame.value = true;
  try {
    const { data } = await axios.post(`/media/${props.media.id}/video-frame`, { time });
    toast.success(data.message);
    // Le temps de refaire les miniatures et l'analyse (quelques secondes).
    setTimeout(() => router.reload({ only: ['media'], onFinish: () => { settingFrame.value = false; } }), 6000);
  } catch (error) {
    settingFrame.value = false;
    toast.error(apiError(error, 'Impossible de changer l’image.'));
  }
};
const playerSeekTo = (t) => playerRef.value?.seekTo?.(t);

// Vignette d'un clip (conversion small/thumbnail, sinon l'original).
const clipThumb = (clip) => conversionUrl(clip, 'small', 'thumbnail', 'medium') || clip.url || null;

// Photo affichée : conversion large/medium plutôt que l'original (plusieurs
// Mo). Les cadres des visages sont en % : indépendants de la résolution.
// L'original reste accessible via « Télécharger ».
const photoUrl = computed(() => displayUrl(props.media));

// Détection de visages : côté serveur (InsightFace) ou, à défaut, dans le
// navigateur (face-api, chargé seulement s'il sert).
const serverFaces = computed(() => usePage().props.faces_engine === 'insightface');

// Visages encore à identifier sur la photo (ni nommés, ni écartés).
const unidentifiedCount = computed(() => (props.media.detected_faces || [])
  .filter((f) => f.status === 'unmatched' && !f.person_id).length);
const markingStrangers = ref(false);
async function markNoKnownFaces() {
  markingStrangers.value = true;
  try {
    await axios.post('/vision/no-known-faces', { media_ids: [props.media.id] });
    router.reload({ only: ['media'], preserveScroll: true });
  } finally {
    markingStrangers.value = false;
  }
}
const browserDetection = useFaceDetection();
const { detectFaces, computeDescriptorForRegion } = browserDetection;
const serverDetecting = ref(false);
const serverError = ref(null);
const detecting = computed(() => serverDetecting.value || browserDetection.loading.value);
const detectProgress = computed(() => (serverDetecting.value ? 'Analyse sur le serveur…' : browserDetection.progress.value));
const detectError = computed(() => serverError.value || browserDetection.error.value);

// Ajout manuel d'un visage (dessin d'une zone).
const addingFace = ref(false);
const savingFace = ref(false);

const handleRegionDrawn = async (box) => {
  savingFace.value = true;
  try {
    // Serveur : l'empreinte est calculée par le service sur la zone.
    // Navigateur : sur l'image proxy même-origine (canvas non tainté).
    const embedding = serverFaces.value
      ? null
      : await computeDescriptorForRegion(`/vision/media/${props.media.id}/image?conversion=medium`, box);
    await axios.post(`/vision/media/${props.media.id}/faces/add`, {
      bounding_box: box,
      ...(embedding ? { embedding } : {}),
    });
    addingFace.value = false;
    router.reload();
  } catch (e) {
    console.error('Ajout de visage échoué :', e);
  } finally {
    savingFace.value = false;
  }
};

// La détection de visages marche sur photos ET vidéos (via l'image extraite).
const supportsFaces = computed(() => ['photo', 'video'].includes(props.media.type));

// Image support des cadres de visages : la photo affichée (conversion
// large/medium) pour une photo, la frame extraite (conversion medium) pour
// une vidéo.
const faceImageUrl = computed(() =>
  props.media.type === 'video' ? thumbnailUrl.value : photoUrl.value
);

const notScanned = computed(
  () => supportsFaces.value && props.media.metadata?.vision_status !== 'completed'
);

// Admin : « forcer » = repartir d'une détection propre (après confirmation).
const forceDetection = async () => {
  const ok = await confirm({
    title: 'Tout recommencer sur cette photo ?',
    message: 'Les cadres non identifiés sont effacés, puis la détection et l’identification automatique sont refaites. Les personnes validées (à la main ou par une quête) sont conservées ; un nom posé automatiquement là où plus aucun visage n’est vu est retiré.',
    confirmLabel: 'Recommencer',
    danger: true,
  });
  if (ok) await runDetection(true);
};

const runDetection = async (force = false) => {
  // Serveur : ajoute les visages manquants sans rien effacer (sauf « forcer »).
  if (serverFaces.value) {
    serverDetecting.value = true;
    serverError.value = null;
    try {
      await axios.post(`/vision/media/${props.media.id}/analyze`, force === true ? { force: true } : {});
      router.reload();
    } catch (e) {
      serverError.value = apiError(e, "L'analyse des visages a échoué. Réessayez dans un instant.");
    } finally {
      serverDetecting.value = false;
    }
    return;
  }
  try {
    // On détecte sur la conversion medium (même origine → pas de canvas tainted).
    const proxyUrl = `/vision/media/${props.media.id}/image?conversion=medium`;
    const faces = await detectFaces(proxyUrl);
    await axios.post(`/vision/media/${props.media.id}/faces`, { faces });
    router.reload();
  } catch (e) {
    // L'erreur est déjà exposée via detectError (composable).
    console.error('Détection des visages échouée :', e);
  }
};

// Poster URL for the video player (medium or small conversion thumbnail)
const thumbnailUrl = computed(() => conversionUrl(props.media, 'medium', 'small', 'thumbnail'));

// Source du lecteur : la version web transcodée (MP4 H.264) si elle existe,
// sinon l'original (les .mkv/.avi/HEVC ne se lisent pas nativement)
const videoUrl = computed(() => webVideoUrl(props.media));

const handleMediaUpdated = (updatedMedia) => {
  router.reload();
};

const handleFaceClick = (face) => {
  // Identification réservée à l'admin et à la personne qui a envoyé la photo.
  if (!canEditFaces.value) return;
  // Matché, non-matché OU rejeté : on ouvre le panneau (permet de corriger,
  // ré-identifier ou ignorer). `rejected` = visage désassocié « collant »
  // (issue #30) : il reste un carré cliquable pour pouvoir l'ignorer/re-tagger.
  if (['unmatched', 'matched', 'rejected'].includes(face.status)) {
    selectedFace.value = face;
  }
};

const handleFaceMatched = () => {
  selectedFace.value = null;
  router.reload();
};

const handleFaceDismissed = () => {
  selectedFace.value = null;
  router.reload();
};

const handleFaceReset = () => {
  selectedFace.value = null;
  router.reload();
};

const deleteMedia = async () => {
  // Suppression douce : le média part dans la corbeille (restaurable).
  const what = { photo: 'La photo', video: 'La vidéo', document: 'Le document' }[props.media.type] || 'Le média';
  const ok = await confirm({
    title: 'Supprimer ce média',
    message: `Êtes-vous sûr de vouloir supprimer ce média ? ${what} ira dans la corbeille.`,
    confirmLabel: 'Supprimer',
    danger: true,
  });
  if (!ok) return;

  try {
    await axios.delete(`/media/${props.media.id}`);
    router.visit('/media');
  } catch (error) {
    toast.error(apiError(error, 'Erreur lors de la suppression.'));
  }
};
</script>
