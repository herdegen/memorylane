<template>
  <!-- Historique des corrections d'une photo (date, lieu, visages) : qui a
       changé quoi, et « Annuler » pour l'auteur de la photo, l'admin ou la
       personne qui a fait la correction. Replié par défaut. -->
  <div class="bg-white rounded-xl border border-surface-200 shadow-xs p-4 sm:p-6">
    <button type="button" class="w-full flex items-center justify-between text-left" :aria-expanded="open" @click="open = !open">
      <h2 class="text-lg font-semibold text-surface-900">
        {{ $t('Historique des corrections') }}
        <span class="ml-1 text-sm font-normal text-surface-400">{{ changes.length }}</span>
      </h2>
      <svg class="w-5 h-5 text-surface-400 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
    </button>

    <ul v-if="open" class="mt-4 space-y-3">
      <li v-for="change in changes" :key="change.id" class="text-sm">
        <p :class="change.reverted ? 'text-surface-400 line-through' : 'text-surface-800'">{{ change.summary }}</p>
        <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-surface-500">
          <span>{{ $t(':name, le :date', { name: change.by, date: formatDateTime(change.at) }) }}</span>
          <span v-if="change.reverted">{{ change.reverted_by ? $t('annulé par :name', { name: change.reverted_by }) : $t('annulé') }}</span>
          <button
            v-else-if="change.can_revert"
            type="button"
            class="font-semibold text-brand-700 dark:text-brand-400 hover:underline disabled:opacity-50"
            :disabled="busy === change.id"
            @click="revert(change)"
          >
            {{ $t('Annuler') }}
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';
import { useConfirm } from '@/Composables/useConfirm';
import { formatDateTime } from '@/utils/format';
import { t } from '@/i18n';

defineProps({
  // [{ id, summary, by, at, reverted, reverted_by, can_revert }]
  changes: { type: Array, required: true },
});

const toast = useToast();
const { confirm } = useConfirm();
const open = ref(false);
const busy = ref(null);

const revert = async (change) => {
  const ok = await confirm({
    title: t('Annuler cette correction ?'),
    message: change.summary,
    confirmLabel: t('Annuler la correction'),
  });
  if (!ok) return;
  busy.value = change.id;
  try {
    const { data } = await axios.post(`/media/changes/${change.id}/revert`);
    toast.success(data.message);
    router.reload({ only: ['media', 'history'] });
  } catch (e) {
    toast.error(e.response?.data?.message || t('Impossible d’annuler cette correction.'));
  } finally {
    busy.value = null;
  }
};
</script>
