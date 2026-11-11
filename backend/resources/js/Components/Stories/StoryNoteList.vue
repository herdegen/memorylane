<template>
  <!-- Ajouts de la famille à une histoire (ou à une de ses questions). -->
  <ul v-if="notes.length" class="flex flex-col gap-2">
    <li v-for="n in notes" :key="n.id" class="rounded-xl bg-white border border-surface-200 px-4 py-3">
      <p class="text-[15px] text-surface-800 whitespace-pre-line">{{ n.body }}</p>
      <div class="mt-1.5 flex items-center justify-between gap-3 text-xs text-surface-500">
        <span>{{ n.author }}<template v-if="n.date"> · {{ formatDate(n.date) }}</template></span>
        <button v-if="n.can_delete" type="button" class="hover:text-red-600" @click="remove(n)">{{ $t('Retirer') }}</button>
      </div>
    </li>
  </ul>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import { formatDate } from '@/utils/format';
import { useConfirm } from '@/Composables/useConfirm';
import { t } from '@/i18n';

defineProps({ notes: { type: Array, default: () => [] } });
const emit = defineEmits(['deleted']);

const { confirm } = useConfirm();
const remove = async (n) => {
  if (!(await confirm({ title: t('Retirer cet ajout ?'), confirmLabel: t('Retirer'), danger: true }))) return;
  router.delete(`/story-notes/${n.id}`, { preserveScroll: true, onSuccess: () => emit('deleted') });
};
</script>
