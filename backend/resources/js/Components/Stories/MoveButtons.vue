<template>
  <!-- Monter, descendre, retirer : chapitres et blocs de l'éditeur d'histoire. -->
  <div class="flex flex-col items-center gap-0.5 shrink-0 text-surface-400">
    <button type="button" class="p-1 rounded hover:text-surface-800 disabled:opacity-30" :disabled="first" :aria-label="$t('Monter')" @click="$emit('up')">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15l6-6 6 6" /></svg>
    </button>
    <button type="button" class="p-1 rounded hover:text-surface-800 disabled:opacity-30" :disabled="last" :aria-label="$t('Descendre')" @click="$emit('down')">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
    </button>
    <button type="button" class="p-1 rounded hover:text-red-600" :aria-label="$t('Retirer')" @click="$emit('remove')">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
    </button>
  </div>
</template>

<script setup>
defineProps({ first: Boolean, last: Boolean });
defineEmits(['up', 'down', 'remove']);
</script>
