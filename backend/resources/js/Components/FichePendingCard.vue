<template>
  <div v-if="items.length" class="rounded-2xl border border-brand-200 bg-brand-50 p-5 dark:border-brand-500/30 dark:bg-brand-500/10">
    <h2 class="font-semibold text-surface-900">À valider sur ta fiche</h2>
    <p class="mt-1 text-sm text-surface-600">
      Ces médias ont été ajoutés à ta fiche. Affichés, ils sont visibles par tous ceux qui voient ta fiche ;
      gardés privés, seuls toi et la personne qui les a ajoutés les voient.
    </p>
    <ul class="mt-4 space-y-3">
      <li v-for="item in items" :key="item.id" class="flex items-center gap-3">
        <Link :href="`/media/${item.id}`" class="shrink-0">
          <img :src="item.thumbnail_url" alt="" class="h-14 w-14 rounded-lg object-cover" />
        </Link>
        <div class="min-w-0 flex-1">
          <p class="text-sm text-surface-800">
            {{ item.by || 'Quelqu’un' }} a ajouté {{ item.type === 'video' ? 'une vidéo' : 'une photo' }}
          </p>
          <div class="mt-1 flex flex-wrap gap-2">
            <button type="button" class="rounded-md bg-brand-600 px-3 py-1 text-xs font-semibold text-white hover:bg-brand-700" :disabled="busy === item.id" @click="decide(item, true)">
              Afficher pour tout le monde
            </button>
            <button type="button" class="rounded-md px-3 py-1 text-xs font-semibold text-surface-700 hover:bg-surface-100" :disabled="busy === item.id" @click="decide(item, false)">
              Garder privé
            </button>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';
import { apiError } from '@/utils/apiError';

const props = defineProps({
  initialItems: { type: Array, default: () => [] },
});

const toast = useToast();
const items = ref([...props.initialItems]);
const busy = ref(null);

const decide = async (item, publish) => {
  busy.value = item.id;
  try {
    const { data } = await axios.post(`/media/${item.id}/fiche-decision`, { publish });
    items.value = items.value.filter((i) => i.id !== item.id);
    toast.success(data.message);
  } catch (error) {
    toast.error(apiError(error, 'Impossible d’enregistrer ton choix.'));
  } finally {
    busy.value = null;
  }
};
</script>
