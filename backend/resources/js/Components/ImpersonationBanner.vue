<template>
  <!-- « Se connecter en tant que » (admin) : rappel permanent + retour. -->
  <div
    v-if="impersonating"
    class="sticky top-0 z-[60] flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-2 text-sm bg-amber-400 text-amber-950 dark:bg-amber-500"
  >
    <span>
      👀 <Trans text="Vous voyez l’appli en tant que :name — vos actions sont faites en son nom.">
        <template #name><strong>{{ impersonating.name }}</strong></template>
      </Trans>
    </span>
    <button
      type="button"
      class="rounded-md bg-amber-950/90 px-3 py-1 text-xs font-semibold text-amber-50 hover:bg-amber-950"
      :disabled="leaving"
      @click="leave"
    >
      {{ $t('Revenir à mon compte') }}
    </button>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Trans from '@/Components/Trans.vue';

const impersonating = computed(() => usePage().props.impersonating);
const leaving = ref(false);

const leave = () => {
  leaving.value = true;
  router.post('/impersonate/leave', {}, { onFinish: () => (leaving.value = false) });
};
</script>
