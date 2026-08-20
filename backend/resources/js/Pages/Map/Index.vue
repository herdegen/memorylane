<template>
  <Head title="Carte" />
  <AppLayout title="Carte">
    <div class="h-[calc(100vh-4rem)] flex">
      <!-- Sidebar with filters and search -->
      <div class="w-80 bg-white shadow-lg p-4 overflow-y-auto">
        <h2 class="text-xl font-semibold mb-4">Recherche de lieu</h2>

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

        <!-- Media count -->
        <div class="mt-4 p-3 bg-brand-50 rounded-lg">
          <div class="text-sm text-surface-600">
            {{ geolocatedMedia.length }} média(s)
            <span v-if="geolocatedAlbums.length"> · {{ geolocatedAlbums.length }} album(s)</span>
            géolocalisé(s)
          </div>
        </div>

        <!-- Nearby search -->
        <div v-if="selectedLocation" class="mt-4 p-3 bg-green-50 dark:bg-green-500/10 rounded-lg">
          <div class="text-sm font-medium text-green-800 dark:text-green-200 mb-2">
            Photos à proximité de {{ selectedLocation.name }}
          </div>
          <button
            @click="searchNearby"
            class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
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
      </div>

      <!-- Map container -->
      <div class="flex-1 relative">
        <div ref="mapContainer" class="w-full h-full"></div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate } from '@/utils/format';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import axios from 'axios';

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

  // Load available tags
  await loadTags();

  // Load geolocated media
  await loadGeolocatedMedia();
});

onUnmounted(() => {
  clearTimeout(searchTimeout);
  clearTimeout(filterTimeout);
  if (map) {
    map.remove();
  }
});

// Load all tags
async function loadTags() {
  try {
    const response = await axios.get('/tags');
    availableTags.value = response.data;
  } catch (error) {
    console.error('Failed to load tags:', error);
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
  }
}

// Icône miniature (bordure blanche par défaut, teintée pour un album).
function thumbIcon(thumbnailUrl, { badge = null, borderColor = '#fff' } = {}) {
  const badgeHtml = badge
    ? `<div style="position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#f59e0b;color:#fff;font-size:11px;font-weight:600;line-height:18px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.3);">${badge}</div>`
    : '';
  return L.divIcon({
    className: 'custom-thumb-marker',
    html: `<div style="position:relative;width:48px;height:48px;border-radius:8px;overflow:hidden;border:3px solid ${borderColor};box-shadow:0 2px 8px rgba(0,0,0,0.3);background:#e5e7eb;">
        <img src="${thumbnailUrl}" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none'" />
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

    marker.bindPopup(`
      <div class="text-center">
        <img src="${album.thumbnail_url}" alt="${album.name}" class="w-32 h-32 object-cover rounded-sm mb-2" />
        <div class="font-medium text-sm">📁 ${album.name}</div>
        <div class="text-xs text-surface-500">${album.media_count} média(s)</div>
        <div class="mt-2">
          <a href="/albums/${album.id}" class="text-brand-600 hover:text-brand-800 text-sm">Voir l'album</a>
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

    marker.bindPopup(`
      <div class="text-center">
        <img src="${media.thumbnail_url}" alt="${media.original_name}" class="w-32 h-32 object-cover rounded-sm mb-2" />
        <div class="font-medium text-sm">${media.original_name}</div>
        ${media.taken_at ? `<div class="text-xs text-surface-500">${formatDate(media.taken_at)}</div>` : ''}
        <div class="mt-2">
          <a href="/media/${media.id}" class="text-brand-600 hover:text-brand-800 text-sm">Voir le média</a>
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
  .bindPopup(location.display_name)
  .addTo(map);

  // Clear search
  searchQuery.value = '';
  searchResults.value = [];
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
  } catch (error) {
    console.error('Nearby search failed:', error);
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
