<template>
  <BaseModal :title="title" max-width="md" @close="$emit('close')">
    <div class="px-6 py-4 space-y-4">
      <!-- Choisis -->
      <div v-if="chosen.length" class="flex flex-wrap gap-2">
        <button
          v-for="p in chosen"
          :key="p.id"
          type="button"
          class="inline-flex items-center gap-2 pl-1 pr-3 py-1 rounded-full bg-brand-600 text-white text-sm font-semibold"
          :aria-label="$t('Retirer :name', { name: p.name })"
          @click="toggle(p)"
        >
          <PersonAvatar :person="p" :size="7" />
          {{ p.name }} <span aria-hidden="true">✕</span>
        </button>
      </div>

      <label class="flex items-center gap-2 h-11 px-3.5 rounded-xl border border-surface-300 bg-white focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-100">
        <svg class="w-4 h-4 text-surface-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M21 21l-5-5m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        <input v-model="query" type="search" :placeholder="$t('Chercher une personne…')" :aria-label="$t('Chercher une personne')" class="w-full border-0 bg-transparent p-0 text-[15px] focus:ring-0" />
      </label>

      <!-- Sans recherche : les personnes présentes sur l'album ; sinon tout l'annuaire -->
      <p class="text-xs font-semibold uppercase tracking-wide text-surface-500">
        {{ query.trim() ? $t('Résultats') : $t('Sur les photos de l’album') }}
      </p>
      <div class="max-h-72 overflow-y-auto -mx-2">
        <button
          v-for="p in list"
          :key="p.id"
          type="button"
          class="w-full flex items-center gap-3 px-2 py-2 rounded-lg text-left hover:bg-surface-50"
          :aria-pressed="isChosen(p)"
          @click="toggle(p)"
        >
          <PersonAvatar :person="p" :size="9" />
          <span class="flex-1 min-w-0">
            <span class="block text-sm font-semibold text-surface-900 truncate">{{ p.name }}</span>
            <span v-if="p.count" class="block text-xs text-surface-500">{{ $tc(':count photo dans l’album|:count photos dans l’album', p.count) }}</span>
          </span>
          <span
            class="w-6 h-6 rounded-full border-2 grid place-items-center text-xs"
            :class="isChosen(p) ? 'bg-brand-600 border-brand-600 text-white' : 'border-surface-300'"
            aria-hidden="true"
          >{{ isChosen(p) ? '✓' : '' }}</span>
        </button>
        <p v-if="!list.length" class="px-2 py-6 text-center text-sm text-surface-500">{{ $t('Personne ne correspond.') }}</p>
      </div>
    </div>

    <template #footer>
      <button type="button" class="btn-secondary" @click="$emit('close')">{{ $t('Annuler') }}</button>
      <button type="button" class="btn-primary" :disabled="saving" @click="save">
        {{ saving ? $t('Enregistrement...') : $t('Enregistrer') }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import BaseModal from '@/Components/BaseModal.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import { usePeopleDirectory } from '@/Composables/usePeopleDirectory';
import { searchPeople } from '@/utils/personSearch';
import { useToast } from '@/Composables/useToast';
import { t } from '@/i18n';

const props = defineProps({
  albumId: { type: String, required: true },
  role: { type: String, required: true },
  title: { type: String, required: true },
  // Déjà dans ce rôle
  selected: { type: Array, default: () => [] },
  // Personnes présentes sur les photos de l'album (les plus vues d'abord)
  suggestions: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'saved']);
const toast = useToast();

const chosen = ref([...props.selected]);
const query = ref('');
const directory = ref([]);
const saving = ref(false);

onMounted(async () => {
  directory.value = await usePeopleDirectory().load();
});

const isChosen = (p) => chosen.value.some((c) => c.id === p.id);
const toggle = (p) => {
  chosen.value = isChosen(p) ? chosen.value.filter((c) => c.id !== p.id) : [...chosen.value, p];
};

const list = computed(() => {
  const q = query.value.trim();
  if (!q) return props.suggestions.slice(0, 40);
  const counts = new Map(props.suggestions.map((p) => [p.id, p.count]));
  return searchPeople(q, directory.value).slice(0, 30).map((p) => ({ ...p, count: counts.get(p.id) || 0 }));
});

const save = async () => {
  saving.value = true;
  try {
    await axios.put(`/albums/${props.albumId}/roles`, { role: props.role, person_ids: chosen.value.map((p) => p.id) });
    emit('saved');
    emit('close');
  } catch (e) {
    toast.error(e.response?.data?.message || t('Impossible d’enregistrer.'));
  } finally {
    saving.value = false;
  }
};
</script>
