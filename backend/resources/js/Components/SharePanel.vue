<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-sm p-6 mb-6">
    <h3 class="text-lg font-semibold text-surface-900 mb-4">Partage</h3>

    <!-- Public Toggle -->
    <div class="flex items-center justify-between py-3 border-b border-surface-200">
      <div>
        <p class="text-sm font-medium text-surface-700">Album public</p>
        <p class="text-xs text-surface-500">Visible par tous les utilisateurs connectes</p>
      </div>
      <button
        type="button"
        :class="[
          'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2',
          isPublic ? 'bg-brand-600' : 'bg-surface-200'
        ]"
        @click="togglePublic"
      >
        <span
          :class="[
            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
            isPublic ? 'translate-x-5' : 'translate-x-0'
          ]"
        />
      </button>
    </div>

    <!-- Share Link Section -->
    <div class="py-4">
      <p class="text-sm font-medium text-surface-700 mb-2">Lien de partage</p>
      <p class="text-xs text-surface-500 mb-3">
        Partagez ce lien pour permettre a n'importe qui de voir l'album
      </p>

      <div v-if="shareUrl" class="space-y-3">
        <!-- Share URL Display -->
        <div class="flex items-center gap-2">
          <input
            type="text"
            :value="shareUrl"
            readonly
            class="flex-1 px-3 py-2 text-sm border border-surface-300 rounded-lg bg-surface-50"
          />
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 rounded-lg hover:bg-brand-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
            @click="copyLink"
          >
            {{ copied ? 'Copie !' : 'Copier' }}
          </button>
        </div>

        <!-- Revoke Button -->
        <button
          type="button"
          class="text-sm text-red-600 hover:text-red-800"
          @click="revokeLink"
        >
          Revoquer le lien
        </button>
      </div>

      <div v-else>
        <button
          type="button"
          class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500"
          :disabled="generating"
          @click="generateLink"
        >
          <svg
            v-if="generating"
            class="animate-spin -ml-1 mr-2 h-4 w-4 text-white"
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
          <svg
            v-else
            class="-ml-1 mr-2 h-4 w-4"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"
            />
          </svg>
          Generer un lien
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
  album: {
    type: Object,
    required: true,
  },
});

const emit = defineEmits(['updated']);

const isPublic = ref(props.album.is_public);
const shareUrl = ref(props.album.share_url);
const copied = ref(false);
const generating = ref(false);

const togglePublic = async () => {
  try {
    const response = await axios.put(`/albums/${props.album.id}`, {
      name: props.album.name,
      description: props.album.description,
      is_public: !isPublic.value,
    });
    isPublic.value = !isPublic.value;
    emit('updated');
  } catch (error) {
    console.error('Failed to update album visibility:', error);
  }
};

const generateLink = async () => {
  generating.value = true;
  try {
    const response = await axios.post(`/albums/${props.album.id}/share`);
    shareUrl.value = response.data.share_url;
    emit('updated');
  } catch (error) {
    console.error('Failed to generate share link:', error);
  } finally {
    generating.value = false;
  }
};

const revokeLink = async () => {
  if (!confirm('Etes-vous sur de vouloir revoquer ce lien ? Les personnes ayant le lien ne pourront plus acceder a l\'album.')) {
    return;
  }
  try {
    await axios.delete(`/albums/${props.album.id}/share`);
    shareUrl.value = null;
    emit('updated');
  } catch (error) {
    console.error('Failed to revoke share link:', error);
  }
};

const copyLink = async () => {
  try {
    await navigator.clipboard.writeText(shareUrl.value);
    copied.value = true;
    setTimeout(() => {
      copied.value = false;
    }, 2000);
  } catch (error) {
    console.error('Failed to copy link:', error);
  }
};
</script>
