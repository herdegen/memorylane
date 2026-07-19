<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
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
            class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-red-600 hover:text-red-800"
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
              class="text-sm text-red-600 hover:text-red-800"
            >
              Retirer
            </button>
          </div>
        </div>

        <p class="mt-6 text-sm text-surface-400">
          Le partage de photos dans un foyer arrivera prochainement.
        </p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  household: { type: Object, required: true },
  members: { type: Array, default: () => [] },
});

const search = ref('');
const candidates = ref([]);
let searchTimer = null;

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
    alert('Erreur : ' + (error.response?.data?.message || error.message));
  }
};

const removeMember = async (member) => {
  if (!confirm(`Retirer ${member.name} du foyer ?`)) return;
  try {
    await axios.delete(`/households/${props.household.id}/members/${member.user_id}`);
    router.reload();
  } catch (error) {
    alert('Erreur : ' + (error.response?.data?.message || error.message));
  }
};

const leaveHousehold = async () => {
  if (!confirm('Quitter ce foyer ?')) return;
  try {
    await axios.post(`/households/${props.household.id}/leave`);
    router.visit('/households');
  } catch (error) {
    alert('Erreur : ' + (error.response?.data?.message || error.message));
  }
};

const destroyHousehold = async () => {
  if (!confirm('Supprimer ce foyer ? Cette action est irréversible.')) return;
  try {
    await axios.delete(`/households/${props.household.id}`);
    router.visit('/households');
  } catch (error) {
    alert('Erreur : ' + (error.response?.data?.message || error.message));
  }
};
</script>
