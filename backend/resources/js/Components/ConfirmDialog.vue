<template>
  <!-- :key : chaque nouvelle demande remonte la modale (focus initial
       recalculé, même si une demande en remplace une autre). -->
  <BaseModal
    v-if="pending"
    :key="pending.id"
    max-width="md"
    :title="pending.title"
    @close="answer(false)"
  >
    <div class="px-6 py-5 flex items-start gap-4">
      <div
        v-if="pending.danger"
        class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400"
        aria-hidden="true"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m0 3.75h.008M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
      </div>
      <p v-if="pending.message" class="text-sm text-surface-600 whitespace-pre-line pt-0.5">
        {{ pending.message }}
      </p>
    </div>

    <template #footer>
      <!-- Action destructive : le focus initial va sur « Annuler » (Entrée
           ne supprime pas par mégarde) ; sinon sur le bouton de confirmation. -->
      <button
        type="button"
        class="btn-secondary"
        :autofocus="pending.danger"
        @click="answer(false)"
      >
        {{ pending.cancelLabel }}
      </button>
      <!-- dark:text-red-50 : en sombre, `white` est redéfini en fond de carte. -->
      <button
        type="button"
        :class="pending.danger
          ? 'btn text-white dark:text-red-50 bg-red-600 hover:bg-red-700 active:bg-red-800 focus:ring-red-500'
          : 'btn-primary'"
        :autofocus="!pending.danger"
        @click="answer(true)"
      >
        {{ pending.confirmLabel }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { onBeforeUnmount } from 'vue';
import BaseModal from '@/Components/BaseModal.vue';
import { useConfirm } from '@/Composables/useConfirm';

// Rendu de la confirmation demandée via useConfirm() — monté une seule fois
// dans AppLayout, à côté de ToastContainer.
const { pending, answer, registerHost } = useConfirm();

const unregister = registerHost();
onBeforeUnmount(unregister);
</script>
