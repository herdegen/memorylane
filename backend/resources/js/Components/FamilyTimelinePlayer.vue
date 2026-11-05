<template>
  <!-- Par-dessus la carte : le calque laisse passer les gestes (glisser,
       zoomer), seuls les contrôles captent le pointeur. -->
  <div class="pointer-events-none absolute inset-0 z-[1000]">
    <!-- Chargement -->
    <div v-if="state === 'loading'" class="ml-tt-dock absolute inset-x-0 flex justify-center px-3">
      <div class="inline-flex items-center gap-2 rounded-full border border-surface-200 bg-white/95 px-4 py-2 text-sm font-medium text-surface-700 shadow-warm-md backdrop-blur">
        <span class="h-2 w-2 animate-pulse rounded-full bg-brand-500" aria-hidden="true"></span>
        {{ $t('On remonte le temps…') }}
      </div>
    </div>

    <!-- Pas assez de données -->
    <div v-else-if="state === 'empty'" class="ml-tt-dock absolute inset-x-0 flex justify-center px-3">
      <div role="status" class="pointer-events-auto w-full max-w-md rounded-2xl border border-surface-200 bg-white/95 p-4 shadow-warm-lg backdrop-blur">
        <p class="font-semibold text-surface-900">⏳ {{ $t('Pas encore assez de données pour remonter le temps') }}</p>
        <p class="mt-1 text-sm text-surface-600">
          {{ focus ? $t('Il faut au moins deux de ces proches avec une date et un lieu : naissance, lieu de vie ou décès.') : $t('Il faut au moins deux personnes de la famille avec une date et un lieu : naissance, lieu de vie ou décès.') }}
        </p>
        <p v-if="pending" class="mt-1 text-sm text-surface-500">{{ pendingLabel }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button v-if="focus" type="button" class="btn-primary btn-sm" @click="emit('update:focus', null)">{{ $t('Voir toute ma famille') }}</button>
          <button type="button" class="btn-secondary btn-sm" @click="emit('close')">{{ $t('Revenir à la carte') }}</button>
        </div>
      </div>
    </div>

    <!-- Lecteur : grande année + frise -->
    <div v-else-if="state === 'ready'" ref="dock" class="ml-tt-dock absolute inset-x-0 flex justify-center px-3">
      <div class="w-full max-w-xl">
        <!-- « 1942 », puis au mois depuis 1990 : « juin 2012 » -->
        <div class="ml-tt-year font-display" aria-hidden="true">
          <span v-for="(part, i) in labelParts" :key="i" :class="{ 'ml-tt-month': part.type !== 'year' }">{{ part.value }}</span>
        </div>
        <div
          class="pointer-events-auto rounded-2xl border border-surface-200 bg-white/90 px-3 py-2.5 shadow-warm-lg backdrop-blur"
          @keydown.esc="menuOpen ? (menuOpen = false) : emit('close')"
        >
          <!-- Qui regarder (toute la famille, mes proches, les proches d'une
               fiche) et voyages oui / non. -->
          <div class="mb-2 flex items-center gap-2">
            <div ref="menuRoot" class="relative min-w-0">
              <button
                type="button"
                class="ml-tt-chip max-w-full"
                aria-haspopup="menu"
                :aria-expanded="menuOpen"
                @click="menuOpen = !menuOpen"
              >
                <span aria-hidden="true">👪</span>
                <span class="truncate">{{ scopeLabel }}</span>
                <svg class="h-3.5 w-3.5 shrink-0 transition-transform" :class="{ 'rotate-180': menuOpen }" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15l6-6 6 6" /></svg>
              </button>
              <div
                v-if="menuOpen"
                role="menu"
                class="absolute bottom-full left-0 mb-2 w-72 max-w-[calc(100vw-2.5rem)] rounded-2xl border border-surface-200 bg-white p-1.5 shadow-warm-lg"
              >
                <button
                  v-for="option in scopeOptions"
                  :key="option.value ?? 'all'"
                  type="button"
                  role="menuitemradio"
                  :aria-checked="option.value === focus"
                  class="flex w-full items-start gap-2.5 rounded-xl px-3 py-2.5 text-left transition hover:bg-surface-100"
                  @click="chooseScope(option.value)"
                >
                  <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2" :class="option.value === focus ? 'border-brand-600' : 'border-surface-300'">
                    <span v-if="option.value === focus" class="h-2 w-2 rounded-full bg-brand-600"></span>
                  </span>
                  <span class="min-w-0">
                    <span class="block text-sm font-semibold text-surface-900">{{ option.label }}</span>
                    <span class="block text-xs leading-snug text-surface-500">{{ option.hint }}</span>
                  </span>
                </button>
              </div>
            </div>
            <button
              type="button"
              class="ml-tt-chip ml-auto shrink-0"
              :class="{ 'ml-tt-chip-on': trips }"
              :aria-pressed="trips"
              :title="trips ? $t('Masquer les voyages (lecture plus rapide, année par année)') : $t('Afficher les voyages (au mois depuis 1990)')"
              @click="emit('update:trips', !trips)"
            >
              <span aria-hidden="true">✈️</span>
              {{ $t('Voyages') }}
            </button>
          </div>
          <div class="flex items-center gap-2">
            <button
              type="button"
              class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-600 text-snow shadow-warm-md transition hover:bg-brand-700 focus:outline-hidden focus-visible:ring-4 focus-visible:ring-brand-500/30"
              :aria-label="playing ? $t('Pause') : $t('Lecture')"
              @click="togglePlay"
            >
              <svg v-if="playing" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <rect x="6" y="5" width="4" height="14" rx="1" /><rect x="14" y="5" width="4" height="14" rx="1" />
              </svg>
              <svg v-else class="ml-0.5 h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z" />
              </svg>
            </button>

            <div class="min-w-0 flex-1">
              <!-- La frise suit le temps de lecture : les années anciennes y
                   sont serrées, les mois récents espacés (repère : 1990). -->
              <div class="relative">
                <input
                  type="range"
                  class="ml-tt-range block w-full"
                  min="0"
                  max="1000"
                  step="any"
                  :value="progress * 1000"
                  :style="{ '--p': `${progress * 100}%` }"
                  :aria-label="$t('Année')"
                  :aria-valuetext="timeLabel"
                  @input="onScrub"
                />
                <span
                  v-if="monthlyTick !== null"
                  class="ml-tt-tick"
                  :style="{ left: `calc(10px + (100% - 20px) * ${monthlyTick})` }"
                  aria-hidden="true"
                ></span>
              </div>
              <div class="-mt-0.5 flex justify-between gap-2 text-[11px] tabular-nums text-surface-500">
                <span>{{ fromLabel }}</span>
                <span class="truncate">{{ presentLabel }}</span>
                <span>{{ toLabel }}</span>
              </div>
            </div>

            <button
              type="button"
              class="h-9 min-w-11 shrink-0 rounded-full border border-surface-200 px-2 text-sm font-semibold tabular-nums text-surface-700 transition hover:bg-surface-100"
              :aria-label="$t('Vitesse de lecture : :speed', { speed: speedLabel })"
              @click="cycleSpeed"
            >{{ speedLabel }}</button>
            <button
              type="button"
              class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-surface-500 transition hover:bg-surface-100 hover:text-surface-800"
              :aria-label="$t('Quitter « Dans le temps »')"
              @click="emit('close')"
            >
              <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <p v-if="pending" class="mt-1 text-[11px] leading-snug text-surface-500">{{ pendingLabel }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
// « Dans le temps » (carte, côté famille) : la heatmap de la famille remonte
// le temps. Chaque personne (anonyme) est un point lumineux qui naît, change
// de lieu à mi-chemin entre deux étapes connues et s'efface à son décès ; la
// heatmap est recalculée sur les positions du moment. La logique
// d'animation vit dans utils/familyTimeline.js (partagée avec l'aperçu).
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import L from 'leaflet';
import 'leaflet.heat';
import axios from 'axios';
import { t, tc, intlLocale } from '@/i18n';
import { apiError } from '@/utils/apiError';
import { useToast } from '@/Composables/useToast';
import { useTheme } from '@/Composables/useTheme';
import { createAnimator, createGlowLayer, createTimeScale, dataBounds, timeLabelParts, tripSpans } from '@/utils/familyTimeline';

const props = defineProps({
  // Instance Leaflet de la page (markRaw : jamais rendue réactive).
  map: { type: Object, required: true },
  // Proches de qui : null = toute ma famille, « moi » ou l'id d'une fiche.
  focus: { type: String, default: null },
  // Voyages (sorties) : sans eux, frise à l'année du début à la fin.
  trips: { type: Boolean, default: true },
});
// Changer de proches ou de voyages : la page recrée le lecteur.
const emit = defineEmits(['close', 'update:focus', 'update:trips']);

const toast = useToast();
const { isDark } = useTheme();
const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

const state = ref('loading'); // loading | empty | ready
const range = ref({ from: 0, to: 1 });
const progress = ref(0); // position dans la lecture, de 0 à 1
const year = ref(0); // instant affiché (année décimale)
const monthlyTick = ref(null); // repère « au mois » sur la frise
const playing = ref(false);
const speed = ref(1);
const present = ref(0);
const pending = ref(0);
const dock = ref(null);

// Vitesses proposées (le bouton les fait défiler). À 1 : les années
// anciennes en 8 à 16 s, puis 1,5 s par an depuis 1990, et les mois de
// réunion au ralenti (voir createTimeScale).
const SPEEDS = [1, 2, 4, 0.5];

// Heatmap des positions du moment : réglée pour de faibles densités (une
// personne seule reste visible), sans atténuation au dézoom (maxZoom 0),
// dégradé chaud assorti aux points.
const HEAT_OPTIONS = {
  radius: 34,
  blur: 26,
  maxZoom: 0,
  max: 3,
  minOpacity: 0.04,
  gradient: { 0.2: '#fde68a', 0.4: '#fbbf24', 0.6: '#f97316', 0.8: '#e11d48', 1: '#9f1239' },
};

let animator = null;
let scale = null;
let heat = null;
let glow = null;
let raf = 0;
let lastTick = 0;
let lastHeat = 0;
let heatCost = 0; // durée moyenne d'un rendu de la heatmap (ms)
let mapMoving = false;
let autoplayTimer = 0;
let destroyed = false;

// ---------------------------------------------------------------- libellés

const numberFormat = new Intl.NumberFormat(intlLocale());
const yearOnly = (y) => timeLabelParts(y, intlLocale(), false)[0].value;

const labelParts = computed(() => timeLabelParts(year.value, intlLocale(), props.trips && !!scale?.monthly(year.value)));
const timeLabel = computed(() => labelParts.value.map((part) => part.value).join(''));
const fromLabel = computed(() => yearOnly(range.value.from));
const toLabel = computed(() => yearOnly(range.value.to));
const speedLabel = computed(() => `${numberFormat.format(speed.value)}×`);
const presentLabel = computed(() => tc(':count personne|:count personnes', present.value, {
  count: numberFormat.format(present.value),
}));
const pendingLabel = computed(() => tc(
  ':count lieu est en cours de localisation : il apparaîtra sur la carte d’ici quelques minutes.|:count lieux sont en cours de localisation : ils apparaîtront sur la carte d’ici quelques minutes.',
  pending.value,
  { count: numberFormat.format(pending.value) },
));

// ---------------------------------------------------------------- qui, voyages

const menuOpen = ref(false);
const menuRoot = ref(null);
const focused = ref(null); // { id, name, self } renvoyé par le serveur

const scopeLabel = computed(() => {
  if (!props.focus) return t('Toute ma famille');
  if (!focused.value || focused.value.self) return t('Mes proches');
  return t('Les proches de :name', { name: focused.value.name });
});
const scopeOptions = computed(() => {
  const options = [
    { value: null, label: t('Toute ma famille'), hint: t('Tout le monde, toutes les branches') },
    { value: 'moi', label: t('Mes proches'), hint: t('Mes ancêtres et descendants, frères et sœurs, oncles, tantes, cousins et leurs enfants') },
  ];
  // Les proches d'une autre fiche (ouverts depuis sa fiche).
  if (props.focus && props.focus !== 'moi' && focused.value && !focused.value.self) {
    options.push({
      value: props.focus,
      label: t('Les proches de :name', { name: focused.value.name }),
      hint: t('Ses ancêtres et descendants, frères et sœurs, oncles, tantes, cousins et leurs enfants'),
    });
  }
  return options;
});

function chooseScope(value) {
  menuOpen.value = false;
  if (value !== props.focus) emit('update:focus', value);
}

const closeMenuOutside = (event) => {
  if (menuOpen.value && menuRoot.value && !menuRoot.value.contains(event.target)) menuOpen.value = false;
};
onMounted(() => document.addEventListener('pointerdown', closeMenuOutside));

// ---------------------------------------------------------------- données

onMounted(async () => {
  props.map.getContainer().classList.add('ml-time-travel');
  try {
    const params = {};
    if (props.focus) params.proches = props.focus;
    if (!props.trips) params.voyages = 0;
    const { data } = await axios.get('/map/heatmap/timeline', { params });
    if (destroyed) return;
    focused.value = data.focus ?? null;
    pending.value = Number(data.pending_places) || 0;
    const tracks = data.tracks || [];
    if (tracks.length < 2 || !data.range) {
      state.value = 'empty';
      return;
    }
    range.value = { from: Number(data.range.from), to: Number(data.range.to) };
    await start(tracks);
  } catch (error) {
    if (destroyed) return;
    console.error('Failed to load family timeline:', error);
    toast.error(apiError(error, t("Impossible d'afficher la carte dans le temps.")));
    emit('close');
  }
});

async function start(tracks) {
  const map = props.map;
  // Lecture : vite pour les siècles anciens, au mois depuis 1990, au
  // ralenti sur les mois de voyage et de réunion de famille. Sans voyages :
  // à l'année jusqu'au bout, plus rapide.
  scale = createTimeScale(range.value, props.trips ? { slowSpans: tripSpans(tracks) } : { secondsPerYear: 0.5 });
  monthlyTick.value = props.trips ? scale.monthlyAt : null;
  animator = createAnimator(tracks, {
    rangeTo: range.value.to,
    moveMs: reduceMotion ? 0 : 900,
    fadeMs: reduceMotion ? 0 : 700,
    ringMs: reduceMotion ? 0 : 1400,
  });

  heat = L.heatLayer([], HEAT_OPTIONS).addTo(map);
  // Mesure du coût d'un rendu : sur grand écran on espace les mises à jour
  // de la heatmap, les points lumineux restent, eux, à chaque image.
  const redraw = heat._redraw;
  heat._redraw = function measuredRedraw() {
    const t0 = performance.now();
    redraw.call(this);
    heatCost = heatCost * 0.8 + (performance.now() - t0) * 0.2;
  };
  glow = createGlowLayer(L)({ dark: isDark.value }).addTo(map);
  map.on('movestart', onMoveStart);
  map.on('moveend', onMoveEnd);

  state.value = 'ready';
  await nextTick();
  if (destroyed) return;

  // Cadrage sur les lieux de la famille, au-dessus de la frise.
  const bounds = dataBounds(tracks);
  if (bounds) {
    const bottom = (dock.value?.offsetHeight ?? 0) + 24;
    map.fitBounds(bounds, { paddingTopLeft: [40, 64], paddingBottomRight: [40, bottom], maxZoom: 9 });
  }

  // Lecture d'office depuis la première année (sauf mouvements réduits :
  // on montre aujourd'hui, à l'arrêt).
  seek(reduceMotion ? 1 : 0);
  render();
  if (!reduceMotion) autoplayTimer = setTimeout(play, 900);
}

// ---------------------------------------------------------------- animation

function render() {
  if (!raf && animator) raf = requestAnimationFrame(tick);
}

function tick(now) {
  raf = 0;
  if (destroyed) return;
  // Onglet en arrière-plan : pas de grand saut au retour.
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
  // Heatmap : pas pendant un glissement de carte (leaflet.heat dessinerait
  // décalé), espacée selon son coût, toujours à jour une fois l'image posée.
  if (!mapMoving && (settled || now - lastHeat >= heatCost * 2.5)) {
    heat.setLatLngs(frame.heat.slice());
    lastHeat = now;
  }

  if (settled) {
    lastTick = 0;
  } else {
    raf = requestAnimationFrame(tick);
  }
}

function onMoveStart() {
  mapMoving = true;
}

function onMoveEnd() {
  mapMoving = false;
  render();
}

// ---------------------------------------------------------------- commandes

function seek(p) {
  progress.value = Math.min(1, Math.max(0, p));
  year.value = scale.yearAt(progress.value);
}

function play() {
  clearTimeout(autoplayTimer);
  if (progress.value >= 1 - 1e-6) seek(0);
  playing.value = true;
  lastTick = 0;
  render();
}

function pause() {
  clearTimeout(autoplayTimer);
  playing.value = false;
}

const togglePlay = () => (playing.value ? pause() : play());

function onScrub(event) {
  pause();
  seek(Number(event.target.value) / 1000);
  render();
}

function cycleSpeed() {
  speed.value = SPEEDS[(SPEEDS.indexOf(speed.value) + 1) % SPEEDS.length];
}

watch(isDark, (dark) => glow?.setDark(dark));

onBeforeUnmount(() => {
  destroyed = true;
  document.removeEventListener('pointerdown', closeMenuOutside);
  cancelAnimationFrame(raf);
  clearTimeout(autoplayTimer);
  const map = props.map;
  map.off('movestart', onMoveStart);
  map.off('moveend', onMoveEnd);
  if (heat) map.removeLayer(heat);
  if (glow) map.removeLayer(glow);
  map.getContainer().classList.remove('ml-time-travel');
});
</script>

<style scoped>
/* Au-dessus du crédit OpenStreetMap (obligatoire) ; sur téléphone, aussi
   au-dessus du bouton « + » de la barre d'onglets, qui dépasse de 1,5rem. */
.ml-tt-dock { bottom: 1.6rem; }
@media (max-width: 639px) {
  .ml-tt-dock { bottom: calc(1.75rem + 1.35rem); }
}

/* Pastilles « qui » et « voyages » au-dessus de la frise. */
.ml-tt-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  height: 2rem;
  padding: 0 0.75rem;
  border-radius: 999px;
  border: 1px solid var(--color-surface-200);
  background: var(--color-surface-50);
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-surface-700);
  transition: background-color 0.15s, border-color 0.15s, color 0.15s;
}
.ml-tt-chip:hover { background: var(--color-surface-100); }
.ml-tt-chip-on {
  border-color: var(--color-brand-300);
  background: var(--color-brand-50);
  color: var(--color-brand-800);
}

/* Grande année, façon filigrane : encre sur halo clair (ou l'inverse en
   mode sombre, où la carte est elle-même assombrie). */
.ml-tt-year {
  margin: 0 0 0.25rem 0.35rem;
  font-size: clamp(2.6rem, 9vw, 4.5rem);
  font-weight: 600;
  line-height: 1;
  letter-spacing: -0.02em;
  font-variant-numeric: lining-nums tabular-nums;
  color: var(--color-surface-900);
  text-shadow: 0 0 2px var(--color-page), 0 0 14px var(--color-page), 0 0 28px var(--color-page);
}

/* Au mois : le nom du mois en petit, l'année en grand. */
.ml-tt-month {
  font-family: var(--font-sans);
  font-size: 0.4em;
  font-weight: 600;
  letter-spacing: 0;
}

/* Repère de la frise : à partir d'ici, mois par mois. */
.ml-tt-tick {
  position: absolute;
  top: calc(50% - 8px);
  width: 2px;
  height: 16px;
  margin-left: -1px;
  border-radius: 1px;
  background: var(--color-surface-400);
  pointer-events: none;
}

/* Frise : piste fine remplie jusqu'à l'instant courant, gros curseur. */
.ml-tt-range {
  position: relative;
  z-index: 1;
  appearance: none;
  height: 26px;
  background: transparent;
  cursor: pointer;
  touch-action: none;
}
.ml-tt-range:focus { outline: none; }
.ml-tt-range::-webkit-slider-runnable-track {
  height: 6px;
  border-radius: 999px;
  background: linear-gradient(to right, var(--color-brand-500) var(--p), var(--color-surface-200) var(--p));
}
.ml-tt-range::-webkit-slider-thumb {
  appearance: none;
  width: 20px;
  height: 20px;
  margin-top: -7px;
  border-radius: 50%;
  background: var(--color-snow);
  border: 3px solid var(--color-brand-600);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
}
.ml-tt-range::-moz-range-track {
  height: 6px;
  border-radius: 999px;
  background: var(--color-surface-200);
}
.ml-tt-range::-moz-range-progress {
  height: 6px;
  border-radius: 999px;
  background: var(--color-brand-500);
}
.ml-tt-range::-moz-range-thumb {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--color-snow);
  border: 3px solid var(--color-brand-600);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
}
.ml-tt-range:focus-visible::-webkit-slider-thumb { box-shadow: 0 0 0 5px rgb(245 158 11 / 0.35); }
.ml-tt-range:focus-visible::-moz-range-thumb { box-shadow: 0 0 0 5px rgb(245 158 11 / 0.35); }
</style>

<style>
/* Carte assourdie pendant « Dans le temps » : la chaleur et les points
   lumineux ressortent. Mode sombre : carte inversée, nuit. */
.app-viewport .leaflet-tile-pane { transition: filter 0.6s ease; }
.ml-time-travel .leaflet-tile-pane { filter: grayscale(0.85) brightness(1.04) contrast(0.9); }
[data-theme='dark'] .ml-time-travel .leaflet-tile-pane { filter: grayscale(1) invert(1) brightness(0.8) contrast(0.85); }
</style>
