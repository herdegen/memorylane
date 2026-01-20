<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="mb-6 flex items-center justify-between">
          <div>
            <h1 class="text-3xl font-bold text-gray-900">Galerie</h1>
            <p class="mt-2 text-gray-600">
              Parcourez vos photos, vidéos et documents
            </p>
          </div>
          <Link
            :href="route('media.create')"
            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:border-indigo-900 focus:ring ring-indigo-300 disabled:opacity-25 transition ease-in-out duration-150"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Télécharger
          </Link>
        </div>

        <!-- Search Bar -->
        <div class="mb-6 bg-white rounded-lg shadow-sm p-4">
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </div>
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Rechercher dans vos médias..."
              class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
              @input="debouncedSearch"
            />
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
import { ref, computed, onMounted } from 'vue';
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
});

// Reactive state
const loading = ref(false);
const searchQuery = ref(props.filters.search || '');
const currentFilter = ref(props.filters.type || 'all');
let searchTimeout = null;

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

// Event handlers
const handleFilterChange = (newFilter) => {
  currentFilter.value = newFilter;
  loading.value = true;

  router.get(route('media.index'), {
    type: newFilter === 'all' ? undefined : newFilter,
    search: searchQuery.value || undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
    onFinish: () => {
      loading.value = false;
    },
  });
};

const performSearch = () => {
  loading.value = true;

  router.get(route('media.index'), {
    type: currentFilter.value === 'all' ? undefined : currentFilter.value,
    search: searchQuery.value || undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
    onFinish: () => {
      loading.value = false;
    },
  });
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
  // Navigate to media detail page
  router.visit(route('media.show', media.id));
};

const handleLoadMore = () => {
  if (!hasMorePages.value || loading.value) return;

  loading.value = true;

  router.get(route('media.index'), {
    page: props.media.current_page + 1,
    type: currentFilter.value === 'all' ? undefined : currentFilter.value,
    search: searchQuery.value || undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
    only: ['media'],
    onFinish: () => {
      loading.value = false;
    },
  });
};
</script>
