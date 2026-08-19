<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-surface-900/50" @click.self="$emit('close')">
    <div class="bg-white rounded-modal shadow-warm-lg w-full max-w-md overflow-hidden">
      <div class="p-6">
        <h3 class="card-title mb-1">Modifier la date de {{ count }} média{{ count > 1 ? 's' : '' }}</h3>
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

        <p v-if="errorMessage" class="mt-3 text-sm text-red-600">{{ errorMessage }}</p>
      </div>

      <div class="flex justify-end gap-2 px-6 py-4 bg-surface-50 border-t border-surface-100">
        <button @click="$emit('close')" type="button" class="btn-secondary">Annuler</button>
        <button
          @click="$emit('apply', takenAt)"
          type="button"
          :disabled="!takenAt || saving"
          class="btn-primary"
        >
          {{ saving ? 'Application…' : 'Appliquer la date' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';

defineProps({
  count: { type: Number, required: true },
  saving: { type: Boolean, default: false },
  errorMessage: { type: String, default: null },
});
defineEmits(['close', 'apply']);

const takenAt = ref('');
const today = new Date().toISOString().slice(0, 10);
</script>
