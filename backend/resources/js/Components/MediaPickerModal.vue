<template>
  <BaseModal max-width="4xl" panel-class="max-h-[80vh] flex flex-col" title="Ajouter des médias" @close="$emit('close')">
    <!-- Compteur à droite du titre -->
    <template #header-extra>
      <span v-if="mode === 'pick'" class="text-sm text-surface-500">
        {{ selectedIds.length }} sélectionné(s)
      </span>
    </template>

    <!-- Choisir dans l'existant / téléverser de nouveaux fichiers -->
    <div class="px-6 pt-5">
      <div class="flex rounded-lg bg-surface-100 p-1 text-sm font-medium">
        <button
          type="button"
          class="flex-1 rounded-md px-3 py-1.5 transition"
          :class="mode === 'pick' ? 'bg-white text-surface-900 shadow-xs' : 'text-surface-500 hover:text-surface-700'"
          @click="mode = 'pick'"
        >
          Mes photos
        </button>
        <button
          type="button"
          class="flex-1 rounded-md px-3 py-1.5 transition"
          :class="mode === 'upload' ? 'bg-white text-surface-900 shadow-xs' : 'text-surface-500 hover:text-surface-700'"
          @click="mode = 'upload'"
        >
          Téléverser
        </button>
      </div>
    </div>

    <!-- Téléversement direct dans l'album -->
    <div v-if="mode === 'upload'" class="flex-1 overflow-y-auto p-6">
      <p v-if="uploadedCount > 0" class="mb-4 text-sm text-teal-700 dark:text-teal-300">
        {{ uploadedCount }} média(s) ajouté(s) à l'album.
      </p>
      <MediaUploader
        :target-album-id="albumId"
        @album-attached="handleUploadedToAlbum"
      />
    </div>

    <!-- Loading -->
    <div v-else-if="loading" class="flex-1 flex items-center justify-center py-12">
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
        <p class="mt-4 text-surface-500">Aucun média disponible</p>
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
              : 'hover:ring-2 hover:ring-surface-300'
          ]"
          @click="toggleSelection(media.id)"
        >
          <!-- Thumbnail -->
          <img
            v-if="media.type === 'photo' || media.type === 'video'"
            :src="thumbnailUrl(media)"
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

    <!-- Erreur d'ajout -->
    <div v-if="errorMessage" class="px-6 py-3 text-sm text-red-700 bg-red-50 dark:bg-red-500/10 dark:text-red-300 border-t border-red-200">
      {{ errorMessage }}
    </div>

    <!-- Pied de page -->
    <template #footer>
      <button type="button" class="btn-secondary" @click="$emit('close')">
        {{ mode === 'upload' ? 'Fermer' : 'Annuler' }}
      </button>
      <button
        v-if="mode === 'pick'"
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
        Ajouter {{ selectedIds.length }} média(s)
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useIntersectionObserver } from '@vueuse/core';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import MediaUploader from '@/Components/MediaUploader.vue';
import { thumbnailUrl } from '@/utils/media';

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

// 'pick' = choisir dans les médias existants ; 'upload' = téléverser de
// nouveaux fichiers directement dans l'album.
const mode = ref('pick');
const uploadedCount = ref(0);

// Upload terminé et rattaché à l'album : on prévient le parent (rafraîchit
// la galerie derrière la modale) et on affiche le cumul.
const handleUploadedToAlbum = ({ count }) => {
  uploadedCount.value += count;
  emit('added');
};

const loading = ref(true);
const loadingMore = ref(false);
const submitting = ref(false);
const errorMessage = ref(null);
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

const confirm = async () => {
  submitting.value = true;
  errorMessage.value = null;
  try {
    await axios.post(`/albums/${props.albumId}/media`, {
      media_ids: selectedIds.value,
    });
    emit('added', selectedIds.value);
    emit('close');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || "Impossible d'ajouter les médias à l'album.";
  } finally {
    submitting.value = false;
  }
};
</script>
