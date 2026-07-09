<template>
  <div
    class="fixed inset-0 z-50 overflow-y-auto"
    aria-labelledby="modal-title"
    role="dialog"
    aria-modal="true"
  >
    <!-- Backdrop -->
    <div
      class="fixed inset-0 bg-surface-900/50 transition-opacity"
      @click="$emit('close')"
    ></div>

    <!-- Modal -->
    <div class="flex min-h-full items-center justify-center p-4">
      <div
        class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all w-full max-w-4xl max-h-[80vh] flex flex-col"
        @click.stop
      >
        <!-- Header -->
        <div class="bg-white px-6 py-4 border-b border-surface-200 flex items-center justify-between">
          <h3 class="text-lg font-semibold text-surface-900">
            Ajouter des medias
          </h3>
          <span class="text-sm text-surface-500">
            {{ selectedIds.length }} selectionne(s)
          </span>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex-1 flex items-center justify-center py-12">
          <svg
            class="animate-spin h-8 w-8 text-brand-600"
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
            />
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
          </svg>
        </div>

        <!-- Media Grid -->
        <div v-else ref="scrollContainer" class="flex-1 overflow-y-auto p-6">
          <div v-if="availableMedia.length === 0" class="text-center py-12">
            <svg
              class="mx-auto h-12 w-12 text-surface-400"
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
            <p class="mt-4 text-surface-500">Aucun media disponible</p>
          </div>

          <div
            v-else
            class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3"
          >
            <div
              v-for="media in availableMedia"
              :key="media.id"
              class="relative aspect-square rounded-lg overflow-hidden cursor-pointer group"
              :class="[
                isSelected(media.id)
                  ? 'ring-2 ring-brand-500 ring-offset-2'
                  : 'hover:ring-2 hover:ring-gray-300'
              ]"
              @click="toggleSelection(media.id)"
            >
              <!-- Thumbnail -->
              <img
                v-if="media.type === 'photo' || media.type === 'video'"
                :src="getThumbnailUrl(media)"
                :alt="media.original_name"
                class="w-full h-full object-cover"
                loading="lazy"
              />
              <div
                v-else
                class="w-full h-full flex items-center justify-center bg-surface-100"
              >
                <svg
                  class="h-8 w-8 text-surface-400"
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

              <!-- Video indicator -->
              <div
                v-if="media.type === 'video'"
                class="absolute inset-0 flex items-center justify-center bg-black/20"
              >
                <svg
                  class="h-8 w-8 text-white opacity-80"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path d="M8 5v14l11-7z" />
                </svg>
              </div>

              <!-- Selection indicator -->
              <div
                class="absolute top-2 right-2"
              >
                <div
                  :class="[
                    'w-6 h-6 rounded-full border-2 flex items-center justify-center transition-all',
                    isSelected(media.id)
                      ? 'bg-brand-600 border-brand-600'
                      : 'bg-white border-white shadow-sm'
                  ]"
                >
                  <svg
                    v-if="isSelected(media.id)"
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
            </div>
          </div>

          <!-- Sentinelle de scroll infini (+ repli bouton) -->
          <div v-if="hasMorePages" ref="sentinel" class="mt-6 flex justify-center">
            <div v-if="loadingMore" class="flex items-center gap-2 text-sm text-surface-500">
              <svg class="animate-spin h-5 w-5 text-brand-600" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
              Chargement…
            </div>
            <button
              v-else
              type="button"
              class="px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
              @click="handleLoadMore"
            >
              Charger plus
            </button>
          </div>
        </div>

        <!-- Footer -->
        <div class="bg-surface-50 px-6 py-4 flex justify-end gap-3 border-t border-surface-200">
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-brand-500"
            @click="$emit('close')"
          >
            Annuler
          </button>
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-brand-500 disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="selectedIds.length === 0 || submitting"
            @click="confirm"
          >
            <svg
              v-if="submitting"
              class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline"
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
              />
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              />
            </svg>
            Ajouter {{ selectedIds.length }} media(s)
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useIntersectionObserver } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
  albumId: {
    type: String,
    required: true,
  },
  excludeMediaIds: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close', 'added']);

const loading = ref(true);
const loadingMore = ref(false);
const submitting = ref(false);
const availableMedia = ref([]);
const selectedIds = ref([]);

// Pagination serveur (24/page) + scroll infini, comme la galerie principale.
const page = ref(1);
const lastPage = ref(1);
const hasMorePages = computed(() => page.value < lastPage.value);

const scrollContainer = ref(null);
const sentinel = ref(null);
const sentinelVisible = ref(false);

// Charge une page et l'accumule (en excluant les médias déjà dans l'album).
const loadPage = async (pageNum) => {
  const { data } = await axios.get('/media', {
    params: { page: pageNum },
    headers: { Accept: 'application/json' },
  });
  const fresh = (data.data || []).filter((m) => !props.excludeMediaIds.includes(m.id));
  availableMedia.value.push(...fresh);
  page.value = data.current_page ?? pageNum;
  lastPage.value = data.last_page ?? page.value;
};

const handleLoadMore = async () => {
  if (loadingMore.value || loading.value || !hasMorePages.value) return;
  loadingMore.value = true;
  try {
    await loadPage(page.value + 1);
  } catch (error) {
    console.error('Failed to load more media:', error);
  } finally {
    loadingMore.value = false;
  }
};

const maybeLoadMore = () => {
  if (sentinelVisible.value && hasMorePages.value && !loading.value && !loadingMore.value) {
    handleLoadMore();
  }
};

// Le scroll se fait dans le conteneur du modal, pas le viewport -> root explicite.
useIntersectionObserver(
  sentinel,
  ([entry]) => { sentinelVisible.value = entry.isIntersecting; maybeLoadMore(); },
  { root: scrollContainer, rootMargin: '400px 0px' }
);

// Enchaîne si la sentinelle reste visible (ex. page entièrement exclue).
watch(loadingMore, (isLoading, wasLoading) => {
  if (wasLoading && !isLoading) maybeLoadMore();
});

onMounted(async () => {
  try {
    await loadPage(1);
  } catch (error) {
    console.error('Failed to load media:', error);
  } finally {
    loading.value = false;
  }
});

const isSelected = (id) => selectedIds.value.includes(id);

const toggleSelection = (id) => {
  const index = selectedIds.value.indexOf(id);
  if (index === -1) {
    selectedIds.value.push(id);
  } else {
    selectedIds.value.splice(index, 1);
  }
};

const getThumbnailUrl = (media) => {
  if (media.conversions && media.conversions.length > 0) {
    const thumbnail = media.conversions.find(
      (conv) => conv.conversion_name === 'small' || conv.conversion_name === 'thumbnail'
    );
    if (thumbnail && thumbnail.url) {
      return thumbnail.url;
    }
  }
  return media.url || '';
};

const confirm = async () => {
  submitting.value = true;
  try {
    await axios.post(`/albums/${props.albumId}/media`, {
      media_ids: selectedIds.value,
    });
    emit('added', selectedIds.value);
    emit('close');
  } catch (error) {
    console.error('Failed to add media to album:', error);
  } finally {
    submitting.value = false;
  }
};
</script>
