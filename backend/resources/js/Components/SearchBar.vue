<template>
  <div ref="rootEl" class="relative w-full max-w-xs">
    <div class="relative">
      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
        <svg class="h-4 w-4 text-surface-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
      </div>
      <input
        ref="inputEl"
        v-model="query"
        type="search"
        placeholder="Rechercher…"
        class="w-full pl-9 pr-3 py-1.5 text-sm border border-surface-200 rounded-full bg-surface-50
               placeholder-surface-400 text-surface-900
               focus:bg-white focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-hidden
               transition-colors"
        @focus="query.length >= 2 && (isOpen = true)"
        @keydown.escape="isOpen = false"
        @keydown.enter.prevent="openFirstResult"
      />
    </div>

    <!-- Résultats -->
    <div
      v-if="isOpen"
      class="absolute right-0 mt-2 w-96 max-w-[calc(100vw-2rem)] bg-white rounded-xl shadow-warm-lg border border-surface-200 py-2 z-50 max-h-[70vh] overflow-y-auto"
    >
      <div v-if="loading" class="px-4 py-3 text-sm text-surface-400">Recherche…</div>

      <template v-else-if="hasResults">
        <!-- Personnes -->
        <div v-if="results.people.length > 0">
          <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-surface-400">Personnes</p>
          <button
            v-for="person in results.people"
            :key="`person-${person.id}`"
            @mousedown.prevent="go(`/people/${person.id}`)"
            class="w-full px-4 py-2 text-left hover:bg-surface-50 transition flex items-center gap-3"
          >
            <span
              v-if="!person.avatar_url"
              class="w-7 h-7 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center shrink-0"
            >
              {{ person.name.charAt(0).toUpperCase() }}
            </span>
            <img v-else :src="person.avatar_url" class="w-7 h-7 rounded-full object-cover shrink-0" alt="" />
            <span class="text-sm text-surface-900 truncate">{{ person.name }}</span>
          </button>
        </div>

        <!-- Albums -->
        <div v-if="results.albums.length > 0">
          <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-surface-400">Albums</p>
          <button
            v-for="album in results.albums"
            :key="`album-${album.id}`"
            @mousedown.prevent="go(`/albums/${album.id}`)"
            class="w-full px-4 py-2 text-left hover:bg-surface-50 transition"
          >
            <span class="text-sm font-display font-semibold text-surface-900 block truncate">{{ album.name }}</span>
            <span v-if="album.description" class="text-xs text-surface-500 block truncate">{{ album.description }}</span>
          </button>
        </div>

        <!-- Tags -->
        <div v-if="results.tags.length > 0">
          <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-surface-400">Tags</p>
          <button
            v-for="tag in results.tags"
            :key="`tag-${tag.id}`"
            @mousedown.prevent="go(`/media?tags[]=${tag.id}`)"
            class="w-full px-4 py-2 text-left hover:bg-surface-50 transition flex items-center gap-2"
          >
            <span class="w-3 h-3 rounded-full shrink-0" :style="{ backgroundColor: tag.color || '#0D9488' }"></span>
            <span class="text-sm text-surface-900 truncate">{{ tag.name }}</span>
          </button>
        </div>

        <!-- Médias -->
        <div v-if="results.media.length > 0">
          <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-surface-400">Médias</p>
          <button
            v-for="media in results.media"
            :key="`media-${media.id}`"
            @mousedown.prevent="go(`/media/${media.id}`)"
            class="w-full px-4 py-2 text-left hover:bg-surface-50 transition flex items-center gap-3"
          >
            <img
              v-if="media.thumbnail_url"
              :src="media.thumbnail_url"
              class="w-9 h-9 rounded-md object-cover shrink-0 bg-surface-100"
              alt=""
              loading="lazy"
            />
            <span class="min-w-0">
              <span class="text-sm text-surface-900 block truncate">{{ media.title || media.original_name }}</span>
              <span class="text-xs text-surface-400 capitalize">{{ typeLabel(media.type) }}</span>
            </span>
          </button>
        </div>
      </template>

      <div v-else class="px-4 py-3 text-sm text-surface-400">
        Aucun résultat pour « {{ query }} »
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const rootEl = ref(null);
const inputEl = ref(null);
const query = ref('');
const isOpen = ref(false);
const loading = ref(false);
const results = ref({ media: [], people: [], albums: [], tags: [] });

let debounceTimer = null;
let lastRequestId = 0;

const hasResults = computed(() =>
  results.value.media.length > 0
  || results.value.people.length > 0
  || results.value.albums.length > 0
  || results.value.tags.length > 0
);

const typeLabel = (type) => ({ photo: 'photo', video: 'vidéo', document: 'document' }[type] || type);

const search = async () => {
  const q = query.value.trim();
  if (q.length < 2) {
    isOpen.value = false;
    return;
  }

  loading.value = true;
  isOpen.value = true;
  const requestId = ++lastRequestId;

  try {
    const { data } = await axios.get('/search', { params: { q } });
    // Ignore les réponses obsolètes (frappe rapide)
    if (requestId === lastRequestId) {
      results.value = data;
    }
  } catch (error) {
    if (requestId === lastRequestId) {
      results.value = { media: [], people: [], albums: [], tags: [] };
    }
  } finally {
    if (requestId === lastRequestId) {
      loading.value = false;
    }
  }
};

watch(query, () => {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(search, 250);
});

const go = (url) => {
  isOpen.value = false;
  query.value = '';
  router.visit(url);
};

const openFirstResult = () => {
  const r = results.value;
  const first =
    (r.people[0] && `/people/${r.people[0].id}`)
    || (r.albums[0] && `/albums/${r.albums[0].id}`)
    || (r.tags[0] && `/media?tags[]=${r.tags[0].id}`)
    || (r.media[0] && `/media/${r.media[0].id}`);
  if (first) go(first);
};

const handleClickOutside = (e) => {
  if (rootEl.value && !rootEl.value.contains(e.target)) {
    isOpen.value = false;
  }
};

// Ctrl+K / Cmd+K : focus sur la recherche
const handleShortcut = (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
    e.preventDefault();
    inputEl.value?.focus();
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
  document.addEventListener('keydown', handleShortcut);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside);
  document.removeEventListener('keydown', handleShortcut);
  if (debounceTimer) clearTimeout(debounceTimer);
});
</script>
