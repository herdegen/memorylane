<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-surface-900/50" @click.self="$emit('close')">
    <div class="bg-white rounded-modal shadow-warm-lg w-full max-w-2xl overflow-hidden">
      <div class="p-6">
        <h3 class="card-title mb-1">{{ title }}</h3>
        <p class="text-sm text-surface-500 mb-4">{{ description }}</p>

        <!-- Recherche d'adresse -->
        <div class="relative mb-3">
          <div class="flex gap-2">
            <input
              v-model="searchQuery"
              @keyup.enter="searchAddress"
              type="text"
              class="form-input"
              placeholder="Rechercher une adresse, une ville…"
            />
            <button @click="searchAddress" type="button" :disabled="searching" class="btn-secondary shrink-0">
              {{ searching ? '…' : 'Chercher' }}
            </button>
          </div>
          <ul
            v-if="results.length"
            class="absolute z-10 mt-1 w-full bg-white border border-surface-200 rounded-lg shadow-warm-lg max-h-48 overflow-auto"
          >
            <li v-for="r in results" :key="r.label">
              <button
                type="button"
                @click="pickResult(r)"
                class="w-full text-left px-3 py-2 hover:bg-surface-50 text-sm text-surface-700"
              >
                {{ r.label }}
              </button>
            </li>
          </ul>
        </div>

        <div ref="mapEl" class="h-80 rounded-lg overflow-hidden border border-surface-200 z-0"></div>

        <p class="mt-3 text-sm" :class="coords ? 'text-surface-700' : 'text-surface-400'">
          <template v-if="coords">Position choisie : {{ coords.lat.toFixed(5) }}, {{ coords.lng.toFixed(5) }}</template>
          <template v-else>Aucune position choisie.</template>
        </p>

        <p v-if="errorMessage" class="mt-2 text-sm text-red-600">{{ errorMessage }}</p>
      </div>

      <div class="flex justify-end gap-2 px-6 py-4 bg-surface-50 border-t border-surface-100">
        <button @click="$emit('close')" type="button" class="btn-secondary">Annuler</button>
        <button
          @click="$emit('apply', { latitude: coords.lat, longitude: coords.lng })"
          type="button"
          :disabled="!coords || saving"
          class="btn-primary"
        >
          {{ saving ? 'Application…' : applyLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
// Sélecteur de position générique (carte + recherche Nominatim) : le parent
// décide quoi faire des coordonnées via l'événement `apply`. Utilisé pour la
// géoloc d'album et la géoloc de masse de la galerie.
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import axios from 'axios';

defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  applyLabel: { type: String, default: 'Appliquer' },
  saving: { type: Boolean, default: false },
  errorMessage: { type: String, default: null },
});
defineEmits(['close', 'apply']);

const mapEl = ref(null);
const coords = ref(null);
const searchQuery = ref('');
const results = ref([]);
const searching = ref(false);
let map = null;
let marker = null;

const pinIcon = L.divIcon({
  html: '<div style="font-size:24px;line-height:1">📍</div>',
  className: '',
  iconSize: [24, 24],
  iconAnchor: [12, 24],
});

const placeMarker = (lat, lng) => {
  coords.value = { lat, lng };
  const latlng = [lat, lng];
  if (marker) {
    marker.setLatLng(latlng);
  } else {
    marker = L.marker(latlng, { icon: pinIcon }).addTo(map);
  }
};

const searchAddress = async () => {
  const q = searchQuery.value.trim();
  if (q.length < 3) return;
  searching.value = true;
  try {
    const { data } = await axios.get('/map/search', { params: { query: q } });
    results.value = (Array.isArray(data) ? data : []).map((r) => ({
      label: r.display_name,
      lat: parseFloat(r.lat),
      lng: parseFloat(r.lon),
    }));
  } catch (e) {
    results.value = [];
  } finally {
    searching.value = false;
  }
};

const pickResult = (r) => {
  results.value = [];
  searchQuery.value = r.label;
  if (map) map.setView([r.lat, r.lng], 13);
  placeMarker(r.lat, r.lng);
};

onMounted(async () => {
  await nextTick();
  map = L.map(mapEl.value).setView([46.603354, 1.888334], 5); // France
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map);

  map.on('click', (e) => {
    placeMarker(e.latlng.lat, e.latlng.lng);
  });

  // La carte est montée dans un conteneur qui vient d'apparaître : recalcul.
  setTimeout(() => map && map.invalidateSize(), 100);
});

onBeforeUnmount(() => {
  if (map) {
    map.remove();
    map = null;
  }
});
</script>
