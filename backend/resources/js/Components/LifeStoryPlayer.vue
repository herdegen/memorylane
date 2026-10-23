<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[80] bg-surface-900">
      <!-- Carte plein écran (le récit voyage de lieu en lieu) -->
      <!-- z-0 : confine les panes internes de Leaflet (z-index 400+) sous les surcouches -->
      <div ref="mapEl" class="absolute inset-0 z-0"></div>
      <!-- Voile : la carte reste un décor, le texte doit rester lisible par-dessus -->
      <div class="absolute inset-0 z-[5] pointer-events-none bg-gradient-to-t from-black/60 via-black/15 to-black/25"></div>

      <!-- Rafale, desktop : les photos glissent une à une et s'empilent dans
           la zone libre au-dessus du chapitre, qui reste lisible. -->
      <div v-if="burst.length > 0 && isDesktop" class="absolute inset-x-0 top-16 bottom-[44%] z-10 pointer-events-none">
        <div class="relative w-full h-full flex items-center justify-center">
          <img
            v-for="(photo, i) in burst"
            :key="photo.src + i"
            :src="photo.src"
            class="absolute max-h-[92%] max-w-[72%] object-contain bg-white p-1.5 rounded-sm shadow-2xl transition-all duration-500 ease-out"
            :class="i <= burstIndex ? 'pointer-events-auto cursor-zoom-in' : ''"
            :style="pileStyle(i)"
            :title="photo.title"
            @click="openZoom(photo.full)"
          />
        </div>
      </div>

      <!-- Rafale, mobile : plein écran en fondu, rappel du chapitre en bas -->
      <div v-show="burst.length > 0 && !isDesktop && burstIndex >= 0" class="absolute inset-0 z-10 bg-black">
        <img
          v-for="(photo, i) in burst"
          :key="photo.src + i"
          :src="photo.src"
          class="absolute inset-0 w-full h-full object-contain transition-opacity duration-700"
          :class="i === burstIndex ? 'opacity-100 cursor-zoom-in' : 'opacity-0 pointer-events-none'"
          @click="openZoom(photo.full)"
        />
        <div v-if="currentEvent" class="absolute inset-x-0 bottom-0 px-6 pb-6 pt-16 bg-gradient-to-t from-black/80 to-transparent pointer-events-none">
          <p class="text-xs font-semibold uppercase tracking-widest text-brand-300">{{ currentEvent.dateLabel }}</p>
          <p class="font-display text-xl font-semibold text-white mt-1">{{ currentEvent.icon }} {{ currentEvent.title }}</p>
        </div>
      </div>

      <!-- Chapitre : LE texte est l'information principale, il occupe le bas
           de l'écran en grand ; la carte (et son marqueur) vit au-dessus. -->
      <Transition
        enter-active-class="transition duration-500"
        enter-from-class="opacity-0 translate-y-6"
        leave-active-class="transition duration-300"
        leave-to-class="opacity-0"
      >
        <div
          v-if="currentEvent && cardVisible && (burst.length === 0 || isDesktop)"
          class="absolute z-20 inset-x-0 bottom-0 flex justify-center px-3 pb-3 sm:px-8 sm:pb-8 pointer-events-none"
        >
          <div class="w-full max-w-3xl bg-white/95 backdrop-blur rounded-2xl shadow-warm-lg overflow-hidden flex flex-col sm:flex-row pointer-events-auto">
            <!-- Illustration : la photo de l'événement d'abord ; à défaut, une
                 photo du lieu trouvée sur Wikimedia Commons. -->
            <div v-if="chapterImage" class="relative sm:w-56 shrink-0">
              <!-- Un document se lit en grand : clic = zoom, le récit attend. -->
              <img
                :src="chapterImage"
                class="w-full h-32 sm:h-full object-cover"
                :class="currentEvent.media ? 'cursor-zoom-in' : ''"
                :title="currentEvent.media ? $t('Agrandir') : ''"
                @click="currentEvent.media && openZoom(currentEvent.media.url || chapterImage)"
              />
              <span v-if="!currentEvent.media" class="absolute bottom-1 right-2 text-[10px] text-white/80 [text-shadow:0_1px_2px_rgba(0,0,0,0.8)]">
                {{ $t('Photo du lieu — Wikimedia Commons') }}
              </span>
            </div>
            <div class="p-5 sm:p-7 flex-1 min-w-0">
              <p class="text-xs sm:text-sm font-semibold uppercase tracking-widest text-brand-700">
                {{ currentEvent.dateLabel }}<span v-if="currentEvent.place" class="normal-case tracking-normal font-medium text-surface-500"> · {{ currentEvent.place }}</span>
              </p>
              <p class="font-display text-2xl sm:text-4xl font-semibold text-surface-900 mt-1 leading-tight">
                {{ currentEvent.icon }} {{ currentEvent.title }}
              </p>
              <p
                v-if="currentEvent.description"
                class="text-base sm:text-lg leading-relaxed text-surface-700 mt-3 whitespace-pre-line line-clamp-5 sm:line-clamp-7"
              >{{ currentEvent.description }}</p>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Zoom plein écran (document, photo) : le récit est en pause tant
           qu'il est ouvert et reprend à la fermeture. -->
      <div v-if="zoomSrc" class="absolute inset-0 z-40 bg-black/92 flex items-center justify-center cursor-zoom-out" @click="closeZoom">
        <img :src="zoomSrc" class="max-w-[96vw] max-h-[94vh] object-contain shadow-2xl" @click.stop />
        <button @click="closeZoom" class="absolute top-4 right-4 w-10 h-10 rounded-full bg-white/15 text-white flex items-center justify-center hover:bg-white/30 transition" :title="$t('Fermer (Échap)')">
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </div>

      <!-- Fin du récit -->
      <div v-if="finished" class="absolute inset-0 z-30 flex items-center justify-center bg-black/55">
        <div class="text-center">
          <p class="font-display text-3xl font-semibold text-white">{{ $t('La vie de :name', { name: personName }) }}</p>
          <p class="text-white/70 text-sm mt-2">{{ $tc(":count moment — et l'histoire continue.|:count moments — et l'histoire continue.", events.length) }}</p>
          <button @click="restart" class="mt-5 inline-flex items-center gap-2 px-5 py-2 rounded-full bg-white/90 text-surface-900 text-sm font-semibold hover:bg-white transition">
            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
            {{ $t('Revoir') }}
          </button>
          <div>
            <button @click="close" class="mt-3 inline-flex items-center gap-2 px-5 py-2 rounded-full bg-white/20 border border-white/70 text-white text-sm font-semibold shadow-lg hover:bg-white/35 transition">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
              {{ $t('Fermer') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Progression : barre blanche continue en haut (chapitre en cours
           compris), cliquable pour sauter à un chapitre. -->
      <div
        class="absolute z-30 top-0 inset-x-0 h-6 cursor-pointer group/bar"
        :title="`${chapterIndex + 1} / ${events.length}`"
        @click="seekFromBar"
      >
        <div class="absolute top-2 inset-x-3 h-1 rounded-full bg-white/25 overflow-hidden transition-all group-hover/bar:h-1.5">
          <div class="h-full bg-white rounded-full transition-[width] duration-200 ease-linear" :style="{ width: `${progressPercent}%` }"></div>
        </div>
      </div>

      <!-- Contrôles -->
      <div class="absolute z-30 top-4 right-4 flex items-center gap-2">
        <button @click="prev" :disabled="!canGoPrev" class="w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 transition disabled:opacity-40" :title="$t('Précédent')">
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <button @click="togglePause" class="w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 transition" :title="paused ? $t('Reprendre') : $t('Pause')">
          <svg v-if="paused" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
          <svg v-else class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M6 5h4v14H6zM14 5h4v14h-4z" /></svg>
        </button>
        <button @click="next" :disabled="!canGoNext" class="w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 transition disabled:opacity-40" :title="$t('Suivant')">
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        </button>
        <button @click="close" class="w-10 h-10 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65 transition" :title="$t('Fermer')">
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
 * Récit de vie : la carte voyage de lieu en lieu, chaque événement affiche
 * son chapitre (texte en grand, photo du lieu Wikimedia quand elle existe),
 * et les événements riches en photos déroulent une rafale.
 *
 * Mise en scène de la carte : au départ, un seul cadrage englobant TOUS les
 * lieux de la frise (calculés en amont). Ensuite la carte ne bouge plus tant
 * que la destination est visible : seul le chemin se trace (arc de grand
 * cercle) avec le marqueur qui le parcourt, d'autant plus vite que le trajet
 * est court À L'ÉCRAN. Elle ne se recadre que si un lieu sort du champ. Le
 * chemin parcouru reste en pointillé : une vie qui bouge beaucoup se lit
 * comme un itinéraire sur la carte du monde.
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
// Desktop : rafale en pile au-dessus du texte ; mobile : plein écran.
const isDesktop = ref(true);

const mapEl = ref(null);
let map = null;
let marker = null;
let trail = null; // chemin déjà parcouru (pointillé)
let segment = null; // tronçon en cours de tracé
let visitedLayer = null; // petits points des lieux déjà visités
// Jeton d'annulation : incrémenté à chaque saut/fermeture, les boucles en
// cours s'arrêtent d'elles-mêmes.
let generation = 0;
// Horloge du chapitre en cours (temps hors pause) : pilote la révélation des
// photos, que ← / → peuvent devancer.
let chapterClock = 0;
let nextRevealAt = 0;
let chapterEndsAt = 0;
let chapterHoldMs = 0;

// Pile de photos : première photo peu après le chapitre, puis une à la fois,
// en parallèle de la lecture ; le chapitre dure au moins le temps de la pile.
const PILE_START_MS = 1500;
const PILE_STEP_MS = 2400;
const PILE_TAIL_MS = 2500;
const FLY_MS = 2600;
const PATH_MAX_MS = 2800;
const PATH_MIN_MS = 350;
const PATH_MS_PER_PX = 5;
const MAX_ZOOM = 12; // cadrage initial : une vie dans une seule ville reste au zoom ville
const TRAIL_COLOR = '#f59e0b';

const currentEvent = computed(() => props.events[chapterIndex.value] || null);

// Avancement dans le chapitre (0 → 1), mis à jour par la boucle de lecture.
const chapterProgress = ref(0);
const progressPercent = computed(() => {
  const n = props.events.length;
  if (!n) return 0;
  if (finished.value) return 100;
  return Math.min(100, ((chapterIndex.value + chapterProgress.value) / n) * 100);
});

const seekFromBar = (e) => {
  const rect = e.currentTarget.getBoundingClientRect();
  const ratio = (e.clientX - rect.left) / rect.width;
  goTo(Math.min(props.events.length - 1, Math.max(0, Math.floor(ratio * props.events.length))));
};
// Zoom : on retient si le récit tournait pour le relancer à la fermeture.
const zoomSrc = ref(null);
let wasPausedBeforeZoom = false;
const openZoom = (src) => {
  if (!src) return;
  wasPausedBeforeZoom = paused.value;
  paused.value = true;
  zoomSrc.value = src;
};
const closeZoom = () => {
  zoomSrc.value = null;
  paused.value = wasPausedBeforeZoom;
};

const chapterImage = computed(() => {
  const ev = currentEvent.value;
  if (!ev) return null;
  return ev.media?.medium_url || ev.media?.url || ev.place_photo_url || null;
});

// Temps d'affichage d'un chapitre : proportionnel à la longueur du texte
// (le texte est l'information principale, il faut le temps de le lire).
const holdFor = (ev) => {
  const chars = (ev.title || '').length + (ev.description || '').length;
  return Math.min(13000, Math.max(3800, 2500 + chars * 38));
};

// Pile de photos : chaque cliché a une inclinaison et un décalage propres
// (déterministes), entre en glissant depuis la droite, puis reste sous les
// suivants.
const pileStyle = (i) => {
  const rot = ((i * 53) % 13) - 6;
  const dx = ((i * 29) % 41) - 20;
  const dy = ((i * 17) % 23) - 11;
  const shown = i <= burstIndex.value;
  return {
    zIndex: i,
    opacity: shown ? 1 : 0,
    transform: shown
      ? `translate(${dx}px, ${dy}px) rotate(${rot}deg)`
      : `translate(${dx + 160}px, ${dy + 24}px) rotate(${rot + 8}deg)`,
  };
};

const hasPoint = (ev) => ev && ev.latitude != null && ev.longitude != null;
const toLatLng = (ev) => [Number(ev.latitude), Number(ev.longitude)];

// Distance orthodromique en km.
const distanceKm = ([lat1, lng1], [lat2, lng2]) => {
  const r = Math.PI / 180;
  const dLat = (lat2 - lat1) * r;
  const dLng = (lng2 - lng1) * r;
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(dLng / 2) ** 2;
  return 2 * 6371 * Math.asin(Math.sqrt(a));
};

// Points intermédiaires sur l'arc de grand cercle (le chemin « d'avion »
// paraît courbe sur la carte, comme sur un atlas).
const greatCircle = (a, b, steps = 64) => {
  const r = Math.PI / 180;
  const [lat1, lng1, lat2, lng2] = [a[0] * r, a[1] * r, b[0] * r, b[1] * r];
  const d = 2 * Math.asin(Math.sqrt(
    Math.sin((lat2 - lat1) / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin((lng2 - lng1) / 2) ** 2
  ));
  if (d < 1e-9) return [a, b];
  const pts = [];
  for (let i = 0; i <= steps; i++) {
    const f = i / steps;
    const A = Math.sin((1 - f) * d) / Math.sin(d);
    const B = Math.sin(f * d) / Math.sin(d);
    const x = A * Math.cos(lat1) * Math.cos(lng1) + B * Math.cos(lat2) * Math.cos(lng2);
    const y = A * Math.cos(lat1) * Math.sin(lng1) + B * Math.cos(lat2) * Math.sin(lng2);
    const z = A * Math.sin(lat1) + B * Math.sin(lat2);
    pts.push([Math.atan2(z, Math.sqrt(x * x + y * y)) / r, Math.atan2(y, x) / r]);
  }
  return pts;
};

// Attente annulable, gelée pendant la pause.
const sleep = async (ms, gen) => {
  const start = Date.now();
  let frozen = 0;
  while (Date.now() - start - frozen < ms) {
    if (gen !== generation) return false;
    if (paused.value) {
      const t = Date.now();
      await new Promise((r) => setTimeout(r, 150));
      frozen += Date.now() - t;
      continue;
    }
    await new Promise((r) => setTimeout(r, Math.min(100, ms - (Date.now() - start - frozen))));
  }
  return gen === generation;
};

// Animation annulable (t de 0 à 1), gelée pendant la pause.
const animate = async (ms, gen, onFrame) => {
  const start = Date.now();
  let frozen = 0;
  for (;;) {
    if (gen !== generation) return false;
    if (paused.value) {
      const t = Date.now();
      await new Promise((r) => setTimeout(r, 150));
      frozen += Date.now() - t;
      continue;
    }
    const t = Math.min(1, (Date.now() - start - frozen) / ms);
    onFrame(t);
    if (t >= 1) return true;
    await new Promise((r) => requestAnimationFrame(r));
  }
};

// Le chapitre occupe le bas de l'écran : la carte doit viser le tiers haut.
const bottomPadPx = () => Math.round((mapEl.value?.clientHeight || 600) * 0.42);

// Un lieu est « visible » s'il tombe dans la partie de la carte non couverte
// par le chapitre (avec une marge), sans avoir à la déplacer.
const isInView = (latlng) => {
  const size = map.getSize();
  const p = map.latLngToContainerPoint(latlng);
  const m = 40;
  return p.x >= m && p.x <= size.x - m && p.y >= m + 40 && p.y <= size.y - bottomPadPx() - m;
};

// Longueur du trajet à l'écran, en pixels : c'est elle qui règle la durée
// du tracé (Paris → Bruxelles sur une carte du monde ne vaut pas un vol).
const screenDistancePx = (from, to) => {
  const a = map.latLngToContainerPoint(from);
  const b = map.latLngToContainerPoint(to);
  return Math.hypot(a.x - b.x, a.y - b.y);
};

const fitOptions = (duration) => ({
  paddingTopLeft: [60, 80],
  paddingBottomRight: [60, bottomPadPx()],
  maxZoom: MAX_ZOOM,
  duration,
});

// Cadrage initial : tous les lieux de la frise d'un coup.
const fitAll = () => {
  const pts = props.events.filter(hasPoint).map(toLatLng);
  if (!pts.length) return;
  map.fitBounds(L.latLngBounds(pts), fitOptions(0));
};

const initMap = () => {
  if (map || !mapEl.value) return;
  const first = props.events.find(hasPoint);
  const center = first ? toLatLng(first) : [46.6, 1.88];
  map = L.map(mapEl.value, {
    zoomControl: false,
    attributionControl: true,
    dragging: false,
    scrollWheelZoom: false,
    doubleClickZoom: false,
    boxZoom: false,
    keyboard: false,
    touchZoom: false,
    worldCopyJump: false,
  }).setView(center, 6);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap',
  }).addTo(map);
  trail = L.polyline([], { color: TRAIL_COLOR, weight: 3, opacity: 0.55, dashArray: '2 8', lineCap: 'round', interactive: false }).addTo(map);
  segment = L.polyline([], { color: TRAIL_COLOR, weight: 4, opacity: 0.95, lineCap: 'round', interactive: false }).addTo(map);
  visitedLayer = L.layerGroup().addTo(map);
  marker = L.marker(center, {
    icon: L.divIcon({ className: 'life-story-marker', html: '<div class="life-story-dot"></div>', iconSize: [18, 18], iconAnchor: [9, 9] }),
    interactive: false,
    opacity: 0,
  }).addTo(map);
};

const resetPath = () => {
  trail?.setLatLngs([]);
  segment?.setLatLngs([]);
  visitedLayer?.clearLayers();
};

const markVisited = (latlng) => {
  L.circleMarker(latlng, { radius: 4, color: '#fff', weight: 1.5, fillColor: TRAIL_COLOR, fillOpacity: 0.9, interactive: false }).addTo(visitedLayer);
};

// Reconstruit le chemin déjà parcouru jusqu'à `index` (saut direct à un
// chapitre, « Revoir »…) pour que l'itinéraire reste cohérent.
const rebuildPathUntil = (index) => {
  resetPath();
  const pts = props.events.slice(0, index).filter(hasPoint).map(toLatLng);
  if (!pts.length) return;
  const latlngs = [];
  for (let i = 1; i < pts.length; i++) latlngs.push(...greatCircle(pts[i - 1], pts[i], 32));
  trail.setLatLngs(latlngs);
  pts.forEach(markVisited);
};

// Amène le marqueur au lieu de l'événement. La carte ne bouge que si le
// départ ou l'arrivée sort du champ.
async function travelTo(ev, prev, gen) {
  const to = toLatLng(ev);
  const from = prev ? toLatLng(prev) : null;
  const km = from ? distanceKm(from, to) : 0;

  const needsMove = !isInView(to) || (from && !isInView(from));
  if (needsMove) {
    marker.setOpacity(0);
    map.flyToBounds(L.latLngBounds(from ? [from, to] : [to]), fitOptions(FLY_MS / 1000));
    if (!(await sleep(FLY_MS + 250, gen))) return false;
  }

  if (from && km > 0.5) {
    const px = screenDistancePx(from, to);
    const arc = greatCircle(from, to);
    marker.setLatLng(from);
    marker.setOpacity(1);
    // Trajet de quelques pixels : pas d'animation, on se contente d'avancer.
    if (px >= 6) {
      const ms = Math.min(PATH_MAX_MS, Math.max(PATH_MIN_MS, px * PATH_MS_PER_PX));
      const ok = await animate(ms, gen, (t) => {
        const n = Math.max(1, Math.round(t * (arc.length - 1)));
        segment.setLatLngs(arc.slice(0, n + 1));
        marker.setLatLng(arc[n]);
      });
      if (!ok) return false;
    }
    trail.setLatLngs([...trail.getLatLngs(), ...arc]);
    segment.setLatLngs([]);
    markVisited(from);
  }

  marker.setLatLng(to);
  marker.setOpacity(1);
  return true;
}

async function playFrom(index, { revealAll = false } = {}) {
  const gen = ++generation;
  finished.value = false;
  zoomSrc.value = null;
  rebuildPathUntil(index);
  let prev = props.events.slice(0, index).filter(hasPoint).pop() || null;

  for (let i = index; i < props.events.length; i++) {
    if (gen !== generation) return;
    chapterIndex.value = i;
    const ev = props.events[i];
    cardVisible.value = false;
    burst.value = [];

    // 1. Voyage sur la carte vers le lieu de l'événement.
    if (hasPoint(ev) && map) {
      if (!(await travelTo(ev, prev, gen))) return;
      prev = ev;
    }

    // 2. Chapitre (texte en grand) et, en parallèle, ses photos qui
    //    arrivent une à une. Sur mobile (plein écran), les photos attendent
    //    la fin de la lecture. ← / → avancent ou reculent dans la pile.
    cardVisible.value = true;
    const photos = ev.burst || [];
    burstIndex.value = -1;
    burst.value = photos;
    chapterClock = 0;
    chapterHoldMs = holdFor(ev);
    nextRevealAt = isDesktop.value ? PILE_START_MS : chapterHoldMs;
    chapterEndsAt = chapterHoldMs;
    if (photos.length) {
      chapterEndsAt = Math.max(chapterEndsAt, nextRevealAt + photos.length * PILE_STEP_MS + PILE_TAIL_MS);
    }
    // « Précédent » depuis le début d'un chapitre : on revient sur le
    // chapitre d'avant tel qu'on l'a quitté, toutes ses photos posées, en
    // pause pour le regarder.
    if (revealAll && i === index && photos.length) {
      burstIndex.value = photos.length - 1;
      if (!isDesktop.value) cardVisible.value = false;
      chapterClock = chapterEndsAt - PILE_TAIL_MS;
      nextRevealAt = Infinity;
      paused.value = true;
    }
    chapterProgress.value = 0;
    while (chapterClock < chapterEndsAt) {
      if (!(await sleep(100, gen))) return;
      chapterClock += 100;
      chapterProgress.value = Math.min(1, chapterClock / chapterEndsAt);
      if (burstIndex.value < photos.length - 1 && chapterClock >= nextRevealAt) {
        revealNextPhoto();
      }
    }
    burst.value = [];
  }

  if (gen === generation) finished.value = true;
}

const start = async () => {
  if (!props.events.length) return;
  isDesktop.value = window.matchMedia('(min-width: 640px)').matches;
  open.value = true;
  paused.value = false;
  finished.value = false;
  chapterIndex.value = 0;
  document.addEventListener('keydown', onKeydown);
  await nextTick();
  initMap();
  // Laisser la carte prendre sa taille, puis cadrer tous les lieux AVANT le
  // premier chapitre (sinon il croirait le lieu hors champ et volerait).
  await new Promise((r) => setTimeout(r, 60));
  if (!map) return; // fermé entre-temps
  map.invalidateSize();
  fitAll();
  playFrom(0);
};

const restart = () => playFrom(0);

const goTo = (index, opts = {}) => {
  if (index < 0 || index >= props.events.length) return;
  paused.value = false;
  playFrom(index, opts);
};

// Révèle la photo suivante de la pile et recale l'horloge : la suivante
// arrivera PILE_STEP_MS plus tard, que la révélation soit automatique ou
// demandée par ←/→. La dernière photo prolonge le chapitre d'une traîne.
const revealNextPhoto = () => {
  burstIndex.value += 1;
  if (!isDesktop.value) cardVisible.value = false;
  nextRevealAt = chapterClock + PILE_STEP_MS;
  if (burstIndex.value >= burst.value.length - 1) {
    // Dernière photo : le chapitre s'achève après une traîne, sans jamais
    // couper le temps de lecture du texte.
    chapterEndsAt = Math.max(chapterHoldMs, chapterClock + PILE_TAIL_MS);
  }
};

// « Suivant » : d'abord la photo suivante, puis le chapitre suivant.
const hasNextPhoto = computed(() => burst.value.length > 0 && burstIndex.value < burst.value.length - 1);
const hasPrevPhoto = computed(() => burst.value.length > 0 && burstIndex.value >= 0);
const canGoNext = computed(() => hasNextPhoto.value || chapterIndex.value < props.events.length - 1);
const canGoPrev = computed(() => hasPrevPhoto.value || chapterIndex.value > 0);

const next = () => {
  if (hasNextPhoto.value) {
    revealNextPhoto();
    return;
  }
  goTo(chapterIndex.value + 1);
};

// « Précédent » : retire la dernière photo (et met en pause : on regarde),
// sinon chapitre précédent.
const prev = () => {
  if (hasPrevPhoto.value) {
    burstIndex.value -= 1;
    paused.value = true;
    return;
  }
  goTo(chapterIndex.value - 1, { revealAll: true });
};

const togglePause = () => {
  paused.value = !paused.value;
};

const close = () => {
  generation += 1;
  open.value = false;
  zoomSrc.value = null;
  burst.value = [];
  cardVisible.value = false;
  document.removeEventListener('keydown', onKeydown);
  map?.remove();
  map = null;
  marker = null;
  trail = null;
  segment = null;
  visitedLayer = null;
};

const onKeydown = (e) => {
  if (e.key === 'Escape') {
    if (zoomSrc.value) closeZoom();
    else close();
    return;
  }
  if (e.key === 'ArrowRight') next();
  if (e.key === 'ArrowLeft') prev();
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
