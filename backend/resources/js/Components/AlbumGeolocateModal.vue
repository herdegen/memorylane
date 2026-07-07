<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-surface-900/50" @click.self="$emit('close')">
    <div class="bg-white rounded-modal shadow-warm-lg w-full max-w-2xl overflow-hidden">
      <div class="p-6">
        <h3 class="card-title mb-1">Géolocaliser « {{ album.name }} »</h3>
        <p class="text-sm text-surface-500 mb-4">
          Cliquez sur la carte pour situer toutes les photos de cet album.
          Pratique quand la localisation d'origine a été perdue à l'import.
        </p>

        <div ref="mapEl" class="h-80 rounded-lg overflow-hidden border border-surface-200 z-0"></div>

        <p class="mt-3 text-sm" :class="coords ? 'text-surface-700' : 'text-surface-400'">
          <template v-if="coords">Position choisie : {{ coords.lat.toFixed(5) }}, {{ coords.lng.toFixed(5) }}</template>
          <template v-else>Aucune position choisie.</template>
        </p>
      </div>

      <div class="flex justify-end gap-2 px-6 py-4 bg-surface-50 border-t border-surface-100">
        <button @click="$emit('close')" type="button" class="btn-secondary">Annuler</button>
        <button @click="apply" type="button" :disabled="!coords || saving" class="btn-primary">
          {{ saving ? 'Application…' : "Appliquer à l'album" }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import axios from 'axios';

const props = defineProps({
  album: { type: Object, required: true },
});
const emit = defineEmits(['close', 'done']);

const mapEl = ref(null);
const coords = ref(null);
const saving = ref(false);
let map = null;
let marker = null;

const pinIcon = L.divIcon({
  html: '<div style="font-size:24px;line-height:1">📍</div>',
  className: '',
  iconSize: [24, 24],
  iconAnchor: [12, 24],
});

onMounted(async () => {
  await nextTick();
  map = L.map(mapEl.value).setView([46.603354, 1.888334], 5); // France
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map);

  map.on('click', (e) => {
    coords.value = { lat: e.latlng.lat, lng: e.latlng.lng };
    if (marker) {
      marker.setLatLng(e.latlng);
    } else {
      marker = L.marker(e.latlng, { icon: pinIcon }).addTo(map);
    }
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

const apply = async () => {
  if (!coords.value) return;
  saving.value = true;
  try {
    await axios.post(`/albums/${props.album.id}/geolocate`, {
      latitude: coords.value.lat,
      longitude: coords.value.lng,
    });
    emit('done');
  } catch (e) {
    alert('Erreur : ' + (e.response?.data?.message || e.message));
  } finally {
    saving.value = false;
  }
};
</script>
