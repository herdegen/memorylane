<template>
  <Head :title="$t('Albums')" />
  <AppLayout>
    <div class="py-4 sm:py-9 px-4 sm:px-8">
      <!-- En-tête (téléphone : titre compact + compteur, bouton « + » rond) -->
      <div class="flex items-end justify-between gap-4 mb-3 sm:mb-5">
        <div class="min-w-0">
          <h1 class="font-display text-[26px] sm:text-4xl font-bold text-surface-900 truncate">
            {{ $t('Albums') }}
            <span class="sm:hidden font-sans text-sm font-semibold text-surface-500 ml-1">{{ albums.length }}</span>
          </h1>
          <p class="mt-1.5 text-sm text-surface-500 hidden sm:block">
            {{ $tc(':count album — les souvenirs, rangés par histoire|:count albums — les souvenirs, rangés par histoire', albums.length) }}
          </p>
        </div>
        <button
          type="button"
          class="sm:hidden w-10 h-10 rounded-full bg-brand-600 text-white flex items-center justify-center shrink-0"
          :aria-label="$t('Nouvel album')"
          @click="showCreateModal = true"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
        </button>
        <button @click="showCreateModal = true" class="btn-primary hidden sm:inline-flex">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          {{ $t('Nouvel album') }}
        </button>
      </div>

      <!-- ============ À l'honneur : change à chaque visite ============ -->
      <section
        v-if="showDiscovery && featured"
        class="relative mb-6 sm:mb-8 -mx-4 sm:mx-0 h-64 sm:h-[340px] sm:rounded-3xl overflow-hidden cursor-pointer group"
        :data-live-card="featured.id"
        @click="goToAlbum(featured)"
      >
        <img
          v-for="(p, k) in featuredFrames"
          :key="p"
          :src="p"
          alt=""
          class="absolute inset-0 w-full h-full object-cover transition-[opacity,transform] ease-out"
          :class="k === heroIndex ? 'opacity-100' : 'opacity-0'"
          :style="{ transitionDuration: '1200ms, 6000ms', transform: k === heroIndex && !reducedMotion ? 'scale(1.08)' : 'scale(1)' }"
          decoding="async"
        />
        <!-- Voile : lisible même sur une photo claire (scans de documents…) -->
        <div class="absolute inset-0 bg-black/20"></div>
        <div class="absolute inset-0 bg-linear-to-t from-black/85 via-black/45 to-transparent"></div>
        <div class="absolute inset-0 bg-linear-to-r from-black/45 to-transparent sm:via-transparent"></div>
        <div class="absolute inset-x-0 bottom-0 p-5 sm:p-8 text-snow">
          <span class="inline-block px-3 py-1 rounded-full bg-snow/20 backdrop-blur text-xs font-bold tracking-wide uppercase">{{ discovery.featured.reason }}</span>
          <h2 class="mt-2 font-display text-[28px] sm:text-5xl font-bold leading-tight [text-shadow:0_2px_12px_rgba(0,0,0,.45)]">{{ featured.name }}</h2>
          <p class="mt-1 text-sm sm:text-base text-snow/85">
            {{ $tc(':count média|:count médias', featured.media_count) }}<span v-if="periodOf(featured)"> · {{ periodOf(featured) }}</span>
          </p>
          <span class="mt-3 inline-flex items-center gap-2 h-10 px-4 rounded-full bg-snow text-ink text-sm font-bold group-hover:bg-snow/90">
            {{ $t('Ouvrir l\'album') }}
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7" /></svg>
          </span>
        </div>
      </section>

      <!-- ============ Rangées thématiques (défilent sur le côté) ============ -->
      <template v-if="showDiscovery">
        <section v-for="row in discoveryRows" :key="row.key" class="mb-6 sm:mb-8">
          <h2 class="font-display text-xl sm:text-2xl font-semibold text-surface-900 mb-3">{{ row.title }}</h2>
          <div class="flex gap-3 sm:gap-4 overflow-x-auto snap-x snap-mandatory -mx-4 px-4 sm:mx-0 sm:px-0 pb-2">
            <div
              v-for="album in row.albums"
              :key="row.key + album.id"
              class="snap-start shrink-0 w-60 sm:w-72"
              :data-live-card="row.key + album.id"
            >
              <AlbumCard
                :album="album"
                :live="liveKey === row.key + album.id"
                :owner-name="album.is_owner === false ? album.user?.name : null"
                @click="goToAlbum(album)"
              />
            </div>
          </div>
        </section>
        <h2 class="font-display text-xl sm:text-2xl font-semibold text-surface-900 mb-3">{{ $t('Tous les albums') }}</h2>
      </template>

      <!-- Filtres (fusionne « Partagés avec moi » dans la page) ;
           téléphone : une seule ligne qui défile -->
      <div class="flex flex-nowrap sm:flex-wrap gap-2 mb-4 sm:mb-6 overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 sm:overflow-visible [&>button]:shrink-0">
        <button
          v-for="f in filters"
          :key="f.value"
          type="button"
          class="px-4 py-1.5 rounded-full text-[13px] font-medium transition whitespace-nowrap"
          :class="filter === f.value
            ? 'bg-brand-600 text-white font-semibold'
            : 'bg-white border border-surface-300 text-surface-600 hover:bg-surface-50'"
          @click="filter = f.value"
        >
          {{ f.label }}
          <span v-if="f.count > 0" class="opacity-70">· {{ f.count }}</span>
        </button>
      </div>

      <!-- Par décennie -->
      <div v-if="decades.length > 1" class="flex flex-nowrap sm:flex-wrap items-center gap-2 -mt-2 sm:-mt-3 mb-4 sm:mb-6 overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 [&>button]:shrink-0">
        <span class="text-xs font-semibold uppercase tracking-wider text-surface-500 mr-1 shrink-0">{{ $t('Par décennie') }}</span>
        <button
          v-for="d in decades"
          :key="d.decade"
          type="button"
          class="px-3.5 py-1.5 rounded-full text-[13px] font-medium whitespace-nowrap border transition"
          :class="decade === d.decade ? 'bg-surface-900 border-surface-900 text-page' : 'bg-white border-surface-300 text-surface-600 hover:bg-surface-50'"
          @click="decade = decade === d.decade ? null : d.decade"
        >
          {{ decadeLabel(d.decade) }} <span class="opacity-60">· {{ d.ids.length }}</span>
        </button>
      </div>

      <!-- Grille : albums du cercle courant -->
      <h2 v-if="hereAlbums.length && elsewhereAlbums.length && current" class="text-sm font-semibold uppercase tracking-wider text-surface-500 mb-3">
        {{ $t('Albums de :name', { name: current.name }) }}
      </h2>
      <!-- Téléphone : le premier album en vedette (pleine largeur), les
           autres sur deux colonnes -->
      <div
        v-if="hereAlbums.length > 0"
        data-tour="albums"
        class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2.5 sm:gap-4"
      >
        <AlbumCard
          v-for="(album, i) in hereAlbums"
          :key="album.id"
          :album="album"
          :live="liveKey === 'grid' + album.id"
          :data-live-card="'grid' + album.id"
          :owner-name="album.is_owner === false ? album.user?.name : null"
          :class="i === 0 ? 'col-span-2 sm:col-span-1' : ''"
          @click="goToAlbum(album)"
        />
      </div>

      <!-- Ailleurs : albums d'autres cercles, mis à part et grisés ;
           téléphone : repliés derrière un titre à déplier -->
      <template v-if="elsewhereAlbums.length > 0">
        <button
          type="button"
          class="mt-8 sm:mt-10 mb-3 w-full flex items-center justify-between gap-3 text-left sm:cursor-default"
          :aria-expanded="elsewhereOpen"
          @click="elsewhereOpen = !elsewhereOpen"
        >
          <div class="min-w-0">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-500">
              {{ $t('Ailleurs') }}
              <span class="sm:hidden normal-case tracking-normal font-medium">· {{ $tc(':count album de vos autres cercles|:count albums de vos autres cercles', elsewhereAlbums.length) }}</span>
            </h2>
            <p class="text-xs text-surface-500 mt-1 hidden sm:block">
              {{ current ? $t('Pas encore partagés avec :name : un clic les y ajoute.', { name: current.name }) : $t('Albums partagés avec d\'autres cercles.') }}
            </p>
          </div>
          <svg class="sm:hidden w-5 h-5 text-surface-400 shrink-0 transition-transform" :class="elsewhereOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <div class="grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2.5 sm:gap-4" :class="elsewhereOpen ? 'grid' : 'hidden sm:grid'">
          <div v-for="album in elsewhereAlbums" :key="album.id" class="flex flex-col gap-2">
            <div class="opacity-60 hover:opacity-100 transition-opacity">
              <AlbumCard
                :album="album"
                :owner-name="album.is_owner === false ? album.user?.name : null"
                @click="goToAlbum(album)"
              />
            </div>
            <button
              v-if="current && album.is_owner"
              type="button"
              class="self-start inline-flex items-center gap-1.5 h-8 px-3 rounded-full text-xs font-semibold border border-dashed border-surface-300 text-surface-700 hover:border-brand-400 hover:text-brand-700"
              :disabled="sharing === album.id"
              @click="shareWithCurrent(album)"
            >
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
              {{ $t('Partager avec :name', { name: current.name }) }}
            </button>
          </div>
        </div>
      </template>

      <!-- Aucun résultat pour ce filtre -->
      <div v-if="filteredAlbums.length === 0 && albums.length > 0" class="text-center py-16 bg-white rounded-lg shadow-xs">
        <p class="text-surface-500">{{ $t('Aucun album ne correspond à ce filtre.') }}</p>
        <button @click="filter = 'all'" class="mt-3 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline">
          {{ $t('Voir tous les albums') }}
        </button>
      </div>

      <!-- Empty State -->
      <div v-if="albums.length === 0" class="text-center py-16 bg-white rounded-lg shadow-xs">
        <svg class="mx-auto h-16 w-16 text-surface-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
          />
        </svg>
        <h3 class="mt-4 text-lg font-medium text-surface-900">{{ $t('Aucun album') }}</h3>
        <p class="mt-2 text-surface-500">{{ $t('Créez votre premier album pour organiser vos médias.') }}</p>
        <button
          @click="showCreateModal = true"
          class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 dark:bg-brand-500/10 rounded-lg hover:bg-brand-100 dark:hover:bg-brand-500/20"
        >
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          {{ $t('Créer un album') }}
        </button>
      </div>

      <!-- Create Modal -->
      <AlbumFormModal
        v-if="showCreateModal"
        @close="showCreateModal = false"
        @saved="handleAlbumCreated"
      />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AlbumCard from '@/Components/AlbumCard.vue';
import AlbumFormModal from '@/Components/AlbumFormModal.vue';
import axios from 'axios';
import { useCircle } from '@/Composables/useCircle';
import { useToast } from '@/Composables/useToast';
import { t, locale, intlLocale } from '@/i18n';

const toast = useToast();

const props = defineProps({
  albums: {
    type: Array,
    default: () => [],
  },
  // À l'honneur, rangées, décennies (calculés à chaque visite)
  discovery: {
    type: Object,
    default: () => ({ featured: null, rows: [], decades: [] }),
  },
});

const showCreateModal = ref(false);
// Téléphone : la section « Ailleurs » est repliée par défaut.
const elsewhereOpen = ref(false);

// Filtres par visibilité/appartenance — « Partagés avec moi » vit ici
// plutôt que sur une page à part.
const filter = ref('all');

const matchers = {
  all: () => true,
  mine: (a) => a.is_owner !== false,
  shared: (a) => a.is_owner === false,
  circles: (a) => (a.households_count || 0) > 0,
  smart: (a) => !!a.is_smart,
};

const filters = computed(() => [
  { value: 'all', label: t('Tous'), count: props.albums.length },
  { value: 'mine', label: t('Les miens'), count: props.albums.filter(matchers.mine).length },
  { value: 'shared', label: t('Partagés avec moi'), count: props.albums.filter(matchers.shared).length },
  { value: 'circles', label: t('Partagés avec un cercle'), count: props.albums.filter(matchers.circles).length },
  { value: 'smart', label: t('Intelligents'), count: props.albums.filter(matchers.smart).length },
]);

// Décennie choisie (null : toutes).
const decade = ref(null);
const decades = computed(() => props.discovery?.decades || []);
// « Années 60 », « Années 2000 » ; en anglais « 1960s », « 2000s ».
const decadeLabel = (dec) => t('Années :decade', {
  decade: locale() === 'en' ? `${dec}s` : (dec < 2000 ? String(dec).slice(2) : dec),
});
const decadeIds = computed(() => new Set(decades.value.find((d) => d.decade === decade.value)?.ids || []));

const filteredAlbums = computed(() => props.albums
  .filter(matchers[filter.value] || matchers.all)
  .filter((a) => decade.value === null || decadeIds.value.has(a.id)));

// ---- Découverte : à l'honneur et rangées (seulement sur « Tous ») -----------
const byId = computed(() => Object.fromEntries(props.albums.map((a) => [a.id, a])));
const showDiscovery = computed(() => filter.value === 'all' && decade.value === null);
const featured = computed(() => byId.value[props.discovery?.featured?.id] || null);
const featuredFrames = computed(() => (featured.value?.previews || []).map((p) => p.medium));
const discoveryRows = computed(() => (props.discovery?.rows || [])
  .map((r) => ({ ...r, albums: r.ids.map((id) => byId.value[id]).filter(Boolean) }))
  .filter((r) => r.albums.length));
const reducedMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

// Diaporama muet de l'album à l'honneur.
const heroIndex = ref(0);
let heroTimer = null;
onMounted(() => {
  if (!reducedMotion && featuredFrames.value.length > 1) {
    heroTimer = setInterval(() => { heroIndex.value = (heroIndex.value + 1) % featuredFrames.value.length; }, 4500);
  }
});

// Téléphone (pas de survol) : la carte la plus proche du centre de l'écran
// s'anime ; une seule à la fois.
const liveKey = ref(null);
const canHover = typeof window !== 'undefined' && window.matchMedia?.('(any-hover: hover)').matches;
let frame = 0;
const pickLive = () => {
  frame = 0;
  const cx = window.innerWidth / 2;
  const cy = window.innerHeight / 2;
  let best = null;
  let bestDist = Infinity;
  for (const el of document.querySelectorAll('[data-live-card]')) {
    const r = el.getBoundingClientRect();
    if (r.bottom < 0 || r.top > window.innerHeight || r.right < 0 || r.left > window.innerWidth) continue;
    const d = Math.hypot(r.left + r.width / 2 - cx, r.top + r.height / 2 - cy);
    if (d < bestDist) { bestDist = d; best = el.dataset.liveCard; }
  }
  liveKey.value = best;
};
const onScroll = () => { if (!frame) frame = requestAnimationFrame(pickLive); };
onMounted(() => {
  if (canHover || reducedMotion) return;
  window.addEventListener('scroll', onScroll, { passive: true });
  document.addEventListener('scroll', onScroll, { passive: true, capture: true }); // rangées horizontales
  pickLive();
});
onBeforeUnmount(() => {
  clearInterval(heroTimer);
  window.removeEventListener('scroll', onScroll);
  document.removeEventListener('scroll', onScroll, { capture: true });
  if (frame) cancelAnimationFrame(frame);
});

// « juin 2020 » ou « 1939 → 1972 »
const periodOf = (a) => {
  if (!a?.first_at) return null;
  const f = a.first_at;
  const l = a.last_at || f;
  if (f.slice(0, 4) === l.slice(0, 4)) return f.slice(0, 7) === l.slice(0, 7)
    ? new Date(`${f}T12:00:00`).toLocaleDateString(intlLocale(), { month: 'long', year: 'numeric' })
    : f.slice(0, 4);
  return `${f.slice(0, 4)} → ${l.slice(0, 4)}`;
};

// Rangement par cercle courant : ses albums d'abord, les autres à part.
const { current } = useCircle();
const hereAlbums = computed(() => filteredAlbums.value.filter((a) => a.in_context !== false));
const elsewhereAlbums = computed(() => filteredAlbums.value.filter((a) => a.in_context === false));

const sharing = ref(null);
const shareWithCurrent = async (album) => {
  sharing.value = album.id;
  try {
    await axios.post(`/albums/${album.id}/circles`, { household_id: current.value.id });
    router.reload({ only: ['albums'], preserveScroll: true });
  } catch (e) {
    toast.error(e.response?.data?.message || t('Impossible de partager cet album.'));
  } finally {
    sharing.value = null;
  }
};

const goToAlbum = (album) => {
  router.visit(`/albums/${album.id}`);
};

const handleAlbumCreated = () => {
  router.reload();
};
</script>
