<template>
  <Head :title="album.name" />
  <AppLayout>
    <!-- ================= Héro pleine largeur =================
         Fond : carte centrée sur la géolocalisation de l'album ; à défaut la
         photo de couverture assombrie ; à défaut un dégradé brand. -->
    <div class="relative h-72 sm:h-[340px]">
      <div v-if="album.hero_location" ref="heroMapEl" class="absolute inset-0 z-0"></div>
      <div
        v-else-if="album.cover_url"
        class="absolute inset-0 bg-cover bg-center"
        :style="{ backgroundImage: `url(${album.cover_url})` }"
      ></div>
      <div v-else class="absolute inset-0 bg-linear-to-br from-brand-200 via-brand-50 to-surface-200"></div>

      <!-- Voile de lisibilité (plus dense en bas, sous le titre) -->
      <div class="absolute inset-0 z-10 pointer-events-none bg-linear-to-b from-black/25 via-transparent to-black/65"></div>

      <!-- Actions flottantes -->
      <div class="absolute top-4 right-4 sm:top-5 sm:right-8 z-20 flex items-center gap-2.5">
        <button
          v-if="hasPlayableMedia"
          @click="startSlideshow"
          class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/90 dark:bg-white/95 backdrop-blur text-sm font-semibold text-surface-900 shadow-warm-md hover:bg-white transition"
        >
          <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
          Diaporama
        </button>
        <button
          v-if="isOwner"
          @click="showSharePanel = !showSharePanel"
          class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/90 backdrop-blur text-surface-900 shadow-warm-md hover:bg-white transition"
          title="Partager"
          aria-label="Partager"
        >
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
        </button>
        <div v-if="album.media && album.media.length > 0" class="relative" ref="actionsMenuRef">
          <button
            @click="showActionsMenu = !showActionsMenu"
            class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/90 backdrop-blur text-surface-900 shadow-warm-md hover:bg-white transition"
            title="Plus d'actions"
            aria-label="Plus d'actions"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <circle cx="12" cy="5" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="19" r="1.6" />
            </svg>
          </button>
          <div v-show="showActionsMenu" class="dropdown top-full w-56">
            <button v-if="isOwner" @click="openFromMenu(() => showAddMediaModal = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
              Ajouter des médias
            </button>
            <button @click="openFromMenu(toggleSelectionMode)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
              {{ selectionMode ? 'Terminer la sélection' : 'Sélectionner' }}
            </button>
            <button v-if="isOwner" @click="openFromMenu(() => showGeolocateModal = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              Géolocaliser
            </button>
            <button v-if="isOwner" @click="openFromMenu(() => showEditModal = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
              Modifier
            </button>
            <template v-if="isOwner">
              <hr class="dropdown-divider" />
              <button @click="openFromMenu(deleteAlbum)" class="dropdown-item w-full text-left flex items-center gap-2.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                Supprimer l'album
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Titre + retour (bas gauche) -->
      <div class="absolute left-4 right-4 sm:left-8 sm:right-8 bottom-6 z-20 flex items-center gap-4">
        <Link
          href="/albums"
          class="inline-flex items-center justify-center w-11 h-11 rounded-full bg-white/15 border border-white/35 text-white backdrop-blur-sm hover:bg-white/30 transition shrink-0"
          title="Retour aux albums"
          aria-label="Retour aux albums"
        >
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div class="min-w-0">
          <div class="text-xs font-medium uppercase tracking-widest text-white/75">Albums</div>
          <h1 class="font-display text-3xl sm:text-5xl font-bold text-white truncate [text-shadow:0_2px_12px_rgba(23,20,15,0.45)]">
            {{ album.name }}
          </h1>
          <div class="mt-1.5 flex items-center gap-3 text-sm text-white/85">
            <span>{{ album.media_count || 0 }} médias</span>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-white/20 border border-white/30 text-white">
              {{ album.is_public ? 'Public' : 'Privé' }}
            </span>
            <span v-if="album.description" class="hidden sm:inline truncate">{{ album.description }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Bandeau du mode sélection (collant sous la nav) -->
    <div
      v-if="selectionMode"
      class="sticky top-16 z-30 flex flex-wrap items-center gap-3 bg-brand-600 px-4 sm:px-8 py-3 text-white shadow-md"
    >
      <span class="font-medium">
        {{ selectedMediaIds.length }} sélectionné{{ selectedMediaIds.length > 1 ? 's' : '' }}
      </span>
      <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
        <button
          v-if="selectedMediaIds.length === 1"
          @click="editSelected"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition"
        >
          Éditer
        </button>
        <button
          v-if="selectedMediaIds.length === 1 && isOwner"
          @click="setAsCover"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition"
        >
          Couverture
        </button>
        <button
          v-if="removableSelectedIds.length > 0"
          @click="removeSelectedMedia"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-red-600 hover:bg-red-50 transition"
        >
          Retirer ({{ removableSelectedIds.length }})
        </button>
        <button
          @click="toggleSelectionMode"
          class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium bg-white/15 hover:bg-white/25 transition"
        >
          Terminer
        </button>
      </div>
    </div>

    <!-- Share Panel -->
    <div v-if="showSharePanel" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
      <SharePanel
        :album="album"
        :is-owner="album.is_owner !== false"
        @updated="handleAlbumUpdated"
      />
    </div>

    <!-- Diaporama plein écran -->
    <FullscreenSlideshow
      ref="slideshowEl"
      :slides="slideshowSlides"
      shuffle
      ken-burns
    />

    <!-- ================= Galerie mosaïque pleine largeur ================= -->
    <div
      v-if="album.media && album.media.length > 0"
      class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 auto-rows-[160px] sm:auto-rows-[200px] xl:auto-rows-[230px] grid-flow-dense gap-1 p-1"
    >
      <div
        v-for="(media, index) in album.media"
        :key="media.id"
        :class="mosaicSpan(index)"
      >
        <MediaCard
          :media="media"
          fill
          :selectable="selectionMode"
          :is-selected="isSelected(media.id)"
          @click="(m, event) => handleMediaClick(media, index, event)"
          @toggle-selection="(m, event) => applySelection(media, index, event)"
        />
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="text-center py-16 bg-white rounded-lg shadow-xs">
        <svg class="mx-auto h-16 w-16 text-surface-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <h3 class="mt-4 text-lg font-medium text-surface-900">Aucun média</h3>
        <p class="mt-2 text-surface-500">Ajoutez des photos et vidéos à cet album.</p>
        <button
          v-if="isOwner"
          @click="showAddMediaModal = true"
          class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 dark:bg-brand-500/10 rounded-lg hover:bg-brand-100 dark:hover:bg-brand-500/20"
        >
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Ajouter des médias
        </button>
      </div>
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
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
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

// ---- Héro carte : fond Leaflet statique centré sur l'album --------------
const heroMapEl = ref(null);
let heroMap = null;

onMounted(() => {
  if (!props.album.hero_location || !heroMapEl.value) return;
  const { latitude, longitude, count } = props.album.hero_location;

  // Carte décorative : toutes les interactions sont coupées (le fond ne doit
  // pas voler le scroll de la page).
  heroMap = L.map(heroMapEl.value, {
    zoomControl: false,
    dragging: false,
    scrollWheelZoom: false,
    doubleClickZoom: false,
    boxZoom: false,
    keyboard: false,
    touchZoom: false,
  }).setView([latitude, longitude], 11);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap',
  }).addTo(heroMap);

  // Marqueur-vignette, même langage visuel que la page Carte.
  const badge = count > 1
    ? `<div style="position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#f59e0b;color:#fff;font-size:11px;font-weight:600;line-height:18px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.3);">${count}</div>`
    : '';
  const thumb = props.album.cover_url
    ? `<img src="${props.album.cover_url}" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none'" />`
    : '';
  L.marker([latitude, longitude], {
    icon: L.divIcon({
      className: 'custom-thumb-marker',
      html: `<div style="position:relative;width:56px;height:56px;border-radius:10px;overflow:visible;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);background:#e7e5e4;"><div style="width:100%;height:100%;border-radius:7px;overflow:hidden;">${thumb}</div>${badge}</div>
        <div style="width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid #fff;margin:0 auto;filter:drop-shadow(0 2px 2px rgba(0,0,0,0.2));"></div>`,
      iconSize: [56, 64],
      iconAnchor: [28, 64],
    }),
    interactive: false,
  }).addTo(heroMap);
});

onBeforeUnmount(() => {
  heroMap?.remove();
  heroMap = null;
  document.removeEventListener('click', closeMenuOnOutsideClick);
});

// ---- Menu ⋯ flottant ------------------------------------------------------
const showActionsMenu = ref(false);
const actionsMenuRef = ref(null);

const closeMenuOnOutsideClick = (event) => {
  if (showActionsMenu.value && actionsMenuRef.value && !actionsMenuRef.value.contains(event.target)) {
    showActionsMenu.value = false;
  }
};
onMounted(() => document.addEventListener('click', closeMenuOnOutsideClick));

const openFromMenu = (action) => {
  showActionsMenu.value = false;
  action();
};

// ---- Mosaïque --------------------------------------------------------------
// Motif déterministe (cycle de 10) : quelques tuiles 2x2 et panoramiques
// cassent la grille uniforme ; grid-flow-dense rebouche les trous.
const mosaicSpan = (index) => {
  const i = index % 10;
  if (i === 0) return 'col-span-2 row-span-2';
  if (i === 4) return 'col-span-2';
  if (i === 7) return 'col-span-2';
  return '';
};

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
// via le menu ⋯ (demande UX : pas de cases par défaut).
const selectionMode = ref(false);
const selectedMediaIds = ref([]);

const toggleSelectionMode = () => {
  selectionMode.value = !selectionMode.value;
  if (!selectionMode.value) {
    selectedMediaIds.value = [];
    lastSelectedIndex.value = null;
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

// Ancre de la dernière sélection, pour la sélection de plage au shift+clic
// (même comportement que la galerie).
const lastSelectedIndex = ref(null);

// Applique la sélection : shift+clic depuis une ancre = ajoute toute la
// plage (dans l'ordre affiché) ; sinon bascule l'élément.
const applySelection = (media, index, event) => {
  if (event?.shiftKey && lastSelectedIndex.value !== null) {
    const [start, end] = lastSelectedIndex.value < index
      ? [lastSelectedIndex.value, index]
      : [index, lastSelectedIndex.value];
    const rangeIds = albumMedia.value.slice(start, end + 1).map((m) => m.id);
    selectedMediaIds.value = Array.from(new Set([...selectedMediaIds.value, ...rangeIds]));
  } else {
    const position = selectedMediaIds.value.indexOf(media.id);
    if (position === -1) {
      selectedMediaIds.value.push(media.id);
    } else {
      selectedMediaIds.value.splice(position, 1);
    }
  }
  lastSelectedIndex.value = index;
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
const handleMediaClick = (media, index, event) => {
  if (selectionMode.value) {
    applySelection(media, index, event);
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
