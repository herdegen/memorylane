<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Back Link -->
        <Link
          href="/people"
          class="inline-flex items-center text-sm text-surface-500 hover:text-surface-700 mb-6"
        >
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
          Retour aux personnes
        </Link>

        <!-- Person Header -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
          <div class="flex flex-col sm:flex-row sm:items-start gap-6">
            <!-- Avatar -->
            <div class="relative group">
              <div class="w-32 h-32 flex-shrink-0 rounded-full overflow-hidden bg-gradient-to-br from-purple-100 to-brand-100 flex items-center justify-center">
                <img
                  v-if="person.avatar_url"
                  :src="person.avatar_url"
                  :alt="person.name"
                  class="w-full h-full object-cover"
                />
                <span
                  v-else
                  class="text-5xl font-bold text-purple-300"
                >
                  {{ person.name.charAt(0).toUpperCase() }}
                </span>
              </div>
              <button
                v-if="media.data && media.data.length > 0"
                @click="showAvatarPicker = true"
                class="absolute inset-0 rounded-full bg-black bg-opacity-0 group-hover:bg-opacity-40 flex items-center justify-center transition-all"
                title="Changer l'avatar"
              >
                <svg class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </button>
            </div>

            <!-- Info -->
            <div class="flex-1">
              <div class="flex items-start justify-between">
                <div>
                  <h1 class="text-2xl font-bold text-surface-900">
                    <span v-if="person.gender === 'M'" class="text-blue-500 mr-1" title="Masculin">&#9794;</span>
                    <span v-else-if="person.gender === 'F'" class="text-pink-500 mr-1" title="Feminin">&#9792;</span>
                    {{ person.name }}
                  </h1>
                  <p v-if="person.birth_date" class="text-sm text-surface-500 mt-1">
                    {{ formatDate(person.birth_date) }}
                    {{ person.death_date ? ' - ' + formatDate(person.death_date) : '' }}
                  </p>
                  <p class="text-sm text-surface-500 mt-2">
                    {{ person.media_count || 0 }} {{ (person.media_count || 0) === 1 ? 'media' : 'medias' }}
                  </p>
                </div>

                <div class="flex gap-2">
                  <button
                    @click="showEditModal = true"
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
                  >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Modifier
                  </button>
                  <button
                    @click="deletePerson"
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-red-600 bg-white border border-red-300 rounded-lg hover:bg-red-50"
                  >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    Supprimer
                  </button>
                </div>
              </div>

              <p v-if="person.notes" class="mt-4 text-surface-600 whitespace-pre-wrap">
                {{ person.notes }}
              </p>
            </div>
          </div>
        </div>

        <!-- Family Panel -->
        <FamilyPanel
          :person="person"
          :father="father"
          :mother="mother"
          :spouses="spouses"
          :children="children"
        />

        <!-- Media Grid -->
        <div v-if="media.data && media.data.length > 0">
          <h2 class="text-lg font-semibold text-surface-900 mb-4">Medias de {{ person.name }}</h2>

          <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            <MediaCard
              v-for="item in media.data"
              :key="item.id"
              :media="item"
              @click="goToMedia(item)"
            />
          </div>

          <!-- Load More -->
          <div
            v-if="media.next_page_url"
            class="mt-6 text-center"
          >
            <button
              @click="loadMore"
              class="px-6 py-2 text-sm font-medium text-brand-600 bg-brand-50 rounded-lg hover:bg-brand-100"
            >
              Charger plus
            </button>
          </div>
        </div>

        <!-- Empty State -->
        <div
          v-else
          class="text-center py-12 bg-white rounded-lg shadow-sm"
        >
          <svg
            class="mx-auto h-12 w-12 text-surface-300"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="1.5"
              d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
            />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-surface-900">Aucun media</h3>
          <p class="mt-2 text-surface-500">Cette personne n'apparait sur aucun media pour le moment.</p>
        </div>

        <!-- Edit Modal -->
        <PersonFormModal
          v-if="showEditModal"
          :person="person"
          @close="showEditModal = false"
          @updated="handlePersonUpdated"
        />

        <!-- Avatar Picker Modal -->
        <div
          v-if="showAvatarPicker"
          class="fixed inset-0 z-50 overflow-y-auto"
          role="dialog"
          aria-modal="true"
        >
          <div
            class="fixed inset-0 bg-surface-500 bg-opacity-75 transition-opacity"
            @click="showAvatarPicker = false"
          ></div>
          <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative bg-white rounded-lg shadow-xl w-full max-w-2xl" @click.stop>
              <div class="px-6 py-4 border-b border-surface-200">
                <h3 class="text-lg font-semibold text-surface-900">Choisir un avatar</h3>
              </div>
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
              <div class="px-6 py-4 bg-surface-50 flex justify-between">
                <button
                  v-if="person.avatar_media_id"
                  @click="removeAvatar"
                  class="px-4 py-2 text-sm font-medium text-red-600 bg-white border border-red-300 rounded-lg hover:bg-red-50"
                >
                  Supprimer l'avatar
                </button>
                <div v-else></div>
                <button
                  @click="showAvatarPicker = false"
                  class="px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
                >
                  Fermer
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import MediaCard from '@/Components/MediaCard.vue';
import PersonFormModal from '@/Components/PersonFormModal.vue';
import FamilyPanel from '@/Components/FamilyPanel.vue';

const props = defineProps({
  person: {
    type: Object,
    required: true,
  },
  media: {
    type: Object,
    default: () => ({ data: [] }),
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
});

const showEditModal = ref(false);
const showAvatarPicker = ref(false);

const photoMedia = computed(() => {
  if (!props.media.data) return [];
  return props.media.data.filter(m => m.type === 'photo');
});

const setAvatar = async (mediaId) => {
  try {
    await axios.put(`/people/${props.person.id}`, {
      name: props.person.name,
      avatar_media_id: mediaId,
    });
    showAvatarPicker.value = false;
    router.reload();
  } catch (error) {
    alert('Erreur: ' + (error.response?.data?.message || error.message));
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
    alert('Erreur: ' + (error.response?.data?.message || error.message));
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
    alert('Erreur: ' + (error.response?.data?.message || error.message));
  }
};

const loadMore = () => {
  router.visit(props.media.next_page_url, {
    preserveState: true,
    preserveScroll: true,
  });
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
};
</script>
