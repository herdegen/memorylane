<template>
  <!-- Carte des albums à la télécommande : un point par album (son lieu
       principal). Vue fixe sur la zone où sont la plupart des albums ; un
       album lointain (Islande) n'est montré qu'une fois choisi. ◀ ▶ passent
       d'un album à l'autre (du plus récent au plus ancien), son repère
       s'allume ; OK ouvre l'album. -->
  <Head title="Carte — Télévision" />
  <TvLayout
    :tab="tab" :circle="circle" :circles="circles" :account="account"
    back="/tv/ecran/accueil" fill nav-class="relative z-10"
    :hints="[]"
  >
    <div ref="mapEl" class="fixed inset-0 z-0 bg-[#0b0a08]"></div>
    <div class="fixed inset-x-0 top-0 h-[12rem] z-[1] pointer-events-none" style="background: linear-gradient(to bottom, rgba(18,16,12,.95), rgba(18,16,12,0))"></div>
    <div class="fixed inset-x-0 bottom-0 h-[26rem] z-[1] pointer-events-none" style="background: linear-gradient(to top, rgba(18,16,12,.96) 40%, rgba(18,16,12,0))"></div>

    <div class="relative z-10 flex-1 flex flex-col justify-end px-24 pb-10">
      <p v-if="!places.length" class="text-[2rem] text-[#c9bfb1] mb-10">Aucun album avec des photos géolocalisées dans ce cercle pour l'instant.</p>
      <button
        v-else
        type="button"
        data-tv
        data-tv-autofocus
        class="tv-place self-stretch flex items-end gap-10 rounded-[1.75rem] px-9 py-7 text-left"
        @click="openPlace"
      >
        <span class="flex flex-col gap-2 min-w-0 w-[34rem] shrink-0">
          <span class="text-[1.375rem] font-bold text-[#fcd34d] tracking-[.06em] uppercase">
            {{ index < 0 ? 'Vue d\'ensemble' : `Album ${index + 1} sur ${places.length}` }}
          </span>
          <span class="font-display text-[3.5rem] font-bold leading-[1.05] line-clamp-2">{{ index < 0 ? `${places.length} album${places.length > 1 ? 's' : ''} sur la carte` : place.name }}</span>
          <span class="text-[1.75rem] text-[#e7e0d6]">
            <template v-if="index < 0">◀ ▶ pour passer d'un album à l'autre</template>
            <template v-else>{{ place.meta }}</template>
          </span>
          <span v-if="index >= 0 && place.place" class="text-[1.625rem] text-[#fcd34d] line-clamp-1">📍 {{ place.place }}</span>
        </span>
        <span class="flex-1 min-w-0 flex gap-4 overflow-hidden">
          <img v-for="src in previews" :key="src" :src="src" alt="" class="w-[13rem] h-[9.75rem] shrink-0 rounded-[1rem] object-cover bg-[#2c261e]" />
        </span>
      </button>
      <div class="mt-6 flex gap-10 text-[1.375rem] text-[#a89c8b]">
        <span class="flex items-center gap-2.5"><span class="px-3 py-0.5 border-2 border-[#6b6155] rounded-lg font-extrabold">◀ ▶</span> Album précédent / suivant</span>
        <span class="flex items-center gap-2.5"><span class="px-3 py-0.5 border-2 border-[#6b6155] rounded-lg font-extrabold">OK</span> {{ index < 0 ? 'Commencer' : 'Ouvrir l\'album' }}</span>
        <span class="flex items-center gap-2.5"><span class="px-3 py-0.5 border-2 border-[#6b6155] rounded-lg font-extrabold">▲</span> Menu</span>
      </div>
    </div>
  </TvLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import TvLayout from '@/Layouts/TvLayout.vue';
import { onTvKey } from '@/Tv/remote';
import { L, createTvMap, showAll } from '@/Tv/map';

const props = defineProps({ tab: String, circle: Object, circles: Array, account: Object, places: Array });

// -1 : vue d'ensemble ; sinon l'album choisi.
const index = ref(-1);
const place = computed(() => props.places[index.value] || null);
const previews = computed(() => (place.value ? place.value.previews : props.places.flatMap((p) => p.previews.slice(0, 1))).slice(0, 6));

const mapEl = ref(null);
let map = null;
let pin = null;

// Tous les points ont la même taille ; l'album choisi devient un repère
// plus gros qui pulse, avec le nom du lieu.
const DOT = { radius: 9, color: '#12100c', weight: 2, fillColor: '#f59e0b', fillOpacity: 0.9 };

const escape = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// ---- Cadrage -------------------------------------------------------------
// Zone principale : les albums à moins de 1 200 km du « centre » (médiane)
// de tous les albums. 90 % en France et un en Islande : on cadre la France,
// l'Islande ne s'affiche que lorsqu'on choisit cet album.
const CORE_KM = 1200;
const km = (a, b) => {
  const r = Math.PI / 180;
  const h = Math.sin(((b[0] - a[0]) * r) / 2) ** 2
    + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(((b[1] - a[1]) * r) / 2) ** 2;
  return 12742 * Math.asin(Math.sqrt(h));
};
const median = (values) => {
  const v = [...values].sort((x, y) => x - y);
  return v.length ? v[Math.floor(v.length / 2)] : 0;
};
const all = props.places.map((p) => [p.lat, p.lng]);
const middle = [median(all.map((p) => p[0])), median(all.map((p) => p[1]))];
const core = all.filter((p) => km(p, middle) <= CORE_KM);
// Albums vraiment partagés entre deux continents : on montre tout.
const corePoints = core.length >= all.length * 0.6 ? core : all;

const FRAME = { top: 0.2, bottom: 0.44, maxZoom: 8 };
let away = false; // vue déplacée sur un album lointain
const frameFor = (p) => {
  const inCore = corePoints.some((c) => c[0] === p.lat && c[1] === p.lng);
  if (inCore && away) {
    away = false;
    showAll(map, corePoints, FRAME);
  } else if (!inCore) {
    away = true;
    // L'album lointain et sa région, au-dessus du panneau.
    showAll(map, [[p.lat, p.lng]], { ...FRAME, maxZoom: 5 });
  }
};

const show = () => {
  pin?.remove();
  pin = null;
  const p = place.value;
  if (!p) {
    if (away) { away = false; showAll(map, corePoints, FRAME); }
    return;
  }
  frameFor(p);
  pin = L.marker([p.lat, p.lng], {
    interactive: false,
    keyboard: false,
    icon: L.divIcon({
      className: 'tv-pin',
      iconSize: [0, 0],
      html: `<span class="tv-pin-ring"></span><span class="tv-pin-dot"></span><span class="tv-pin-label">${escape(p.place || p.name)}</span>`,
    }),
  }).addTo(map);
  for (const src of props.places[index.value + 1]?.previews || []) new Image().src = src;
};

const go = (step) => {
  if (!props.places.length) return;
  const n = props.places.length;
  index.value = index.value < 0 ? (step > 0 ? 0 : n - 1) : (index.value + step + n) % n;
  show();
};

const openPlace = () => {
  if (index.value < 0) return go(1);
  router.visit(`/tv/ecran/albums/${place.value.id}`);
};

// ◀ ▶ sur le panneau : changer d'album (▲ remonte au menu comme ailleurs).
onTvKey((action) => {
  if (!document.activeElement?.classList?.contains('tv-place')) return false;
  if (action === 'left' || action === 'prev') { go(-1); return true; }
  if (action === 'right' || action === 'next') { go(1); return true; }
  return false;
});

onMounted(() => {
  map = createTvMap(mapEl.value);
  map.setView([46.6, 2.3], 5);
  props.places.forEach((p, i) => {
    L.circleMarker([p.lat, p.lng], DOT).addTo(map).on('click', () => { index.value = i; show(); });
  });
  showAll(map, corePoints, { ...FRAME, animate: false });
});
onBeforeUnmount(() => map?.remove());
</script>

<style scoped>
.tv-place { background: rgba(33, 29, 23, .92); }
.tv-root .tv-place:focus { background: rgba(44, 38, 30, .96); }
/* Repère du lieu choisi : point plus gros, cerclé, qui pulse, et son nom. */
:deep(.tv-pin) { position: relative; }
:deep(.tv-pin-dot) { position: absolute; left: -0.95rem; top: -0.95rem; width: 1.9rem; height: 1.9rem; border-radius: 999px; background: #f59e0b; border: 0.3rem solid #fef3c7; box-shadow: 0 0 0 0.25rem #12100c; }
:deep(.tv-pin-ring) { position: absolute; left: -2rem; top: -2rem; width: 4rem; height: 4rem; border-radius: 999px; border: 0.25rem solid #fcd34d; animation: tv-pin-pulse 1.8s ease-out infinite; }
:deep(.tv-pin-label) { position: absolute; left: 50%; bottom: 1.6rem; transform: translateX(-50%); white-space: nowrap; padding: 0.35rem 1rem; border-radius: 999px; background: #f7f2ea; color: #14110d; font: 700 1.5rem/1.2 Figtree, system-ui, sans-serif; box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,.6); }
@keyframes tv-pin-pulse { from { transform: scale(.5); opacity: .9; } to { transform: scale(1.6); opacity: 0; } }
:deep(.leaflet-control-attribution) { background: rgba(0,0,0,.5); color: #8a7f72; font-size: .9rem; }
:deep(.leaflet-control-attribution a) { color: #a89c8b; }
</style>
