<template>
  <Head title="Carte" />
  <AppLayout title="Carte">
    <div class="h-[calc(100dvh-4rem)] flex relative">
      <!-- Fond assombri (téléphone, panneau des filtres ouvert) -->
      <div
        v-if="filtersOpen"
        @click="filtersOpen = false"
        class="md:hidden fixed inset-x-0 top-16 bottom-0 z-20 bg-black/40"
        aria-hidden="true"
      ></div>

      <!-- Barre latérale : colonne fixe sur ordinateur, tiroir par-dessus la
           carte sur téléphone (sinon la carte ne fait que quelques pixels). -->
      <aside
        id="map-filters"
        class="w-80 bg-white shadow-lg p-4 overflow-y-auto shrink-0
               max-md:fixed max-md:top-16 max-md:bottom-0 max-md:left-0 max-md:z-30
               max-md:w-[85%] max-md:max-w-xs max-md:shadow-warm-lg
               max-md:transition-transform max-md:duration-300"
        :class="filtersOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full'"
      >
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xl font-semibold">Recherche de lieu</h2>
          <button
            type="button"
            @click="filtersOpen = false"
            class="md:hidden -mr-1 p-1 text-surface-500 hover:text-surface-800"
            aria-label="Fermer les filtres"
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
            placeholder="Rechercher un lieu..."
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
          <h3 class="font-semibold mb-2">Filtres</h3>

          <!-- Type filter -->
          <select
            v-model="filters.type"
            @change="applyFilters"
            class="w-full px-3 py-2 border border-surface-300 rounded-lg mb-3"
          >
            <option value="">Tous les types</option>
            <option value="photo">Photos</option>
            <option value="video">Vidéos</option>
            <option value="document">Documents</option>
          </select>

          <!-- Search by name -->
          <input
            v-model="filters.search"
            @input="debounceFilters"
            type="text"
            placeholder="Rechercher par nom..."
            class="w-full px-3 py-2 border border-surface-300 rounded-lg mb-3"
          />

          <!-- Tag filter -->
          <div v-if="availableTags.length > 0" class="mb-3">
            <label class="block text-sm font-medium text-surface-700 mb-2">Tags</label>
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

        <!-- Couches (« Où vit la famille » : famille seulement) -->
        <div v-if="!isFriends" class="mb-4">
          <h3 class="font-semibold mb-2">Couches</h3>
          <button
            type="button"
            role="switch"
            :aria-checked="heatmapEnabled"
            @click="toggleHeatmap"
            class="w-full flex items-center justify-between px-3 py-2 border border-surface-300 rounded-lg hover:bg-surface-50 transition-colors"
          >
            <span class="text-sm text-surface-700">🔥 Où vit la famille</span>
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
            Positions approximatives (~1 km), jamais les adresses exactes.
          </p>
        </div>

        <!-- Media count -->
        <div class="mt-4 p-3 bg-brand-50 rounded-lg">
          <div class="text-sm text-surface-600">
            {{ geolocatedMedia.length }} média(s)
            <span v-if="geolocatedAlbums.length"> · {{ geolocatedAlbums.length }} album(s)</span>
            géolocalisé(s)
          </div>
        </div>

        <!-- Nearby search -->
        <div v-if="selectedLocation" class="mt-4 p-3 bg-surface-100 border border-surface-200 rounded-lg">
          <div class="text-sm font-medium text-surface-800 mb-2">
            Photos à proximité de {{ selectedLocation.name }}
          </div>
          <button
            @click="searchNearby"
            class="btn-primary w-full"
          >
            Rechercher (5km)
          </button>
          <button
            @click="clearSelection"
            class="w-full mt-2 px-4 py-2 bg-surface-200 text-surface-700 rounded-lg hover:bg-surface-300"
          >
            Effacer la sélection
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
          Filtres
          <span
            v-if="activeFilterCount"
            class="ml-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-600 px-1 text-xs font-semibold text-white"
          >{{ activeFilterCount }}</span>
        </button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useCircle } from '@/Composables/useCircle';
import { formatDate } from '@/utils/format';
import { escapeHtml } from '@/utils/html';
import { apiError } from '@/utils/apiError';
import { useToast } from '@/Composables/useToast';
import { useTags } from '@/Composables/useTags';
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
  + (heatmapEnabled.value ? 1 : 0)
  + (selectedLocation.value ? 1 : 0));

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
});

onUnmounted(() => {
  clearTimeout(searchTimeout);
  clearTimeout(filterTimeout);
  if (map) {
    map.remove();
  }
});

// Load all tags (liste partagée, souvent déjà en cache)
const tagList = useTags();
async function loadTags() {
  try {
    availableTags.value = await tagList.load();
  } catch (error) {
    console.error('Failed to load tags:', error);
    toast.error(apiError(error, 'Impossible de charger les tags pour le filtre.'));
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

    const response = await axios.get(`/map/media?${params.toString()}`);
    geolocatedMedia.value = response.data.media || [];
    geolocatedAlbums.value = response.data.albums || [];

    // Update markers on map
    updateMarkers();
  } catch (error) {
    console.error('Failed to load geolocated media:', error);
    toast.error(apiError(error, 'Impossible de charger les photos de la carte. Réessayez dans un instant.'));
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

// Update markers on the map
function updateMarkers() {
  // Clear existing markers
  markersLayer.clearLayers();

  const bounds = [];

  // Marqueurs d'album : le clic mène à l'album (permissions album respectées).
  geolocatedAlbums.value.forEach(album => {
    const marker = L.marker([album.latitude, album.longitude], {
      icon: thumbIcon(album.thumbnail_url, { badge: album.media_count, borderColor: '#f59e0b' }),
    });

    const name = escapeHtml(album.name);
    marker.bindPopup(`
      <div class="text-center">
        <img src="${escapeHtml(album.thumbnail_url)}" alt="${name}" class="w-32 h-32 object-cover rounded-sm mb-2" />
        <div class="font-medium text-sm">📁 ${name}</div>
        <div class="text-xs text-surface-500">${escapeHtml(album.media_count)} média(s)</div>
        <div class="mt-2">
          <a href="/albums/${encodeURIComponent(album.id)}" class="text-brand-600 hover:text-brand-800 text-sm">Voir l'album</a>
        </div>
      </div>
    `);
    marker.addTo(markersLayer);
    bounds.push([album.latitude, album.longitude]);
  });

  // Marqueurs média (hors album) : le clic mène au média.
  geolocatedMedia.value.forEach(media => {
    const marker = L.marker([media.latitude, media.longitude], {
      icon: thumbIcon(media.thumbnail_url),
    });

    const name = escapeHtml(media.original_name);
    marker.bindPopup(`
      <div class="text-center">
        <img src="${escapeHtml(media.thumbnail_url)}" alt="${name}" class="w-32 h-32 object-cover rounded-sm mb-2" />
        <div class="font-medium text-sm">${name}</div>
        ${media.taken_at ? `<div class="text-xs text-surface-500">${escapeHtml(formatDate(media.taken_at))}</div>` : ''}
        <div class="mt-2">
          <a href="/media/${encodeURIComponent(media.id)}" class="text-brand-600 hover:text-brand-800 text-sm">Voir le média</a>
        </div>
      </div>
    `);
    marker.addTo(markersLayer);
    bounds.push([media.latitude, media.longitude]);
  });

  // Fit map to show all markers
  if (bounds.length > 0) {
    map.fitBounds(bounds, { padding: [50, 50] });
  }
}

// Couche heatmap : les points arrivent déjà arrondis (~1 km) et anonymes
// du serveur ; le grand radius accentue volontairement l'aspect « nuage ».
async function toggleHeatmap() {
  heatmapEnabled.value = !heatmapEnabled.value;

  if (!heatmapEnabled.value) {
    if (heatLayer) map.removeLayer(heatLayer);
    return;
  }

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
      toast.error(apiError(error, "Impossible d'afficher la couche « Où vit la famille »."));
      heatmapEnabled.value = false;
      return;
    }
  }

  // Réglé pour de FAIBLES densités (souvent 1 personne par ville, max 2-3) :
  // - maxZoom bas : leaflet.heat divise l'intensité par 2^(maxZoom - zoom),
  //   avec la valeur par défaut un point isolé est invisible au niveau France ;
  // - max: 1.5 : 1 personne = tache orange bien visible, 2+ = rouge saturé ;
  // - minOpacity garde le halo lisible sur les tuiles claires.
  heatLayer ??= L.heatLayer(heatPoints, {
    radius: 45,
    blur: 25,
    maxZoom: 6,
    max: 1.5,
    minOpacity: 0.35,
  });
  heatLayer.setLatLngs(heatPoints).addTo(map);
}

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
    toast.error(apiError(error, "La recherche de lieu n'a pas abouti. Réessayez dans un instant."));
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
    const response = await axios.get('/map/nearby', {
      params: {
        latitude: selectedLocation.value.lat,
        longitude: selectedLocation.value.lon,
        radius: 5, // 5km
      }
    });

    geolocatedMedia.value = response.data;
    geolocatedAlbums.value = [];
    updateMarkers();
    // Téléphone : on referme le tiroir pour montrer le résultat sur la carte.
    if (isMobile()) filtersOpen.value = false;
  } catch (error) {
    console.error('Nearby search failed:', error);
    toast.error(apiError(error, 'Impossible de rechercher les photos à proximité.'));
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
