<template>
  <BaseModal max-width="md" :title="$tc('Modifier la date de :count média|Modifier la date de :count médias', count)" @close="$emit('close')">
    <div class="p-6">
      <p class="text-sm text-surface-500 mb-4">
        {{ $t('La date de prise de vue sélectionnée remplacera celle de tous les médias choisis.') }}
      </p>

      <label class="form-label" for="bulk-taken-at">{{ $t('Date de prise de vue') }}</label>
      <!-- Vieilles photos : mois, année ou décennie si le jour est inconnu. -->
      <PhotoDateInput id="bulk-taken-at" v-model="value" />

      <!-- Photo papier numérisée : la date est celle du scan, pas la vraie. -->
      <div class="mt-5 border-t border-surface-200 pt-4">
        <button
          type="button"
          class="text-sm font-medium text-brand-700 hover:text-brand-800 disabled:opacity-50"
          :disabled="saving"
          @click="$emit('apply', null)"
        >
          {{ $t('Retirer la date') }}
        </button>
        <p class="mt-1 text-xs text-surface-500">
          {{ $t('Par exemple pour des photos papier datées du jour où on les a scannées : elles redeviennent « sans date » (et prennent la date de leur album s’il en a une).') }}
        </p>
      </div>

      <p v-if="errorMessage" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
    </div>

    <!-- Pied de page -->
    <template #footer>
      <button @click="$emit('close')" type="button" class="btn-secondary">{{ $t('Annuler') }}</button>
      <button
        @click="$emit('apply', value.date, value.precision)"
        type="button"
        :disabled="!value.date || saving"
        class="btn-primary"
      >
        {{ saving ? $t('Application…') : $t('Appliquer la date') }}
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref } from 'vue';
import BaseModal from '@/Components/BaseModal.vue';
import PhotoDateInput from '@/Components/PhotoDateInput.vue';

defineProps({
  count: { type: Number, required: true },
  saving: { type: Boolean, default: false },
  errorMessage: { type: String, default: null },
});
defineEmits(['close', 'apply']);

// { date: 'AAAA-MM-JJ', precision: null | 'month' | 'year' | 'decade' }
const value = ref({ date: null, precision: null });
</script>
