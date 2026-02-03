<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
          <div>
            <h1 class="text-2xl font-bold text-gray-900">Personnes</h1>
            <p class="mt-1 text-sm text-gray-500">Gerez les personnes presentes sur vos medias</p>
          </div>
          <button
            @click="showCreateModal = true"
            class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Ajouter une personne
          </button>
        </div>

        <!-- People Grid -->
        <div
          v-if="people.length > 0"
          class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
        >
          <div
            v-for="person in people"
            :key="person.id"
            class="bg-white rounded-lg shadow-md overflow-hidden cursor-pointer hover:shadow-lg transition-shadow"
            @click="goToPerson(person)"
          >
            <!-- Avatar -->
            <div class="aspect-square bg-gradient-to-br from-purple-100 to-indigo-100 flex items-center justify-center">
              <img
                v-if="person.avatar_url"
                :src="person.avatar_url"
                :alt="person.name"
                class="w-full h-full object-cover"
              />
              <span
                v-else
                class="text-6xl font-bold text-purple-300"
              >
                {{ person.name.charAt(0).toUpperCase() }}
              </span>
            </div>

            <!-- Info -->
            <div class="p-4">
              <h3 class="text-lg font-semibold text-gray-900 truncate">{{ person.name }}</h3>
              <p class="text-sm text-gray-500 mt-1">
                {{ person.media_count }} {{ person.media_count === 1 ? 'media' : 'medias' }}
              </p>
              <p v-if="person.birth_date" class="text-xs text-gray-400 mt-1">
                {{ formatDate(person.birth_date) }}
                {{ person.death_date ? ' - ' + formatDate(person.death_date) : '' }}
              </p>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div
          v-else
          class="text-center py-16 bg-white rounded-lg shadow-sm"
        >
          <svg
            class="mx-auto h-16 w-16 text-gray-300"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="1.5"
              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
            />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-gray-900">Aucune personne</h3>
          <p class="mt-2 text-gray-500">Ajoutez des personnes pour les tagger sur vos photos.</p>
          <button
            @click="showCreateModal = true"
            class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Ajouter une personne
          </button>
        </div>

        <!-- Create Modal -->
        <PersonFormModal
          v-if="showCreateModal"
          @close="showCreateModal = false"
          @created="handlePersonCreated"
        />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PersonFormModal from '@/Components/PersonFormModal.vue';

defineProps({
  people: {
    type: Array,
    default: () => [],
  },
});

const showCreateModal = ref(false);

const goToPerson = (person) => {
  router.visit(`/people/${person.id}`);
};

const handlePersonCreated = () => {
  router.reload();
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
};
</script>
