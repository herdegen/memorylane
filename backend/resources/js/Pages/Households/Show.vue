<template>
  <Head :title="household.name" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <Link href="/households" class="inline-flex items-center text-sm text-surface-500 hover:text-surface-700 mb-6">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
          Retour aux cercles
        </Link>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6 flex items-start justify-between gap-4">
          <div>
            <div class="flex items-center gap-2">
              <h1 class="font-display text-2xl font-semibold text-surface-900">{{ household.name }}</h1>
              <span
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold"
                :class="household.kind === 'friends' ? 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'"
              >
                <CircleKindIcon :kind="household.kind" class="w-3.5 h-3.5" />
                {{ KIND_LABELS[household.kind] }}
              </span>
            </div>
            <p class="mt-1 text-sm text-surface-500">{{ members.length }} membre{{ members.length > 1 ? 's' : '' }}</p>
          </div>
          <div class="flex items-center gap-1">
            <button
              v-if="household.is_member"
              @click="leaveHousehold"
              class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-surface-700 hover:text-surface-900"
            >
              Quitter le cercle
            </button>
            <button
              v-if="household.can_manage"
              @click="destroyHousehold"
              class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
            >
              Supprimer le cercle
            </button>
          </div>
        </div>

        <!-- Réglage : qui peut faire entrer quelqu'un (administrateur) -->
        <div v-if="household.can_manage" class="bg-white rounded-lg shadow-xs p-4 mb-6 flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="text-sm font-medium text-surface-800">Qui peut inviter dans ce cercle ?</div>
            <div class="text-xs text-surface-500">Invitations par mail et ajout de comptes existants. Retirer un membre reste réservé à l'admin.</div>
          </div>
          <div class="inline-flex rounded-full border border-surface-200 p-0.5 text-sm font-semibold" role="radiogroup" aria-label="Qui peut inviter">
            <button
              v-for="opt in [{ value: 'everyone', label: 'Tous les membres' }, { value: 'admin', label: 'Admin seulement' }]"
              :key="opt.value"
              type="button"
              role="radio"
              :aria-checked="invitePolicy === opt.value"
              class="px-3.5 py-1.5 rounded-full transition-colors"
              :class="invitePolicy === opt.value ? 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200' : 'text-surface-600 hover:text-surface-900'"
              @click="setInvitePolicy(opt.value)"
            >{{ opt.label }}</button>
          </div>
        </div>

        <!-- Ajouter un membre (admin, ou tout membre si le cercle est ouvert) -->
        <div v-if="household.can_invite" class="bg-white rounded-lg shadow-xs p-4 mb-6">
          <label class="block text-sm font-medium text-surface-700 mb-2">Ajouter un compte au cercle</label>
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
              v-if="household.can_manage"
              @click="removeMember(m)"
              class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200"
            >
              Retirer
            </button>
          </div>
        </div>

        <!-- Fiches du cercle d'amis -->
        <div v-if="household.kind === 'friends'" class="mt-8">
          <h2 class="text-lg font-semibold text-surface-900 mb-1">
            Fiches du cercle
            <span v-if="people.length" class="ml-1 text-sm font-normal text-surface-500">({{ people.length }})</span>
          </h2>
          <p class="text-sm text-surface-500 mb-4">Visibles des seuls membres du cercle. Une fiche créée depuis ce cercle y est rattachée automatiquement.</p>

          <div v-if="household.can_manage" class="relative mb-4 max-w-md">
            <input
              v-model="personSearch"
              type="text"
              placeholder="Ajouter une fiche existante…"
              aria-label="Ajouter une fiche existante"
              class="form-input"
              @input="onPersonSearch"
            />
            <ul v-if="personCandidates.length" class="absolute z-10 mt-1 w-full bg-white border border-surface-200 rounded-md shadow-lg max-h-60 overflow-auto">
              <li v-for="p in personCandidates" :key="p.id">
                <button type="button" class="w-full px-3 py-2 hover:bg-surface-50 flex items-center justify-between text-left" @click="addPerson(p)">
                  <span class="font-medium text-surface-800">{{ p.name }}</span>
                  <span class="text-brand-600 text-sm font-medium">Ajouter</span>
                </button>
              </li>
            </ul>
          </div>

          <div v-if="people.length" class="flex flex-wrap gap-2">
            <span
              v-for="p in people"
              :key="p.id"
              class="inline-flex items-center gap-2 pl-3 pr-1.5 py-1 rounded-full bg-white border border-surface-200 text-sm"
            >
              <Link :href="`/people/${p.id}`" class="font-medium text-surface-800 hover:text-brand-700">{{ p.name }}</Link>
              <button
                v-if="household.can_manage"
                type="button"
                class="w-6 h-6 rounded-full text-surface-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10"
                :aria-label="`Retirer ${p.name} du cercle`"
                @click="removePerson(p)"
              >×</button>
            </span>
          </div>
          <p v-else class="text-sm text-surface-500">Aucune fiche pour l'instant.</p>
        </div>

        <!-- Galerie du cercle -->
        <div class="mt-8">
          <h2 class="text-lg font-semibold text-surface-900 mb-4">
            Photos du cercle
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
              Depuis <Link href="/media" class="text-brand-600 dark:text-brand-400 font-medium hover:underline">Mes photos</Link>,
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
import CircleKindIcon from '@/Components/CircleKindIcon.vue';
import { KIND_LABELS } from '@/Composables/useCircle';
import MediaCard from '@/Components/MediaCard.vue';
import { useToast } from '@/Composables/useToast';
import { usePhotoSwipe } from '@/Composables/usePhotoSwipe';

const toast = useToast();

const props = defineProps({
  household: { type: Object, required: true },
  members: { type: Array, default: () => [] },
  media: { type: Array, default: () => [] },
  people: { type: Array, default: () => [] },
});

// Visionneuse partagée : photos et vidéos en lightbox (bouton « i » vers la
// fiche, accessible aux membres via la branche foyer de MediaPolicy::view) ;
// les documents ouvrent directement la fiche.
const { open: openLightbox } = usePhotoSwipe(() => props.media, {
  watchSource: () => props.media,
  detailLink: true,
});

const handleMediaClick = (item) => {
  if (!openLightbox(item)) {
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
  if (!confirm(`Retirer ${member.name} du cercle ?`)) return;
  try {
    await axios.delete(`/households/${props.household.id}/members/${member.user_id}`);
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors du retrait du membre.');
  }
};

// ---- Qui peut inviter (administrateur) ----
const invitePolicy = ref(props.household.invite_policy);

const setInvitePolicy = async (value) => {
  if (value === invitePolicy.value) return;
  const previous = invitePolicy.value;
  invitePolicy.value = value;
  try {
    await axios.patch(`/households/${props.household.id}`, { invite_policy: value });
  } catch (error) {
    invitePolicy.value = previous;
    toast.error(error.response?.data?.message || 'Impossible de modifier le réglage.');
  }
};

// ---- Fiches du cercle d'amis (administrateur) ----
const personSearch = ref('');
const personCandidates = ref([]);
let personTimer = null;
onUnmounted(() => clearTimeout(personTimer));

const onPersonSearch = () => {
  clearTimeout(personTimer);
  const q = personSearch.value.trim();
  if (q.length < 2) {
    personCandidates.value = [];
    return;
  }
  personTimer = setTimeout(async () => {
    try {
      const { data } = await axios.get(`/households/${props.household.id}/people/candidates`, { params: { q } });
      personCandidates.value = data;
    } catch {
      personCandidates.value = [];
    }
  }, 250);
};

const addPerson = async (p) => {
  try {
    await axios.post(`/households/${props.household.id}/people`, { person_id: p.id });
    personSearch.value = '';
    personCandidates.value = [];
    router.reload({ only: ['people'] });
  } catch (error) {
    toast.error(error.response?.data?.message || "Erreur lors de l'ajout.");
  }
};

const removePerson = async (p) => {
  if (!confirm(`Retirer la fiche ${p.name} du cercle ? Ses membres ne la verront plus.`)) return;
  try {
    await axios.delete(`/households/${props.household.id}/people/${p.id}`);
    router.reload({ only: ['people'] });
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors du retrait.');
  }
};

const leaveHousehold = async () => {
  if (!confirm('Quitter ce cercle ?')) return;
  try {
    await axios.post(`/households/${props.household.id}/leave`);
    router.visit('/households');
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur : impossible de quitter le cercle.');
  }
};

const destroyHousehold = async () => {
  if (!confirm('Supprimer ce cercle ? Cette action est irréversible.')) return;
  try {
    await axios.delete(`/households/${props.household.id}`);
    router.visit('/households');
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors de la suppression du cercle.');
  }
};
</script>
