<template>
  <div v-if="currentStatus" class="bg-white rounded-lg shadow-xs p-4">
    <!-- Processing -->
    <div v-if="currentStatus === 'pending' || currentStatus === 'processing'" class="flex items-center gap-3">
      <svg class="animate-spin h-5 w-5 text-brand-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
      <div>
        <p class="text-sm font-medium text-surface-900">Analyse IA en cours...</p>
        <p class="text-xs text-surface-500">Détection des visages et labels</p>
      </div>
    </div>

    <!-- Completed -->
    <div v-else-if="currentStatus === 'completed'" class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="shrink-0 w-8 h-8 bg-green-100 dark:bg-green-500/15 rounded-full flex items-center justify-center">
          <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-medium text-surface-900">Analyse terminée</p>
          <p class="text-xs text-surface-500">
            {{ facesCount }} visage{{ facesCount !== 1 ? 's' : '' }} détecté{{ facesCount !== 1 ? 's' : '' }}
          </p>
        </div>
      </div>
      <button
        @click="$emit('rerun')"
        class="text-xs text-brand-600 hover:text-brand-800 font-medium"
      >
        Relancer
      </button>
    </div>

    <!-- Failed -->
    <div v-else-if="currentStatus === 'failed'" class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="shrink-0 w-8 h-8 bg-red-100 dark:bg-red-500/15 rounded-full flex items-center justify-center">
          <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-medium text-surface-900">Échec de l'analyse</p>
          <p class="text-xs text-red-500">{{ currentError || 'Erreur inconnue' }}</p>
        </div>
      </div>
      <button
        @click="$emit('rerun')"
        class="text-xs text-brand-600 hover:text-brand-800 font-medium"
      >
        Réessayer
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
  mediaId: {
    type: String,
    required: true,
  },
  initialStatus: {
    type: String,
    default: null,
  },
  initialFacesCount: {
    type: Number,
    default: 0,
  },
  initialError: {
    type: String,
    default: null,
  },
});

// La détection tourne côté navigateur (cf. Media/Show.vue) : ce badge se
// contente d'afficher l'état et de déléguer la relance au parent via 'rerun'.
defineEmits(['rerun']);

const currentStatus = ref(props.initialStatus);
const facesCount = ref(props.initialFacesCount);
const currentError = ref(props.initialError);
</script>
