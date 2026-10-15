<template>
  <div v-if="people.length" class="bg-white rounded-lg shadow-xs p-6">
    <h2 class="text-lg font-semibold text-surface-900">Sur les fiches</h2>
    <p class="mt-1 text-sm text-surface-500">
      Sur la fiche d’une personne, ce média est visible par tous ceux qui voient sa fiche.
      Si elle a un compte, elle valide d’abord.
    </p>
    <ul class="mt-4 space-y-2">
      <li v-for="p in people" :key="p.id" class="flex flex-wrap items-center justify-between gap-2">
        <span class="min-w-0">
          <span class="font-medium text-surface-900">{{ p.name }}</span>
          <span class="ml-2 text-sm" :class="statusClass(status(p))">{{ statusLabel(p) }}</span>
        </span>
        <button
          v-if="status(p) === 'published' || status(p) === 'pending'"
          type="button"
          :disabled="busy === p.id"
          class="text-sm font-medium text-surface-600 hover:text-red-600 disabled:opacity-50"
          @click="withdraw(p)"
        >
          Retirer de sa fiche
        </button>
        <button
          v-else
          type="button"
          :disabled="busy === p.id"
          class="text-sm font-medium text-brand-600 hover:text-brand-800 disabled:opacity-50"
          @click="publish(p)"
        >
          Mettre sur sa fiche
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';
import { apiError } from '@/utils/apiError';

const props = defineProps({
  mediaId: { type: String, required: true },
  // Personnes identifiées sur le média (pivot.fiche_status).
  initialPeople: { type: Array, default: () => [] },
});

const toast = useToast();
const busy = ref(null);
const statuses = ref({});
// Fiches masquées (autre cercle) : pas de publication possible.
const people = computed(() => props.initialPeople.filter((p) => !p.restricted));

const status = (p) => statuses.value[p.id] ?? p.pivot?.fiche_status ?? null;
const first = (p) => p.first_name || (p.name || '').split(' ')[0];
const statusLabel = (p) => ({
  published: '· sur sa fiche, visible par tous',
  pending: `· en attente de la validation de ${first(p)}`,
  private: '· privé',
})[status(p)] ?? '';
const statusClass = (s) => (s === 'published' ? 'text-green-700 dark:text-green-400' : 'text-surface-500');

const publish = async (p) => {
  busy.value = p.id;
  try {
    const { data } = await axios.post(`/media/${props.mediaId}/fiches`, { person_id: p.id });
    statuses.value = { ...statuses.value, [p.id]: data.status };
    toast.success(data.message);
  } catch (error) {
    toast.error(apiError(error, 'Impossible de le mettre sur sa fiche.'));
  } finally {
    busy.value = null;
  }
};

const withdraw = async (p) => {
  busy.value = p.id;
  try {
    const { data } = await axios.delete(`/media/${props.mediaId}/fiches/${p.id}`);
    statuses.value = { ...statuses.value, [p.id]: 'private' };
    toast.success(data.message);
  } catch (error) {
    toast.error(apiError(error, 'Impossible de le retirer.'));
  } finally {
    busy.value = null;
  }
};
</script>
