<template>
  <Head :title="$t(':title — Télévision', { title: album.title })" />
  <TvLayout
    :tab="tab" :circle="circle" :circles="circles" :account="account"
    back="/tv/ecran/albums" nav-class="relative z-10"
    :hints="[['OK', $t('Voir la photo')], ['◀ ▲ ▼ ▶', $t('Se déplacer')], ['▶❚❚', $t('Diaporama')], ['↩', $t('Albums')]]"
  >
    <!-- Fond du titre : la photo choisie pour l'album (comme sur le site) -->
    <div class="absolute inset-x-0 top-0 h-[46rem] pointer-events-none overflow-hidden">
      <img v-if="album.hero" :src="album.hero" alt="" class="absolute inset-0 w-full h-full object-cover" />
      <div class="absolute inset-0" style="background: linear-gradient(to right, rgba(18,16,12,.94), rgba(18,16,12,.55) 55%, rgba(18,16,12,.1))"></div>
      <div class="absolute inset-x-0 bottom-0 h-[16rem]" style="background: linear-gradient(to top, #12100c, rgba(18,16,12,0))"></div>
    </div>

    <div class="relative z-10 flex flex-col gap-10 pb-10">
      <header class="px-24 pt-24 min-h-[30rem] flex flex-col justify-end gap-4 w-[78rem]">
        <span v-if="album.theme" class="self-start px-4.5 py-2 rounded-full bg-white/15 text-[1.375rem] font-extrabold tracking-[.08em] uppercase">{{ album.theme }}</span>
        <h1 class="font-display text-[4.5rem] leading-[1.03] font-bold line-clamp-2">{{ album.title }}</h1>
        <p class="text-[1.875rem] text-[#e7e0d6]">{{ album.subtitle }}<template v-if="album.description"> · {{ album.description }}</template></p>
        <div v-if="items.length" class="flex gap-5 mt-3" data-tv-row>
          <button type="button" data-tv data-tv-autofocus class="tv-btn tv-btn-primary" @click="open(items, 0, true)">
            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>{{ $t('Diaporama') }}
          </button>
          <button type="button" data-tv class="tv-btn" @click="open(items, Math.floor(Math.random() * items.length), true, true)">{{ $t('Lecture aléatoire') }}</button>
          <Link :href="`/tv/ecran/qui-est-ce?album=${gameAlbumId}`" data-tv class="tv-btn">{{ $t('Qui est-ce ?') }}</Link>
        </div>
      </header>

      <!-- Rubriques du thème : une rangée chacune -->
      <section v-for="s in sections" :key="s.key" class="flex flex-col gap-3">
        <h2 class="px-24 font-display text-[2.25rem] font-bold">
          {{ s.title }}<span v-if="s.subtitle" class="ml-4 font-sans text-[1.5rem] font-normal text-[#b9ad9c]">{{ s.subtitle }}</span>
        </h2>
        <div class="flex gap-6 overflow-x-auto px-24 py-5 -my-3" data-tv-row>
          <template v-if="s.people">
            <button
              v-for="p in s.people" :key="p.id" type="button" data-tv
              class="tv-person shrink-0 w-[13rem] flex flex-col items-center gap-3 text-center rounded-[1.5rem] p-3"
              @click="open(p.items.map((i) => items[i]), 0, false)"
            >
              <img v-if="p.avatar_url" :src="p.avatar_url" alt="" loading="lazy" class="w-[10.5rem] h-[10.5rem] rounded-full object-cover bg-[#3a2f1e]" />
              <span v-else class="w-[10.5rem] h-[10.5rem] rounded-full bg-[#3a2f1e] text-[#fcd34d] text-[4rem] font-bold flex items-center justify-center">{{ p.name.slice(0, 1) }}</span>
              <span class="text-[1.625rem] font-bold leading-tight line-clamp-2">{{ p.name }}</span>
              <span class="-mt-1 text-[1.25rem] text-[#b9ad9c]">{{ p.sub }}</span>
            </button>
          </template>
          <template v-else>
            <button
              v-for="(i, n) in s.items.slice(0, ROW_MAX)" :key="items[i].id" type="button" data-tv
              class="tv-card relative shrink-0 w-[21rem] aspect-[4/3] rounded-[1.25rem] overflow-hidden bg-[#2c261e]"
              :aria-label="items[i].title || $t('Photo :number', { number: n + 1 })"
              @click="open(s.items.map((k) => items[k]), n, false)"
            >
              <img :src="items[i].thumb" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover" />
              <span v-if="items[i].type === 'video'" class="absolute right-3 bottom-3 w-12 h-12 rounded-full bg-black/60 flex items-center justify-center">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
              </span>
            </button>
            <button
              v-if="s.items.length > ROW_MAX" type="button" data-tv
              class="tv-card shrink-0 w-[14rem] aspect-[4/3] rounded-[1.25rem] bg-[#2c261e] flex flex-col items-center justify-center gap-1 text-[1.625rem] font-bold"
              @click="open(s.items.map((k) => items[k]), ROW_MAX, false)"
            >
              +{{ s.items.length - ROW_MAX }}<span class="text-[1.25rem] font-normal text-[#b9ad9c]">{{ $t('Voir la suite') }}</span>
            </button>
          </template>
        </div>
      </section>

      <!-- Toutes les photos -->
      <section v-if="items.length" class="flex flex-col gap-5 px-24">
        <h2 class="font-display text-[2.25rem] font-bold">
          {{ sections.length ? $t('Toutes les photos') : $t('Les photos') }}<span class="ml-4 font-sans text-[1.5rem] font-normal text-[#b9ad9c]">{{ $tc(':count photo|:count photos', items.length) }}</span>
        </h2>
        <TvMediaGrid :items="items" @open="(i) => open(items, i, false)" />
      </section>
      <p v-else class="px-24 text-[2rem] text-[#c9bfb1]">{{ $t('Rien à regarder ici pour l\'instant.') }}</p>
    </div>

    <TvViewer
      v-if="viewer"
      :key="viewer.key"
      :items="viewer.list"
      :start="viewer.start"
      :autoplay="viewer.play"
      :shuffle="viewer.shuffle"
      :title="album.title"
      @close="close"
    />
  </TvLayout>
</template>

<script setup>
import { ref, nextTick, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import TvLayout from '@/Layouts/TvLayout.vue';
import TvViewer from '@/Components/Tv/TvViewer.vue';
import TvMediaGrid from '@/Components/Tv/TvMediaGrid.vue';
import { focusEl, onTvKey } from '@/Tv/remote';

const props = defineProps({
  tab: String,
  circle: Object,
  circles: Array,
  account: Object,
  album: { type: Object, required: true },
  items: { type: Array, required: true },
  sections: { type: Array, default: () => [] },
  gameAlbumId: { type: String, default: null },
});

/** Photos au plus dans une rangée (le reste : « +N, voir la suite »). */
const ROW_MAX = 12;

const viewer = ref(null);
let opened = 0;
let returnTo = null;
const open = (list, start, play, shuffle = false) => {
  if (!list.length) return;
  returnTo = document.activeElement;
  viewer.value = { key: ++opened, list, start, play, shuffle };
};
// En fermant la visionneuse : retour sur l'élément qui l'a ouverte.
const close = async () => {
  viewer.value = null;
  await nextTick();
  focusEl(returnTo);
};

// Touche lecture de la télécommande : diaporama de tout l'album.
onTvKey((action) => {
  if (viewer.value || action !== 'playpause') return false;
  open(props.items, 0, true);
  return true;
});

// Depuis la page Albums : « Diaporama » / « Lecture aléatoire » lancent directement.
onMounted(() => {
  const q = new URLSearchParams(window.location.search);
  if (q.get('play')) {
    const shuffle = !!q.get('shuffle');
    open(props.items, shuffle ? Math.floor(Math.random() * props.items.length) : 0, true, shuffle);
  }
});
</script>

<style scoped>
.tv-person:focus { background: #2c261e; transform: scale(1.05); }
</style>
