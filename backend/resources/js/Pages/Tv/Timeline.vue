<template>
  <!-- « La famille dans le temps » à la télécommande : la famille se déplace
       sur la carte, des plus anciens ancêtres à aujourd'hui. Frise : OK
       lecture / pause, ◀ ▶ reculer / avancer. Dessous, les réglages : toute
       la famille ou mes proches, voyages, vitesse. -->
  <Head :title="$t('La famille dans le temps — Télévision')" />
  <TvLayout
    :tab="tab" :circle="circle" :circles="circles" :account="account"
    back="/tv/ecran/carte" fill nav-class="relative z-10"
    :hints="[]"
  >
    <div ref="mapEl" class="fixed inset-0 z-0 bg-[#0b0a08]"></div>
    <div class="fixed inset-x-0 top-0 h-[12rem] z-[1] pointer-events-none" style="background: linear-gradient(to bottom, rgba(18,16,12,.95), rgba(18,16,12,0))"></div>
    <div class="fixed inset-x-0 bottom-0 h-[24rem] z-[1] pointer-events-none" style="background: linear-gradient(to top, rgba(18,16,12,.96) 40%, rgba(18,16,12,0))"></div>

    <div class="relative z-10 flex-1 flex flex-col justify-end px-24 pb-10">
      <p v-if="state === 'loading'" class="text-[2rem] text-[#c9bfb1] mb-10">{{ $t('On remonte le temps…') }}</p>
      <p v-else-if="state === 'empty'" class="text-[2rem] text-[#c9bfb1] mb-10">{{ $t('Pas encore assez de données pour remonter le temps') }}</p>
      <template v-else>
        <div class="tv-year font-display" aria-hidden="true">{{ yearLabel }}</div>
        <div class="tv-panel rounded-[1.75rem] px-9 py-6">
          <button type="button" data-tv data-tv-autofocus class="tv-timeline w-full flex items-center gap-7 rounded-[1.25rem] px-5 py-4 text-left" @click="togglePlay">
            <span class="w-[4.5rem] h-[4.5rem] shrink-0 rounded-full bg-[#f59e0b] text-[#12100c] flex items-center justify-center text-[2rem] font-black" aria-hidden="true">{{ playing ? '❚❚' : '▶' }}</span>
            <span class="flex-1 min-w-0">
              <span class="block h-[0.75rem] rounded-full bg-[#3b342b] overflow-hidden">
                <span class="block h-full bg-[#f59e0b]" :style="{ width: `${progress * 100}%` }"></span>
              </span>
              <span class="mt-2 flex justify-between text-[1.5rem] text-[#a89c8b] tabular-nums">
                <span>{{ fromLabel }}</span>
                <span>{{ presentLabel }}</span>
                <span>{{ toLabel }}</span>
              </span>
            </span>
          </button>
          <div class="mt-5 flex gap-4" data-tv-row>
            <button type="button" data-tv class="tv-chip" @click="toggleFocus">👪 {{ focus ? $t('Mes proches') : $t('Toute ma famille') }}</button>
            <button type="button" data-tv class="tv-chip" @click="toggleTrips">✈️ {{ trips ? $t('Voyages : oui') : $t('Voyages : non') }}</button>
            <button type="button" data-tv class="tv-chip" @click="cycleSpeed">{{ $t('Vitesse :speed×', { speed }) }}</button>
          </div>
        </div>
      </template>
      <div class="mt-6 flex gap-10 text-[1.375rem] text-[#a89c8b]">
        <span class="flex items-center gap-2.5"><span class="px-3 py-0.5 border-2 border-[#6b6155] rounded-lg font-extrabold">OK</span> {{ $t('Lecture / pause') }}</span>
        <span class="flex items-center gap-2.5"><span class="px-3 py-0.5 border-2 border-[#6b6155] rounded-lg font-extrabold">◀ ▶</span> {{ $t('Reculer / avancer') }}</span>
        <span class="flex items-center gap-2.5"><span class="px-3 py-0.5 border-2 border-[#6b6155] rounded-lg font-extrabold">▼</span> {{ $t('Réglages') }}</span>
        <span class="flex items-center gap-2.5"><span class="px-3 py-0.5 border-2 border-[#6b6155] rounded-lg font-extrabold">▲</span> {{ $t('Menu') }}</span>
      </div>
    </div>
  </TvLayout>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import 'leaflet.heat';
import TvLayout from '@/Layouts/TvLayout.vue';
import { onTvKey, focusInitial } from '@/Tv/remote';
import { L, createTvMap, showAll } from '@/Tv/map';
import { tc, intlLocale } from '@/i18n';
import { createAnimator, createGlowLayer, createTimeScale, dataBounds, timeLabelParts, tripSpans } from '@/utils/familyTimeline';

defineProps({ tab: String, circle: Object, circles: Array, account: Object, family: Boolean });

// Réglages mémorisés sur l'appareil (la TV reste dans la même pièce).
const read = (key, fallback) => { try { return localStorage.getItem(key) ?? fallback; } catch { return fallback; } };
const write = (key, value) => { try { localStorage.setItem(key, value); } catch { /* stockage indisponible */ } };

const state = ref('loading');
const focus = ref(read('ml-tv-timeline-focus', '') === 'moi');
const trips = ref(read('ml-timeline-trips', '1') !== '0');
const SPEEDS = [1, 2, 4, 0.5];
const speed = ref(1);
const playing = ref(false);
const progress = ref(0);
const year = ref(0);
const present = ref(0);
const range = ref({ from: 0, to: 1 });

const mapEl = ref(null);
let map = null;
let heat = null;
let glow = null;
let animator = null;
let scale = null;
let raf = 0;
let lastTick = 0;
let lastHeat = 0;
let destroyed = false;
let load = 0;

const numberFormat = new Intl.NumberFormat(intlLocale());
const yearOnly = (y) => timeLabelParts(y, intlLocale(), false)[0].value;
const yearLabel = computed(() => timeLabelParts(year.value, intlLocale(), trips.value && !!scale?.monthly(year.value)).map((p) => p.value).join(''));
const fromLabel = computed(() => yearOnly(range.value.from));
const toLabel = computed(() => yearOnly(range.value.to));
const presentLabel = computed(() => tc(':count personne|:count personnes', present.value, { count: numberFormat.format(present.value) }));

// Heatmap des positions du moment, réglée pour de faibles densités ; sur un
// projecteur, redessinée au plus toutes les 250 ms (les points lumineux, eux,
// suivent chaque image).
const HEAT = { radius: 34, blur: 26, maxZoom: 0, max: 3, minOpacity: 0.04, gradient: { 0.2: '#fde68a', 0.4: '#fbbf24', 0.6: '#f97316', 0.8: '#e11d48', 1: '#9f1239' } };

function teardown() {
  cancelAnimationFrame(raf);
  raf = 0;
  playing.value = false;
  if (heat) map.removeLayer(heat);
  if (glow) map.removeLayer(glow);
  heat = glow = animator = null;
}

async function reload() {
  const mine = ++load;
  teardown();
  state.value = 'loading';
  const params = {};
  if (focus.value) params.proches = 'moi';
  if (!trips.value) params.voyages = 0;
  const { data } = await axios.get('/map/heatmap/timeline', { params });
  if (destroyed || mine !== load) return;
  const tracks = data.tracks || [];
  if (tracks.length < 2 || !data.range) {
    state.value = 'empty';
    return;
  }
  range.value = { from: Number(data.range.from), to: Number(data.range.to) };
  scale = createTimeScale(range.value, trips.value ? { slowSpans: tripSpans(tracks) } : { secondsPerYear: 0.5 });
  animator = createAnimator(tracks, { rangeTo: range.value.to });
  heat = L.heatLayer([], HEAT).addTo(map);
  glow = createGlowLayer(L)({ dark: true }).addTo(map);
  state.value = 'ready';
  // La frise n'existe qu'une fois les données là : on lui donne le focus.
  await nextTick();
  focusInitial();

  // Cadrage sur les lieux de la famille, entre l'en-tête et le panneau
  // (en proportion de l'écran : les TV n'ont pas toutes la même taille).
  const bounds = dataBounds(tracks);
  if (bounds) showAll(map, [bounds[0], bounds[1]], { top: 0.18, bottom: 0.46, maxZoom: 7, animate: false });
  seek(0);
  render();
  setTimeout(() => { if (mine === load && state.value === 'ready') play(); }, 1200);
}

function seek(p) {
  progress.value = Math.min(1, Math.max(0, p));
  year.value = scale.yearAt(progress.value);
}

function render() {
  if (!raf && animator) raf = requestAnimationFrame(tick);
}

function tick(now) {
  raf = 0;
  if (destroyed || !animator) return;
  const dt = lastTick ? Math.min(0.1, (now - lastTick) / 1000) : 0;
  lastTick = now;
  if (playing.value) {
    seek(progress.value + (dt * speed.value) / scale.duration);
    if (progress.value >= 1) playing.value = false;
  }
  const frame = animator.frame(year.value, now, playing.value);
  present.value = frame.present;
  glow.setDots(frame.dots);
  const settled = !playing.value && !frame.busy;
  if (settled || now - lastHeat >= 250) {
    heat.setLatLngs(frame.heat.slice());
    lastHeat = now;
  }
  if (settled) lastTick = 0;
  else raf = requestAnimationFrame(tick);
}

function play() {
  if (progress.value >= 1 - 1e-6) seek(0);
  playing.value = true;
  lastTick = 0;
  render();
}
const togglePlay = () => (playing.value ? (playing.value = false) : play());

function toggleFocus() {
  focus.value = !focus.value;
  write('ml-tv-timeline-focus', focus.value ? 'moi' : '');
  reload();
}
function toggleTrips() {
  trips.value = !trips.value;
  write('ml-timeline-trips', trips.value ? '1' : '0');
  reload();
}
const cycleSpeed = () => { speed.value = SPEEDS[(SPEEDS.indexOf(speed.value) + 1) % SPEEDS.length]; };

// Sur la frise : ◀ ▶ reculent / avancent (un vingtième de la lecture).
onTvKey((action) => {
  if (!document.activeElement?.classList?.contains('tv-timeline') || !scale) return false;
  if (action === 'left' || action === 'prev') { seek(progress.value - 0.05); render(); return true; }
  if (action === 'right' || action === 'next') { seek(progress.value + 0.05); render(); return true; }
  return false;
});

onMounted(() => {
  map = createTvMap(mapEl.value);
  map.setView([46.6, 2.3], 4);
  reload();
});
onBeforeUnmount(() => {
  destroyed = true;
  teardown();
  map?.remove();
});
</script>

<style scoped>
.tv-panel { background: rgba(33, 29, 23, .92); }
.tv-year {
  margin: 0 0 0.75rem 0.5rem;
  font-size: 7rem;
  font-weight: 600;
  line-height: 1;
  font-variant-numeric: lining-nums tabular-nums;
  color: #f7f2ea;
  text-shadow: 0 0 1.5rem rgba(0, 0, 0, .9);
}
.tv-root .tv-timeline:focus { background: rgba(52, 45, 35, .96); box-shadow: 0 0 0 0.3rem #fcd34d; }
.tv-chip { padding: 0.8rem 1.6rem; border-radius: 999px; background: #2c261e; color: #e7e0d6; font-size: 1.625rem; font-weight: 700; }
.tv-root .tv-chip:focus { background: #f7f2ea; color: #14110d; box-shadow: 0 0 0 0.3rem #fcd34d; }
:deep(.leaflet-container) { background: #0b0a08; }
:deep(.leaflet-control-attribution) { background: rgba(0,0,0,.5); color: #8a7f72; font-size: .9rem; }
</style>
