<template>
  <Head :title="$t('Albums')" />
  <AppLayout>
    <div class="py-4 sm:py-9 px-4 sm:px-8">
      <!-- En-tête : titre, recherche, nouvel album -->
      <div class="flex items-center sm:items-end justify-between gap-4 mb-3 sm:mb-5">
        <div class="min-w-0">
          <h1 class="font-display text-[26px] sm:text-4xl font-bold text-surface-900 truncate">
            {{ $t('Albums') }}
            <span class="sm:hidden font-sans text-sm font-semibold text-surface-500 ml-1">{{ listed.length }}</span>
          </h1>
          <p class="mt-1.5 text-sm text-surface-500 hidden sm:block">{{ subtitle }}</p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
          <label class="hidden sm:flex items-center gap-2 h-10 w-64 lg:w-72 px-3.5 rounded-full bg-white border border-surface-300 text-surface-500 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-100">
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M21 21l-5-5m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <input v-model="query" type="search" :placeholder="$t('Chercher un album…')" :aria-label="$t('Chercher un album')" class="w-full border-0 bg-transparent p-0 text-sm text-surface-900 focus:ring-0" />
          </label>
          <button
            type="button"
            class="sm:hidden w-10 h-10 rounded-full bg-brand-600 text-white flex items-center justify-center"
            :aria-label="$t('Nouvel album')"
            @click="showCreateModal = true"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
          </button>
          <button class="btn-primary hidden sm:inline-flex" @click="showCreateModal = true">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
            {{ $t('Nouvel album') }}
          </button>
        </div>
      </div>

      <!-- Téléphone : recherche pleine largeur -->
      <label v-if="albums.length" class="sm:hidden flex items-center gap-2 h-11 px-3.5 mb-1 rounded-full bg-white border border-surface-300 text-surface-500 focus-within:border-brand-500">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M21 21l-5-5m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        <input v-model="query" type="search" :placeholder="$t('Chercher un album…')" :aria-label="$t('Chercher un album')" class="w-full border-0 bg-transparent p-0 text-[15px] text-surface-900 focus:ring-0" />
      </label>

      <!-- Ordinateur : filtre par thème, une ligne -->
      <div v-if="themeCounts.length > 1" class="hidden lg:flex flex-wrap gap-2 mb-6" role="group" :aria-label="$t('Thèmes')">
        <button
          type="button"
          class="h-9 px-3.5 rounded-full text-[13.5px] font-semibold border transition"
          :class="theme === null ? 'bg-surface-900 border-surface-900 text-page' : 'bg-white border-surface-300 text-surface-600 hover:bg-surface-50'"
          :aria-pressed="theme === null"
          @click="theme = null"
        >
          {{ $t('Tous') }} <span class="font-medium opacity-60">{{ listed.length }}</span>
        </button>
        <button
          v-for="th in themeCounts"
          :key="th.key"
          type="button"
          class="h-9 px-3.5 rounded-full text-[13.5px] font-semibold border transition"
          :class="theme === th.key ? 'bg-surface-900 border-surface-900 text-page' : 'bg-white border-surface-300 text-surface-600 hover:bg-surface-50'"
          :aria-pressed="theme === th.key"
          @click="theme = theme === th.key ? null : th.key"
        >
          {{ themeEmoji(th.key) }} {{ themePlural(th.key) }} <span class="font-medium opacity-60">{{ th.count }}</span>
        </button>
      </div>

      <!-- Téléphone et tablette : thème + époques, collés sous l'en-tête -->
      <div
        v-if="listed.length"
        class="lg:hidden sticky top-16 z-30 -mx-4 sm:-mx-8 px-4 sm:px-8 py-2 mb-3 bg-page flex gap-1.5 overflow-x-auto [scrollbar-width:none]"
        ref="chipsEl"
      >
        <button
          v-if="themeCounts.length > 1"
          type="button"
          class="shrink-0 h-9 px-3.5 rounded-full text-[13.5px] font-semibold border whitespace-nowrap"
          :class="theme ? 'bg-surface-900 border-surface-900 text-page' : 'bg-white border-surface-300 text-surface-600'"
          @click="theme ? (theme = null) : (showThemes = true)"
        >
          <template v-if="theme">{{ themeEmoji(theme) }} {{ themePlural(theme) }} ✕</template>
          <template v-else>{{ $t('Thème') }} ▾</template>
        </button>
        <span v-if="themeCounts.length > 1" class="shrink-0 w-px my-1.5 bg-surface-300" aria-hidden="true"></span>
        <button
          v-for="s in jumps"
          :key="s.key"
          type="button"
          :data-jump="s.key"
          class="shrink-0 h-9 px-3.5 rounded-full text-[13.5px] font-semibold border whitespace-nowrap transition"
          :class="activeSec === s.key ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white border-surface-300 text-surface-600'"
          @click="jumpTo(s.key)"
        >
          {{ s.short }}
        </button>
      </div>

      <!-- À redécouvrir : un seul album, change à chaque visite -->
      <Link
        v-if="featured && !filtering"
        :href="`/albums/${featured.id}`"
        class="flex items-center gap-3 sm:gap-4 mb-6 sm:mb-8 p-2 sm:p-2.5 rounded-2xl bg-white shadow-warm-sm hover:shadow-warm-md transition"
      >
        <span class="relative w-20 h-15 sm:w-32 sm:h-20 shrink-0 rounded-xl overflow-hidden bg-surface-100">
          <img
            v-for="(p, k) in featuredFrames"
            :key="p"
            :src="p"
            alt=""
            class="absolute inset-0 w-full h-full object-cover transition-opacity duration-1000"
            :class="k === heroIndex ? 'opacity-100' : 'opacity-0'"
            decoding="async"
          />
        </span>
        <span class="min-w-0">
          <span class="block text-[11px] font-bold uppercase tracking-wide text-brand-700 dark:text-brand-400">{{ discovery.featured.reason }}</span>
          <span class="block font-display text-base sm:text-xl font-semibold text-surface-900 leading-tight truncate">{{ featured.name }}</span>
          <span class="block text-xs text-surface-500 mt-0.5">{{ $tc(':count média|:count médias', featured.media_count) }}</span>
        </span>
        <span class="hidden sm:inline ml-auto mr-3 text-sm font-semibold text-brand-700 dark:text-brand-400 whitespace-nowrap">{{ $t('Ouvrir') }} →</span>
      </Link>

      <div v-if="listed.length" class="lg:grid lg:grid-cols-[7.5rem_minmax(0,1fr)] lg:gap-8">
        <!-- Ordinateur : la frise des époques, collée en haut -->
        <nav class="hidden lg:flex flex-col sticky top-24 self-start border-l-2 border-surface-200" :aria-label="$t('Époques')">
          <button
            v-for="s in jumps"
            :key="s.key"
            type="button"
            class="-ml-0.5 flex items-baseline justify-between pl-3.5 py-2 border-l-2 text-[15px] font-semibold text-left transition"
            :class="activeSec === s.key ? 'border-brand-600 text-brand-700 dark:text-brand-400' : 'border-transparent text-surface-500 hover:text-surface-800'"
            @click="jumpTo(s.key)"
          >
            <span>{{ s.short }}</span>
            <span class="text-xs font-medium text-surface-500">{{ s.albums.length }}</span>
          </button>
        </nav>

        <div class="min-w-0">
          <section
            v-for="(s, i) in sections"
            :key="s.key"
            :data-sec="s.key"
            class="mb-8 sm:mb-10 scroll-mt-32 lg:scroll-mt-24"
            :class="s.key === 'archives' ? '-mx-4 sm:mx-0 px-4 sm:px-5 pt-5 pb-1 sm:rounded-2xl bg-surface-100' : ''"
          >
            <div class="flex items-baseline flex-wrap gap-x-2.5 gap-y-0.5 mb-3">
              <h2 class="font-display text-xl sm:text-2xl font-semibold text-surface-900">{{ s.title }}</h2>
              <span class="text-[13px] text-surface-500">
                {{ $tc(':count album|:count albums', s.albums.length) }} · {{ $tc(':count média|:count médias', s.media) }}
              </span>
              <p v-if="s.hint" class="w-full text-[13px] text-surface-600">{{ s.hint }}</p>
            </div>
            <div
              class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-x-3 gap-y-5 sm:gap-x-4 sm:gap-y-6"
              :data-tour="i === 0 ? 'albums' : null"
            >
              <AlbumTile
                v-for="album in s.albums"
                :key="album.id"
                :album="album"
                :live="liveKey === album.id"
                :data-live-card="album.id"
                :owner-name="album.is_owner === false ? album.user?.name : null"
              />
            </div>
          </section>

          <!-- Aucun résultat pour la recherche ou le thème -->
          <div v-if="filtering && sections.length === 0 && elsewhereAlbums.length === 0" class="text-center py-14">
            <p class="text-surface-500">{{ $t('Aucun album ne correspond.') }}</p>
            <button class="mt-3 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline" @click="resetFilters">
              {{ $t('Voir tous les albums') }}
            </button>
          </div>

          <!-- Albums vides : à compléter, sans prendre de place -->
          <p v-if="emptyAlbums.length && !filtering" class="text-[13px] text-surface-500 mb-2">
            <span class="font-semibold text-surface-600">{{ $tc(':count album vide|:count albums vides', emptyAlbums.length) }}</span> ·
            <template v-for="(a, k) in emptyAlbums" :key="a.id">
              <Link :href="`/albums/${a.id}`" class="hover:underline">{{ a.name }}</Link><span v-if="k < emptyAlbums.length - 1">, </span>
            </template>
          </p>

          <!-- Ailleurs : albums d'autres cercles, repliés -->
          <div v-if="elsewhereAlbums.length" class="border-t border-surface-200 mt-4">
            <button
              type="button"
              class="w-full flex items-center justify-between gap-3 py-3.5 text-left"
              :aria-expanded="elsewhereShown"
              @click="elsewhereOpen = !elsewhereOpen"
            >
              <span class="min-w-0">
                <span class="block text-sm font-semibold text-surface-700">{{ $tc(':count album dans vos autres cercles|:count albums dans vos autres cercles', elsewhereAlbums.length) }}</span>
                <span v-if="current" class="block text-xs text-surface-500 mt-0.5">{{ $t('Pas encore partagés avec :name', { name: current.name }) }}</span>
              </span>
              <svg class="w-5 h-5 text-surface-400 shrink-0 transition-transform" :class="elsewhereShown ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
            </button>
            <div v-if="elsewhereShown" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-x-3 gap-y-5 sm:gap-x-4 sm:gap-y-6 pb-6">
              <div v-for="album in elsewhereAlbums" :key="album.id" class="flex flex-col gap-2 min-w-0">
                <AlbumTile :album="album" :owner-name="album.is_owner === false ? album.user?.name : null" />
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
          </div>
        </div>
      </div>

      <!-- Seulement des albums d'autres cercles ou vides -->
      <div v-else-if="albums.length && !elsewhereAlbums.length && !emptyAlbums.length" class="text-center py-14">
        <p class="text-surface-500">{{ $t('Aucun album ne correspond.') }}</p>
      </div>

      <!-- Aucun album -->
      <div v-if="albums.length === 0" class="text-center py-16 bg-white rounded-lg shadow-xs">
        <svg class="mx-auto h-16 w-16 text-surface-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
        </svg>
        <h3 class="mt-4 text-lg font-medium text-surface-900">{{ $t('Aucun album') }}</h3>
        <p class="mt-2 text-surface-500">{{ $t('Créez votre premier album pour organiser vos médias.') }}</p>
        <button
          class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 dark:bg-brand-500/10 rounded-lg hover:bg-brand-100 dark:hover:bg-brand-500/20"
          @click="showCreateModal = true"
        >
          {{ $t('Créer un album') }}
        </button>
      </div>

      <!-- Téléphone : choix du thème -->
      <BaseModal v-if="showThemes" :title="$t('Thème')" max-width="md" @close="showThemes = false">
        <div class="py-1">
          <button
            v-for="th in [{ key: null, count: listed.length }, ...themeCounts]"
            :key="th.key ?? 'all'"
            type="button"
            class="w-full flex items-center gap-3 px-6 py-3.5 border-b border-surface-100 text-left text-[15px] font-semibold"
            :class="theme === th.key ? 'text-brand-700 dark:text-brand-400' : 'text-surface-800'"
            @click="theme = th.key; showThemes = false"
          >
            <span v-if="th.key" class="w-6 text-center">{{ themeEmoji(th.key) }}</span>
            {{ th.key ? themePlural(th.key) : $t('Tous les albums') }}
            <span class="ml-auto font-medium text-surface-500">{{ th.count }}</span>
          </button>
        </div>
      </BaseModal>

      <AlbumFormModal v-if="showCreateModal" @close="showCreateModal = false" @saved="router.reload()" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AlbumTile from '@/Components/AlbumTile.vue';
import AlbumFormModal from '@/Components/AlbumFormModal.vue';
import BaseModal from '@/Components/BaseModal.vue';
import axios from 'axios';
import { useCircle } from '@/Composables/useCircle';
import { useToast } from '@/Composables/useToast';
import { t, tc, locale } from '@/i18n';
import { ALBUM_THEMES, themeKey, themeEmoji, themePlural } from '@/utils/albumThemes';

const toast = useToast();

const props = defineProps({
  albums: { type: Array, default: () => [] },
  // Album à l'honneur (change à chaque visite)
  discovery: { type: Object, default: () => ({ featured: null }) },
});

const showCreateModal = ref(false);
const showThemes = ref(false);
const elsewhereOpen = ref(false);
const query = ref('');
const theme = ref(null);
const { current } = useCircle();

// ---- Qui va où ---------------------------------------------------------------
// Albums du cercle courant avec au moins une photo : la frise. Les autres
// cercles sont repliés à part ; les albums vides tiennent sur une ligne.
const hereAll = computed(() => props.albums.filter((a) => a.in_context !== false));
const listed = computed(() => hereAll.value.filter((a) => (a.media_count || 0) > 0));
const emptyAlbums = computed(() => hereAll.value.filter((a) => !(a.media_count > 0)));

const plain = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const filtering = computed(() => theme.value !== null || query.value.trim() !== '');
const matches = (a) => (theme.value === null || themeKey(a.theme) === theme.value)
  && (!query.value.trim() || plain(a.name).includes(plain(query.value.trim())));

const elsewhereAlbums = computed(() => props.albums.filter((a) => a.in_context === false && matches(a)));
// Une recherche qui trouve quelque chose dans les autres cercles les déplie.
const elsewhereShown = computed(() => elsewhereOpen.value || (query.value.trim() !== '' && elsewhereAlbums.value.length > 0));

// Thèmes présents (filtre) : seulement ceux qui ont des albums.
const themeCounts = computed(() => ALBUM_THEMES
  .map((key) => ({ key, count: listed.value.filter((a) => themeKey(a.theme) === key).length }))
  .filter((th) => th.count > 0));

// « Années 60 », « Années 2000 » ; en anglais « 1960s ».
const decadeLabel = (dec) => t('Années :decade', {
  decade: locale() === 'en' ? `${dec}s` : (dec < 2000 ? String(dec).slice(2) : dec),
});

// La frise : décennie de la date de l'album, sinon de la date médiane de ses
// photos (placed_at, calculée par le serveur). Les archives, qui couvrent
// plusieurs époques, sont rangées à part ; puis les albums sans date.
const sections = computed(() => {
  const shown = listed.value.filter(matches);
  const byDecade = new Map();
  const archives = [];
  const undated = [];
  for (const a of shown) {
    if (themeKey(a.theme) === 'archives') archives.push(a);
    else if (!a.placed_at) undated.push(a);
    else {
      const d = Math.floor(Number(a.placed_at.slice(0, 4)) / 10) * 10;
      if (!byDecade.has(d)) byDecade.set(d, []);
      byDecade.get(d).push(a);
    }
  }
  const media = (list) => list.reduce((s, a) => s + (a.media_count || 0), 0);
  const recentFirst = (x, y) => (y.placed_at || '').localeCompare(x.placed_at || '');
  const out = [...byDecade.entries()].sort((x, y) => y[0] - x[0]).map(([d, list]) => ({
    key: String(d), short: String(d), title: decadeLabel(d), albums: list.sort(recentFirst), media: media(list),
  }));
  if (archives.length) {
    out.push({
      key: 'archives', short: `📜 ${t('Archives')}`, title: `📜 ${t('Archives de famille')}`,
      hint: t('Des photos de plusieurs époques, rangées à part.'), albums: archives, media: media(archives),
    });
  }
  if (undated.length) {
    out.push({ key: 'undated', short: t('Sans date'), title: t('Sans date'), albums: undated, media: media(undated) });
  }
  return out;
});
const jumps = sections;

const subtitle = computed(() => (theme.value
  ? `${themeEmoji(theme.value)} ${themePlural(theme.value)} · ${tc(':count album|:count albums', listed.value.filter(matches).length)}`
  : (current.value
    ? tc(':count album de :name, du plus récent au plus ancien|:count albums de :name, du plus récent au plus ancien', listed.value.length, { name: current.value.name })
    : tc(':count album, du plus récent au plus ancien|:count albums, du plus récent au plus ancien', listed.value.length))));

const resetFilters = () => { theme.value = null; query.value = ''; };

// ---- Album à l'honneur ---------------------------------------------------------
const featured = computed(() => props.albums.find((a) => a.id === props.discovery?.featured?.id) || null);
const featuredFrames = computed(() => (featured.value?.previews || []).map((p) => p.small));
const reducedMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const heroIndex = ref(0);
let heroTimer = null;

// ---- Époque visible (frise) et sauts -------------------------------------------
const activeSec = ref(null);
const chipsEl = ref(null);
const isWide = () => window.matchMedia('(min-width: 1024px)').matches;
// En-tête collant (64 px), plus la barre des époques sur téléphone.
const offset = () => (isWide() ? 96 : 128);
const jumpTo = (key) => {
  const el = document.querySelector(`[data-sec="${key}"]`);
  if (!el) return;
  activeSec.value = key;
  window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - offset() + 8, behavior: reducedMotion ? 'auto' : 'smooth' });
};
const pickSection = () => {
  const secs = [...document.querySelectorAll('[data-sec]')];
  if (!secs.length) { activeSec.value = null; return; }
  let cur = secs[0].dataset.sec;
  for (const s of secs) if (s.getBoundingClientRect().top - offset() < 40) cur = s.dataset.sec;
  if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4 && window.scrollY > 0) cur = secs[secs.length - 1].dataset.sec;
  activeSec.value = cur;
};
// Garde la puce active visible dans la barre qui défile.
watch(activeSec, async (key) => {
  await nextTick();
  const bar = chipsEl.value;
  const chip = bar?.querySelector(`[data-jump="${key}"]`);
  if (bar && chip) bar.scrollTo({ left: chip.offsetLeft - bar.clientWidth / 3, behavior: 'smooth' });
});
watch(sections, () => nextTick(pickSection));

// ---- Couvertures vivantes sur téléphone : la carte au centre s'anime ----------
const liveKey = ref(null);
const canHover = typeof window !== 'undefined' && window.matchMedia?.('(any-hover: hover)').matches;
const pickLive = () => {
  if (canHover || reducedMotion) return;
  const cx = window.innerWidth / 2;
  const cy = window.innerHeight / 2;
  let best = null;
  let bestDist = Infinity;
  for (const el of document.querySelectorAll('[data-live-card]')) {
    const r = el.getBoundingClientRect();
    if (r.bottom < 0 || r.top > window.innerHeight) continue;
    const d = Math.hypot(r.left + r.width / 2 - cx, r.top + r.height / 2 - cy);
    if (d < bestDist) { bestDist = d; best = el.dataset.liveCard; }
  }
  liveKey.value = best;
};

let frame = 0;
const onScroll = () => {
  if (frame) return;
  frame = requestAnimationFrame(() => { frame = 0; pickSection(); pickLive(); });
};
onMounted(() => {
  if (!reducedMotion && featuredFrames.value.length > 1) {
    heroTimer = setInterval(() => { heroIndex.value = (heroIndex.value + 1) % featuredFrames.value.length; }, 3500);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  pickSection();
  pickLive();
});
onBeforeUnmount(() => {
  clearInterval(heroTimer);
  window.removeEventListener('scroll', onScroll);
  if (frame) cancelAnimationFrame(frame);
});

// ---- Partager un album d'un autre cercle avec le cercle courant ---------------
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
</script>
