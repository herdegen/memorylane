<template>
  <Head :title="personLabel(person)" />
  <AppLayout>
    <!-- ================= En-tête doux pleine largeur ================= -->
    <div class="relative bg-linear-to-br from-brand-100 via-page to-page border-b border-surface-200">
      <!-- Actions flottantes -->
      <div class="absolute top-4 right-4 sm:top-5 sm:right-8 z-20 flex items-center gap-2.5">
        <Link
          v-if="viewerPersonId && viewerPersonId !== person.id"
          :href="`/family-tree?kinship=${person.id}`"
          class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/90 backdrop-blur text-sm font-semibold text-surface-900 shadow-warm-md hover:bg-white transition"
          title="Voir notre lien de parenté dans l'arbre"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
          </svg>
          Lien de parenté
        </Link>
        <button
          @click="playLifeSlideshow"
          class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/90 backdrop-blur text-sm font-semibold text-surface-900 shadow-warm-md hover:bg-white transition"
        >
          <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
          Diaporama de sa vie
        </button>
        <div class="relative" ref="actionsMenuRef">
          <button
            @click="showActionsMenu = !showActionsMenu"
            class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/90 backdrop-blur text-surface-900 shadow-warm-md hover:bg-white transition"
            title="Plus d'actions"
            aria-label="Plus d'actions"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <circle cx="12" cy="5" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="19" r="1.6" />
            </svg>
          </button>
          <div v-show="showActionsMenu" class="dropdown top-full w-56">
            <button @click="menuAction(toggleSelf)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4" :class="isSelf ? 'text-brand-600' : 'text-surface-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
              {{ isSelf ? "C'est moi ✓ (retirer)" : "C'est moi" }}
            </button>
            <button v-if="canManage" @click="menuAction(() => showEditModal = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
              Modifier la fiche
            </button>
            <button v-if="canManage && media.length > 0" @click="menuAction(() => showAvatarPicker = true)" class="dropdown-item w-full text-left flex items-center gap-2.5">
              <svg class="w-4 h-4 text-surface-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              Choisir un avatar
            </button>
            <template v-if="canManage">
              <hr class="dropdown-divider" />
              <button @click="menuAction(deletePerson)" class="dropdown-item w-full text-left flex items-center gap-2.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                Supprimer
              </button>
            </template>
          </div>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row sm:items-center gap-5 sm:gap-7 px-4 sm:px-8 pt-9 pb-8">
        <Link
          href="/people"
          class="hidden sm:inline-flex items-center justify-center w-11 h-11 rounded-full bg-white border border-surface-200 text-surface-600 shadow-warm-sm hover:bg-surface-50 transition shrink-0"
          title="Retour aux personnes"
          aria-label="Retour aux personnes"
        >
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>

        <!-- Avatar (clic = choisir parmi les photos) -->
        <div class="relative group shrink-0">
          <div class="w-32 h-32 rounded-full overflow-hidden bg-brand-100 border-4 border-white shadow-warm-md flex items-center justify-center">
            <img
              v-if="person.avatar_url"
              :src="person.avatar_url"
              :alt="person.name"
              class="w-full h-full object-cover"
              :style="person.avatar_position ? { objectPosition: person.avatar_position } : undefined"
            />
            <span v-else class="text-5xl font-bold text-brand-700">
              {{ person.name.charAt(0).toUpperCase() }}
            </span>
          </div>
          <button
            v-if="canManage && media.length > 0"
            @click="showAvatarPicker = true"
            class="absolute inset-0 rounded-full bg-black/0 group-hover:bg-black/40 flex items-center justify-center transition-all"
            title="Changer l'avatar"
          >
            <svg class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </button>
        </div>

        <div class="min-w-0">
          <div class="text-xs font-semibold uppercase tracking-widest text-brand-700">Personnes</div>
          <h1 class="font-display text-3xl sm:text-[42px] font-bold text-surface-900 leading-tight">
            {{ personLabel(person) }}
            <span v-if="person.gender === 'M'" class="text-surface-400 text-2xl align-middle" title="Masculin">&#9794;</span>
            <span v-else-if="person.gender === 'F'" class="text-surface-400 text-2xl align-middle" title="Féminin">&#9792;</span>
          </h1>
          <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-surface-500">
            <span v-if="person.birth_date">
              {{ formatLongDate(person.birth_date) }}{{ person.death_date ? ' – ' + formatLongDate(person.death_date) : '' }}
            </span>
            <span v-if="person.birth_date">·</span>
            <span>{{ person.media_count || 0 }} {{ (person.media_count || 0) === 1 ? 'média' : 'médias' }}</span>
            <span
              v-if="isSelf"
              class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-brand-100 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 text-xs font-medium"
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
              C'est moi
            </span>
          </div>
          <p v-if="person.notes" class="mt-2 text-sm text-surface-600 whitespace-pre-wrap max-w-2xl">
            {{ person.notes }}
          </p>
        </div>
      </div>
    </div>

    <!-- ================= Famille (mini-arbre) ================= -->
    <FamilyPanel
      :person="person"
      :father="father"
      :mother="mother"
      :spouses="spouses"
      :children="children"
      :siblings="siblings"
      :can-manage="canManage"
    />

    <!-- ================= Sa vie (frise horizontale) ================= -->
    <PersonTimeline ref="timelineRef" :person-id="person.id" :person-name="personLabel(person)" :can-manage="canManage" />

    <!-- ================= Galeries, séparées par album puis par année ================= -->
    <div v-if="media.length > 0" class="pt-9 pb-12">
      <div class="flex items-center justify-between px-4 sm:px-8 mb-2">
        <h2 class="font-display text-2xl font-semibold text-surface-900">Ses photos</h2>
        <span class="text-sm text-surface-400">{{ media.length }} médias visibles</span>
      </div>

      <!-- Une section par album -->
      <div
        v-for="album in mediaGroups.albums"
        :key="'album-' + album.id"
        class="pt-5"
      >
        <div class="flex items-baseline gap-2.5 px-4 sm:px-8 mb-3">
          <svg class="w-4.5 h-4.5 text-brand-600 shrink-0 self-center" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V6z" />
          </svg>
          <span class="text-base font-semibold text-surface-900">{{ album.name }}</span>
          <span class="text-sm text-surface-400">{{ album.media_ids.length }}</span>
          <Link :href="`/albums/${album.id}`" class="ml-auto text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline shrink-0">
            Voir l'album →
          </Link>
        </div>
        <div class="grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-8 gap-1 px-1">
          <MediaCard
            v-for="id in album.media_ids"
            :key="id"
            :media="mediaById[id]"
            fill
            class="aspect-square"
            @click="goToMedia(mediaById[id])"
          />
        </div>
        <div class="border-t border-surface-200/70 mx-4 sm:mx-8 mt-6"></div>
      </div>

      <!-- Hors album, regroupé par année -->
      <div
        v-for="group in mediaGroups.by_year"
        :key="'year-' + (group.year ?? 'none')"
        class="pt-5"
      >
        <div class="flex items-baseline gap-2.5 px-4 sm:px-8 mb-3">
          <span class="text-sm font-semibold uppercase tracking-wider text-surface-500">{{ group.year ?? 'Sans date' }}</span>
          <span class="text-sm text-surface-400">{{ group.media_ids.length }}</span>
        </div>
        <div class="grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-8 gap-1 px-1">
          <MediaCard
            v-for="id in group.media_ids"
            :key="id"
            :media="mediaById[id]"
            fill
            class="aspect-square"
            @click="goToMedia(mediaById[id])"
          />
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="text-center py-12 bg-white rounded-lg shadow-xs">
        <svg class="mx-auto h-12 w-12 text-surface-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <h3 class="mt-4 text-lg font-medium text-surface-900">
          {{ canManage ? 'Aucun média' : 'Aucune photo partagée avec vous' }}
        </h3>
        <p class="mt-2 text-surface-500">
          {{ canManage
            ? "Cette personne n'apparaît sur aucun média pour le moment."
            : "Les photos de cette personne ne vous sont pas accessibles. Elles apparaîtront ici lorsqu'elles seront partagées avec vous." }}
        </p>
      </div>
    </div>

    <!-- Edit Modal -->
    <PersonFormModal
      v-if="showEditModal"
      :person="person"
      @close="showEditModal = false"
      @updated="handlePersonUpdated"
    />

    <!-- Avatar Picker Modal -->
    <BaseModal
      v-if="showAvatarPicker"
      title="Choisir un avatar"
      max-width="2xl"
      @close="showAvatarPicker = false"
    >
      <div class="p-6 grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-3 max-h-96 overflow-y-auto">
        <button
          v-for="item in photoMedia"
          :key="item.id"
          @click="setAvatar(item.id)"
          class="aspect-square rounded-lg overflow-hidden border-2 hover:border-brand-500 transition-colors"
          :class="person.avatar_media_id === item.id ? 'border-brand-500 ring-2 ring-brand-300' : 'border-surface-200'"
        >
          <img
            :src="item.conversions?.find(c => c.conversion_name === 'thumbnail')?.url || item.url"
            class="w-full h-full object-cover"
          />
        </button>
      </div>
      <template #footer>
        <!-- mr-auto : pousse l'action destructive à gauche du pied justify-end -->
        <button
          v-if="person.avatar_media_id"
          @click="removeAvatar"
          class="mr-auto px-4 py-2 text-sm font-medium text-red-600 dark:text-red-400 bg-white border border-red-300 dark:border-red-500/40 rounded-lg hover:bg-red-50 dark:hover:bg-red-500/10"
        >
          Supprimer l'avatar
        </button>
        <button @click="showAvatarPicker = false" class="btn-secondary">
          Fermer
        </button>
      </template>
    </BaseModal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import MediaCard from '@/Components/MediaCard.vue';
import PersonFormModal from '@/Components/PersonFormModal.vue';
import FamilyPanel from '@/Components/FamilyPanel.vue';
import PersonTimeline from '@/Components/PersonTimeline.vue';
import { personLabel } from '@/utils/personName';
import { formatLongDate } from '@/utils/format';
import { useToast } from '@/Composables/useToast';

const toast = useToast();

const props = defineProps({
  person: {
    type: Object,
    required: true,
  },
  media: {
    type: Array,
    default: () => [],
  },
  mediaGroups: {
    type: Object,
    default: () => ({ albums: [], by_year: [] }),
  },
  father: {
    type: Object,
    default: null,
  },
  mother: {
    type: Object,
    default: null,
  },
  spouses: {
    type: Array,
    default: () => [],
  },
  children: {
    type: Array,
    default: () => [],
  },
  siblings: {
    type: Array,
    default: () => [],
  },
  isSelf: {
    type: Boolean,
    default: false,
  },
  canManage: {
    type: Boolean,
    default: false,
  },
});

// Fiche « moi » du visiteur : conditionne le bouton « Lien de parenté ».
const viewerPersonId = computed(() => usePage().props.auth?.user?.person_id ?? null);

const toggleSelf = () => {
  router.post(`/people/${props.person.id}/set-self`, {}, { preserveScroll: true });
};

const showEditModal = ref(false);
const showAvatarPicker = ref(false);

// Le diaporama vit dans PersonTimeline (il connaît les slides) ; le bouton
// d'en-tête le déclenche via la ref.
const timelineRef = ref(null);
const playLifeSlideshow = () => timelineRef.value?.play();

// Menu ⋯ flottant (même pattern que la page album).
const showActionsMenu = ref(false);
const actionsMenuRef = ref(null);

const closeMenuOnOutsideClick = (event) => {
  if (showActionsMenu.value && actionsMenuRef.value && !actionsMenuRef.value.contains(event.target)) {
    showActionsMenu.value = false;
  }
};
onMounted(() => document.addEventListener('click', closeMenuOnOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', closeMenuOnOutsideClick));

const menuAction = (action) => {
  showActionsMenu.value = false;
  action();
};

// Index id -> média : les sections ne portent que des IDs, on résout ici.
const mediaById = computed(() => {
  const map = {};
  for (const m of props.media) map[m.id] = m;
  return map;
});

const photoMedia = computed(() => props.media.filter((m) => m.type === 'photo'));

const setAvatar = async (mediaId) => {
  try {
    await axios.put(`/people/${props.person.id}`, {
      name: props.person.name,
      avatar_media_id: mediaId,
    });
    showAvatarPicker.value = false;
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || "Erreur lors du changement d'avatar.");
  }
};

const removeAvatar = async () => {
  try {
    await axios.put(`/people/${props.person.id}`, {
      name: props.person.name,
      avatar_media_id: null,
    });
    showAvatarPicker.value = false;
    router.reload();
  } catch (error) {
    toast.error(error.response?.data?.message || "Erreur lors de la suppression de l'avatar.");
  }
};

const goToMedia = (media) => {
  router.visit(`/media/${media.id}`);
};

const handlePersonUpdated = () => {
  router.reload();
};

const deletePerson = async () => {
  if (!confirm(`Etes-vous sur de vouloir supprimer ${props.person.name} ? Cette action ne supprimera pas les medias.`)) {
    return;
  }

  try {
    await axios.delete(`/people/${props.person.id}`);
    router.visit('/people');
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors de la suppression de la personne.');
  }
};
</script>
