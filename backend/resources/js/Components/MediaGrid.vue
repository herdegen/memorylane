<template>
  <div class="media-grid">
    <!-- Filter Tabs -->
    <div class="mb-6 border-b border-gray-200">
      <nav class="-mb-px flex space-x-8">
        <button
          v-for="tab in filterTabs"
          :key="tab.value"
          @click="$emit('filter-change', tab.value)"
          :class="[
            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-150',
            currentFilter === tab.value
              ? 'border-indigo-500 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          ]"
        >
          {{ tab.label }}
          <span
            v-if="tab.count !== undefined"
            :class="[
              'ml-2 py-0.5 px-2 rounded-full text-xs',
              currentFilter === tab.value
                ? 'bg-indigo-100 text-indigo-600'
                : 'bg-gray-100 text-gray-900'
            ]"
          >
            {{ tab.count }}
          </span>
        </button>
      </nav>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center items-center py-12">
      <svg
        class="animate-spin h-8 w-8 text-indigo-600"
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
      <span class="ml-3 text-gray-600">Chargement des médias...</span>
    </div>

    <!-- Empty State -->
    <div
      v-else-if="!media || media.length === 0"
      class="text-center py-12 px-4"
    >
      <svg
        class="mx-auto h-12 w-12 text-gray-400"
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
      <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun média</h3>
      <p class="mt-1 text-sm text-gray-500">
        {{ emptyStateMessage }}
      </p>
    </div>

    <!-- Media Grid -->
    <div
      v-else
      class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4"
    >
      <div
        v-for="item in media"
        :key="item.id"
        class="relative group aspect-square rounded-lg overflow-hidden bg-gray-100 cursor-pointer transition-transform duration-200 hover:scale-105 hover:shadow-lg"
        @click="$emit('media-click', item)"
      >
        <!-- Image Thumbnail -->
        <img
          v-if="item.type === 'photo' && getThumbnailUrl(item)"
          :src="getThumbnailUrl(item)"
          :alt="item.original_name"
          class="w-full h-full object-cover"
          loading="lazy"
        />

        <!-- Video Thumbnail -->
        <div
          v-else-if="item.type === 'video'"
          class="relative w-full h-full"
        >
          <img
            v-if="getThumbnailUrl(item)"
            :src="getThumbnailUrl(item)"
            :alt="item.original_name"
            class="w-full h-full object-cover"
            loading="lazy"
          />
          <div
            v-else
            class="w-full h-full flex items-center justify-center bg-gray-200"
          >
            <svg
              class="h-12 w-12 text-gray-400"
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
          </div>
          <!-- Play Icon Overlay -->
          <div
            class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-20"
          >
            <svg
              class="h-12 w-12 text-white opacity-80"
              fill="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                d="M8 5v14l11-7z"
              />
            </svg>
          </div>
        </div>

        <!-- Document Thumbnail -->
        <div
          v-else
          class="w-full h-full flex flex-col items-center justify-center bg-gray-200"
        >
          <svg
            class="h-12 w-12 text-gray-400"
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
          <span class="mt-2 text-xs text-gray-500 truncate max-w-full px-2">
            {{ getFileExtension(item.original_name) }}
          </span>
        </div>

        <!-- Hover Overlay with Info -->
        <div
          class="absolute inset-0 bg-gradient-to-t from-black via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-200"
        >
          <div class="absolute bottom-0 left-0 right-0 p-3 text-white">
            <p class="text-xs font-medium truncate">
              {{ item.original_name }}
            </p>
            <p class="text-xs text-gray-300 mt-1">
              {{ formatDate(item.taken_at || item.uploaded_at) }}
            </p>
          </div>
        </div>

        <!-- Selection Checkbox (if selectable) -->
        <div
          v-if="selectable"
          class="absolute top-2 right-2 z-10"
          @click.stop="toggleSelection(item)"
        >
          <div
            :class="[
              'w-6 h-6 rounded-md border-2 flex items-center justify-center transition-all',
              isSelected(item.id)
                ? 'bg-indigo-600 border-indigo-600'
                : 'bg-white bg-opacity-80 border-white hover:bg-opacity-100'
            ]"
          >
            <svg
              v-if="isSelected(item.id)"
              class="w-4 h-4 text-white"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="3"
                d="M5 13l4 4L19 7"
              />
            </svg>
          </div>
        </div>

        <!-- Media Type Badge -->
        <div class="absolute top-2 left-2">
          <span
            v-if="item.type === 'video'"
            class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-purple-100 text-purple-800"
          >
            {{ formatDuration(item.duration) }}
          </span>
        </div>
      </div>
    </div>

    <!-- Load More / Pagination -->
    <div
      v-if="hasMorePages && !loading"
      class="mt-8 flex justify-center"
    >
      <button
        @click="$emit('load-more')"
        class="px-6 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-150"
      >
        Charger plus
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  media: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  currentFilter: {
    type: String,
    default: 'all',
  },
  filterTabs: {
    type: Array,
    default: () => [
      { value: 'all', label: 'Tous', count: undefined },
      { value: 'photo', label: 'Photos', count: undefined },
      { value: 'video', label: 'Vidéos', count: undefined },
      { value: 'document', label: 'Documents', count: undefined },
    ],
  },
  selectable: {
    type: Boolean,
    default: false,
  },
  selectedIds: {
    type: Array,
    default: () => [],
  },
  hasMorePages: {
    type: Boolean,
    default: false,
  },
  emptyStateMessage: {
    type: String,
    default: 'Commencez par télécharger vos premiers médias.',
  },
});

const emit = defineEmits([
  'media-click',
  'filter-change',
  'load-more',
  'selection-change',
]);

const getThumbnailUrl = (item) => {
  // Try to get the small or thumbnail conversion
  if (item.conversions && item.conversions.length > 0) {
    const thumbnail = item.conversions.find(
      (conv) => conv.conversion_name === 'small' || conv.conversion_name === 'thumbnail'
    );
    if (thumbnail && thumbnail.url) {
      return thumbnail.url;
    }
  }
  // Fallback to original URL if available
  return item.url || null;
};

const getFileExtension = (filename) => {
  if (!filename) return '';
  const parts = filename.split('.');
  return parts.length > 1 ? parts.pop().toUpperCase() : '';
};

const formatDate = (dateString) => {
  if (!dateString) return '';

  const date = new Date(dateString);
  const now = new Date();
  const diffTime = Math.abs(now - date);
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

  if (diffDays === 0) {
    return "Aujourd'hui";
  } else if (diffDays === 1) {
    return 'Hier';
  } else if (diffDays < 7) {
    return `Il y a ${diffDays} jours`;
  } else {
    return date.toLocaleDateString('fr-FR', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  }
};

const formatDuration = (seconds) => {
  if (!seconds) return '';

  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);

  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  }
  return `${minutes}:${secs.toString().padStart(2, '0')}`;
};

const isSelected = (mediaId) => {
  return props.selectedIds.includes(mediaId);
};

const toggleSelection = (item) => {
  const newSelection = isSelected(item.id)
    ? props.selectedIds.filter(id => id !== item.id)
    : [...props.selectedIds, item.id];

  emit('selection-change', newSelection);
};
</script>
