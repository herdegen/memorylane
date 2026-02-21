<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-sm p-6">
    <h3 class="text-lg font-semibold mb-4">Géolocalisation</h3>

    <!-- Current location display -->
    <div v-if="hasLocation" class="mb-4 p-4 bg-green-50 rounded-lg">
      <div class="text-sm text-surface-700 mb-2">
        <strong>Latitude:</strong> {{ currentLatitude }}
      </div>
      <div class="text-sm text-surface-700 mb-2">
        <strong>Longitude:</strong> {{ currentLongitude }}
      </div>
      <div v-if="currentAltitude" class="text-sm text-surface-700">
        <strong>Altitude:</strong> {{ currentAltitude }}m
      </div>
      <div class="mt-3 flex gap-2">
        <button
          @click="viewOnMap"
          class="px-4 py-2 bg-brand-600 text-white rounded-lg hover:bg-brand-700 text-sm"
        >
          Voir sur la carte
        </button>
        <button
          @click="showEditForm = true"
          class="px-4 py-2 bg-surface-200 text-surface-700 rounded-lg hover:bg-surface-300 text-sm"
        >
          Modifier
        </button>
        <button
          @click="removeLocation"
          class="px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 text-sm"
        >
          Supprimer
        </button>
      </div>
    </div>

    <!-- No location message -->
    <div v-else class="mb-4 p-4 bg-surface-50 rounded-lg">
      <p class="text-sm text-surface-600 mb-3">
        Aucune géolocalisation disponible pour ce média.
      </p>
      <button
        @click="showEditForm = true"
        class="px-4 py-2 bg-brand-600 text-white rounded-lg hover:bg-brand-700 text-sm"
      >
        Ajouter une localisation
      </button>
    </div>

    <!-- Edit form -->
    <div v-if="showEditForm" class="border-t pt-4">
      <h4 class="font-medium mb-3">{{ hasLocation ? 'Modifier' : 'Ajouter' }} la localisation</h4>

      <!-- Location search -->
      <div class="mb-4">
        <label class="block text-sm font-medium text-surface-700 mb-2">
          Rechercher un lieu
        </label>
        <input
          v-model="locationSearch"
          @input="debounceLocationSearch"
          type="text"
          placeholder="Paris, France..."
          class="w-full px-3 py-2 border border-surface-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-transparent"
        />

        <!-- Search results -->
        <div v-if="locationResults.length > 0" class="mt-2 border border-surface-200 rounded-lg max-h-40 overflow-y-auto">
          <button
            v-for="result in locationResults"
            :key="result.place_id"
            @click="selectSearchResult(result)"
            class="w-full text-left px-3 py-2 hover:bg-surface-100 border-b border-surface-100 last:border-b-0 text-sm"
          >
            {{ result.display_name }}
          </button>
        </div>
      </div>

      <!-- Manual input -->
      <div class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">
            Latitude (-90 à 90)
          </label>
          <input
            v-model.number="editForm.latitude"
            type="number"
            step="0.000001"
            min="-90"
            max="90"
            class="w-full px-3 py-2 border border-surface-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-transparent"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">
            Longitude (-180 à 180)
          </label>
          <input
            v-model.number="editForm.longitude"
            type="number"
            step="0.000001"
            min="-180"
            max="180"
            class="w-full px-3 py-2 border border-surface-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-transparent"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-surface-700 mb-1">
            Altitude (optionnel, en mètres)
          </label>
          <input
            v-model.number="editForm.altitude"
            type="number"
            step="0.1"
            class="w-full px-3 py-2 border border-surface-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-transparent"
          />
        </div>
      </div>

      <!-- Action buttons -->
      <div class="mt-4 flex gap-2">
        <button
          @click="saveLocation"
          :disabled="!isFormValid"
          class="px-4 py-2 bg-brand-600 text-white rounded-lg hover:bg-brand-700 disabled:bg-surface-300 disabled:cursor-not-allowed"
        >
          Enregistrer
        </button>
        <button
          @click="cancelEdit"
          class="px-4 py-2 bg-surface-200 text-surface-700 rounded-lg hover:bg-surface-300"
        >
          Annuler
        </button>
      </div>

      <!-- Error message -->
      <div v-if="errorMessage" class="mt-3 p-3 bg-red-50 text-red-700 rounded-lg text-sm">
        {{ errorMessage }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
  mediaId: {
    type: String,
    required: true,
  },
  initialLatitude: {
    type: Number,
    default: null,
  },
  initialLongitude: {
    type: Number,
    default: null,
  },
  initialAltitude: {
    type: Number,
    default: null,
  },
});

const emit = defineEmits(['updated', 'removed']);

// State
const currentLatitude = ref(props.initialLatitude);
const currentLongitude = ref(props.initialLongitude);
const currentAltitude = ref(props.initialAltitude);

const showEditForm = ref(false);
const locationSearch = ref('');
const locationResults = ref([]);
const errorMessage = ref('');
let searchTimeout = null;

const editForm = ref({
  latitude: props.initialLatitude,
  longitude: props.initialLongitude,
  altitude: props.initialAltitude,
});

// Computed
const hasLocation = computed(() => {
  return currentLatitude.value !== null && currentLongitude.value !== null;
});

const isFormValid = computed(() => {
  return (
    editForm.value.latitude !== null &&
    editForm.value.longitude !== null &&
    editForm.value.latitude >= -90 &&
    editForm.value.latitude <= 90 &&
    editForm.value.longitude >= -180 &&
    editForm.value.longitude <= 180
  );
});

// Watch for prop changes
watch(() => props.initialLatitude, (newVal) => {
  currentLatitude.value = newVal;
  editForm.value.latitude = newVal;
});

watch(() => props.initialLongitude, (newVal) => {
  currentLongitude.value = newVal;
  editForm.value.longitude = newVal;
});

watch(() => props.initialAltitude, (newVal) => {
  currentAltitude.value = newVal;
  editForm.value.altitude = newVal;
});

// Methods
function debounceLocationSearch() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    if (locationSearch.value.length >= 3) {
      searchLocations();
    } else {
      locationResults.value = [];
    }
  }, 500);
}

async function searchLocations() {
  try {
    const response = await axios.get('/map/search', {
      params: { query: locationSearch.value }
    });
    locationResults.value = response.data;
  } catch (error) {
    console.error('Location search failed:', error);
  }
}

function selectSearchResult(result) {
  editForm.value.latitude = parseFloat(result.lat);
  editForm.value.longitude = parseFloat(result.lon);
  locationSearch.value = '';
  locationResults.value = [];
}

async function saveLocation() {
  errorMessage.value = '';

  try {
    const response = await axios.post(`/map/media/${props.mediaId}/geolocation`, editForm.value);

    currentLatitude.value = editForm.value.latitude;
    currentLongitude.value = editForm.value.longitude;
    currentAltitude.value = editForm.value.altitude;

    showEditForm.value = false;

    emit('updated', response.data.metadata);

    // Show success message
    alert('Géolocalisation mise à jour avec succès !');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Erreur lors de la sauvegarde';
  }
}

async function removeLocation() {
  if (!confirm('Êtes-vous sûr de vouloir supprimer la géolocalisation ?')) {
    return;
  }

  try {
    await axios.delete(`/map/media/${props.mediaId}/geolocation`);

    currentLatitude.value = null;
    currentLongitude.value = null;
    currentAltitude.value = null;

    emit('removed');

    alert('Géolocalisation supprimée avec succès !');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Erreur lors de la suppression';
  }
}

function cancelEdit() {
  showEditForm.value = false;
  editForm.value = {
    latitude: currentLatitude.value,
    longitude: currentLongitude.value,
    altitude: currentAltitude.value,
  };
  errorMessage.value = '';
}

function viewOnMap() {
  window.open(`/map`, '_blank');
}
</script>
