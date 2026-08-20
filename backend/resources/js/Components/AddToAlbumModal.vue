<template>
  <BaseModal panel-class="max-h-[85vh] flex flex-col" title="Ajouter à un album" @close="$emit('close')">
    <!-- Compteur à droite du titre -->
    <template #header-extra>
      <span class="text-sm text-surface-500">
        {{ mediaIds.length }} média{{ mediaIds.length > 1 ? 's' : '' }}
      </span>
    </template>

    <!-- Corps -->
    <div class="flex-1 overflow-y-auto p-6 space-y-5">
      <!-- Mode toggle -->
      <div class="flex rounded-lg bg-surface-100 p-1 text-sm font-medium">
        <button
          type="button"
          class="flex-1 rounded-md px-3 py-1.5 transition"
          :class="mode === 'new' ? 'bg-white text-surface-900 shadow-xs' : 'text-surface-500 hover:text-surface-700'"
          @click="mode = 'new'"
        >
          Nouvel album
        </button>
        <button
          type="button"
          class="flex-1 rounded-md px-3 py-1.5 transition"
          :class="mode === 'existing' ? 'bg-white text-surface-900 shadow-xs' : 'text-surface-500 hover:text-surface-700'"
          @click="mode = 'existing'"
        >
          Album existant
        </button>
      </div>

      <!-- New album -->
      <div v-if="mode === 'new'">
        <label for="new-album-name" class="block text-sm font-medium text-surface-700 mb-1">
          Nom de l'album
        </label>
        <input
          id="new-album-name"
          v-model="newAlbumName"
          type="text"
          autofocus
          placeholder="Ex. Vacances été 2026"
          class="block w-full px-3 py-2 border border-surface-300 rounded-md focus:outline-hidden focus:ring-1 focus:ring-brand-500 focus:border-brand-500 sm:text-sm"
          @keyup.enter="confirm"
        />
      </div>

      <!-- Existing album -->
      <div v-else>
        <div v-if="loadingAlbums" class="flex items-center justify-center py-8 text-surface-500">
          <svg class="animate-spin h-6 w-6 text-brand-600" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
        </div>
        <p v-else-if="ownedAlbums.length === 0" class="text-center py-8 text-sm text-surface-500">
          Vous n'avez pas encore d'album. Créez-en un nouveau.
        </p>
        <ul v-else class="space-y-1.5 max-h-72 overflow-y-auto">
          <li v-for="album in ownedAlbums" :key="album.id">
            <button
              type="button"
              class="w-full flex items-center justify-between px-3 py-2 rounded-lg border text-left transition"
              :class="selectedAlbumId === album.id
                ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500'
                : 'border-surface-200 hover:border-surface-300 hover:bg-surface-50'"
              @click="selectedAlbumId = album.id"
            >
              <span class="font-medium text-surface-800 truncate">{{ album.name }}</span>
              <span class="ml-2 shrink-0 text-xs text-surface-400">
                {{ album.media_count }} média{{ album.media_count > 1 ? 's' : '' }}
              </span>
            </button>
          </li>
        </ul>
      </div>

      <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>

    <!-- Pied de page -->
    <template #footer>
      <button type="button" class="btn-secondary" @click="$emit('close')">
        Annuler
      </button>
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center"
        :disabled="!canConfirm || submitting"
        @click="confirm"
      >
        <svg v-if="submitting" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        {{ mode === 'new' ? 'Créer l\'album' : 'Ajouter' }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BaseModal from '@/Components/BaseModal.vue';
import { fetchOwnedAlbums, createAlbumWithMedia, addMediaToAlbum } from '@/utils/albums';

const props = defineProps({
  // IDs des médias à ajouter/regrouper dans un album.
  mediaIds: {
    type: Array,
    required: true,
  },
});

// `done` : { albumId, albumName, count, isNew } — le parent affiche un lien.
const emit = defineEmits(['close', 'done']);

const mode = ref('new');
const newAlbumName = ref('');
const selectedAlbumId = ref(null);
const ownedAlbums = ref([]);
const loadingAlbums = ref(true);
const submitting = ref(false);
const error = ref('');

const canConfirm = computed(() => {
  if (props.mediaIds.length === 0) return false;
  return mode.value === 'new'
    ? newAlbumName.value.trim().length > 0
    : !!selectedAlbumId.value;
});

// Seuls les albums dont on est propriétaire (et non intelligents) acceptent un
// ajout manuel de médias.
const loadAlbums = async () => {
  loadingAlbums.value = true;
  try {
    ownedAlbums.value = await fetchOwnedAlbums();
  } catch (e) {
    console.error('Chargement des albums impossible :', e);
  } finally {
    loadingAlbums.value = false;
  }
};

const confirm = async () => {
  if (!canConfirm.value || submitting.value) return;
  submitting.value = true;
  error.value = '';
  try {
    if (mode.value === 'new') {
      const album = await createAlbumWithMedia(newAlbumName.value.trim(), props.mediaIds);
      emit('done', {
        albumId: album.id,
        albumName: album.name,
        count: props.mediaIds.length,
        isNew: true,
      });
    } else {
      const album = ownedAlbums.value.find((a) => a.id === selectedAlbumId.value);
      await addMediaToAlbum(selectedAlbumId.value, props.mediaIds);
      emit('done', {
        albumId: selectedAlbumId.value,
        albumName: album?.name ?? '',
        count: props.mediaIds.length,
        isNew: false,
      });
    }
    emit('close');
  } catch (e) {
    error.value = e?.response?.data?.message || "Impossible d'ajouter les médias à l'album.";
    console.error('Ajout à l\'album impossible :', e);
  } finally {
    submitting.value = false;
  }
};

onMounted(() => {
  loadAlbums();
});
</script>
