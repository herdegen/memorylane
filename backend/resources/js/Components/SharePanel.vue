<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-xs p-6 mb-6">
    <h3 class="text-lg font-semibold text-surface-900 mb-4">{{ $t('Partage') }}</h3>

    <!-- Erreur d'action de partage -->
    <div v-if="errorMessage" class="mb-3 px-3 py-2 text-sm text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-500/10 rounded-lg flex items-start justify-between gap-2">
      <span>{{ errorMessage }}</span>
      <button type="button" class="text-red-500 hover:text-red-700 dark:hover:text-red-200" @click="errorMessage = null">✕</button>
    </div>

    <!-- Share Link Section (lien anonyme — propriétaire uniquement) -->
    <div v-if="isOwner" class="py-4">
      <p class="text-sm font-medium text-surface-700 mb-2">{{ $t('Lien de partage') }}</p>
      <p class="text-xs text-surface-500 mb-3">
        {{ $t('Partagez ce lien pour permettre à n\'importe qui de voir l\'album') }}
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
            {{ copied ? $t('Copié !') : $t('Copier') }}
          </button>
        </div>

        <!-- Revoke Button -->
        <button
          type="button"
          class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
          @click="revokeLink"
        >
          {{ $t('Révoquer le lien') }}
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
          {{ $t('Générer un lien') }}
        </button>
      </div>
    </div>

    <!-- Partage avec un cercle entier (membres actuels et futurs) -->
    <div v-if="circlesShared.length || circlesAvailable.length" class="py-4 border-t border-surface-200">
      <p class="text-sm font-medium text-surface-700 mb-2">{{ $t('Cercles') }}</p>
      <p class="text-xs text-surface-500 mb-3">
        {{ $t('Tous les membres du cercle voient l\'album, y compris ceux qui le rejoindront plus tard.') }}
      </p>
      <div class="flex flex-wrap gap-2">
        <span
          v-for="c in circlesShared"
          :key="c.id"
          class="inline-flex items-center gap-1.5 h-9 pl-2 pr-1.5 rounded-full text-sm font-semibold border"
          :class="c.kind === 'friends' ? 'border-teal-300 bg-teal-50 text-teal-800 dark:bg-teal-500/10 dark:text-teal-200' : 'border-amber-300 bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200'"
        >
          <CircleKindIcon :kind="c.kind" class="w-4 h-4" />
          {{ c.name }}
          <button
            v-if="c.can_revoke"
            type="button"
            class="w-6 h-6 rounded-full hover:bg-black/10"
            :aria-label="$t('Ne plus partager avec :name', { name: c.name })"
            @click="unshareCircle(c)"
          >×</button>
        </span>
        <button
          v-for="c in circlesAvailable"
          :key="c.id"
          type="button"
          class="inline-flex items-center gap-1.5 h-9 px-3 rounded-full text-sm font-semibold border border-dashed border-surface-300 text-surface-600 hover:border-surface-400 hover:text-surface-900"
          @click="shareCircle(c)"
        >
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
          {{ c.name }}
        </button>
      </div>
    </div>

    <!-- Accès par compte (partage restreint + délégation) -->
    <div class="py-4 border-t border-surface-200">
      <p class="text-sm font-medium text-surface-700 mb-2">{{ $t('Personnes ayant accès') }}</p>
      <p class="text-xs text-surface-500 mb-3">
        {{ $t('Donnez accès à des membres précis (ils pourront le repartager, mais pas le rendre public).') }}
      </p>

      <!-- Recherche / ajout -->
      <div class="relative mb-3">
        <input
          v-model="search"
          type="text"
          :placeholder="$t('Ajouter un membre (nom ou email)…')"
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

      <!-- Réglage permanent : l'album entier pour toute personne identifiée dedans -->
      <label class="mb-3 flex items-start gap-3 rounded-xl border border-surface-200 px-3 py-2.5 cursor-pointer hover:bg-surface-50">
        <input
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-surface-300 text-brand-600 focus:ring-brand-500"
          :checked="shareWithTagged"
          :disabled="savingShareWithTagged"
          @change="toggleShareWithTagged($event.target.checked)"
        />
        <span class="text-sm">
          <span class="font-medium text-surface-900">{{ $t('Partager tout l\'album avec les personnes identifiées dedans') }}</span>
          <span class="block text-xs text-surface-500 mt-0.5">{{ $t('Y compris celles identifiées plus tard. Une association automatique non vérifiée ne suffit pas.') }}</span>
        </span>
      </label>

      <!-- Identifiées sur des photos mais sans l'album : l'ouvrir d'un coup -->
      <div
        v-if="taggedCount > 0 && !shareWithTagged"
        class="mb-3 rounded-xl border border-brand-200 bg-brand-50 dark:border-brand-500/30 dark:bg-brand-500/10 px-3 py-2.5 flex flex-wrap items-center gap-2"
      >
        <p class="flex-1 min-w-[12rem] text-sm text-surface-800">
          {{ $tc(':count personne identifiée sur les photos ne voit que ses photos.|:count personnes identifiées sur les photos ne voient que leurs photos.', taggedCount) }}
        </p>
        <button
          type="button"
          class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50"
          :disabled="grantingTagged"
          @click="grantTagged"
        >
          {{ grantingTagged ? $t('Partage…') : $tc('Lui donner accès|Donner accès aux :count', taggedCount) }}
        </button>
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
            {{ $t('Retirer') }}
          </button>
          <button
            v-else-if="a.origin === 'tagged'"
            type="button"
            class="text-xs font-semibold text-brand-700 dark:text-brand-400 hover:underline"
            @click="grant({ id: a.user_id })"
          >
            {{ $t('Donner accès') }}
          </button>
          <span v-else class="text-xs text-surface-400">
            {{ a.origin === 'owner' ? $t('Propriétaire') : (a.origin === 'tagged_shared' ? $t('Identifié·e') : $t('Cercle')) }}
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useToast } from '@/Composables/useToast';
import CircleKindIcon from '@/Components/CircleKindIcon.vue';
import axios from 'axios';
import { useConfirm } from '@/Composables/useConfirm';
import { t } from '@/i18n';

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
const { confirm } = useConfirm();
const toast = useToast();

const shareUrl = ref(props.album.share_url);
const copied = ref(false);
const generating = ref(false);
const errorMessage = ref(null);

// Accès par compte (partage restreint)
const accesses = ref([]);
const search = ref('');
const candidates = ref([]);
let searchTimer = null;

// ---- Cercles ----
const circlesShared = ref([]);
const circlesAvailable = ref([]);

const applyCircles = (data) => {
  circlesShared.value = data.shared;
  circlesAvailable.value = data.available;
};

const loadCircles = async () => {
  try {
    const { data } = await axios.get(`/albums/${props.album.id}/circles`);
    applyCircles(data);
  } catch (e) {
    console.error('Failed to load circles:', e);
  }
};

const shareCircle = async (c) => {
  try {
    const { data } = await axios.post(`/albums/${props.album.id}/circles`, { household_id: c.id });
    applyCircles(data);
    emit('updated');
  } catch (e) {
    errorMessage.value = e.response?.data?.message || t('Impossible de partager avec ce cercle.');
  }
};

const unshareCircle = async (c) => {
  try {
    const { data } = await axios.delete(`/albums/${props.album.id}/circles/${c.id}`);
    applyCircles(data);
    emit('updated');
  } catch (e) {
    errorMessage.value = e.response?.data?.message || t('Impossible de retirer ce cercle.');
  }
};

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
    errorMessage.value = e.response?.data?.message || t("Impossible d'ajouter cet accès.");
  }
};

const revoke = async (access) => {
  try {
    await axios.delete(`/albums/${props.album.id}/access`, { data: { user_id: access.user_id } });
    await loadAccesses();
    emit('updated');
  } catch (e) {
    errorMessage.value = e.response?.data?.message || t('Impossible de retirer cet accès.');
  }
};

// Identifiées sur des photos, sans l'album entier.
const taggedCount = computed(() => accesses.value.filter((a) => a.origin === 'tagged').length);
const grantingTagged = ref(false);
const grantTagged = async () => {
  grantingTagged.value = true;
  try {
    const { data } = await axios.post(`/albums/${props.album.id}/access/tagged`);
    toast.success(data.message);
    await loadAccesses();
    emit('updated');
  } catch (e) {
    errorMessage.value = e.response?.data?.message || t('Impossible de partager avec les personnes identifiées.');
  } finally {
    grantingTagged.value = false;
  }
};

const shareWithTagged = ref(!!props.album.share_with_tagged);
const savingShareWithTagged = ref(false);
const toggleShareWithTagged = async (enabled) => {
  savingShareWithTagged.value = true;
  errorMessage.value = null;
  try {
    const { data } = await axios.put(`/albums/${props.album.id}/share-with-tagged`, { enabled });
    shareWithTagged.value = data.share_with_tagged;
    await loadAccesses();
  } catch (e) {
    errorMessage.value = e.response?.data?.message || t('Impossible de modifier ce réglage.');
  } finally {
    savingShareWithTagged.value = false;
  }
};

const originLabel = (a) => {
  if (a.origin === 'owner') return t('Propriétaire');
  if (a.origin === 'tagged') return t('Voit seulement les photos où il apparaît');
  if (a.origin === 'circle') return t('Voit l\'album via un cercle');
  if (a.origin === 'tagged_shared') return t('Voit tout l\'album (identifié·e sur une photo)');
  return a.granted_by ? t('Ajouté par :name', { name: a.granted_by }) : t('Ajouté');
};

onMounted(() => {
  loadAccesses();
  loadCircles();
});
onUnmounted(() => clearTimeout(searchTimer));

const generateLink = async () => {
  generating.value = true;
  try {
    const response = await axios.post(`/albums/${props.album.id}/share`);
    shareUrl.value = response.data.share_url;
    emit('updated');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || t('Impossible de générer le lien de partage.');
  } finally {
    generating.value = false;
  }
};

const revokeLink = async () => {
  const ok = await confirm({
    title: t('Révoquer le lien de partage'),
    message: t("Les personnes ayant le lien ne pourront plus accéder à l'album."),
    confirmLabel: t('Révoquer'),
    danger: true,
  });
  if (!ok) return;
  try {
    await axios.delete(`/albums/${props.album.id}/share`);
    shareUrl.value = null;
    emit('updated');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || t('Impossible de révoquer le lien de partage.');
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
