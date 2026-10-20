<template>
  <Head :title="`${title} — Télévision`" />
  <TvLayout
    :tab="tab" :circle="circle" :circles="circles" :account="account"
    :back="backHref"
    :hints="[['OK', 'Voir la photo'], ['◀ ▲ ▼ ▶', 'Se déplacer'], ['▶❚❚', 'Diaporama'], ['↩', 'Retour']]"
  >
    <div class="px-24 pt-10 pb-6 flex flex-col gap-8">
      <header class="flex items-end justify-between gap-10">
        <div class="flex flex-col gap-3 min-w-0">
          <h1 class="font-display text-[4rem] leading-[1.05] font-bold line-clamp-2">{{ title }}</h1>
          <p class="text-[1.875rem] text-[#c9bfb1]">{{ subtitle }}</p>
        </div>
        <div v-if="items.length" class="flex gap-5 shrink-0" data-tv-row>
          <button type="button" data-tv data-tv-autofocus class="tv-btn tv-btn-primary" @click="open(0, true)">
            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>Diaporama
          </button>
          <button type="button" data-tv class="tv-btn" @click="open(randomStart(), true, true)">Lecture aléatoire</button>
          <Link v-if="gameAlbumId" :href="`/tv/ecran/qui-est-ce?album=${gameAlbumId}`" data-tv class="tv-btn">Qui est-ce ?</Link>
        </div>
      </header>

      <div v-if="items.length" class="grid grid-cols-5 gap-6">
        <button
          v-for="(m, i) in items"
          :key="m.id"
          type="button"
          data-tv
          :data-index="i"
          class="tv-card relative aspect-[4/3] rounded-[1.25rem] overflow-hidden bg-[#2c261e]"
          :aria-label="m.title || `Photo ${i + 1}`"
          @click="open(i, false)"
        >
          <img :src="m.thumb" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover" />
          <span v-if="m.type === 'video'" class="absolute right-3 bottom-3 w-12 h-12 rounded-full bg-black/60 flex items-center justify-center">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
          </span>
          <span v-if="m.voice_notes.length" class="absolute left-3 bottom-3 w-12 h-12 rounded-full bg-[#f59e0b] text-[#14110d] flex items-center justify-center" aria-label="Souvenir raconté">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0014 0M12 18v3" /></svg>
          </span>
        </button>
      </div>
      <p v-else class="text-[2rem] text-[#c9bfb1]">Rien à regarder ici pour l'instant.</p>
    </div>

    <TvViewer
      v-if="viewer"
      :key="viewer.key"
      :items="items"
      :start="viewer.start"
      :autoplay="viewer.play"
      :shuffle="viewer.shuffle"
      :title="title"
      @close="close"
    />
  </TvLayout>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import TvLayout from '@/Layouts/TvLayout.vue';
import TvViewer from '@/Components/Tv/TvViewer.vue';
import { focusEl, onTvKey } from '@/Tv/remote';

const props = defineProps({
  tab: String,
  circle: Object,
  circles: Array,
  account: Object,
  title: String,
  subtitle: String,
  items: Array,
  gameAlbumId: { type: String, default: null },
});

const backHref = computed(() => (props.tab === 'albums' ? '/tv/ecran/albums' : '/tv/ecran/accueil'));

const viewer = ref(null);
let opened = 0;
const open = (start, play, shuffle = false) => {
  if (!props.items.length) return;
  viewer.value = { key: ++opened, start, play, shuffle };
};
const randomStart = () => Math.floor(Math.random() * props.items.length);

// En fermant la visionneuse : focus sur la dernière photo vue.
const close = async (index) => {
  viewer.value = null;
  await nextTick();
  focusEl(document.querySelector(`[data-index="${index}"]`));
};

// Touche lecture de la télécommande : diaporama depuis la photo choisie.
onTvKey((action) => {
  if (viewer.value || action !== 'playpause') return false;
  const i = Number(document.activeElement?.dataset?.index ?? 0);
  open(i, true);
  return true;
});

onMounted(() => {
  const q = new URLSearchParams(window.location.search);
  if (q.get('play')) {
    const shuffle = !!q.get('shuffle');
    open(shuffle ? randomStart() : 0, true, shuffle);
  }
});
</script>
