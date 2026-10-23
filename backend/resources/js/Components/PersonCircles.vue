<template>
  <!-- Cercles d'une fiche : où elle est, où on peut l'ajouter -->
  <div class="space-y-3">
    <div v-if="loading" class="text-xs text-surface-500">{{ $t('Chargement…') }}</div>
    <template v-else>
      <div>
        <div class="text-xs font-semibold text-surface-600 mb-1.5">{{ $t('Visible dans') }}</div>
        <div v-if="state.in.length" class="flex flex-wrap gap-1.5">
          <span
            v-for="c in state.in"
            :key="c.kind + (c.id || '')"
            class="inline-flex items-center gap-1.5 h-8 pl-2 pr-1.5 rounded-full text-xs font-semibold border"
            :class="c.kind === 'friends' ? 'border-teal-300 bg-teal-50 text-teal-800 dark:bg-teal-500/10 dark:text-teal-200' : 'border-amber-300 bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200'"
          >
            <CircleKindIcon :kind="c.kind" class="w-3.5 h-3.5" />
            {{ c.name }}
            <button
              v-if="c.can_remove && c.id"
              type="button"
              class="w-5 h-5 rounded-full hover:bg-black/10"
              :aria-label="$t('Retirer de :name', { name: c.name })"
              @click="remove(c)"
            >×</button>
          </span>
        </div>
        <p v-else class="text-xs text-surface-500">{{ $t('Dans aucun de vos cercles.') }}</p>
      </div>

      <div v-if="state.can_add.length">
        <div class="text-xs font-semibold text-surface-600 mb-1.5">{{ $t('Ajouter à') }}</div>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="c in state.can_add"
            :key="c.id"
            type="button"
            :disabled="busy"
            class="inline-flex items-center gap-1.5 h-8 px-3 rounded-full text-xs font-semibold border border-dashed border-surface-300 text-surface-700 hover:border-surface-400 hover:text-surface-900"
            @click="add(c)"
          >
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            {{ c.name }}
          </button>
        </div>
        <p class="mt-1.5 text-xs text-surface-500">{{ $t('Les membres du cercle verront sa fiche complète.') }}</p>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import CircleKindIcon from '@/Components/CircleKindIcon.vue';
import { usePeopleDirectory } from '@/Composables/usePeopleDirectory';
import { useToast } from '@/Composables/useToast';
import { t } from '@/i18n';

const props = defineProps({
  personId: { type: String, required: true },
  personName: { type: String, default: '' },
});
const emit = defineEmits(['changed']);

const toast = useToast();
const loading = ref(true);
const busy = ref(false);
const state = ref({ in: [], can_add: [] });

async function load() {
  loading.value = true;
  try {
    const { data } = await axios.get(`/people/${props.personId}/circles`);
    state.value = data;
  } catch {
    state.value = { in: [], can_add: [] };
  } finally {
    loading.value = false;
  }
}

async function add(c) {
  busy.value = true;
  try {
    const { data } = await axios.post(`/people/${props.personId}/circles`, { household_id: c.id });
    state.value = data;
    usePeopleDirectory().invalidate();
    emit('changed');
  } catch (e) {
    toast.error(e.response?.data?.message || t("Impossible d'ajouter la fiche."));
  } finally {
    busy.value = false;
  }
}

async function remove(c) {
  const question = props.personName
    ? t('Retirer :person de :circle ? Ses membres ne verront plus sa fiche.', { person: props.personName, circle: c.name })
    : t('Retirer cette fiche de :circle ? Ses membres ne verront plus sa fiche.', { circle: c.name });
  if (!window.confirm(question)) return;
  busy.value = true;
  try {
    const { data } = await axios.delete(`/people/${props.personId}/circles/${c.id}`);
    state.value = data;
    usePeopleDirectory().invalidate();
    emit('changed');
  } catch (e) {
    toast.error(e.response?.data?.message || t('Impossible de retirer la fiche.'));
  } finally {
    busy.value = false;
  }
}

watch(() => props.personId, load, { immediate: true });
</script>
