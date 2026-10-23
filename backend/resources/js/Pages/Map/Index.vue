<template>
  <Head :title="$t('Carte')" />
  <AppLayout :title="$t('Carte')">
    <PeopleSegments />
    <div class="app-viewport flex relative">
      <!-- Fond assombri (téléphone, panneau des filtres ouvert) -->
      <div
        v-if="filtersOpen"
        @click="filtersOpen = false"
        class="md:hidden fixed inset-x-0 top-16 bottom-0 z-50 bg-black/40"
        aria-hidden="true"
      ></div>

      <!-- Barre latérale : colonne fixe sur ordinateur, tiroir par-dessus la
           carte sur téléphone (sinon la carte ne fait que quelques pixels). -->
      <aside
        id="map-filters"
        class="w-80 bg-white shadow-lg p-4 overflow-y-auto shrink-0
               max-md:fixed max-md:top-16 max-md:bottom-0 max-md:left-0 max-md:z-50
               max-md:w-[85%] max-md:max-w-xs max-md:shadow-warm-lg
               max-md:transition-transform max-md:duration-300"
        :class="filtersOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full'"
      >
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xl font-semibold">{{ $t('Recherche de lieu') }}</h2>
          <button
            type="button"
            @click="filtersOpen = false"
            class="md:hidden -mr-1 p-1 text-surface-500 hover:text-surface-800"
            :aria-label="$t('Fermer les filtres')"
          >
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Location search -->
        <div class="mb-6">
          <input
            v-model="searchQuery"
            @input="debounceSearch"
            type="text"
            :placeholder="$t('Rechercher un lieu...')"
            class="w-full px-4 py-2 border border-surface-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-transparent"
          />

          <!-- Search results -->
          <div v-if="searchResults.length > 0" class="mt-2 bg-white border border-surface-200 rounded-lg shadow-lg max-h-60 overflow-y-auto">
            <button
              v-for="result in searchResults"
              :key="result.place_id"
              @click="selectLocation(result)"
              class="w-full text-left px-4 py-2 hover:bg-surface-100 border-b border-surface-100 last:border-b-0"
            >
              <div class="font-medium">{{ result.display_name }}</div>
            </button>
          </div>
        </div>

        <!-- Filters -->
        <div class="mb-4">
          <h3 class="font-semibold mb-2">{{ $t('Filtres') }}</h3>

          <!-- Type filter -->
          <select
            v-model="filters.type"
            @change="applyFilters"
            class="w-full px-3 py-2 border border-surface-300 rounded-lg mb-3"
          >
            <option value="">{{ $t('Tous les types') }}</option>
            <option value="photo">{{ $t('Photos') }}</option>
            <option value="video">{{ $t('Vidéos') }}</option>
            <option value="document">{{ $t('Documents') }}</option>
          </select>

          <!-- Search by name -->
          <input
            v-model="filters.search"
            @input="debounceFilters"
            type="text"
            :placeholder="$t('Rechercher par nom...')"
            class="w-full px-3 py-2 border border-surface-300 rounded-lg mb-3"
          />

          <!-- Tag filter -->
          <div v-if="availableTags.length > 0" class="mb-3">
            <label class="block text-sm font-medium text-surface-700 mb-2">{{ $t('Tags') }}</label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="tag in availableTags"
                :key="tag.id"
                @click="toggleTag(tag.id)"
                :class="[
                  'px-3 py-1 rounded-full text-sm font-medium transition-colors',
                  selectedTags.includes(tag.id)
                    ? 'text-white'
                    : 'bg-surface-100 text-surface-700 hover:bg-surface-200'
                ]"
                :style="selectedTags.includes(tag.id) ? { backgroundColor: tag.color || '#0D9488' } : {}"
              >
                {{ tag.name }}
              </button>
            </div>
          </div>
        </div>

        <!-- Couches : « Où vit la famille » / « Où vivent mes amis » -->
        <div class="mb-4">
          <h3 class="font-semibold mb-2">{{ $t('Couches') }}</h3>
          <button
            type="button"
            role="switch"
            :aria-checked="heatmapEnabled"
            @click="toggleHeatmap"
            class="w-full flex items-center justify-between px-3 py-2 border border-surface-300 rounded-lg hover:bg-surface-50 transition-colors"
          >
            <span class="text-sm text-surface-700">🔥 {{ isFriends ? $t('Où vivent mes amis') : $t('Où vit la famille') }}</span>
            <span
              :class="[
                'relative inline-flex h-5 w-9 shrink-0 rounded-full transition-colors',
                heatmapEnabled ? 'bg-brand-600' : 'bg-surface-300'
              ]"
            >
              <span
                :class="[
                  'absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform',
                  heatmapEnabled ? 'translate-x-4.5' : 'translate-x-0.5'
                ]"
              ></span>
            </span>
          </button>
          <p v-if="heatmapEnabled" class="mt-1 text-xs text-surface-500">
            {{ $t('Positions approximatives (~1 km), jamais les adresses exactes.') }}
          </p>
        </div>

        <!-- Media count -->
        <div class="mt-4 p-3 bg-brand-50 rounded-lg">
          <div class="text-sm text-surface-600">
            {{ geolocatedCountLabel }}
          </div>
        </div>

        <!-- Nearby search -->
        <div v-if="selectedLocation" class="mt-4 p-3 bg-surface-100 border border-surface-200 rounded-lg">
          <div class="text-sm font-medium text-surface-800 mb-2">
            {{ $t('Photos à proximité de :place', { place: selectedLocation.name }) }}
          </div>
          <button
            @click="searchNearby"
            class="btn-primary w-full"
          >
            {{ $t('Rechercher (5km)') }}
          </button>
          <button
            @click="clearSelection"
            class="w-full mt-2 px-4 py-2 bg-surface-200 text-surface-700 rounded-lg hover:bg-surface-300"
          >
            {{ $t('Effacer la sélection') }}
          </button>
        </div>
      </aside>

      <!-- Map container — `isolate` confine les z-index de Leaflet (400 à 1000)
           pour que le tiroir et les menus restent au-dessus de la carte. -->
      <div class="flex-1 relative isolate">
        <div ref="mapContainer" class="w-full h-full"></div>

        <!-- Ouverture du panneau des filtres (téléphone) — en haut à droite,
             le zoom Leaflet occupe le coin gauche. -->
        <button
          type="button"
          @click="filtersOpen = true"
          class="md:hidden absolute top-3 right-3 z-[1001] inline-flex items-center gap-1.5
                 rounded-full bg-white/90 backdrop-blur px-3 py-2 text-sm font-medium
                 text-surface-700 shadow-warm-md border border-surface-200
                 dark:bg-surface-100/90"
          aria-controls="map-filters"
          :aria-expanded="filtersOpen"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18l-7 8v6l-4 2v-8L3 4z" />
          </svg>
          {{ $t('Filtres') }}
          <span
            v-if="activeFilterCount"
            class="ml-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-600 px-1 text-xs font-semibold text-white"
          >{{ activeFilterCount }}</span>
        </button>
      </div>
    </div>

    <!-- Plusieurs souvenirs au même endroit : liste complète (toucher sur
         téléphone, clic au centre de la pile sur ordinateur). -->
    <BottomSheet :open="!!placeSheet" :title="placeSheet ? $tc(':count souvenir à cet endroit|:count souvenirs à cet endroit', placeSheet.items.length) : ''" @close="placeSheet = null">
      <div v-if="placeSheet" class="-mx-4 divide-y divide-surface-100">
        <Link
          v-for="item in placeSheet.items"
          :key="item.kind + item.id"
          :href="item.href"
          class="flex items-center gap-3 px-4 py-2.5 active:bg-surface-50 hover:bg-surface-50"
          @click="placeSheet = null"
        >
          <span
            class="w-14 h-14 rounded-xl overflow-hidden bg-surface-200 shrink-0 border-2"
            :class="item.kind === 'album' ? 'border-amber-400' : 'border-transparent'"
          >
            <img v-if="item.thumb" :src="item.thumb" alt="" class="w-full h-full object-cover" loading="lazy" />
          </span>
          <span class="flex-1 min-w-0">
            <span class="block text-[15px] font-bold text-surface-900 truncate">{{ item.title }}</span>
            <span class="block text-xs text-surface-500 truncate">{{ item.sub }}</span>
          </span>
          <span class="text-surface-400">›</span>
        </Link>
      </div>
    </BottomSheet>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PeopleSegments from '@/Components/PeopleSegments.vue';
import BottomSheet from '@/Components/BottomSheet.vue';
import { useCircle } from '@/Composables/useCircle';
import { formatDate } from '@/utils/format';
import { escapeHtml } from '@/utils/html';
import { apiError } from '@/utils/apiError';
import { useToast } from '@/Composables/useToast';
import { useTags } from '@/Composables/useTags';
import { t, tc } from '@/i18n';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.heat';
import axios from 'axios';

const { isFriends } = useCircle();

const toast = useToast();

// Téléphone (< md) : la barre latérale devient un tiroir ouvert à la demande.
const isMobile = () => window.matchMedia('(max-width: 767px)').matches;
const filtersOpen = ref(false);

const props = defineProps({
  filters: {
    type: Object,
    default: () => ({}),
  },
});

// Map instance
const mapContainer = ref(null);
let map = null;
let markersLayer = null;
let searchMarker = null;
let heatLayer = null;

// Couche « où vit la famille » (heatmap grossière des adresses)
const heatmapEnabled = ref(false);
let heatPoints = null; // cache : un seul fetch par visite

// Data
const geolocatedMedia = ref([]);
const geolocatedAlbums = ref([]);
const availableTags = ref([]);
const selectedTags = ref(props.filters.tags || []);

// Search
const searchQuery = ref('');
const searchResults = ref([]);
const selectedLocation = ref(null);
let searchTimeout = null;

// Filters
const filters = ref({
  type: props.filters.type || '',
  search: props.filters.search || '',
  tags: props.filters.tags || [],
});

// Pastille du bouton « Filtres » (téléphone) : rappelle qu'un filtre est actif.
const activeFilterCount = computed(() =>
  (filters.value.type ? 1 : 0)
  + (filters.value.search ? 1 : 0)
  + selectedTags.value.length
  // Couche « où vit la famille » : active par défaut, la pastille signale
  // seulement qu'on l'a coupée.
  + (heatmapEnabled.value ? 0 : 1)
  + (selectedLocation.value ? 1 : 0));

// Compteur du panneau : médias (et albums) placés sur la carte.
const geolocatedCountLabel = computed(() => (geolocatedAlbums.value.length
  ? t(':media · :albums géolocalisés', {
    media: tc(':count média|:count médias', geolocatedMedia.value.length),
    albums: tc(':count album|:count albums', geolocatedAlbums.value.length),
  })
  : tc(':count média géolocalisé|:count médias géolocalisés', geolocatedMedia.value.length)));

// Initialize map
onMounted(async () => {
  // Create map
  map = L.map(mapContainer.value).setView([46.603354, 1.888334], 6); // Center on France

  // Add OpenStreetMap tiles
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19,
  }).addTo(map);

  // Create markers layer
  markersLayer = L.layerGroup().addTo(map);

  // Tags (filtres) et médias géolocalisés : indépendants, chargés en parallèle.
  await Promise.all([loadTags(), loadGeolocatedMedia()]);
  // « Où vit la famille / où vivent mes amis » : affichée d'office.
  showHeatmap();
});

onUnmounted(() => {
  clearTimeout(searchTimeout);
  clearTimeout(filterTimeout);
  if (map) {
    map.remove();
    // Les chargements encore en route (marqueurs, chaleur) voient la carte
    // disparue et s'arrêtent, au lieu d'écrire sur une carte détruite.
    map = null;
  }
});

// Une seule réponse fait foi : la dernière demandée (filtres, recherche
// autour d'un lieu) — une réponse lente ne réécrase pas une plus récente.
let markersRequest = 0;

// Load all tags (liste partagée, souvent déjà en cache)
const tagList = useTags();
async function loadTags() {
  try {
    availableTags.value = await tagList.load();
  } catch (error) {
    console.error('Failed to load tags:', error);
    toast.error(apiError(error, t('Impossible de charger les tags pour le filtre.')));
  }
}

// Load geolocated media
async function loadGeolocatedMedia() {
  try {
    const params = new URLSearchParams();
    if (filters.value.type) params.append('type', filters.value.type);
    if (filters.value.search) params.append('search', filters.value.search);
    if (selectedTags.value.length > 0) {
      selectedTags.value.forEach(tag => params.append('tags[]', tag));
    }

    const id = ++markersRequest;
    const response = await axios.get(`/map/media?${params.toString()}`);
    if (id !== markersRequest || !map) return;
    geolocatedMedia.value = response.data.media || [];
    geolocatedAlbums.value = response.data.albums || [];

    // Update markers on map
    updateMarkers();
  } catch (error) {
    console.error('Failed to load geolocated media:', error);
    toast.error(apiError(error, t('Impossible de charger les photos de la carte. Réessayez dans un instant.')));
  }
}

// ⚠️ Leaflet insère icônes et popups en innerHTML : toute valeur venant des
// données (noms, titres, URLs…) passe par escapeHtml, texte comme attribut.

// Icône miniature (bordure blanche par défaut, teintée pour un album).
function thumbIcon(thumbnailUrl, { badge = null, borderColor = '#fff' } = {}) {
  const badgeHtml = badge
    ? `<div style="position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#f59e0b;color:#fff;font-size:11px;font-weight:600;line-height:18px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.3);">${escapeHtml(badge)}</div>`
    : '';
  return L.divIcon({
    className: 'custom-thumb-marker',
    html: `<div style="position:relative;width:48px;height:48px;border-radius:8px;overflow:hidden;border:3px solid ${borderColor};box-shadow:0 2px 8px rgba(0,0,0,0.3);background:#e5e7eb;">
        <img src="${escapeHtml(thumbnailUrl)}" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none'" />
        ${badgeHtml}
      </div>
      <div style="width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid ${borderColor};margin:0 auto;filter:drop-shadow(0 2px 2px rgba(0,0,0,0.2));"></div>`,
    iconSize: [48, 56],
    iconAnchor: [24, 56],
    popupAnchor: [0, -56],
  });
}

// Souvenirs au même endroit (à ~1 m près : même adresse) : une seule pile.
const placeSheet = ref(null);
// Au moins un pointeur qui survole (souris, pavé) : PC, y compris tactile.
const canHover = () => window.matchMedia('(any-hover: hover)').matches;
const placeKey = (lat, lng) => `${Number(lat).toFixed(5)},${Number(lng).toFixed(5)}`;

// Album ou média, sous une forme commune (pile, éventail, feuille).
const albumItem = (album) => ({
  kind: 'album', id: album.id, lat: album.latitude, lng: album.longitude,
  thumb: album.thumbnail_url, title: album.name,
  sub: tc('Album · :count média|Album · :count médias', album.media_count),
  href: `/albums/${encodeURIComponent(album.id)}`,
  raw: album,
});
const mediaItem = (media) => ({
  kind: 'media', id: media.id, lat: media.latitude, lng: media.longitude,
  thumb: media.thumbnail_url,
  title: media.taken_at ? formatDate(media.taken_at) : (media.original_name || t('Photo')),
  sub: media.type === 'video' ? t('Vidéo') : t('Photo'),
  href: `/media/${encodeURIComponent(media.id)}`,
  raw: media,
});

// Marqueur seul : vignette + bulle (comportement historique).
function singleMarker(item) {
  const marker = L.marker([item.lat, item.lng], {
    icon: item.kind === 'album'
      ? thumbIcon(item.thumb, { badge: item.raw.media_count, borderColor: '#f59e0b' })
      : thumbIcon(item.thumb),
  });
  const name = escapeHtml(item.kind === 'album' ? item.raw.name : item.raw.original_name);
  marker.bindPopup(item.kind === 'album' ? `
      <div class="text-center">
        <img src="${escapeHtml(item.thumb)}" alt="${name}" class="w-32 h-32 object-cover rounded-sm mb-2" />
        <div class="font-medium text-sm">📁 ${name}</div>
        <div class="text-xs text-surface-500">${escapeHtml(tc(':count média|:count médias', item.raw.media_count))}</div>
        <div class="mt-2">
          <a href="${escapeHtml(item.href)}" class="text-brand-600 hover:text-brand-800 text-sm">${escapeHtml(t("Voir l'album"))}</a>
        </div>
      </div>
    ` : `
      <div class="text-center">
        <img src="${escapeHtml(item.thumb)}" alt="${name}" class="w-32 h-32 object-cover rounded-sm mb-2" />
        <div class="font-medium text-sm">${name}</div>
        ${item.raw.taken_at ? `<div class="text-xs text-surface-500">${escapeHtml(formatDate(item.raw.taken_at))}</div>` : ''}
        <div class="mt-2">
          <a href="${escapeHtml(item.href)}" class="text-brand-600 hover:text-brand-800 text-sm">${escapeHtml(t('Voir le média'))}</a>
        </div>
      </div>
    `);
  return marker;
}

// Pile : vignettes empilées + compteur. Au survol (ordinateur), elles
// s'écartent en éventail autour du point, chacune avec son nom ; au-delà de
// 8, la dernière carte « +N » ouvre la liste complète.
const FAN_MAX = 8;
function stackMarker(items) {
  const shown = items.length > FAN_MAX ? items.slice(0, FAN_MAX - 1) : items;
  const more = items.length - shown.length;
  const m = shown.length + (more ? 1 : 0);
  const radius = m <= 2 ? 70 : m <= 4 ? 84 : m <= 6 ? 104 : 120;
  const start = m === 2 ? 180 : -90;
  const hasAlbum = items.some((i) => i.kind === 'album');

  const card = (k, inner, attrs) => {
    const angle = ((start + (k * 360) / m) * Math.PI) / 180;
    const dx = Math.round(Math.cos(angle) * radius);
    const dy = Math.round(Math.sin(angle) * radius);
    // Repos : 3 cartes visibles, légèrement décalées ; les suivantes dessous.
    const rest = k < 3 ? [(k - 1) * 5, -k * 3, (k - 1) * 7] : [0, 0, 0];
    return `<div class="ml-stack-card" ${attrs} style="--i:${k};--dx:${dx}px;--dy:${dy}px;--rx:${rest[0]}px;--ry:${rest[1]}px;--rot:${rest[2]}deg;z-index:${20 - k};">${inner}</div>`;
  };

  const cards = shown.map((item, k) => card(k,
    `<div class="ml-stack-thumb${item.kind === 'album' ? ' is-album' : ''}"><img src="${escapeHtml(item.thumb)}" alt="" onerror="this.style.display='none'" /></div>
     <span class="ml-stack-label">${escapeHtml(item.title)}</span>`,
    `data-href="${escapeHtml(item.href)}" title="${escapeHtml(item.title)}"`)).join('');
  const moreCard = more ? card(shown.length,
    `<div class="ml-stack-thumb ml-stack-more">+${more}</div><span class="ml-stack-label">${escapeHtml(t('Tout voir'))}</span>`,
    'data-sheet="1"') : '';

  const marker = L.marker([items[0].lat, items[0].lng], {
    riseOnHover: true,
    riseOffset: 2000,
    icon: L.divIcon({
      className: 'ml-stack-marker',
      html: `<div class="ml-stack" style="--r:${radius}px">
          <div class="ml-stack-hit"></div>
          ${cards}${moreCard}
          <div class="ml-stack-count" title="${escapeHtml(tc(':count souvenir à cet endroit|:count souvenirs à cet endroit', items.length))}">${items.length}</div>
          <div class="ml-stack-tip" style="border-top-color:${hasAlbum ? '#f59e0b' : '#fff'}"></div>
        </div>`,
      iconSize: [48, 56],
      iconAnchor: [24, 56],
    }),
  });

  // Près d'un bord, l'éventail se décale vers l'intérieur de la carte
  // (sinon le conteneur Leaflet le coupe).
  marker.on('mouseover', () => {
    const el = marker.getElement()?.querySelector('.ml-stack');
    if (!el || !canHover()) return;
    const pt = map.latLngToContainerPoint(marker.getLatLng());
    const size = map.getSize();
    const cx = pt.x;          // centre de la pile (ancre en bas, carte de 48 px)
    const cy = pt.y - 32;
    const mx = radius + 70;   // demi-largeur utile (carte + étiquette)
    const top = radius + 34;
    const bottom = radius + 60;
    const ox = cx - mx < 0 ? mx - cx : (cx + mx > size.x ? size.x - mx - cx : 0);
    const oy = cy - top < 0 ? top - cy : (cy + bottom > size.y ? size.y - bottom - cy : 0);
    el.style.setProperty('--ox', `${Math.round(ox)}px`);
    el.style.setProperty('--oy', `${Math.round(oy)}px`);
  });

  // Ordinateur : clic sur une carte de l'éventail → l'album / la photo ;
  // ailleurs (centre, « +N ») → la liste. Téléphone : toujours la liste.
  marker.on('click', (e) => {
    const target = e.originalEvent?.target;
    const hit = target?.closest?.('[data-href]');
    if (hit && canHover()) {
      router.visit(hit.getAttribute('data-href'));
      return;
    }
    placeSheet.value = { items };
  });
  return marker;
}

// Update markers on the map
function updateMarkers() {
  if (!map) return;
  markersLayer.clearLayers();
  placeSheet.value = null;

  const items = [
    ...geolocatedAlbums.value.map(albumItem),
    ...geolocatedMedia.value.map(mediaItem),
  ];
  const groups = new Map();
  for (const item of items) {
    const key = placeKey(item.lat, item.lng);
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(item);
  }

  const bounds = [];
  for (const group of groups.values()) {
    const marker = group.length === 1 ? singleMarker(group[0]) : stackMarker(group);
    marker.addTo(markersLayer);
    bounds.push([group[0].lat, group[0].lng]);
  }

  // Fit map to show all markers
  if (bounds.length > 0) {
    map.fitBounds(bounds, { padding: [50, 50] });
  }
}

// Couche heatmap : les points arrivent déjà arrondis (~1 km) et anonymes
// du serveur ; le grand radius accentue volontairement l'aspect « nuage ».
// Activée par défaut. Le calque est recréé à chaque affichage : leaflet.heat
// ne se réaffiche pas correctement une fois retiré puis réajouté.
async function showHeatmap() {
  heatmapEnabled.value = true;

  if (!heatPoints) {
    try {
      const response = await axios.get('/map/heatmap');
      heatPoints = (response.data.points || []).map(([lat, lng, weight]) => [
        Number(lat),
        Number(lng),
        Number(weight),
      ]);
    } catch (error) {
      console.error('Failed to load heatmap:', error);
      toast.error(apiError(error, t("Impossible d'afficher cette couche de la carte.")));
      heatmapEnabled.value = false;
      return;
    }
  }
  // Désactivée pendant le chargement : on n'affiche rien.
  if (!heatmapEnabled.value || !map) return;

  if (heatLayer) map.removeLayer(heatLayer);
  // Le poids d'un point est un nombre de FOYERS (serveur) : une famille
  // sous le même toit compte pour un. Réglé pour de faibles densités :
  // - maxZoom bas : leaflet.heat divise l'intensité par 2^(maxZoom - zoom),
  //   avec la valeur par défaut un point isolé est invisible au niveau France ;
  // - max: 1.5 : 1 foyer = tache orange bien visible, 2+ foyers proches = rouge ;
  // - minOpacity garde le halo lisible sur les tuiles claires.
  heatLayer = L.heatLayer(heatPoints, {
    radius: 45,
    blur: 25,
    maxZoom: 6,
    max: 1.5,
    minOpacity: 0.35,
  }).addTo(map);
}

function hideHeatmap() {
  heatmapEnabled.value = false;
  if (heatLayer) {
    map.removeLayer(heatLayer);
    heatLayer = null;
  }
}

const toggleHeatmap = () => (heatmapEnabled.value ? hideHeatmap() : showHeatmap());

// Toggle tag filter
function toggleTag(tagId) {
  const index = selectedTags.value.indexOf(tagId);
  if (index > -1) {
    selectedTags.value.splice(index, 1);
  } else {
    selectedTags.value.push(tagId);
  }
  filters.value.tags = selectedTags.value;
  applyFilters();
}

// Apply filters
function applyFilters() {
  loadGeolocatedMedia();
}

// Filtre « nom » : requête réseau debouncée (pas une par frappe)
let filterTimeout = null;
function debounceFilters() {
  clearTimeout(filterTimeout);
  filterTimeout = setTimeout(applyFilters, 400);
}

// Debounced location search
function debounceSearch() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    if (searchQuery.value.length >= 3) {
      searchLocation();
    } else {
      searchResults.value = [];
    }
  }, 500);
}

// Search for a location
async function searchLocation() {
  try {
    const response = await axios.get('/map/search', {
      params: { query: searchQuery.value }
    });
    searchResults.value = response.data;
  } catch (error) {
    console.error('Location search failed:', error);
    toast.error(apiError(error, t("La recherche de lieu n'a pas abouti. Réessayez dans un instant.")));
  }
}

// Select a location from search results
function selectLocation(location) {
  selectedLocation.value = {
    name: location.display_name,
    lat: parseFloat(location.lat),
    lon: parseFloat(location.lon),
  };

  // Pan map to selected location
  map.setView([selectedLocation.value.lat, selectedLocation.value.lon], 12);

  // Marqueur du lieu recherché : un seul à la fois (l'ancien est retiré),
  // icône locale (pas de dépendance à un CDN externe).
  if (searchMarker) {
    searchMarker.remove();
  }
  searchMarker = L.marker([selectedLocation.value.lat, selectedLocation.value.lon], {
    icon: L.divIcon({
      className: 'custom-thumb-marker',
      html: '<div style="width:18px;height:18px;border-radius:50% 50% 50% 0;background:#dc2626;border:2px solid #fff;transform:rotate(-45deg);box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
      iconSize: [18, 18],
      iconAnchor: [9, 18],
      popupAnchor: [0, -18],
    })
  })
  .bindPopup(escapeHtml(location.display_name))
  .addTo(map);

  // Clear search
  searchQuery.value = '';
  searchResults.value = [];

  // Téléphone : on referme le tiroir pour montrer le lieu sur la carte
  // (« Rechercher à proximité » reste accessible via le bouton Filtres).
  if (isMobile()) filtersOpen.value = false;
}

// Search for nearby media
async function searchNearby() {
  if (!selectedLocation.value) return;

  try {
    const id = ++markersRequest;
    const response = await axios.get('/map/nearby', {
      params: {
        latitude: selectedLocation.value.lat,
        longitude: selectedLocation.value.lon,
        radius: 5, // 5km
      }
    });

    if (id !== markersRequest || !map) return;
    geolocatedMedia.value = response.data;
    geolocatedAlbums.value = [];
    updateMarkers();
    // Téléphone : on referme le tiroir pour montrer le résultat sur la carte.
    if (isMobile()) filtersOpen.value = false;
  } catch (error) {
    console.error('Nearby search failed:', error);
    toast.error(apiError(error, t('Impossible de rechercher les photos à proximité.')));
  }
}

// Clear location selection
function clearSelection() {
  selectedLocation.value = null;
  if (searchMarker) {
    searchMarker.remove();
    searchMarker = null;
  }
  loadGeolocatedMedia();
}
</script>

<style scoped>
:deep(.custom-thumb-marker) {
  background: none !important;
  border: none !important;
}
</style>

<style>
/* Pile de souvenirs au même endroit (marqueur Leaflet, injecté hors du
   composant : styles globaux). */
.ml-stack-marker { background: none; border: 0; }
.ml-stack { position: relative; width: 48px; height: 56px; }
.ml-stack-hit {
  position: absolute; left: 50%; top: 24px;
  width: calc(var(--r) * 2 + 130px); height: calc(var(--r) * 2 + 120px);
  transform: translate(calc(-50% + var(--ox, 0px)), calc(-50% + var(--oy, 0px))); border-radius: 50%;
  pointer-events: none;
}
.ml-stack-card {
  position: absolute; left: 0; top: 0; width: 48px; height: 48px;
  transform: translate(var(--rx), var(--ry)) rotate(var(--rot));
  transition: transform .28s cubic-bezier(.2, .8, .2, 1) calc(var(--i) * 25ms);
}
.ml-stack-card:nth-child(n+5) { opacity: 0; }
.ml-stack-thumb {
  width: 48px; height: 48px; border-radius: 8px; overflow: hidden;
  border: 3px solid #fff; background: #e5e7eb;
  box-shadow: 0 2px 8px rgba(0, 0, 0, .3);
}
.ml-stack-thumb.is-album { border-color: #f59e0b; }
.ml-stack-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.ml-stack-more {
  display: flex; align-items: center; justify-content: center;
  background: #1c1917; color: #fff; font: 700 14px/1 system-ui, sans-serif;
}
.ml-stack-label {
  position: absolute; left: 50%; top: 52px; transform: translateX(-50%);
  max-width: 112px; padding: 2px 8px; border-radius: 999px;
  background: rgba(28, 25, 23, .85); color: #fff;
  font: 600 11px/1.4 system-ui, sans-serif;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  opacity: 0; pointer-events: none; transition: opacity .2s ease;
}
.ml-stack-count {
  position: absolute; top: -9px; right: -10px; z-index: 30;
  min-width: 22px; height: 22px; padding: 0 6px; border-radius: 11px;
  background: #1c1917; color: #fff; border: 2px solid #fff;
  font: 700 12px/18px system-ui, sans-serif; text-align: center;
  box-shadow: 0 1px 3px rgba(0, 0, 0, .3); transition: opacity .2s ease;
}
.ml-stack-tip {
  position: absolute; left: 16px; top: 48px; width: 0; height: 0;
  border-left: 8px solid transparent; border-right: 8px solid transparent;
  border-top: 8px solid #fff; filter: drop-shadow(0 2px 2px rgba(0, 0, 0, .2));
}

/* Ordinateur (souris ou pavé) : l'éventail s'ouvre au survol. */
@media (any-hover: hover) {
  .ml-stack { cursor: pointer; }
  .ml-stack:hover .ml-stack-hit { pointer-events: auto; }
  .ml-stack:hover .ml-stack-card {
    opacity: 1;
    transform: translate(calc(var(--dx) + var(--ox, 0px)), calc(var(--dy) + var(--oy, 0px))) rotate(0deg) scale(1.15);
  }
  .ml-stack:hover .ml-stack-card:hover { transform: translate(calc(var(--dx) + var(--ox, 0px)), calc(var(--dy) + var(--oy, 0px))) scale(1.3); z-index: 40 !important; }
  .ml-stack:hover .ml-stack-label { opacity: 1; }
  .ml-stack:hover .ml-stack-count { opacity: 0; }
}
</style>

