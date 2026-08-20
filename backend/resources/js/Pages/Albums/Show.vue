<template>
  <Head :title="album.name" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Back Link -->
        <Link
          href="/albums"
          class="inline-flex items-center text-sm text-surface-500 hover:text-surface-700 mb-6"
        >
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
          Retour aux albums
        </Link>

        <!-- Album Header -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
          <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div class="flex-1">
              <h1 class="font-display text-2xl font-semibold text-surface-900">{{ album.name }}</h1>
              <p v-if="album.description" class="mt-2 text-surface-600">{{ album.description }}</p>
              <div class="mt-3 flex items-center gap-4 text-sm text-surface-500">
                <span>{{ album.media_count || 0 }} médias</span>
                <span
                  :class="[
                    'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                    album.is_public
                      ? 'bg-teal-100 dark:bg-teal-500/15 text-teal-700 dark:text-teal-300'
                      : 'bg-surface-100 text-surface-600'
                  ]"
                >
                  {{ album.is_public ? 'Public' : 'Privé' }}
                </span>
              </div>
            </div>

            <div class="flex items-center gap-2">
              <button
                v-if="hasPlayableMedia"
                @click="startSlideshow"
                class="inline-flex items-center px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
              >
                <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M8 5v14l11-7z" />
                </svg>
                Diaporama
              </button>
              <button
                v-if="album.media && album.media.length > 0"
                @click="toggleSelectionMode"
                :class="[
                  'inline-flex items-center px-4 py-2 text-sm font-medium rounded-lg border',
                  selectionMode
                    ? 'text-brand-700 bg-brand-50 border-brand-300 hover:bg-brand-100'
                    : 'text-surface-700 bg-white border-surface-300 hover:bg-surface-50'
                ]"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                {{ selectionMode ? 'Terminer' : 'Sélectionner' }}
              </button>
              <button
                v-if="isOwner"
                @click="showSharePanel = !showSharePanel"
                class="inline-flex items-center px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                </svg>
                Partager
              </button>
              <button
                v-if="isOwner"
                @click="showGeolocateModal = true"
                class="inline-flex items-center px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Géolocaliser
              </button>
              <button
                v-if="isOwner"
                @click="showEditModal = true"
                class="inline-flex items-center px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
                Modifier
              </button>
              <button
                v-if="isOwner"
                @click="showAddMediaModal = true"
                class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Ajouter des médias
              </button>
            </div>
          </div>
        </div>

        <!-- Share Panel -->
        <SharePanel
          v-if="showSharePanel"
          :album="album"
          :is-owner="album.is_owner !== false"
          @updated="handleAlbumUpdated"
        />

        <!-- Diaporama plein écran -->
        <FullscreenSlideshow
          ref="slideshowEl"
          :slides="slideshowSlides"
          shuffle
          ken-burns
        />

        <!-- Media Grid -->
        <div v-if="album.media && album.media.length > 0">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-surface-900">Médias</h2>
            <div v-if="selectedMediaIds.length > 0" class="flex items-center gap-2">
              <button
                v-if="selectedMediaIds.length === 1"
                @click="editSelected"
                class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-brand-700 bg-brand-50 rounded-lg hover:bg-brand-100"
              >
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
                Éditer
              </button>
              <button
                v-if="selectedMediaIds.length === 1 && isOwner"
                @click="setAsCover"
                class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-surface-700 bg-surface-100 rounded-lg hover:bg-surface-200"
              >
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                Couverture
              </button>
              <button
                v-if="removableSelectedIds.length > 0"
                @click="removeSelectedMedia"
                class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 rounded-lg hover:bg-red-100 dark:hover:bg-red-500/20"
              >
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Retirer ({{ removableSelectedIds.length }})
              </button>
            </div>
          </div>

          <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            <MediaCard
              v-for="media in album.media"
              :key="media.id"
              :media="media"
              :selectable="selectionMode"
              :is-selected="isSelected(media.id)"
              @click="handleMediaClick(media)"
              @toggle-selection="toggleSelection(media)"
            />
          </div>
        </div>

        <!-- Empty State -->
        <div
          v-else
          class="text-center py-16 bg-white rounded-lg shadow-xs"
        >
          <svg
            class="mx-auto h-16 w-16 text-surface-300"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="1.5"
              d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
            />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-surface-900">Aucun média</h3>
          <p class="mt-2 text-surface-500">Ajoutez des photos et vidéos à cet album.</p>
          <button
            v-if="isOwner"
            @click="showAddMediaModal = true"
            class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 rounded-lg hover:bg-brand-100"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Ajouter des médias
          </button>
        </div>

        <!-- Delete Album Button -->
        <div v-if="isOwner" class="mt-8 pt-8 border-t border-surface-200">
          <button
            @click="deleteAlbum"
            class="inline-flex items-center px-4 py-2 text-sm font-medium text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            Supprimer l'album
          </button>
        </div>

        <!-- Modals -->
        <AlbumFormModal
          v-if="showEditModal"
          :album="album"
          @close="showEditModal = false"
          @saved="handleAlbumUpdated"
        />

        <MediaPickerModal
          v-if="showAddMediaModal"
          :album-id="album.id"
          :exclude-media-ids="albumMediaIds"
          @close="showAddMediaModal = false"
          @added="handleMediaAdded"
        />

        <GeolocatePickerModal
          v-if="showGeolocateModal"
          :title="`Géolocaliser « ${album.name} »`"
          description="Cliquez sur la carte pour situer toutes les photos de cet album. Pratique quand la localisation d'origine a été perdue à l'import."
          apply-label="Appliquer à l'album"
          :saving="geolocating"
          :error-message="geolocateError"
          @close="showGeolocateModal = false"
          @apply="applyAlbumGeolocation"
        />
      </div>
    </div>
  </AppLayout>
</template>

<style scoped>
</style>

<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import MediaCard from '@/Components/MediaCard.vue';
import AlbumFormModal from '@/Components/AlbumFormModal.vue';
import SharePanel from '@/Components/SharePanel.vue';
import MediaPickerModal from '@/Components/MediaPickerModal.vue';
import GeolocatePickerModal from '@/Components/GeolocatePickerModal.vue';
import FullscreenSlideshow from '@/Components/FullscreenSlideshow.vue';
import { usePhotoSwipe } from '@/composables/usePhotoSwipe';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
  album: {
    type: Object,
    required: true,
  },
});

const toast = useToast();

const showSharePanel = ref(false);

// Diaporama
const slideshowEl = ref(null);
const hasPlayableMedia = computed(() =>
  (props.album.media || []).some((m) => m.type === 'photo' || m.type === 'video')
);
const startSlideshow = () => slideshowEl.value?.open(0);
const showEditModal = ref(false);
const showAddMediaModal = ref(false);
const showGeolocateModal = ref(false);

const geolocating = ref(false);
const geolocateError = ref(null);

const applyAlbumGeolocation = async ({ latitude, longitude }) => {
  geolocating.value = true;
  geolocateError.value = null;
  try {
    await axios.post(`/albums/${props.album.id}/geolocate`, { latitude, longitude });
    showGeolocateModal.value = false;
    router.reload();
  } catch (e) {
    geolocateError.value = e.response?.data?.message || 'Erreur lors de la géolocalisation.';
  } finally {
    geolocating.value = false;
  }
};
// Mode sélection : les cases à cocher n'apparaissent qu'une fois activé
// via le bouton « Sélectionner » (demande UX : pas de cases par défaut).
const selectionMode = ref(false);
const selectedMediaIds = ref([]);

const toggleSelectionMode = () => {
  selectionMode.value = !selectionMode.value;
  if (!selectionMode.value) {
    selectedMediaIds.value = [];
  }
};

const albumMediaIds = computed(() => {
  return props.album.media?.map((m) => m.id) || [];
});

const albumMedia = computed(() => props.album.media || []);

// Propriétaire de l'album (les actions de gestion — couverture, modif,
// partage — lui sont réservées ; le backend le vérifie aussi).
const isOwner = computed(() => props.album.is_owner !== false);
const currentUserId = computed(() => usePage().props.auth?.user?.id ?? null);

// Médias de la sélection que l'utilisateur a le droit de RETIRER : tout s'il
// est propriétaire de l'album, sinon uniquement ceux qu'il a lui-même ajoutés
// (dont il est propriétaire).
const removableSelectedIds = computed(() => {
  if (isOwner.value) {
    return selectedMediaIds.value;
  }
  return selectedMediaIds.value.filter((id) => {
    const media = albumMedia.value.find((m) => m.id === id);
    return media && media.user_id === currentUserId.value;
  });
});

const isSelected = (id) => selectedMediaIds.value.includes(id);

const toggleSelection = (media) => {
  const index = selectedMediaIds.value.indexOf(media.id);
  if (index === -1) {
    selectedMediaIds.value.push(media.id);
  } else {
    selectedMediaIds.value.splice(index, 1);
  }
};

const editSelected = () => {
  if (selectedMediaIds.value.length === 1) {
    router.visit(`/media/${selectedMediaIds.value[0]}`);
  }
};

const setAsCover = async () => {
  if (selectedMediaIds.value.length !== 1) return;
  try {
    await axios.post(`/albums/${props.album.id}/cover`, { media_id: selectedMediaIds.value[0] });
    selectedMediaIds.value = [];
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors de la définition de la couverture.');
  }
};

// En mode sélection, le clic sur la tuile coche/décoche (comme la galerie) ;
// sinon il ouvre la visionneuse (ou la fiche pour les non-photos).
const handleMediaClick = (media) => {
  if (selectionMode.value) {
    toggleSelection(media);
    return;
  }
  if (media.type !== 'photo' || !openLightbox(media)) {
    router.visit(`/media/${media.id}`);
  }
};

const handleAlbumUpdated = () => {
  router.reload();
};

const handleMediaAdded = () => {
  router.reload();
};

const removeSelectedMedia = async () => {
  const ids = removableSelectedIds.value;
  if (ids.length === 0) return;

  if (!confirm(`Retirer ${ids.length} média(s) de l'album ?`)) {
    return;
  }

  try {
    await axios.delete(`/albums/${props.album.id}/media`, {
      data: { media_ids: ids },
    });
    selectedMediaIds.value = [];
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || "Impossible de retirer ces médias de l'album.");
  }
};

const deleteAlbum = async () => {
  if (!confirm('Êtes-vous sûr de vouloir supprimer cet album ? Les médias ne seront pas supprimés.')) {
    return;
  }

  try {
    await axios.delete(`/albums/${props.album.id}`);
    router.visit('/albums');
  } catch (error) {
    toast.error(error.response?.data?.message || "Impossible de supprimer l'album.");
  }
};

// Visionneuse partagée (reconstruite quand la liste de médias change)
const { open: openLightbox } = usePhotoSwipe(() => albumMedia.value, {
  watchSource: () => props.album.media,
});

// Slides du diaporama : médias jouables normalisés (poids fort aux visages).
const slideshowSlides = computed(() =>
  albumMedia.value
    .filter((m) => m.type === 'photo' || m.type === 'video')
    .map((m) => ({
      key: m.id,
      type: m.type,
      src: m.type === 'video'
        ? (m.conversions?.find((c) => c.conversion_name === 'web')?.url || m.url)
        : (m.conversions?.find((c) => c.conversion_name === 'medium')?.url || m.url),
      label: m.title || m.original_name,
      weight: m.matched_faces_count > 0 ? 3 : 1,
    }))
);
</script>
