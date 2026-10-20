<template>
  <!-- Récit de vie à la télévision : la carte voyage de lieu en lieu, un
       chapitre par événement (grand texte, ses photos qui défilent).
       ◀ ▶ chapitre précédent / suivant · OK pause · ↩ fermer. -->
  <div data-tv-scope class="fixed inset-0 z-[60] bg-[#0b0a08] overflow-hidden select-none">
    <div ref="mapEl" class="absolute inset-0 z-0"></div>
    <div class="absolute inset-0 z-[5] pointer-events-none" style="background: linear-gradient(to right, rgba(11,10,8,.92) 0%, rgba(11,10,8,.55) 42%, rgba(11,10,8,.1) 70%, rgba(11,10,8,.35) 100%)"></div>

    <!-- En-tête : de qui, où l'on en est -->
    <div class="absolute z-10 left-24 right-24 top-[3.375rem] flex items-start justify-between">
      <div class="flex flex-col gap-1">
        <span class="text-[1.625rem] text-[#e7e0d6]">La vie de {{ personName }}</span>
        <span class="text-[1.375rem] text-[#a89c8b]">Chapitre {{ index + 1 }} sur {{ events.length }}</span>
      </div>
      <span class="flex items-center gap-3 px-5 py-2.5 rounded-full text-[1.5rem] font-bold" style="background: rgba(255,255,255,.14)">
        {{ playing ? 'Lecture' : 'En pause' }}
      </span>
    </div>

    <!-- Chapitre -->
    <Transition name="tvchap" mode="out-in">
      <div v-if="ev" :key="index" class="absolute z-10 left-24 bottom-[6.5rem] w-[50rem] flex flex-col gap-4">
        <span class="flex items-center gap-4 text-[2.25rem] text-[#fcd34d] font-bold">
          <span class="text-[3rem] leading-none">{{ ev.icon }}</span>{{ ev.dateLabel }}
        </span>
        <h2 class="font-display text-[4.25rem] font-bold leading-[1.05]">{{ ev.title }}</h2>
        <p v-if="ev.place" class="text-[2rem] text-[#e7e0d6]">{{ ev.place }}</p>
        <p v-if="ev.description" class="text-[1.75rem] text-[#c9bfb1] line-clamp-3">{{ ev.description }}</p>
        <div class="mt-4 flex gap-2.5">
          <span v-for="(_, i) in events" :key="i" class="h-2 rounded-full" :class="i === index ? 'w-12 bg-[#fcd34d]' : 'w-5 bg-[#4a4034]'"></span>
        </div>
      </div>
    </Transition>

    <!-- Photos du chapitre (ou la photo du lieu) -->
    <div class="absolute z-10 right-24 top-[11rem] bottom-[6.5rem] w-[46rem] flex items-center justify-center">
      <Transition name="tvphoto">
        <img
          v-if="photo"
          :key="photo"
          :src="photo"
          alt=""
          class="absolute max-w-full max-h-full object-contain rounded-[1rem] bg-[#f7f2ea] p-2"
          style="box-shadow: 0 2rem 4rem -1rem rgba(0,0,0,.8)"
        />
      </Transition>
    </div>

    <div class="absolute z-10 left-24 bottom-[2.5rem] flex gap-10 text-[1.375rem] text-[#a89c8b]">
      <span>◀ ▶ Chapitre précédent / suivant</span>
      <span>OK {{ playing ? 'Pause' : 'Lecture' }}</span>
      <span>↩ Fermer</span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { onTvKey, tvActivity } from '@/Tv/remote';
import { L, createTvMap, showAll, showPlace } from '@/Tv/map';

const props = defineProps({
  events: { type: Array, required: true },
  personName: { type: String, default: '' },
  start: { type: Number, default: 0 },
});
const emit = defineEmits(['close']);

/** Durée d'un chapitre : 8 s, plus 3 s par photo de la rafale au-delà de deux. */
const chapterMs = (ev) => 8000 + Math.max(0, (ev?.burst?.length || 0) - 2) * 3000;
const PHOTO_MS = 3000;

const index = ref(Math.min(Math.max(props.start, 0), props.events.length - 1));
const playing = ref(true);
const burstIndex = ref(0);
const ev = computed(() => props.events[index.value] || null);

const photos = computed(() => {
  const e = ev.value;
  if (!e) return [];
  if (e.burst?.length) return e.burst.map((b) => b.src);
  const one = e.media?.medium_url || e.media?.url || e.place_photo_url || e.related?.avatar_url;
  return one ? [one] : [];
});
const photo = computed(() => photos.value[burstIndex.value % Math.max(1, photos.value.length)] || null);

// ---- Carte : sombre, sans interaction, voyage d'un lieu à l'autre ----------
const mapEl = ref(null);
let map = null;
let path = null;
let marker = null;
const located = (e) => e && e.latitude != null && e.longitude != null;

const initMap = () => {
  map = createTvMap(mapEl.value);
  const points = props.events.filter(located).map((e) => [e.latitude, e.longitude]);
  if (points.length) showAll(map, points, { maxZoom: 8, animate: false });
  else map.setView([46.6, 2.3], 5);
  path = L.polyline([], { color: '#f59e0b', weight: 4, opacity: 0.85 }).addTo(map);
};

const travel = () => {
  if (!map) return;
  // Le chemin parcouru jusqu'ici, puis on vole vers le lieu du chapitre.
  const visited = props.events.slice(0, index.value + 1).filter(located).map((e) => [e.latitude, e.longitude]);
  path.setLatLngs(visited);
  const e = ev.value;
  if (!located(e)) return;
  const at = [e.latitude, e.longitude];
  if (marker) marker.setLatLng(at);
  else marker = L.circleMarker(at, { radius: 14, color: '#fef3c7', weight: 4, fillColor: '#f59e0b', fillOpacity: 1 }).addTo(map);
  // Le lieu à droite de l'écran (le texte occupe la gauche).
  // Glissement si le lieu est proche, sinon fondu (pas de survol : trop lourd pour un projecteur).
  showPlace(map, at, 9);
};

// ---- Lecture ----------------------------------------------------------------
let chapterTimer = null;
let photoTimer = null;
const schedule = () => {
  clearTimeout(chapterTimer);
  clearInterval(photoTimer);
  if (photos.value.length > 1) photoTimer = setInterval(() => { burstIndex.value++; }, PHOTO_MS);
  if (!playing.value) return;
  chapterTimer = setTimeout(() => {
    if (index.value < props.events.length - 1) index.value++;
    else playing.value = false;
  }, chapterMs(ev.value));
};

const go = (step) => {
  const next = index.value + step;
  if (next >= 0 && next < props.events.length) index.value = next;
};

watch(index, () => {
  burstIndex.value = 0;
  travel();
  schedule();
  // Photos du chapitre suivant préchargées.
  for (const b of props.events[index.value + 1]?.burst || []) new Image().src = b.src;
});
watch(playing, schedule);

onTvKey((action) => {
  switch (action) {
    case 'left': case 'prev': go(-1); break;
    case 'right': case 'next': go(1); break;
    case 'ok': case 'playpause': playing.value = !playing.value; break;
    case 'back': emit('close'); break;
    default: break;
  }
  return true;
});

onMounted(() => {
  tvActivity.blockers++;
  initMap();
  travel();
  schedule();
});
onBeforeUnmount(() => {
  tvActivity.blockers--;
  tvActivity.last = Date.now();
  clearTimeout(chapterTimer);
  clearInterval(photoTimer);
  map?.remove();
});
</script>

<style scoped>
.tvchap-enter-active, .tvchap-leave-active { transition: opacity .45s ease, transform .45s ease; }
.tvchap-enter-from { opacity: 0; transform: translateY(1.5rem); }
.tvchap-leave-to { opacity: 0; transform: translateY(-1rem); }
.tvphoto-enter-active, .tvphoto-leave-active { transition: opacity .9s ease; }
.tvphoto-enter-from, .tvphoto-leave-to { opacity: 0; }
:deep(.leaflet-control-attribution) { background: rgba(0,0,0,.5); color: #8a7f72; font-size: .9rem; }
:deep(.leaflet-control-attribution a) { color: #a89c8b; }
</style>
