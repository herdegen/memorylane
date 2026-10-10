<template>
  <Head title="Réseau" />
  <AppLayout title="Réseau">
    <div class="h-[calc(100vh-4rem)] flex relative">
      <!-- Zone graphe -->
      <div class="relative flex-1 min-w-0 bg-page">
        <svg
          ref="svgRef"
          class="w-full h-full block select-none touch-none"
          role="img"
          aria-label="Réseau des personnes reliées par les photos"
          @click="onBackgroundClick"
        >
          <g :transform="transformAttr">
            <!-- Liens -->
            <line
              v-for="e in visibleEdges"
              :key="e.key"
              :x1="e.s.x" :y1="e.s.y" :x2="e.t.x" :y2="e.t.y"
              :stroke-width="e.width"
              :stroke-dasharray="e.kind === 'album' ? '4 4' : null"
              :stroke="edgeStroke(e)"
              :class="edgeStroke(e) === 'currentColor' ? 'text-surface-300' : ''"
              :opacity="edgeOpacity(e)"
              stroke-linecap="round"
            />

            <!-- Nœuds -->
            <g
              v-for="n in visibleNodes"
              :key="n.id"
              :transform="`translate(${n.x},${n.y})`"
              class="cursor-pointer"
              :opacity="nodeOpacity(n)"
              @click.stop="select(n.id)"
            >
              <circle :r="n.r + 3" :fill="n.color" />
              <clipPath :id="`clip-${n.idx}`"><circle :r="n.r" /></clipPath>
              <circle :r="n.r" class="fill-current text-white" />
              <image
                v-if="n.avatar_url"
                :href="n.avatar_url"
                :x="-n.r" :y="-n.r" :width="n.r * 2" :height="n.r * 2"
                :clip-path="`url(#clip-${n.idx})`"
                preserveAspectRatio="xMidYMid slice"
              />
              <text
                v-else
                text-anchor="middle" dominant-baseline="central"
                :font-size="Math.max(10, n.r * 0.7)" font-weight="700"
                :fill="n.color"
              >{{ initials(n.name) }}</text>
              <circle
                v-if="n.id === selectedId"
                :r="n.r + 7" fill="none" :stroke="n.color" stroke-width="2"
              />
              <text
                v-if="showLabel(n)"
                :y="n.r + 15"
                text-anchor="middle"
                font-size="12"
                :font-weight="n.id === selectedId ? 700 : 600"
                class="fill-current text-surface-800"
                paint-order="stroke"
                stroke-width="3"
                stroke-linejoin="round"
                :style="{ stroke: 'var(--color-page)' }"
              >{{ n.is_self ? 'Moi' : shortName(n.name) }}</text>
            </g>
          </g>
        </svg>

        <!-- En-tête flottant -->
        <div class="absolute top-4 left-4 right-4 flex flex-wrap items-start justify-between gap-3 pointer-events-none">
          <div class="pointer-events-auto bg-white/90 backdrop-blur rounded-2xl border border-surface-200 shadow-warm-sm px-4 py-3 max-sm:py-2 max-w-md">
            <div class="flex items-center gap-3">
              <h1 class="text-display text-2xl text-surface-900">Réseau</h1>
              <div v-if="!isFriends && canSeeFamily" class="inline-flex rounded-full border border-surface-200 p-0.5 text-xs font-semibold">
                <Link href="/family-tree" class="px-3 py-1 rounded-full text-surface-600 hover:text-surface-900">Arbre</Link>
                <span class="px-3 py-1 rounded-full bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200">Réseau</span>
              </div>
            </div>
            <p class="text-xs text-surface-500 mt-1 max-sm:hidden">
              Tissé à partir de {{ totalPhotos }} photo{{ totalPhotos > 1 ? 's' : '' }} réunissant plusieurs personnes — seules les photos que vous pouvez voir comptent.
            </p>
          </div>

          <div class="pointer-events-auto flex flex-wrap items-center gap-2">
            <div class="relative">
              <input
                v-model="searchQuery"
                type="search"
                placeholder="Rechercher…"
                aria-label="Trouver une personne"
                class="form-input !rounded-full !py-2 w-56 max-sm:w-36"
              />
              <div
                v-if="searchResults.length"
                class="absolute right-0 z-20 mt-1 w-64 bg-white border border-surface-200 rounded-xl shadow-warm-lg max-h-64 overflow-auto"
              >
                <button
                  v-for="p in searchResults"
                  :key="p.id"
                  type="button"
                  class="w-full text-left px-4 py-2 text-sm text-surface-700 hover:bg-surface-50"
                  @click="pickFromSearch(p.id)"
                >{{ p.name }}</button>
              </div>
            </div>
            <button
              type="button"
              class="inline-flex items-center gap-2 h-10 pl-2 pr-4 rounded-full border border-surface-200 bg-white text-sm font-semibold text-surface-700"
              :aria-pressed="showAlbumLinks"
              @click="showAlbumLinks = !showAlbumLinks"
            >
              <span class="relative w-9 h-5 rounded-full transition-colors" :class="showAlbumLinks ? 'bg-brand-600' : 'bg-surface-300'">
                <span class="absolute top-0.5 w-4 h-4 rounded-full bg-white transition-all" :class="showAlbumLinks ? 'left-4.5' : 'left-0.5'"></span>
              </span>
              <span class="max-sm:hidden">Liens par album</span>
              <span class="sm:hidden">Albums</span>
            </button>
          </div>
        </div>

        <!-- Groupes -->
        <div v-if="groups.length" class="absolute left-4 bottom-20 flex flex-wrap gap-2 max-w-[60%] max-md:hidden">
          <div
            v-for="g in groups"
            :key="g.id"
            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/90 border border-surface-200 text-xs font-semibold text-surface-700"
          >
            <span class="w-2.5 h-2.5 rounded-full" :style="{ background: g.color }"></span>
            {{ g.label }}
          </div>
        </div>

        <!-- Zoom -->
        <div class="absolute right-4 bottom-20 flex flex-col rounded-xl overflow-hidden border border-surface-200 bg-white shadow-warm-sm">
          <button type="button" class="w-11 h-11 text-xl text-surface-700 hover:bg-surface-50" aria-label="Zoomer" @click="zoomBy(1.4)">+</button>
          <button type="button" class="w-11 h-11 text-xl text-surface-700 hover:bg-surface-50 border-t border-surface-200" aria-label="Dézoomer" @click="zoomBy(1 / 1.4)">−</button>
          <button type="button" class="w-11 h-11 text-surface-700 hover:bg-surface-50 border-t border-surface-200 flex items-center justify-center" aria-label="Tout afficher" @click="fitView(true)">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" /></svg>
          </button>
        </div>

        <!-- Frise des années -->
        <div v-if="yearSteps.length > 1" class="absolute left-4 right-4 bottom-4 flex items-center gap-3 bg-white/90 backdrop-blur border border-surface-200 rounded-2xl px-4 py-2 shadow-warm-sm">
          <span class="text-xs font-semibold text-surface-600 whitespace-nowrap max-sm:hidden">Le réseau en</span>
          <div class="flex-1 flex gap-1.5 overflow-x-auto">
            <button
              v-for="y in yearSteps"
              :key="y.value ?? 'now'"
              type="button"
              class="flex-1 min-w-16 h-9 rounded-full text-xs font-semibold border transition-colors"
              :class="yearLimit === y.value
                ? 'border-brand-500 bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200'
                : 'border-surface-200 bg-white text-surface-600 hover:text-surface-900'"
              :aria-pressed="yearLimit === y.value"
              @click="yearLimit = y.value"
            >{{ y.label }}</button>
          </div>
        </div>

        <!-- États -->
        <div v-if="loading" class="absolute inset-0 flex items-center justify-center text-surface-500">Chargement du réseau…</div>
        <div v-else-if="!rawEdges.length" class="absolute inset-0 flex items-center justify-center p-8">
          <div class="max-w-md text-center bg-white border border-surface-200 rounded-2xl p-8 shadow-warm-sm">
            <h2 class="text-display text-xl text-surface-900 mb-2">Pas encore de liens</h2>
            <p class="text-sm text-surface-600">
              Le réseau se dessine tout seul à partir des photos où plusieurs personnes sont identifiées.
              Identifiez des visages sur vos photos pour le voir apparaître.
            </p>
          </div>
        </div>
      </div>

      <!-- Panneau personne : colonne (desktop), feuille du bas (mobile) -->
      <aside
        v-if="!loading && rawEdges.length"
        class="bg-white border-surface-200 overflow-y-auto
               md:w-80 md:border-l md:p-5 md:static
               max-md:fixed max-md:inset-x-0 max-md:bottom-0 max-md:z-30 max-md:max-h-[60vh]
               max-md:rounded-t-2xl max-md:border-t max-md:shadow-warm-lg max-md:p-4
               max-md:transition-transform max-md:duration-300"
        :class="selected ? 'max-md:translate-y-0' : 'max-md:translate-y-full'"
      >
        <template v-if="selected">
          <div class="md:hidden w-10 h-1 rounded-full bg-surface-300 mx-auto mb-3"></div>
          <div class="flex items-center gap-3">
            <img
              v-if="selected.avatar_url"
              :src="selected.avatar_url"
              :alt="selected.name"
              class="w-16 h-16 rounded-full object-cover border-[3px]"
              :style="{ borderColor: selected.color }"
            />
            <div
              v-else
              class="w-16 h-16 rounded-full flex items-center justify-center text-xl font-bold text-white"
              :style="{ background: selected.color }"
            >{{ initials(selected.name) }}</div>
            <div class="min-w-0 flex-1">
              <div class="text-display text-xl text-surface-900 truncate">{{ selected.is_self ? `${selected.name} (moi)` : selected.name }}</div>
              <div class="flex items-center gap-1.5 text-xs text-surface-600">
                <span class="w-2 h-2 rounded-full" :style="{ background: selected.color }"></span>
                {{ selected.groupLabel }}
              </div>
            </div>
            <button type="button" class="md:hidden p-2 text-surface-500" aria-label="Fermer" @click="selectedId = null">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>

          <div class="grid grid-cols-2 gap-2 mt-4">
            <div class="rounded-xl bg-surface-100 p-3">
              <div class="text-xl font-bold text-surface-900">{{ withSelfWeight ?? selected.photos }}</div>
              <div class="text-xs text-surface-600">{{ withSelfWeight !== null ? 'photos avec vous' : 'photos' }}</div>
            </div>
            <div class="rounded-xl bg-surface-100 p-3">
              <div class="text-xl font-bold text-surface-900">{{ selected.first_year ?? '—' }}</div>
              <div class="text-xs text-surface-600">première photo</div>
            </div>
          </div>

          <div v-if="topLinks.length" class="mt-5">
            <div class="text-xs font-semibold text-surface-600 mb-1">Liens les plus forts</div>
            <button
              v-for="l in topLinks"
              :key="l.id"
              type="button"
              class="w-full flex items-center gap-2.5 p-1.5 rounded-lg hover:bg-surface-50 text-left"
              @click="select(l.id, true)"
            >
              <img v-if="l.avatar_url" :src="l.avatar_url" alt="" class="w-8 h-8 rounded-full object-cover" />
              <span v-else class="w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold text-white" :style="{ background: l.color }">{{ initials(l.name) }}</span>
              <span class="flex-1 text-sm font-semibold text-surface-800 truncate">{{ l.is_self ? 'Moi' : l.name }}</span>
              <span class="text-xs text-surface-500">{{ l.weight }} photo{{ l.weight > 1 ? 's' : '' }}</span>
            </button>
          </div>

          <div v-if="togetherFor" class="mt-5">
            <div class="text-xs font-semibold text-surface-600 mb-2">Photos avec {{ togetherFor }}</div>
            <div v-if="togetherLoading" class="text-xs text-surface-500">Chargement…</div>
            <div v-else-if="together.length" class="grid grid-cols-3 gap-1 rounded-xl overflow-hidden">
              <Link
                v-for="m in together"
                :key="m.id"
                :href="`/media/${m.id}`"
                class="block aspect-square bg-surface-200"
              >
                <img :src="m.thumbnail_url" alt="" loading="lazy" class="w-full h-full object-cover" />
              </Link>
            </div>
            <div v-else class="text-xs text-surface-500">Aucune photo visible ensemble.</div>
          </div>

          <div class="flex gap-2 mt-5">
            <Link :href="`/people/${selected.id}`" class="btn btn-primary flex-1">Voir la fiche</Link>
            <button type="button" class="btn btn-secondary flex-1" @click="centerOn(selected.id)">Centrer</button>
          </div>
        </template>
        <div v-else class="max-md:hidden text-sm text-surface-500">
          <div class="text-display text-lg text-surface-900 mb-2">Une personne, ses liens</div>
          Cliquez sur une personne pour voir avec qui elle apparaît le plus, et vos photos ensemble.
          L'épaisseur d'un lien suit le nombre de photos communes ; les couleurs regroupent les personnes souvent photographiées ensemble.
        </div>
      </aside>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, shallowRef, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';
import { forceSimulation, forceLink, forceManyBody, forceCollide, forceX, forceY } from 'd3-force';
import { zoom as d3zoom, zoomIdentity } from 'd3-zoom';
import { select as d3select } from 'd3-selection';
import Graph from 'graphology';
import louvain from 'graphology-communities-louvain';
import { searchPeople } from '@/utils/personSearch';
import { useCircle } from '@/Composables/useCircle';

const { isFriends, canSeeFamily } = useCircle();

// Palette des groupes : teintes distinctes ET de luminosités différentes,
// lisibles en clair comme en sombre. Au-delà : gris neutre.
const PALETTE = ['#2563eb', '#7c3aed', '#0d9488', '#c2410c', '#db2777', '#65a30d', '#0891b2', '#a16207'];
const NEUTRAL = '#78716c';
const SELF_COLOR = '#b45309';

const svgRef = ref(null);
const loading = ref(true);
const nodes = shallowRef([]);   // nœuds positionnés { id, name, x, y, r, color, group, ... }
const rawEdges = shallowRef([]);
const groups = ref([]);
const selfId = ref(null);
const selectedId = ref(null);
const showAlbumLinks = ref(false);
const yearLimit = ref(null);     // null = aujourd'hui (tout)
const transform = ref(zoomIdentity);
const searchQuery = ref('');
const together = ref([]);
const togetherLoading = ref(false);

let zoomBehavior = null;
let byId = {};

const transformAttr = computed(() => `translate(${transform.value.x},${transform.value.y}) scale(${transform.value.k})`);

// ---------------------------------------------------------------------------
// Chargement + mise en page (une seule fois : positions stables, la frise et
// le filtre « album » masquent sans recalculer)
// ---------------------------------------------------------------------------

onMounted(async () => {
  try {
    const { data } = await axios.get('/network/data');
    selfId.value = data.self_id;
    totalPhotos.value = data.photo_count ?? 0;
    layout(data.nodes, data.edges);
  } finally {
    loading.value = false;
  }
  await nextTick();
  setupZoom();
  fitView(false);
  window.addEventListener('resize', onResize);
});

onBeforeUnmount(() => window.removeEventListener('resize', onResize));

function onResize() {
  fitView(false);
}

// Générateur pseudo-aléatoire seedé : groupes stables d'une visite à l'autre.
function mulberry32(seed) {
  return function () {
    seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function layout(apiNodes, apiEdges) {
  rawEdges.value = apiEdges;

  // Groupes (Louvain) sur les liens photo, SANS la fiche « moi » : reliée à
  // presque tout le monde, elle fusionnerait les groupes.
  const g = new Graph({ type: 'undirected' });
  apiNodes.forEach((n) => { if (!n.is_self) g.addNode(n.id); });
  apiEdges.forEach((e) => {
    if (e.kind !== 'photo' || !g.hasNode(e.source) || !g.hasNode(e.target)) return;
    g.mergeEdge(e.source, e.target, { weight: e.weight });
  });
  const communities = g.order > 0 && g.size > 0
    ? louvain(g, { getEdgeWeight: 'weight', rng: mulberry32(42) })
    : {};

  // Degré pondéré : nomme le groupe d'après sa personne la plus reliée.
  const strength = {};
  apiEdges.forEach((e) => {
    if (e.kind !== 'photo') return;
    strength[e.source] = (strength[e.source] ?? 0) + e.weight;
    strength[e.target] = (strength[e.target] ?? 0) + e.weight;
  });

  const members = {};
  Object.entries(communities).forEach(([id, c]) => { (members[c] ??= []).push(id); });
  const nameOf = Object.fromEntries(apiNodes.map((n) => [n.id, n.name]));
  const ordered = Object.entries(members)
    .filter(([, ids]) => ids.length >= 2)
    .sort((a, b) => b[1].length - a[1].length);
  const groupColor = {};
  const groupLabel = {};
  groups.value = ordered.map(([c, ids], i) => {
    const color = PALETTE[i] ?? NEUTRAL;
    const lead = ids.reduce((best, id) => ((strength[id] ?? 0) > (strength[best] ?? 0) ? id : best), ids[0]);
    groupColor[c] = color;
    groupLabel[c] = `Autour de ${shortName(nameOf[lead])}`;
    return { id: c, color, label: groupLabel[c] };
  });

  const maxPhotos = Math.max(1, ...apiNodes.map((n) => n.photos));
  const simNodes = apiNodes.map((n, idx) => {
    const c = communities[n.id];
    return {
      ...n,
      idx,
      group: c ?? null,
      color: n.is_self ? SELF_COLOR : (groupColor[c] ?? NEUTRAL),
      groupLabel: n.is_self ? 'Au centre du réseau' : (groupLabel[c] ?? 'Hors groupe'),
      r: n.is_self ? 30 : Math.round(12 + 18 * Math.sqrt(n.photos / maxPhotos)),
      // « Moi » épinglé au centre : le réseau se lit depuis soi.
      ...(n.is_self ? { fx: 0, fy: 0 } : {}),
    };
  });
  byId = Object.fromEntries(simNodes.map((n) => [n.id, n]));

  const simLinks = apiEdges.map((e) => ({
    source: e.source,
    target: e.target,
    weight: e.weight,
    kind: e.kind,
  }));

  const sim = forceSimulation(simNodes)
    .force('link', forceLink(simLinks).id((d) => d.id)
      .distance((l) => (l.kind === 'album' ? 220 : 70 + 140 / Math.sqrt(l.weight)))
      .strength((l) => (l.kind === 'album' ? 0.02 : Math.min(1, 0.15 + l.weight / 20))))
    .force('charge', forceManyBody().strength(-260))
    .force('collide', forceCollide((d) => d.r + 14))
    .force('x', forceX(0).strength(0.05))
    .force('y', forceY(0).strength(0.05))
    .stop();
  for (let i = 0; i < 400; i++) sim.tick();

  nodes.value = simNodes;
}

// ---------------------------------------------------------------------------
// Filtres (frise, liens album) + mise en évidence de la sélection
// ---------------------------------------------------------------------------

const inYear = (year) => yearLimit.value === null || (year !== null && year <= yearLimit.value);

const visibleEdges = computed(() => rawEdges.value
  .filter((e) => (showAlbumLinks.value || e.kind === 'photo') && inYear(e.first_year))
  .map((e) => ({
    ...e,
    key: `${e.source}|${e.target}`,
    s: byId[e.source],
    t: byId[e.target],
    width: e.kind === 'album' ? 1.5 : Math.min(8, 1 + Math.sqrt(e.weight)),
  }))
  .filter((e) => e.s && e.t));

const visibleNodes = computed(() => {
  const ids = new Set();
  visibleEdges.value.forEach((e) => { ids.add(e.source); ids.add(e.target); });
  if (selfId.value) ids.add(selfId.value);
  return nodes.value.filter((n) => ids.has(n.id));
});

const neighbors = computed(() => {
  const map = {};
  if (!selectedId.value) return map;
  visibleEdges.value.forEach((e) => {
    if (e.source === selectedId.value) map[e.target] = e;
    if (e.target === selectedId.value) map[e.source] = e;
  });
  return map;
});

const selected = computed(() => (selectedId.value ? byId[selectedId.value] ?? null : null));

function touches(e) {
  return e.source === selectedId.value || e.target === selectedId.value;
}

function edgeStroke(e) {
  if (selectedId.value && touches(e)) return selected.value.color;
  return 'currentColor';
}

function edgeOpacity(e) {
  // Liens d'une seule photo très discrets : la structure ressort sans pelote.
  const base = e.kind === 'album' ? 0.7 : 0.2 + 0.8 * Math.min(1, (e.weight - 1) / 8);
  if (!selectedId.value) return base;
  return touches(e) ? 1 : base * 0.3;
}

function nodeOpacity(n) {
  if (!selectedId.value || n.id === selectedId.value || neighbors.value[n.id]) return 1;
  return 0.25;
}

function showLabel(n) {
  if (n.id === selectedId.value || neighbors.value[n.id] || n.is_self) return true;
  if (selectedId.value) return false;
  return n.r >= 18 || transform.value.k >= 1.3;
}

const totalPhotos = ref(0);

// Frise : ~6 jalons répartis sur les années où des liens apparaissent.
const yearSteps = computed(() => {
  const years = [...new Set(rawEdges.value.map((e) => e.first_year).filter((y) => y !== null))].sort((a, b) => a - b);
  if (years.length < 2) return [];
  const picks = new Set();
  const n = Math.min(5, years.length);
  for (let i = 0; i < n; i++) picks.add(years[Math.round((i * (years.length - 1)) / Math.max(1, n))]);
  return [...picks].sort((a, b) => a - b).map((y) => ({ value: y, label: String(y) }))
    .concat([{ value: null, label: "Aujourd'hui" }]);
});

const topLinks = computed(() => Object.entries(neighbors.value)
  // « Moi » est déjà résumé par la tuile « photos avec vous ».
  .filter(([id, e]) => e.kind === 'photo' && (id !== selfId.value || selected.value?.is_self))
  .sort((a, b) => b[1].weight - a[1].weight)
  .slice(0, 6)
  .map(([id, e]) => ({ ...byId[id], weight: e.weight })));

const withSelfWeight = computed(() => {
  if (!selected.value || !selfId.value || selected.value.is_self) return null;
  const e = rawEdges.value.find((x) => x.kind === 'photo'
    && ((x.source === selfId.value && x.target === selected.value.id) || (x.target === selfId.value && x.source === selected.value.id)));
  return e ? e.weight : null;
});

// Photos ensemble : avec « moi » si la fiche existe, sinon rien.
const togetherFor = computed(() => (withSelfWeight.value ? 'vous' : null));

watch(selectedId, async (id) => {
  together.value = [];
  if (!id || !togetherFor.value) return;
  togetherLoading.value = true;
  try {
    const { data } = await axios.get('/network/together', { params: { a: selfId.value, b: id } });
    if (selectedId.value === id) together.value = data.slice(0, 9);
  } finally {
    togetherLoading.value = false;
  }
});

// ---------------------------------------------------------------------------
// Sélection, recherche, zoom
// ---------------------------------------------------------------------------

function select(id, center = false) {
  selectedId.value = id;
  if (center) centerOn(id);
}

function onBackgroundClick(event) {
  if (event.target === svgRef.value) selectedId.value = null;
}

const searchResults = computed(() => {
  if (!searchQuery.value.trim()) return [];
  return searchPeople(searchQuery.value, nodes.value).slice(0, 8);
});

function pickFromSearch(id) {
  searchQuery.value = '';
  yearLimit.value = null;
  select(id, true);
}

function setupZoom() {
  if (!svgRef.value) return;
  zoomBehavior = d3zoom()
    .scaleExtent([0.2, 4])
    .on('zoom', (event) => { transform.value = event.transform; });
  d3select(svgRef.value).call(zoomBehavior).on('dblclick.zoom', null);
}

function fitView(animate) {
  if (!svgRef.value || !zoomBehavior || !nodes.value.length) return;
  const { width, height } = svgRef.value.getBoundingClientRect();
  const xs = nodes.value.map((n) => n.x);
  const ys = nodes.value.map((n) => n.y);
  const pad = 80;
  const minX = Math.min(...xs) - pad, maxX = Math.max(...xs) + pad;
  const minY = Math.min(...ys) - pad, maxY = Math.max(...ys) + pad;
  // Réserve l'en-tête (haut) et la frise (bas).
  const usableH = Math.max(100, height - 170);
  const k = Math.min(2, width / (maxX - minX), usableH / (maxY - minY));
  const t = zoomIdentity
    .translate(width / 2, 90 + usableH / 2)
    .scale(k)
    .translate(-(minX + maxX) / 2, -(minY + maxY) / 2);
  apply(t, animate);
}

function centerOn(id) {
  const n = byId[id];
  if (!n || !svgRef.value) return;
  const { width, height } = svgRef.value.getBoundingClientRect();
  const k = Math.max(transform.value.k, 1.2);
  apply(zoomIdentity.translate(width / 2, height / 2).scale(k).translate(-n.x, -n.y), true);
}

function zoomBy(factor) {
  if (!zoomBehavior) return;
  d3select(svgRef.value).transition().duration(250).call(zoomBehavior.scaleBy, factor);
}

function apply(t, animate) {
  const sel = d3select(svgRef.value);
  (animate ? sel.transition().duration(450) : sel).call(zoomBehavior.transform, t);
}

// ---------------------------------------------------------------------------

function shortName(name) {
  return (name || '').split(' ')[0];
}

function initials(name) {
  return (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
}
</script>
