<template>
  <Head :title="household.name" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <Link href="/households" class="inline-flex items-center text-sm text-surface-500 hover:text-surface-700 mb-6">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
          Retour aux foyers
        </Link>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6 flex items-start justify-between gap-4">
          <div>
            <h1 class="font-display text-2xl font-semibold text-surface-900">{{ household.name }}</h1>
            <p class="mt-1 text-sm text-surface-500">{{ members.length }} membre{{ members.length > 1 ? 's' : '' }}</p>
          </div>
          <button
            v-if="household.is_creator"
            @click="destroyHousehold"
            class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
          >
            Supprimer le foyer
          </button>
          <button
            v-else
            @click="leaveHousehold"
            class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-surface-700 hover:text-surface-900"
          >
            Quitter le foyer
          </button>
        </div>

        <!-- Inviter (créateur) -->
        <div v-if="household.is_creator" class="bg-white rounded-lg shadow-xs p-4 mb-6">
          <label class="block text-sm font-medium text-surface-700 mb-2">Inviter un compte</label>
          <div class="relative">
            <input
              v-model="search"
              type="text"
              placeholder="Rechercher par nom ou e-mail…"
              class="block w-full px-3 py-2 border border-surface-300 rounded-md focus:ring-1 focus:ring-brand-500 focus:border-brand-500"
              @input="onSearch"
            />
            <ul
              v-if="candidates.length > 0"
              class="absolute z-10 mt-1 w-full bg-white border border-surface-200 rounded-md shadow-lg max-h-60 overflow-auto"
            >
              <li
                v-for="c in candidates"
                :key="c.id"
                class="px-3 py-2 hover:bg-surface-50 cursor-pointer flex items-center justify-between"
                @click="invite(c)"
              >
                <span>
                  <span class="font-medium text-surface-800">{{ c.name }}</span>
                  <span class="text-sm text-surface-500 ml-2">{{ c.email }}</span>
                </span>
                <span class="text-brand-600 text-sm font-medium">Ajouter</span>
              </li>
            </ul>
          </div>
        </div>

        <!-- Membres -->
        <div class="bg-white rounded-lg shadow-xs divide-y divide-surface-100">
          <div
            v-for="m in members"
            :key="m.user_id"
            class="flex items-center justify-between px-4 py-3"
          >
            <div>
              <span class="font-medium text-surface-800">{{ m.name }}</span>
              <span class="text-sm text-surface-500 ml-2">{{ m.email }}</span>
              <span
                v-if="m.is_creator"
                class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-brand-100 text-brand-700"
              >
                Créateur
              </span>
            </div>
            <button
              v-if="household.is_creator && !m.is_creator"
              @click="removeMember(m)"
              class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
            >
              Retirer
            </button>
          </div>
        </div>

        <!-- Galerie du foyer -->
        <div class="mt-8">
          <h2 class="text-lg font-semibold text-surface-900 mb-4">
            Photos du foyer
            <span v-if="media.length > 0" class="ml-1 text-sm font-normal text-surface-500">({{ media.length }})</span>
          </h2>

          <div v-if="media.length > 0" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            <MediaCard
              v-for="item in media"
              :key="item.id"
              :media="item"
              @click="handleMediaClick(item)"
            />
          </div>

          <!-- État vide -->
          <div v-else class="text-center py-12 bg-white rounded-lg shadow-xs">
            <svg class="mx-auto h-12 w-12 text-surface-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <h3 class="mt-3 text-sm font-medium text-surface-900">Aucune photo partagée</h3>
            <p class="mt-1 text-sm text-surface-500">
              Depuis la <Link href="/media" class="text-brand-600 dark:text-brand-400 font-medium hover:underline">galerie</Link>,
              sélectionnez des médias puis « Foyer » pour les partager ici.
            </p>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onUnmounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import MediaCard from '@/Components/MediaCard.vue';
import { useToast } from '@/Composables/useToast';
import { usePhotoSwipe } from '@/composables/usePhotoSwipe';

const toast = useToast();

const props = defineProps({
  household: { type: Object, required: true },
  members: { type: Array, default: () => [] },
  media: { type: Array, default: () => [] },
});

// Visionneuse partagée : photos en lightbox, le reste ouvre la fiche média
// (accessible aux membres via la branche foyer de MediaPolicy::view).
const { open: openLightbox } = usePhotoSwipe(() => props.media);

const handleMediaClick = (item) => {
  if (item.type !== 'photo' || !openLightbox(item)) {
    router.visit(`/media/${item.id}`);
  }
};

const search = ref('');
const candidates = ref([]);
let searchTimer = null;
onUnmounted(() => clearTimeout(searchTimer));

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer);
  const q = search.value.trim();
  if (q.length < 2) {
    candidates.value = [];
    return;
  }
  searchTimer = setTimeout(async () => {
    try {
      const { data } = await axios.get(`/households/${props.household.id}/members/candidates`, { params: { q } });
      candidates.value = data;
    } catch {
      candidates.value = [];
    }
  }, 250);
};

const invite = async (candidate) => {
  try {
    await axios.post(`/households/${props.household.id}/members`, { user_id: candidate.id });
    search.value = '';
    candidates.value = [];
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || "Erreur lors de l'invitation.");
  }
};

const removeMember = async (member) => {
  if (!confirm(`Retirer ${member.name} du foyer ?`)) return;
  try {
    await axios.delete(`/households/${props.household.id}/members/${member.user_id}`);
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors du retrait du membre.');
  }
};

const leaveHousehold = async () => {
  if (!confirm('Quitter ce foyer ?')) return;
  try {
    await axios.post(`/households/${props.household.id}/leave`);
    router.visit('/households');
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur : impossible de quitter le foyer.');
  }
};

const destroyHousehold = async () => {
  if (!confirm('Supprimer ce foyer ? Cette action est irréversible.')) return;
  try {
    await axios.delete(`/households/${props.household.id}`);
    router.visit('/households');
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors de la suppression du foyer.');
  }
};
</script>
