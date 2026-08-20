<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-xs p-6 mb-6">
    <h3 class="text-lg font-semibold text-surface-900 mb-4">Partage</h3>

    <!-- Erreur d'action de partage -->
    <div v-if="errorMessage" class="mb-3 px-3 py-2 text-sm text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-500/10 rounded-lg flex items-start justify-between gap-2">
      <span>{{ errorMessage }}</span>
      <button type="button" class="text-red-500 hover:text-red-700 dark:hover:text-red-200" @click="errorMessage = null">✕</button>
    </div>

    <!-- Public Toggle (propriétaire uniquement) -->
    <div v-if="isOwner" class="flex items-center justify-between py-3 border-b border-surface-200">
      <div>
        <p class="text-sm font-medium text-surface-700">Album public</p>
        <p class="text-xs text-surface-500">Visible par tous les utilisateurs connectés</p>
      </div>
      <button
        type="button"
        :class="[
          'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:ring-offset-2',
          isPublic ? 'bg-brand-600' : 'bg-surface-200'
        ]"
        @click="togglePublic"
      >
        <span
          :class="[
            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-in-out',
            isPublic ? 'translate-x-5' : 'translate-x-0'
          ]"
        />
      </button>
    </div>

    <!-- Share Link Section (lien anonyme — propriétaire uniquement) -->
    <div v-if="isOwner" class="py-4">
      <p class="text-sm font-medium text-surface-700 mb-2">Lien de partage</p>
      <p class="text-xs text-surface-500 mb-3">
        Partagez ce lien pour permettre à n'importe qui de voir l'album
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
            class="px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 rounded-lg hover:bg-brand-100 focus:outline-hidden focus:ring-2 focus:ring-brand-500"
            @click="copyLink"
          >
            {{ copied ? 'Copié !' : 'Copier' }}
          </button>
        </div>

        <!-- Revoke Button -->
        <button
          type="button"
          class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
          @click="revokeLink"
        >
          Révoquer le lien
        </button>
      </div>

      <div v-else>
        <button
          type="button"
          class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 focus:outline-hidden focus:ring-2 focus:ring-brand-500"
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
          Générer un lien
        </button>
      </div>
    </div>

    <!-- Accès par compte (partage restreint + délégation) -->
    <div class="py-4 border-t border-surface-200">
      <p class="text-sm font-medium text-surface-700 mb-2">Personnes ayant accès</p>
      <p class="text-xs text-surface-500 mb-3">
        Donnez accès à des membres précis (ils pourront le repartager, mais pas le rendre public).
      </p>

      <!-- Recherche / ajout -->
      <div class="relative mb-3">
        <input
          v-model="search"
          type="text"
          placeholder="Ajouter un membre (nom ou email)…"
          class="w-full px-3 py-2 text-sm border border-surface-300 rounded-lg focus:ring-brand-500 focus:border-brand-500"
          @input="searchCandidates"
        />
        <div
          v-if="candidates.length"
          class="absolute z-10 mt-1 w-full bg-white border border-surface-200 rounded-lg shadow-sm max-h-48 overflow-y-auto"
        >
          <button
            v-for="c in candidates"
            :key="c.id"
            type="button"
            class="w-full px-3 py-2 text-left text-sm hover:bg-brand-50 flex flex-col"
            @click="grant(c)"
          >
            <span class="text-surface-900">{{ c.name }}</span>
            <span class="text-xs text-surface-500">{{ c.email }}</span>
          </button>
        </div>
      </div>

      <!-- Liste des accès -->
      <ul class="space-y-1">
        <li
          v-for="a in accesses"
          :key="a.user_id"
          class="flex items-center justify-between text-sm py-1"
        >
          <span class="flex flex-col">
            <span class="text-surface-900">{{ a.name }}</span>
            <span class="text-xs text-surface-500">{{ originLabel(a) }}</span>
          </span>
          <button
            v-if="a.origin === 'granted'"
            type="button"
            class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
            @click="revoke(a)"
          >
            Retirer
          </button>
          <span v-else class="text-xs text-surface-400">
            {{ a.origin === 'owner' ? 'Propriétaire' : 'Tagué' }}
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const props = defineProps({
  album: {
    type: Object,
    required: true,
  },
  // Le toggle public et le lien anonyme sont réservés au propriétaire ;
  // les délégués ne voient que la gestion des accès.
  isOwner: {
    type: Boolean,
    default: true,
  },
});

const emit = defineEmits(['updated']);

const isPublic = ref(props.album.is_public);
const shareUrl = ref(props.album.share_url);
const copied = ref(false);
const generating = ref(false);
const errorMessage = ref(null);

// Accès par compte (partage restreint)
const accesses = ref([]);
const search = ref('');
const candidates = ref([]);
let searchTimer = null;

const loadAccesses = async () => {
  try {
    const { data } = await axios.get(`/albums/${props.album.id}/access`);
    accesses.value = data;
  } catch (e) {
    console.error('Failed to load access list:', e);
  }
};

const searchCandidates = () => {
  clearTimeout(searchTimer);
  const q = search.value.trim();
  if (q.length < 2) {
    candidates.value = [];
    return;
  }
  searchTimer = setTimeout(async () => {
    try {
      const { data } = await axios.get(`/albums/${props.album.id}/access/candidates`, { params: { q } });
      candidates.value = data;
    } catch (e) {
      console.error('Candidate search failed:', e);
    }
  }, 250);
};

const grant = async (candidate) => {
  try {
    await axios.post(`/albums/${props.album.id}/access`, { user_id: candidate.id });
    search.value = '';
    candidates.value = [];
    await loadAccesses();
    emit('updated');
  } catch (e) {
    errorMessage.value = e.response?.data?.message || "Impossible d'ajouter cet accès.";
  }
};

const revoke = async (access) => {
  try {
    await axios.delete(`/albums/${props.album.id}/access`, { data: { user_id: access.user_id } });
    await loadAccesses();
    emit('updated');
  } catch (e) {
    errorMessage.value = e.response?.data?.message || 'Impossible de retirer cet accès.';
  }
};

const originLabel = (a) => {
  if (a.origin === 'owner') return 'Propriétaire';
  if (a.origin === 'tagged') return 'Tagué sur une photo';
  return 'Ajouté' + (a.granted_by ? ` par ${a.granted_by}` : '');
};

onMounted(loadAccesses);
onUnmounted(() => clearTimeout(searchTimer));

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
    errorMessage.value = error.response?.data?.message || "Impossible de changer la visibilité de l'album.";
  }
};

const generateLink = async () => {
  generating.value = true;
  try {
    const response = await axios.post(`/albums/${props.album.id}/share`);
    shareUrl.value = response.data.share_url;
    emit('updated');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Impossible de générer le lien de partage.';
  } finally {
    generating.value = false;
  }
};

const revokeLink = async () => {
  if (!confirm('Êtes-vous sûr de vouloir révoquer ce lien ? Les personnes ayant le lien ne pourront plus accéder à l\'album.')) {
    return;
  }
  try {
    await axios.delete(`/albums/${props.album.id}/share`);
    shareUrl.value = null;
    emit('updated');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Impossible de révoquer le lien de partage.';
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
