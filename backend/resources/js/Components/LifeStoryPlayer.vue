<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[80] bg-surface-900">
      <!-- Carte plein écran (le récit voyage de lieu en lieu) -->
      <!-- z-0 : confine les panes internes de Leaflet (z-index 400+) sous les surcouches -->
      <div ref="mapEl" class="absolute inset-0 z-0"></div>
      <div class="absolute inset-0 z-[5] pointer-events-none bg-black/10"></div>

      <!-- Rafale de photos de l'événement (fondu enchaîné) -->
      <div v-show="burst.length > 0" class="absolute inset-0 z-10 bg-black">
        <img
          v-for="(src, i) in burst"
          :key="src + i"
          :src="src"
          class="absolute inset-0 w-full h-full object-contain transition-opacity duration-700"
          :class="i === burstIndex ? 'opacity-100' : 'opacity-0'"
        />
      </div>

      <!-- Carte-chapitre de l'événement -->
      <Transition
        enter-active-class="transition duration-500"
        enter-from-class="opacity-0 translate-y-4"
        leave-active-class="transition duration-300"
        leave-to-class="opacity-0"
      >
        <div
          v-if="currentEvent && cardVisible && burst.length === 0"
          class="absolute z-20 left-4 bottom-4 sm:left-10 sm:bottom-10 w-[min(92%,420px)] bg-white/95 backdrop-blur rounded-2xl shadow-warm-lg overflow-hidden"
        >
          <div v-if="currentEvent.place_photo_url" class="relative">
            <img :src="currentEvent.place_photo_url" class="w-full h-40 object-cover" />
            <span class="absolute bottom-1 right-2 text-[10px] text-white/80 [text-shadow:0_1px_2px_rgba(0,0,0,0.8)]">
              Photo du lieu — Wikimedia Commons
            </span>
          </div>
          <div class="p-5">
            <p class="text-xs font-semibold uppercase tracking-widest text-brand-700">{{ currentEvent.dateLabel }}</p>
            <p class="font-display text-2xl font-semibold text-surface-900 mt-1">
              {{ currentEvent.icon }} {{ currentEvent.title }}
            </p>
            <p v-if="currentEvent.place" class="text-sm text-surface-500 mt-1">{{ currentEvent.place }}</p>
            <p v-if="currentEvent.description" class="text-sm text-surface-600 mt-2 line-clamp-3 whitespace-pre-line">{{ currentEvent.description }}</p>
          </div>
        </div>
      </Transition>

      <!-- Fin du récit -->
      <div v-if="finished" class="absolute inset-0 z-30 flex items-center justify-center bg-black/55">
        <div class="text-center">
          <p class="font-display text-3xl font-semibold text-white">La vie de {{ personName }}</p>
          <p class="text-white/70 text-sm mt-2">{{ events.length }} moments — et l'histoire continue.</p>
          <button @click="restart" class="mt-5 inline-flex items-center gap-2 px-5 py-2 rounded-full bg-white/90 text-surface-900 text-sm font-semibold hover:bg-white transition">
            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
            Revoir
          </button>
        </div>
      </div>

      <!-- Progression (points chapitres) -->
      <div class="absolute z-30 top-5 left-1/2 -translate-x-1/2 flex items-center gap-1.5">
        <button
          v-for="(ev, i) in events"
          :key="ev.date + i"
          @click="goTo(i)"
          class="rounded-full transition-all"
          :class="i === chapterIndex ? 'w-6 h-2 bg-white' : 'w-2 h-2 bg-white/45 hover:bg-white/70'"
          :title="ev.title"
        ></button>
      </div>

      <!-- Contrôles -->
      <div class="absolute z-30 top-4 right-4 flex items-center gap-2">
        <button @click="goTo(chapterIndex - 1)" :disabled="chapterIndex === 0" class="w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 transition disabled:opacity-40" title="Précédent">
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <button @click="togglePause" class="w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 transition" :title="paused ? 'Reprendre' : 'Pause'">
          <svg v-if="paused" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
          <svg v-else class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M6 5h4v14H6zM14 5h4v14h-4z" /></svg>
        </button>
        <button @click="goTo(chapterIndex + 1)" :disabled="chapterIndex >= events.length - 1" class="w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 transition disabled:opacity-40" title="Suivant">
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        </button>
        <button @click="close" class="w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 transition" title="Fermer">
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, nextTick, onBeforeUnmount } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

/**
 * Récit de vie : la carte voyage de lieu en lieu (naissance, baptême,
 * déménagements, mariage…), chaque événement affiche sa carte-chapitre
 * (avec photo du lieu trouvée sur Wikimedia quand elle existe), et les
 * événements riches en photos déroulent une rafale en fondu enchaîné
 * avant de repartir.
 *
 * `events` : items de frise ASC, enrichis par le parent :
 *  { date, dateLabel, kind, icon, title, place, description,
 *    latitude?, longitude?, place_photo_url?, burst: [urls] }
 */
const props = defineProps({
  events: { type: Array, default: () => [] },
  personName: { type: String, default: '' },
});

const open = ref(false);
const chapterIndex = ref(0);
const cardVisible = ref(false);
const burst = ref([]);
const burstIndex = ref(0);
const paused = ref(false);
const finished = ref(false);

const mapEl = ref(null);
let map = null;
let marker = null;
// Jeton d'annulation : incrémenté à chaque saut/fermeture, les boucles en
// cours s'arrêtent d'elles-mêmes.
let generation = 0;

const CARD_HOLD_MS = 4200;
const BURST_PHOTO_MS = 2300;
const FLY_MS = 2600;

const currentEvent = computed(() => props.events[chapterIndex.value] || null);

const sleep = async (ms, gen) => {
  const start = Date.now();
  while (Date.now() - start < ms) {
    if (gen !== generation) return false;
    if (paused.value) {
      await new Promise((r) => setTimeout(r, 150));
      continue;
    }
    await new Promise((r) => setTimeout(r, Math.min(150, ms - (Date.now() - start))));
  }
  return gen === generation;
};

const initMap = () => {
  if (map || !mapEl.value) return;
  const first = props.events.find((e) => e.latitude != null);
  map = L.map(mapEl.value, {
    zoomControl: false,
    attributionControl: true,
    dragging: false,
    scrollWheelZoom: false,
    doubleClickZoom: false,
    boxZoom: false,
    keyboard: false,
    touchZoom: false,
  }).setView(first ? [first.latitude, first.longitude] : [46.6, 1.88], first ? 7 : 6);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap',
  }).addTo(map);
  marker = L.marker(first ? [first.latitude, first.longitude] : [46.6, 1.88], {
    icon: L.divIcon({ className: 'life-story-marker', html: '<div class="life-story-dot"></div>', iconSize: [18, 18], iconAnchor: [9, 9] }),
    interactive: false,
    opacity: 0,
  }).addTo(map);
};

async function playFrom(index) {
  const gen = ++generation;
  finished.value = false;

  for (let i = index; i < props.events.length; i++) {
    if (gen !== generation) return;
    chapterIndex.value = i;
    const ev = props.events[i];
    cardVisible.value = false;
    burst.value = [];

    // 1. Voyage sur la carte vers le lieu de l'événement.
    if (ev.latitude != null && map) {
      marker.setOpacity(0);
      map.flyTo([ev.latitude, ev.longitude], 13, { duration: FLY_MS / 1000 });
      if (!(await sleep(FLY_MS + 250, gen))) return;
      marker.setLatLng([ev.latitude, ev.longitude]);
      marker.setOpacity(1);
    }

    // 2. Carte-chapitre.
    cardVisible.value = true;
    if (!(await sleep(CARD_HOLD_MS, gen))) return;

    // 3. Rafale de photos de l'événement (fondu enchaîné).
    if (ev.burst?.length) {
      cardVisible.value = false;
      burst.value = ev.burst;
      for (let b = 0; b < ev.burst.length; b++) {
        burstIndex.value = b;
        if (!(await sleep(BURST_PHOTO_MS, gen))) return;
      }
      burst.value = [];
    }
  }

  if (gen === generation) finished.value = true;
}

const start = async () => {
  if (!props.events.length) return;
  open.value = true;
  paused.value = false;
  finished.value = false;
  chapterIndex.value = 0;
  document.addEventListener('keydown', onKeydown);
  await nextTick();
  initMap();
  // Laisser la carte peindre ses tuiles avant le premier vol.
  setTimeout(() => map?.invalidateSize(), 50);
  playFrom(0);
};

const restart = () => playFrom(0);

const goTo = (index) => {
  if (index < 0 || index >= props.events.length) return;
  playFrom(index);
};

const togglePause = () => {
  paused.value = !paused.value;
};

const close = () => {
  generation += 1;
  open.value = false;
  burst.value = [];
  cardVisible.value = false;
  document.removeEventListener('keydown', onKeydown);
  map?.remove();
  map = null;
  marker = null;
};

const onKeydown = (e) => {
  if (e.key === 'Escape') close();
  if (e.key === 'ArrowRight') goTo(chapterIndex.value + 1);
  if (e.key === 'ArrowLeft') goTo(chapterIndex.value - 1);
  if (e.key === ' ') { e.preventDefault(); togglePause(); }
};

onBeforeUnmount(close);

defineExpose({ start });
</script>

<style>
/* Marqueur pulsant du récit de vie (élément créé par Leaflet → style global). */
.life-story-dot {
  width: 18px;
  height: 18px;
  border-radius: 999px;
  background: #f59e0b;
  border: 3px solid #ffffff;
  box-shadow: 0 0 0 rgba(245, 158, 11, 0.5);
  animation: life-story-pulse 1.8s infinite;
}
@keyframes life-story-pulse {
  0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.5); }
  70% { box-shadow: 0 0 0 16px rgba(245, 158, 11, 0); }
  100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
}
</style>
