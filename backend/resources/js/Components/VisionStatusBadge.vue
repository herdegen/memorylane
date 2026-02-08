<template>
  <div v-if="currentStatus" class="bg-white rounded-lg shadow-sm p-4">
    <!-- Processing -->
    <div v-if="currentStatus === 'pending' || currentStatus === 'processing'" class="flex items-center gap-3">
      <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
      <div>
        <p class="text-sm font-medium text-gray-900">Analyse IA en cours...</p>
        <p class="text-xs text-gray-500">Detection des visages et labels</p>
      </div>
    </div>

    <!-- Completed -->
    <div v-else-if="currentStatus === 'completed'" class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="flex-shrink-0 w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
          <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-medium text-gray-900">Analyse terminee</p>
          <p class="text-xs text-gray-500">
            {{ facesCount }} visage{{ facesCount !== 1 ? 's' : '' }} detecte{{ facesCount !== 1 ? 's' : '' }}
          </p>
        </div>
      </div>
      <button
        @click="reanalyze"
        :disabled="reanalyzing"
        class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
      >
        Relancer
      </button>
    </div>

    <!-- Failed -->
    <div v-else-if="currentStatus === 'failed'" class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="flex-shrink-0 w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
          <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-medium text-gray-900">Echec de l'analyse</p>
          <p class="text-xs text-red-500">{{ currentError || 'Erreur inconnue' }}</p>
        </div>
      </div>
      <button
        @click="reanalyze"
        :disabled="reanalyzing"
        class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
      >
        Reessayer
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

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

const emit = defineEmits(['analysis-complete']);

const currentStatus = ref(props.initialStatus);
const facesCount = ref(props.initialFacesCount);
const currentError = ref(props.initialError);
const reanalyzing = ref(false);
let pollInterval = null;

const pollStatus = async () => {
  try {
    const response = await axios.get(`/vision/media/${props.mediaId}/status`);
    currentStatus.value = response.data.status;
    facesCount.value = response.data.faces_count || 0;
    currentError.value = response.data.error;

    if (response.data.status === 'completed') {
      stopPolling();
      emit('analysis-complete');
    } else if (response.data.status === 'failed') {
      stopPolling();
    }
  } catch (error) {
    console.error('Failed to poll vision status:', error);
  }
};

const startPolling = () => {
  if (pollInterval) return;
  pollInterval = setInterval(pollStatus, 3000);
};

const stopPolling = () => {
  if (pollInterval) {
    clearInterval(pollInterval);
    pollInterval = null;
  }
};

const reanalyze = async () => {
  reanalyzing.value = true;
  try {
    await axios.post(`/vision/media/${props.mediaId}/analyze`);
    currentStatus.value = 'pending';
    currentError.value = null;
    facesCount.value = 0;
    startPolling();
  } catch (error) {
    console.error('Failed to re-analyze:', error);
  } finally {
    reanalyzing.value = false;
  }
};

onMounted(() => {
  if (currentStatus.value === 'pending' || currentStatus.value === 'processing') {
    startPolling();
  }
});

onUnmounted(() => {
  stopPolling();
});
</script>
