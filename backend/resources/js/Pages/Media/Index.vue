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

<style scoped>
:deep(.pswp__custom-caption) {
  background: rgba(0, 0, 0, 0.75);
  color: white;
  padding: 12px 16px;
  font-size: 14px;
  text-align: center;
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 20;
  border-radius: 0 0 4px 4px;
}
</style>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import MediaGrid from '@/Components/MediaGrid.vue';
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';

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
let lightbox = null;

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
  // For photos, open in lightbox
  if (media.type === 'photo') {
    // Find the index in the filtered photoItems array (not all media items)
    const photoItems = mediaItems.value.filter(item => item.type === 'photo');
    const photoIndex = photoItems.findIndex(item => item.id === media.id);
    if (photoIndex !== -1 && lightbox) {
      lightbox.loadAndOpen(photoIndex);
    }
  } else {
    // For videos and documents, navigate to detail page
    router.visit(route('media.show', media.id));
  }
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

// Helper function to get the best image URL for lightbox
const getImageUrl = (media, size = 'large') => {
  if (!media.conversions || media.conversions.length === 0) {
    return media.url;
  }

  // Try to find the requested size conversion
  const conversion = media.conversions.find(c => c.conversion_name === size);
  if (conversion) {
    return conversion.url;
  }

  // Fallback to medium, then small, then original
  const medium = media.conversions.find(c => c.conversion_name === 'medium');
  if (medium) return medium.url;

  const small = media.conversions.find(c => c.conversion_name === 'small');
  if (small) return small.url;

  return media.url;
};

// Helper function to get image dimensions
const getImageDimensions = (media, size = 'large') => {
  if (!media.conversions || media.conversions.length === 0) {
    return { width: media.width || 1600, height: media.height || 1200 };
  }

  const conversion = media.conversions.find(c => c.conversion_name === size);
  if (conversion && conversion.width && conversion.height) {
    return { width: conversion.width, height: conversion.height };
  }

  // Fallback to original dimensions or defaults
  return { width: media.width || 1600, height: media.height || 1200 };
};

// Initialize PhotoSwipe
const initPhotoSwipe = () => {
  if (lightbox) {
    lightbox.destroy();
  }

  // Only include photos in the gallery
  const photoItems = mediaItems.value.filter(item => item.type === 'photo');

  if (photoItems.length === 0) {
    return;
  }

  lightbox = new PhotoSwipeLightbox({
    dataSource: photoItems.map(media => {
      const dimensions = getImageDimensions(media);
      return {
        src: getImageUrl(media),
        width: dimensions.width,
        height: dimensions.height,
        alt: media.original_name,
        // Store additional metadata
        caption: media.original_name,
        takenAt: media.taken_at,
        uploadedAt: media.created_at,
      };
    }),
    pswpModule: () => import('photoswipe'),
    padding: { top: 50, bottom: 50, left: 50, right: 50 },
    bgOpacity: 0.9,
    showHideAnimationType: 'zoom',
    // Add captions below images
    appendToEl: document.body,
  });

  // Add caption to UI
  lightbox.on('uiRegister', function() {
    lightbox.pswp.ui.registerElement({
      name: 'custom-caption',
      order: 9,
      isButton: false,
      appendTo: 'root',
      html: '',
      onInit: (el, pswp) => {
        lightbox.pswp.on('change', () => {
          const currSlideElement = lightbox.pswp.currSlide.data;
          const captionHTML = currSlideElement.caption || '';
          el.innerHTML = `<div class="pswp__custom-caption">${captionHTML}</div>`;
        });
      }
    });
  });

  lightbox.init();
};

// Watch for media changes and reinitialize PhotoSwipe
watch(() => props.media, () => {
  initPhotoSwipe();
}, { deep: true });

// Initialize on mount
onMounted(() => {
  initPhotoSwipe();
});

// Cleanup on unmount
onUnmounted(() => {
  if (lightbox) {
    lightbox.destroy();
    lightbox = null;
  }
});
</script>
