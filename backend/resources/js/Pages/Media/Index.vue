<template>
  <Head title="Mes photos" />
  <AppLayout>
    <div class="py-3 sm:py-12">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Page Header (téléphone : titre compact + compteur, « Sélectionner ») -->
        <div class="mb-3 sm:mb-6 flex items-center justify-between gap-4">
          <div class="min-w-0">
            <h1 class="text-display text-[26px] sm:text-4xl text-surface-900 truncate">
              <span class="sm:hidden">Photos</span><span class="hidden sm:inline">Mes photos</span>
              <span v-if="totalCount" class="font-sans text-sm font-semibold text-surface-500 ml-1">{{ totalCount }}</span>
            </h1>
            <p class="mt-2 text-surface-600 hidden sm:block">
              Parcourez vos photos, vidéos et documents
            </p>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button
              type="button"
              class="sm:hidden h-9 px-3.5 rounded-full border text-sm font-semibold"
              :class="selectionMode ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white border-surface-300 text-surface-900'"
              @click="toggleSelectionMode"
            >
              {{ selectionMode ? 'Terminer' : 'Sélectionner' }}
            </button>
            <Link
              href="/media/upload"
              class="btn-primary hidden sm:inline-flex"
            >
              <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
              </svg>
              Ajouter des photos
            </Link>
          </div>
        </div>

        <!-- Barre d'actions de sélection -->
        <!-- Téléphone : barre fixée en bas (sous le pouce, par-dessus les onglets) ; desktop : collante en haut -->
        <div
          v-if="selectedIds.length > 0"
          class="fixed inset-x-0 bottom-0 z-50 pb-[env(safe-area-inset-bottom)] sm:pb-3 sm:static sm:mb-6 sm:sticky sm:top-2 sm:z-20 sm:inset-auto
                 flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2 sm:gap-3 sm:rounded-lg bg-brand-600 px-4 py-3 text-white shadow-md"
        >
          <span class="font-medium">
            {{ selectedIds.length }} sélectionné{{ selectedIds.length > 1 ? 's' : '' }}
          </span>
          <div class="flex flex-nowrap sm:flex-wrap items-center gap-2 sm:ml-auto overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 sm:overflow-visible [&>button]:shrink-0">
            <button
              type="button"
              class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium bg-white/15 hover:bg-white/25 transition disabled:opacity-50"
              :disabled="selectingAll"
              @click="selectAll"
            >
              <svg v-if="selectingAll" class="animate-spin h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              Tout sélectionner
            </button>
            <button
              v-if="selectedIds.length > 0"
              type="button"
              class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium bg-white/15 hover:bg-white/25 transition"
              @click="selectedIds = []"
            >
              Effacer
            </button>
            <button
              type="button"
              class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="selectedIds.length === 0 || selectedIds.length > 24"
              :title="selectedIds.length > 24 ? '24 photos au plus par moment' : ''"
              @click="showMakeMoment = true"
            >
              Créer un moment
            </button>
            <button
              type="button"
              class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="selectedIds.length === 0"
              @click="showDateModal = true"
            >
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              Dater
            </button>
            <button
              type="button"
              class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="selectedIds.length === 0"
              @click="showGeoModal = true"
            >
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              Géolocaliser
            </button>
            <button
              type="button"
              class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="selectedIds.length === 0"
              @click="showAlbumModal = true"
            >
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V6z" />
              </svg>
              Ajouter à un album
            </button>
            <button
              type="button"
              class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="selectedIds.length === 0"
              @click="showHouseholdModal = true"
            >
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
              </svg>
              Cercle
            </button>
            <button
              type="button"
              class="inline-flex items-center px-3 py-1.5 rounded-md text-sm font-semibold bg-white text-brand-700 hover:bg-brand-50 transition disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="selectedIds.length === 0 || markingStrangers"
              title="Tous les visages encore à identifier de la sélection passent en « personne inconnue »"
              @click="markNoKnownFaces"
            >
              <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
              Personne de connu
            </button>
          </div>
        </div>

        <!-- Confirmation après ajout -->
        <div
          v-if="albumFeedback"
          class="mb-6 flex items-center justify-between gap-3 rounded-lg bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/30 px-4 py-3 text-sm text-green-800 dark:text-green-200"
        >
          <span>{{ albumFeedback.message }}</span>
          <Link
            v-if="albumFeedback.albumId"
            :href="`/albums/${albumFeedback.albumId}`"
            class="font-semibold underline shrink-0"
          >
            Voir l'album
          </Link>
        </div>

        <!-- Search & Filters Bar (téléphone : sans carte, une ligne de filtres qui défile) -->
        <div class="mb-3 sm:mb-6 sm:bg-white sm:rounded-lg sm:shadow-xs sm:p-4 space-y-3 sm:space-y-4">
          <!-- Search -->
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </div>
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Rechercher dans vos médias..."
              class="block w-full pl-10 pr-3 py-2 border border-surface-300 rounded-full sm:rounded-md leading-5 bg-white placeholder-surface-500 focus:outline-hidden focus:placeholder-surface-400 focus:ring-1 focus:ring-brand-500 focus:border-brand-500 sm:text-sm"
              @input="debouncedSearch"
            />
          </div>

          <!-- Video Advanced Filters (visible uniquement sur l'onglet Vidéos) -->
          <div v-if="currentFilter === 'video'" class="pt-4 border-t border-surface-100">
            <div class="flex items-center justify-between mb-3">
              <span class="text-sm font-medium text-surface-700">Filtres avancés</span>
              <button
                v-if="hasVideoFilters"
                @click="clearVideoFilters"
                class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200 transition"
              >
                Réinitialiser
              </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <!-- Durée -->
              <div>
                <label class="block text-xs font-medium text-surface-500 mb-1">Durée (minutes)</label>
                <div class="flex items-center gap-2">
                  <input
                    v-model.number="videoFilters.durationMin"
                    type="number"
                    min="0"
                    placeholder="Min"
                    class="w-full px-2 py-1.5 text-sm border border-surface-300 rounded-md focus:ring-1 focus:ring-brand-500 focus:border-brand-500"
                  />
                  <span class="text-surface-400 text-xs shrink-0">→</span>
                  <input
                    v-model.number="videoFilters.durationMax"
                    type="number"
                    min="0"
                    placeholder="Max"
                    class="w-full px-2 py-1.5 text-sm border border-surface-300 rounded-md focus:ring-1 focus:ring-brand-500 focus:border-brand-500"
                  />
                </div>
              </div>
              <!-- Résolution -->
              <div>
                <label class="block text-xs font-medium text-surface-500 mb-1">Résolution minimale</label>
                <div class="flex flex-wrap gap-1.5">
                  <button
                    v-for="res in resolutionOptions"
                    :key="res.value"
                    @click="videoFilters.resolution = videoFilters.resolution === res.value ? null : res.value"
                    :class="[
                      'px-2.5 py-1 text-xs rounded-md border transition',
                      videoFilters.resolution === res.value
                        ? 'bg-brand-600 border-brand-600 text-white'
                        : 'bg-white border-surface-300 text-surface-600 hover:border-brand-400 hover:text-brand-600'
                    ]"
                  >
                    {{ res.label }}
                  </button>
                </div>
              </div>
              <!-- Codec -->
              <div v-if="availableCodecs && availableCodecs.length > 0">
                <label class="block text-xs font-medium text-surface-500 mb-1">Codec vidéo</label>
                <select
                  v-model="videoFilters.codec"
                  class="w-full px-2 py-1.5 text-sm border border-surface-300 rounded-md focus:ring-1 focus:ring-brand-500 focus:border-brand-500"
                >
                  <option value="">Tous</option>
                  <option v-for="codec in availableCodecs" :key="codec" :value="codec">
                    {{ codec.toUpperCase() }}
                  </option>
                </select>
              </div>
            </div>
            <button
              @click="applyVideoFilters"
              class="mt-3 px-4 py-1.5 text-sm font-medium bg-brand-600 text-white rounded-lg hover:bg-brand-700 transition"
            >
              Appliquer
            </button>
          </div>

          <!-- Tri + filtres (date, personnes, tags) -->
          <MediaFilters
            :state="filterState"
            :years="years"
            :counts="counts"
            :tags="availableTags"
            :known-people="filterPeople"
            @change="handleFiltersChange"
          />
        </div>

        <!-- Media Grid (téléphone : bord à bord, sans carte) -->
        <div class="-mx-4 sm:mx-0 sm:bg-white sm:rounded-lg sm:shadow-xs sm:p-6" data-tour="gallery">
          <MediaGrid
            :media="mediaItems"
            :loading="loading"
            :loading-more="loadingMore"
            :current-filter="currentFilter"
            :filter-tabs="filterTabs"
            :has-more-pages="hasMorePages"
            :empty-state-message="emptyStateMessage"
            :group-by="filterState.sort === 'added' ? 'added' : 'taken'"
            :selectable="true"
            :selection-active="selectedIds.length > 0 || selectionMode"
            :selected-ids="selectedIds"
            @filter-change="handleFilterChange"
            @media-click="handleMediaClick"
            @load-more="handleLoadMore"
            @selection-change="selectedIds = $event"
          />
        </div>
      </div>
    </div>

    <AddToAlbumModal
      v-if="showAlbumModal"
      :media-ids="selectedIds"
      @close="showAlbumModal = false"
      @done="handleAlbumDone"
    />

    <ShareToHouseholdModal
      v-if="showHouseholdModal"
      :media-ids="selectedIds"
      @close="showHouseholdModal = false"
      @done="handleHouseholdDone"
    />

    <BulkDateModal
      v-if="showDateModal"
      :count="selectedIds.length"
      :saving="bulkSaving"
      :error-message="bulkError"
      @close="closeBulkModals"
      @apply="applyBulkDate"
    />

    <GeolocatePickerModal
      v-if="showGeoModal"
      :title="`Géolocaliser ${selectedIds.length} média${selectedIds.length > 1 ? 's' : ''}`"
      description="Cliquez sur la carte ou cherchez une adresse : la position sera appliquée à toute la sélection."
      apply-label="Appliquer la position"
      :saving="bulkSaving"
      :error-message="bulkError"
      @close="closeBulkModals"
      @apply="applyBulkGeolocation"
    />
    <MomentFromPhotos
      :open="showMakeMoment"
      :photos="selectedPhotosForMoment"
      @close="showMakeMoment = false"
      @saved="selectedIds = []; selectionMode = false"
    />
  </AppLayout>
</template>

<script setup>
import { ref, computed, reactive, onMounted, onUnmounted, watch, defineAsyncComponent } from 'vue';
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import MediaGrid from '@/Components/MediaGrid.vue';
import MediaFilters from '@/Components/MediaFilters.vue';
import AddToAlbumModal from '@/Components/AddToAlbumModal.vue';
import ShareToHouseholdModal from '@/Components/ShareToHouseholdModal.vue';
import BulkDateModal from '@/Components/BulkDateModal.vue';
import { usePhotoSwipe } from '@/Composables/usePhotoSwipe';
import { useToast } from '@/Composables/useToast';
import { apiError } from '@/utils/apiError';
import { useTags } from '@/Composables/useTags';
import MomentFromPhotos from '@/Components/MomentFromPhotos.vue';
import { thumbnailUrl as smallThumbUrl } from '@/utils/media';

// Chargé à l'ouverture seulement : la carte embarque Leaflet (~45 ko gzip).
const GeolocatePickerModal = defineAsyncComponent(() => import('@/Components/GeolocatePickerModal.vue'));

const props = defineProps({
  media: {
    type: Object,
    required: true,
  },
  filters: {
    type: Object,
    default: () => ({}),
  },
  availableCodecs: {
    type: Array,
    default: () => [],
  },
  years: { type: Array, default: () => [] },
  counts: { type: Object, default: () => ({}) },
  filterPeople: { type: Array, default: () => [] },
});

// Reactive state
const loading = ref(false);      // rechargement complet (changement de filtre)
const loadingMore = ref(false);  // chargement de la page suivante (scroll infini)
const searchQuery = ref(props.filters.search || '');
const currentFilter = ref(props.filters.type || 'all');
const asList = (value) => (value ? (Array.isArray(value) ? value : [value]) : []);
const selectedTags = ref(asList(props.filters.tags));

// Tri + filtres date/personnes, repris de l'URL (lien partageable, retour arrière).
const filterState = reactive({
  sort: props.filters.sort || 'recent',
  yearFrom: Number(props.filters.year_from) || null,
  yearTo: Number(props.filters.year_to) || null,
  undated: !!props.filters.undated,
  people: asList(props.filters.people),
  peopleMode: props.filters.people_mode || null,
  // Même tableau que selectedTags (partagé avec la logique existante).
  tags: selectedTags,
});
const availableTags = ref([]);
let searchTimeout = null;

// Sélection multiple + ajout à un album.
const selectedIds = ref([]);

// Téléphone : « Sélectionner » bascule la grille en mode sélection (un
// toucher coche) sans attendre une première case ; « Terminer » vide tout.
const selectionMode = ref(false);
const toggleSelectionMode = () => {
  selectionMode.value = !selectionMode.value;
  if (!selectionMode.value) selectedIds.value = [];
};
const selectingAll = ref(false);
const toast = useToast();

// « Personne de connu » : les visages non identifiés de la sélection
// passent en « personne inconnue » (photos de monuments, foules…).
const markingStrangers = ref(false);
const markNoKnownFaces = async () => {
  const n = selectedIds.value.length;
  if (!window.confirm(`Aucune personne connue sur ${n} photo${n > 1 ? 's' : ''} ? Les visages encore à identifier passeront en « personne inconnue » (les visages déjà nommés ne bougent pas).`)) return;
  markingStrangers.value = true;
  try {
    const { data } = await axios.post('/vision/no-known-faces', { media_ids: selectedIds.value });
    toast.success(`${data.marked} visage${data.marked > 1 ? 's' : ''} rangé${data.marked > 1 ? 's' : ''} en « inconnu ».`);
  } catch (error) {
    toast.error(apiError(error, 'Impossible de ranger ces visages.'));
  } finally {
    markingStrangers.value = false;
  }
};

const showAlbumModal = ref(false);
const showHouseholdModal = ref(false);
const albumFeedback = ref(null);
const showDateModal = ref(false);
const showGeoModal = ref(false);
const bulkSaving = ref(false);
const bulkError = ref(null);

// Video filters (durées affichées en minutes, envoyées en secondes — pas de
// troncature pour préserver les valeurs sub-minute venues de l'URL)
const videoFilters = reactive({
  durationMin: props.filters.duration_min ? props.filters.duration_min / 60 : null,
  durationMax: props.filters.duration_max ? props.filters.duration_max / 60 : null,
  resolution: props.filters.resolution || null,
  codec: props.filters.video_codec || '',
});

const resolutionOptions = [
  { label: '720p+', value: '720p' },
  { label: '1080p+', value: '1080p' },
  { label: '4K+', value: '4k' },
];

const hasVideoFilters = computed(() => {
  return !!(videoFilters.durationMin || videoFilters.durationMax || videoFilters.resolution || videoFilters.codec);
});

// Scroll infini : accumulateur local des médias. Les navigations de filtres
// passent toujours par Inertia (URL partageable) et remplacent `props.media`
// par la page 1 ; ce watch réamorce alors l'accumulateur. Les pages suivantes
// sont ajoutées via axios (sans toucher à l'URL) dans handleLoadMore.
const items = ref([]);
const page = ref(1);
const lastPage = ref(1);

watch(
  () => props.media,
  (media) => {
    items.value = media?.data ? [...media.data] : [];
    page.value = media?.current_page ?? 1;
    lastPage.value = media?.last_page ?? 1;
  },
  { immediate: true, deep: false }
);

// Computed properties
const mediaItems = computed(() => items.value);

// « Créer un moment » avec la sélection (photos seulement, dans l'ordre de la galerie).
const showMakeMoment = ref(false);
const selectedPhotosForMoment = computed(() => {
  const ids = new Set(selectedIds.value);
  return mediaItems.value
    .filter((m) => ids.has(m.id) && m.type === 'photo')
    .map((m) => ({ id: m.id, thumbnail_url: smallThumbUrl(m), taken_at: m.taken_at }));
});

const hasMorePages = computed(() => page.value < lastPage.value);

// Compteur du titre (téléphone) : total de l'onglet « Tous ».
const totalCount = computed(() => (filterTabs.value || []).find((t) => t.value === 'all')?.count ?? null);

const filterTabs = computed(() => [
  { value: 'all', label: 'Tous', count: props.media.total },
  { value: 'photo', label: 'Photos' },
  { value: 'video', label: 'Vidéos' },
  { value: 'document', label: 'Documents' },
]);

const hasActiveFilters = computed(() => !!(
  filterState.yearFrom || filterState.yearTo || filterState.undated
  || filterState.people.length || filterState.peopleMode || selectedTags.value.length
));

const emptyStateMessage = computed(() => {
  if (hasActiveFilters.value) {
    return 'Aucun média ne correspond à ces filtres.';
  }
  if (currentFilter.value !== 'all') {
    return {
      photo: 'Aucune photo trouvée.',
      video: 'Aucune vidéo trouvée.',
    }[currentFilter.value] || 'Aucun document trouvé.';
  }
  if (searchQuery.value) {
    return `Aucun résultat pour "${searchQuery.value}".`;
  }
  return 'Commencez par ajouter vos premières photos.';
});

// Construit les paramètres de requête courants (type, recherche, tags et,
// sur l'onglet Vidéos, les filtres vidéo) pour que chaque navigation les préserve.
const buildQuery = (extra = {}) => {
  const query = {
    type: currentFilter.value === 'all' ? undefined : currentFilter.value,
    search: searchQuery.value || undefined,
    tags: selectedTags.value.length > 0 ? selectedTags.value : undefined,
    sort: filterState.sort !== 'recent' ? filterState.sort : undefined,
    year_from: filterState.undated ? undefined : filterState.yearFrom || undefined,
    year_to: filterState.undated ? undefined : filterState.yearTo || undefined,
    undated: filterState.undated ? 1 : undefined,
    people: filterState.people.length > 0 ? filterState.people : undefined,
    people_mode: filterState.peopleMode || undefined,
  };

  if (currentFilter.value === 'video') {
    query.duration_min = videoFilters.durationMin ? Math.round(videoFilters.durationMin * 60) : undefined;
    query.duration_max = videoFilters.durationMax ? Math.round(videoFilters.durationMax * 60) : undefined;
    query.resolution = videoFilters.resolution || undefined;
    query.video_codec = videoFilters.codec || undefined;
  }

  return { ...query, ...extra };
};

const navigate = (extra = {}, options = {}) => {
  loading.value = true;

  // preserveState garde l'état local (recherche, tags) ; on NE préserve PAS
  // le scroll : un changement de filtre réamorce la liste, on revient en haut.
  router.get('/media', buildQuery(extra), {
    preserveState: true,
    preserveScroll: false,
    ...options,
    onFinish: () => {
      loading.value = false;
    },
  });
};

// Event handlers
const handleFilterChange = (newFilter) => {
  currentFilter.value = newFilter;

  // Les filtres vidéo n'ont pas de sens hors de l'onglet Vidéos
  if (newFilter !== 'video') {
    videoFilters.durationMin = null;
    videoFilters.durationMax = null;
    videoFilters.resolution = null;
    videoFilters.codec = '';
  }

  navigate();
};

// Changement venu de la barre de filtres : on applique puis on recharge.
const handleFiltersChange = (patch) => {
  Object.assign(filterState, patch);
  navigate();
};

// Liste partagée (en cache si une autre page l'a déjà chargée).
const tagList = useTags();
const loadAvailableTags = async () => {
  try {
    availableTags.value = await tagList.load();
  } catch (error) {
    console.error('Error loading tags:', error);
  }
};

const performSearch = () => {
  navigate();
};

const debouncedSearch = () => {
  if (searchTimeout) {
    clearTimeout(searchTimeout);
  }
  searchTimeout = setTimeout(() => {
    performSearch();
  }, 300);
};

// Visionneuse PhotoSwipe sur la galerie (reconstruite quand la liste grandit
// via le scroll infini) ; bouton « i » vers la fiche.
const { open: openPhoto } = usePhotoSwipe(() => mediaItems.value, {
  watchSource: () => mediaItems.value,
  detailLink: true,
});

const handleMediaClick = (media) => {
  // Quand une sélection est en cours, MediaGrid gère les clics (sélection).
  // Sinon : photo/vidéo → visionneuse ; document → page média.
  if (!openPhoto(media)) {
    router.visit(`/media/${media.id}`);
  }
};

// « Tout sélectionner » : récupère les IDs de TOUS les médias du filtre courant
// (pages non encore chargées comprises), pas seulement ceux affichés.
const selectAll = async () => {
  selectingAll.value = true;
  try {
    const { data } = await axios.get('/media/ids', {
      params: buildQuery(),
      headers: { Accept: 'application/json' },
    });
    selectedIds.value = data.ids || [];
  } catch (error) {
    console.error('« Tout sélectionner » a échoué :', error);
    toast.error(apiError(error, 'Impossible de sélectionner tous les médias. Réessayez.'));
  } finally {
    selectingAll.value = false;
  }
};

const closeBulkModals = () => {
  showDateModal.value = false;
  showGeoModal.value = false;
  bulkError.value = null;
};

const finishBulk = (data) => {
  closeBulkModals();
  albumFeedback.value = {
    message: data.skipped > 0
      ? `${data.message} — ${data.skipped} ignoré(s) (médias d'un autre membre).`
      : data.message,
  };
  selectedIds.value = [];
};

const applyBulkDate = async (takenAt) => {
  bulkSaving.value = true;
  bulkError.value = null;
  try {
    const { data } = await axios.post('/media/bulk/taken-at', {
      media_ids: selectedIds.value,
      taken_at: takenAt,
    });
    finishBulk(data);
    // La date change l'ordre de la galerie : on recharge avec les filtres courants.
    navigate();
  } catch (e) {
    bulkError.value = apiError(e, "Impossible d'appliquer la date.");
  } finally {
    bulkSaving.value = false;
  }
};

const applyBulkGeolocation = async ({ latitude, longitude }) => {
  bulkSaving.value = true;
  bulkError.value = null;
  try {
    const { data } = await axios.post('/media/bulk/geolocation', {
      media_ids: selectedIds.value,
      latitude,
      longitude,
    });
    finishBulk(data);
  } catch (e) {
    bulkError.value = apiError(e, "Impossible d'appliquer la position.");
  } finally {
    bulkSaving.value = false;
  }
};

// Partage foyer terminé : toast récapitulatif (dont les ignorés — médias
// d'autrui présents dans la sélection) et sortie du mode sélection.
const handleHouseholdDone = ({ message, skipped }) => {
  toast.success(skipped > 0 ? `${message} (${skipped} ignoré(s) : pas à vous)` : message);
  selectedIds.value = [];
};

const handleAlbumDone = ({ albumId, albumName, count, isNew }) => {
  albumFeedback.value = {
    albumId,
    message: isNew
      ? `Album « ${albumName} » créé avec ${count} média${count > 1 ? 's' : ''}.`
      : `${count} média${count > 1 ? 's' : ''} ajouté${count > 1 ? 's' : ''} à « ${albumName} ».`,
  };
  selectedIds.value = [];
};

// Charge la page suivante et l'AJOUTE à l'accumulateur (scroll infini).
// Ne touche pas à l'URL : les filtres courants sont repris via buildQuery.
// Après un échec, MediaGrid relancerait aussitôt (sentinelle toujours
// visible) : on laisse souffler quelques secondes pour éviter une rafale
// de requêtes et de messages d'erreur.
const LOAD_MORE_RETRY_MS = 3000;
let loadMoreFailedAt = 0;

const handleLoadMore = async () => {
  if (loadingMore.value || loading.value || !hasMorePages.value) return;
  if (Date.now() - loadMoreFailedAt < LOAD_MORE_RETRY_MS) return;

  loadingMore.value = true;
  try {
    const { data } = await axios.get('/media', {
      params: buildQuery({ page: page.value + 1 }),
      headers: { Accept: 'application/json' },
    });

    items.value.push(...(data.data || []));
    page.value = data.current_page ?? page.value + 1;
    lastPage.value = data.last_page ?? lastPage.value;
  } catch (error) {
    console.error('Erreur lors du chargement de la page suivante :', error);
    loadMoreFailedAt = Date.now();
    toast.error(apiError(error, 'Impossible de charger la suite de la galerie. Faites défiler ou touchez « Charger plus » pour réessayer.'));
  } finally {
    loadingMore.value = false;
  }
};

const applyVideoFilters = () => {
  currentFilter.value = 'video';
  navigate();
};

const clearVideoFilters = () => {
  videoFilters.durationMin = null;
  videoFilters.durationMax = null;
  videoFilters.resolution = null;
  videoFilters.codec = '';
  applyVideoFilters();
};

// Initialize on mount
onUnmounted(() => clearTimeout(searchTimeout));

onMounted(() => {
  loadAvailableTags();
});
</script>
