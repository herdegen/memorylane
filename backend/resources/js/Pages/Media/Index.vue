<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="mb-6 flex items-center justify-between">
          <div>
            <h1 class="text-display text-4xl text-surface-900">Galerie</h1>
            <p class="mt-2 text-surface-600">
              Parcourez vos photos, vidéos et documents
            </p>
          </div>
          <Link
            :href="route('media.create')"
            class="btn-primary"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Télécharger
          </Link>
        </div>

        <!-- Search & Filters Bar -->
        <div class="mb-6 bg-white rounded-lg shadow-sm p-4 space-y-4">
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
              class="block w-full pl-10 pr-3 py-2 border border-surface-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-brand-500 focus:border-brand-500 sm:text-sm"
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
                class="text-xs text-red-600 hover:text-red-800 transition"
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
                  <span class="text-surface-400 text-xs flex-shrink-0">→</span>
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

          <!-- Tag filters -->
          <div v-if="availableTags.length > 0">
            <label class="block text-sm font-medium text-surface-700 mb-2">Filtrer par tags</label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="tag in availableTags"
                :key="tag.id"
                @click="toggleTagFilter(tag.id)"
                :class="[
                  'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium transition',
                  selectedTags.includes(tag.id)
                    ? 'text-white'
                    : 'bg-surface-100 text-surface-700 hover:bg-surface-200'
                ]"
                :style="selectedTags.includes(tag.id) ? { backgroundColor: tag.color || '#0D9488' } : {}"
              >
                {{ tag.name }}
                <span class="ml-1.5 text-xs opacity-75">({{ tag.media_count }})</span>
              </button>
              <button
                v-if="selectedTags.length > 0"
                @click="clearTagFilters"
                class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-700 hover:bg-red-200 transition"
              >
                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                Effacer les filtres
              </button>
            </div>
          </div>
        </div>

        <!-- Media Grid -->
        <div class="bg-white rounded-lg shadow-sm p-6">
          <MediaGrid
            :media="mediaItems"
            :loading="loading"
            :current-filter="currentFilter"
            :filter-tabs="filterTabs"
            :has-more-pages="hasMorePages"
            :empty-state-message="emptyStateMessage"
            @filter-change="handleFilterChange"
            @media-click="handleMediaClick"
            @load-more="handleLoadMore"
          />
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, reactive, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import MediaGrid from '@/Components/MediaGrid.vue';

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
});

// Reactive state
const loading = ref(false);
const searchQuery = ref(props.filters.search || '');
const currentFilter = ref(props.filters.type || 'all');
const selectedTags = ref(props.filters.tags ? (Array.isArray(props.filters.tags) ? props.filters.tags : [props.filters.tags]) : []);
const availableTags = ref([]);
let searchTimeout = null;

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

// Computed properties
const mediaItems = computed(() => props.media.data || []);

const hasMorePages = computed(() => {
  return props.media.current_page < props.media.last_page;
});

const filterTabs = computed(() => [
  { value: 'all', label: 'Tous', count: props.media.total },
  { value: 'photo', label: 'Photos' },
  { value: 'video', label: 'Vidéos' },
  { value: 'document', label: 'Documents' },
]);

const emptyStateMessage = computed(() => {
  if (currentFilter.value !== 'all') {
    return `Aucun ${currentFilter.value === 'photo' ? 'photo' : currentFilter.value === 'video' ? 'vidéo' : 'document'} trouvé.`;
  }
  if (searchQuery.value) {
    return `Aucun résultat pour "${searchQuery.value}".`;
  }
  return 'Commencez par télécharger vos premiers médias.';
});

// Construit les paramètres de requête courants (type, recherche, tags et,
// sur l'onglet Vidéos, les filtres vidéo) pour que chaque navigation les préserve.
const buildQuery = (extra = {}) => {
  const query = {
    type: currentFilter.value === 'all' ? undefined : currentFilter.value,
    search: searchQuery.value || undefined,
    tags: selectedTags.value.length > 0 ? selectedTags.value : undefined,
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

  router.get(route('media.index'), buildQuery(extra), {
    preserveState: true,
    preserveScroll: true,
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

const toggleTagFilter = (tagId) => {
  const index = selectedTags.value.indexOf(tagId);
  if (index > -1) {
    selectedTags.value.splice(index, 1);
  } else {
    selectedTags.value.push(tagId);
  }

  navigate();
};

const clearTagFilters = () => {
  selectedTags.value = [];
  navigate();
};

const loadAvailableTags = async () => {
  try {
    const response = await fetch('/tags', {
      headers: { 'Accept': 'application/json' }
    });
    const data = await response.json();
    availableTags.value = data;
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

const handleMediaClick = (media) => {
  router.visit(route('media.show', media.id));
};

const handleLoadMore = () => {
  if (!hasMorePages.value || loading.value) return;

  navigate({ page: props.media.current_page + 1 }, { only: ['media'] });
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
onMounted(() => {
  loadAvailableTags();
});
</script>
