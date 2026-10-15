<template>
  <!-- Carte plein écran entre deux médias tournés à des endroits différents -->
  <div v-show="visible" class="absolute inset-0 bg-black">
    <div ref="mapEl" class="absolute inset-0"></div>
    <Transition name="fade">
      <div
        v-if="label"
        class="absolute bottom-24 left-1/2 -translate-x-1/2 max-w-[90vw] truncate rounded-full bg-black/65 px-5 py-2 text-lg font-semibold text-snow shadow-lg"
      >
        📍 {{ label }}
      </div>
    </Transition>
  </div>
</template>

<script setup>
// Interlude du diaporama qui voyage (albums tournés à plusieurs endroits) :
// la carte trace le trajet du lieu précédent au suivant, zoome sur
// l'étape et l'annonce ; le chemin parcouru reste en pointillé, comme dans
// le récit de vie. Leaflet chargé à la première étape seulement.
import { ref, nextTick, onBeforeUnmount } from 'vue';

const visible = ref(false);
const label = ref('');
const mapEl = ref(null);
let L = null;
let map = null;
let trail = null;
let segment = null;
let marker = null;
let token = 0;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const ensureMap = async () => {
  if (map) return;
  [L] = await Promise.all([import('leaflet').then((m) => m.default), import('leaflet/dist/leaflet.css')]);
  map = L.map(mapEl.value, {
    zoomControl: false, dragging: false, scrollWheelZoom: false, doubleClickZoom: false,
    boxZoom: false, keyboard: false, touchZoom: false,
  });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
  trail = L.polyline([], { color: '#f59e0b', weight: 3, opacity: 0.85, dashArray: '6 8' }).addTo(map);
  segment = L.polyline([], { color: '#f59e0b', weight: 4 }).addTo(map);
  marker = L.circleMarker([0, 0], { radius: 9, color: '#fff', weight: 3, fillColor: '#f59e0b', fillOpacity: 1 });
};

/**
 * Vole de `from` (null : première étape) à `to` ; résout quand l'étape est
 * annoncée. Un nouvel appel ou cancel() interrompt l'animation en cours.
 */
const fly = async (from, to, placeLabel) => {
  const mine = ++token;
  visible.value = true;
  label.value = '';
  await nextTick();
  await ensureMap();
  if (mine !== token) return false;
  map.invalidateSize();
  const dest = [to.latitude, to.longitude];
  marker.addTo(map);

  if (!from || reduceMotion()) {
    map.setView(dest, 10, { animate: false });
    marker.setLatLng(dest);
    label.value = placeLabel;
    await sleep(1800);
    return mine === token;
  }

  const start = [from.latitude, from.longitude];
  map.fitBounds(L.latLngBounds([start, dest]), { padding: [90, 90], maxZoom: 10, animate: false });
  marker.setLatLng(start);
  segment.setLatLngs([start]);
  const steps = 45;
  for (let i = 1; i <= steps; i++) {
    if (mine !== token) return false;
    const f = i / steps;
    const point = [start[0] + (dest[0] - start[0]) * f, start[1] + (dest[1] - start[1]) * f];
    segment.addLatLng(point);
    marker.setLatLng(point);
    await sleep(1700 / steps);
  }
  trail.addLatLng(start).addLatLng(dest);
  segment.setLatLngs([]);
  map.flyTo(dest, 10, { duration: 1 });
  await sleep(1000);
  label.value = placeLabel;
  await sleep(1100);
  return mine === token;
};

const hide = () => {
  visible.value = false;
  label.value = '';
};

const cancel = () => {
  token++;
  hide();
};

/** Nouvelle séance : trajet effacé. */
const reset = () => {
  cancel();
  trail?.setLatLngs([]);
  segment?.setLatLngs([]);
};

defineExpose({ fly, hide, cancel, reset });

onBeforeUnmount(() => {
  token++;
  map?.remove();
  map = null;
});
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.4s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
