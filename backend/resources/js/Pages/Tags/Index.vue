<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-6">
          <h1 class="text-3xl font-bold text-gray-900">Gestion des tags</h1>
          <p class="mt-2 text-gray-600">Organisez vos médias avec des tags personnalisés</p>
        </div>

        <!-- Create new tag form -->
        <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Créer un nouveau tag</h2>
          <form @submit.prevent="createTag" class="flex gap-4 items-end">
            <div class="flex-1">
              <label class="block text-sm font-medium text-gray-700 mb-2">Nom du tag</label>
              <input
                v-model="newTag.name"
                type="text"
                required
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                placeholder="Ex: Famille, Vacances, Paris..."
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
              <input
                v-model="newTag.color"
                type="color"
                class="h-10 w-20 border border-gray-300 rounded-lg cursor-pointer"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Type</label>
              <select
                v-model="newTag.type"
                class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500"
              >
                <option value="">Autre</option>
                <option value="person">Personne</option>
                <option value="place">Lieu</option>
                <option value="event">Événement</option>
              </select>
            </div>
            <button
              type="submit"
              :disabled="!newTag.name || creating"
              class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
              {{ creating ? 'Création...' : 'Créer' }}
            </button>
          </form>
        </div>

        <!-- Tags list -->
        <div class="bg-white rounded-lg shadow-sm p-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Tous les tags ({{ tags.length }})</h2>

          <!-- Empty state -->
          <div v-if="tags.length === 0" class="text-center py-12">
            <div class="text-gray-400 text-5xl mb-4">🏷️</div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun tag</h3>
            <p class="text-gray-600">Créez votre premier tag pour commencer à organiser vos médias</p>
          </div>

          <!-- Tags grid -->
          <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div
              v-for="tag in tags"
              :key="tag.id"
              class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:border-indigo-300 transition"
            >
              <div class="flex items-center gap-3">
                <div
                  class="w-4 h-4 rounded-full"
                  :style="{ backgroundColor: tag.color || '#6366f1' }"
                ></div>
                <div>
                  <h3 class="font-medium text-gray-900">{{ tag.name }}</h3>
                  <p class="text-sm text-gray-500">
                    {{ tag.media_count }} {{ tag.media_count > 1 ? 'médias' : 'média' }}
                    <span v-if="tag.type" class="ml-2 text-xs text-gray-400">• {{ formatType(tag.type) }}</span>
                  </p>
                </div>
              </div>
              <button
                @click="deleteTag(tag)"
                class="text-red-600 hover:text-red-800 transition"
                title="Supprimer ce tag"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const props = defineProps({
  tags: {
    type: Array,
    required: true,
  },
});

const newTag = ref({
  name: '',
  color: '#6366f1',
  type: '',
});

const creating = ref(false);

const createTag = async () => {
  if (!newTag.value.name) return;

  creating.value = true;

  try {
    await axios.post('/tags', newTag.value);

    // Reset form
    newTag.value = {
      name: '',
      color: '#6366f1',
      type: '',
    };

    // Reload page
    router.reload();
  } catch (error) {
    alert('Erreur lors de la création du tag: ' + (error.response?.data?.message || error.message));
  } finally {
    creating.value = false;
  }
};

const deleteTag = async (tag) => {
  if (!confirm(`Supprimer le tag "${tag.name}" ? Il sera retiré de tous les médias.`)) {
    return;
  }

  try {
    await axios.delete(`/tags/${tag.id}`);
    router.reload();
  } catch (error) {
    alert('Erreur lors de la suppression du tag: ' + (error.response?.data?.message || error.message));
  }
};

const formatType = (type) => {
  const types = {
    person: 'Personne',
    place: 'Lieu',
    event: 'Événement',
    other: 'Autre',
  };
  return types[type] || type;
};
</script>
