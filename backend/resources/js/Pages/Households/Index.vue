<template>
  <Head title="Foyers" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
          <h1 class="text-display text-4xl text-surface-900">Foyers</h1>
          <p class="mt-2 text-surface-600">
            Un foyer est un cercle familial de comptes qui partagent une mémoire commune. Vous pouvez appartenir à plusieurs foyers.
          </p>
        </div>

        <!-- Créer un foyer -->
        <form
          class="mb-8 flex flex-wrap items-end gap-3 bg-white rounded-lg shadow-xs p-4"
          @submit.prevent="createHousehold"
        >
          <div class="flex-1 min-w-[220px]">
            <label class="block text-sm font-medium text-surface-700 mb-1">Nom du foyer</label>
            <input
              v-model="newName"
              type="text"
              placeholder="Ex. Famille Herdegen"
              maxlength="120"
              class="block w-full px-3 py-2 border border-surface-300 rounded-md focus:ring-1 focus:ring-brand-500 focus:border-brand-500"
            />
          </div>
          <button type="submit" class="btn-primary" :disabled="!newName.trim() || creating">
            Créer le foyer
          </button>
        </form>

        <!-- Liste -->
        <div v-if="households.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <Link
            v-for="h in households"
            :key="h.id"
            :href="`/households/${h.id}`"
            class="block bg-white rounded-lg shadow-xs p-5 hover:shadow-md transition"
          >
            <div class="flex items-start justify-between gap-2">
              <h2 class="text-lg font-semibold text-surface-900">{{ h.name }}</h2>
              <span
                v-if="h.is_creator"
                class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-brand-100 text-brand-700"
              >
                Créateur
              </span>
            </div>
            <p class="mt-2 text-sm text-surface-500">
              {{ h.members_count }} membre{{ h.members_count > 1 ? 's' : '' }}
              <span v-if="!h.is_creator && h.creator_name"> · créé par {{ h.creator_name }}</span>
            </p>
          </Link>
        </div>

        <div v-else class="text-center py-16 bg-white rounded-lg shadow-xs">
          <h3 class="text-lg font-medium text-surface-900">Aucun foyer</h3>
          <p class="mt-2 text-surface-500">Créez un foyer pour partager vos souvenirs avec vos proches.</p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useToast } from '@/Composables/useToast';

const toast = useToast();

defineProps({
  households: { type: Array, default: () => [] },
});

const newName = ref('');
const creating = ref(false);

const createHousehold = async () => {
  const name = newName.value.trim();
  if (!name || creating.value) return;
  creating.value = true;
  try {
    const { data } = await axios.post('/households', { name });
    router.visit(`/households/${data.id}`);
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors de la création du foyer.');
    creating.value = false;
  }
};
</script>
