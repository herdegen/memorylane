<template>
  <BaseModal max-width="md" :title="`Modifier la date de ${count} média${count > 1 ? 's' : ''}`" @close="$emit('close')">
    <div class="p-6">
      <p class="text-sm text-surface-500 mb-4">
        La date de prise de vue sélectionnée remplacera celle de tous les médias choisis.
      </p>

      <label class="form-label" for="bulk-taken-at">Date de prise de vue</label>
      <input
        id="bulk-taken-at"
        v-model="takenAt"
        type="date"
        class="form-input"
        :max="today"
      />

      <p v-if="errorMessage" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
    </div>

    <!-- Pied de page -->
    <template #footer>
      <button @click="$emit('close')" type="button" class="btn-secondary">Annuler</button>
      <button
        @click="$emit('apply', takenAt)"
        type="button"
        :disabled="!takenAt || saving"
        class="btn-primary"
      >
        {{ saving ? 'Application…' : 'Appliquer la date' }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref } from 'vue';
import BaseModal from '@/Components/BaseModal.vue';

defineProps({
  count: { type: Number, required: true },
  saving: { type: Boolean, default: false },
  errorMessage: { type: String, default: null },
});
defineEmits(['close', 'apply']);

const takenAt = ref('');
// Date locale (pas toISOString/UTC : vers minuit, un fuseau UTC+ ne
// pourrait plus sélectionner le jour courant).
const now = new Date();
const today = [
  now.getFullYear(),
  String(now.getMonth() + 1).padStart(2, '0'),
  String(now.getDate()).padStart(2, '0'),
].join('-');
</script>
