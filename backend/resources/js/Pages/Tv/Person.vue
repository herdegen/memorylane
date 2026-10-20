<template>
  <!-- Fiche d'un proche : sa vie en grandes cartes (frise), son récit de vie
       sur la carte, sa place dans l'arbre, ses photos. -->
  <Head :title="`${person.name} — Télévision`" />
  <TvLayout
    :tab="tab" :circle="circle" :circles="circles" :account="account"
    :back="back"
    :hints="[['OK', 'Ouvrir'], ['◀ ▲ ▼ ▶', 'Se déplacer'], ['▶❚❚', 'Diaporama'], ['↩', 'Retour']]"
  >
    <div class="px-24 pt-8 pb-6 flex flex-col gap-9">
      <header class="flex items-center gap-8">
        <img v-if="person.avatar" :src="person.avatar" alt="" class="w-[9rem] h-[9rem] shrink-0 rounded-full object-cover bg-[#3a2f1e]" />
        <span v-else class="w-[9rem] h-[9rem] shrink-0 rounded-full bg-[#3a2f1e] text-[#fcd34d] text-[4rem] font-bold flex items-center justify-center">{{ person.name.slice(0, 1) }}</span>
        <div class="flex flex-col gap-2 min-w-0">
          <h1 class="font-display text-[3.75rem] leading-[1.05] font-bold line-clamp-2">{{ person.name }}</h1>
          <p class="text-[1.75rem] text-[#c9bfb1]">
            <span v-if="person.relation" class="text-[#fcd34d] font-semibold">{{ person.relation }}</span>
            <template v-for="(part, i) in [person.life, person.count].filter(Boolean)" :key="i"> · {{ part }}</template>
          </p>
        </div>
      </header>

      <div class="flex gap-5 flex-wrap" data-tv-row>
        <button v-if="story.length" type="button" data-tv data-tv-autofocus class="tv-btn tv-btn-primary" @click="openStory(0)">
          <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>Le récit de sa vie
        </button>
        <button v-if="items.length" type="button" data-tv :data-tv-autofocus="story.length ? null : ''" class="tv-btn" :class="{ 'tv-btn-primary': !story.length }" @click="openViewer(0, true)">Diaporama de ses photos</button>
        <Link v-if="inTree" :href="`/tv/ecran/arbre/${person.id}`" data-tv class="tv-btn">Dans l'arbre</Link>
      </div>

      <!-- Sa vie : un événement par carte -->
      <section v-if="showLife && (lifeEvents.length || loadingLife)" class="flex flex-col gap-4">
        <h2 class="font-display text-[2.25rem] font-bold">Sa vie</h2>
        <p v-if="loadingLife" class="text-[1.625rem] text-[#a89c8b]">Chargement de la frise…</p>
        <div v-else class="flex gap-6 overflow-x-auto -mx-24 px-24 py-6 -my-3" data-tv-row>
          <button
            v-for="(ev, i) in lifeEvents"
            :key="ev._key"
            type="button"
            data-tv
            class="tv-card tv-life shrink-0 w-[21rem] flex flex-col rounded-[1.5rem] overflow-hidden bg-[#211d17] text-left"
            @click="openStory(i)"
          >
            <span class="relative h-[11rem] bg-[#2c261e] flex items-center justify-center">
              <img v-if="ev.image" :src="ev.image" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover" />
              <span v-else class="text-[4.5rem]">{{ ev.icon }}</span>
              <span v-if="ev.burst.length" class="absolute right-3 bottom-3 px-3 py-1 rounded-full text-[1.125rem] font-bold" style="background: rgba(0,0,0,.65)">{{ ev.burst.length }} photos</span>
            </span>
            <span class="flex flex-col gap-1.5 px-5 py-4">
              <span class="text-[1.375rem] font-bold text-[#fcd34d]">{{ ev.icon }} {{ ev.dateLabel }}</span>
              <span class="text-[1.625rem] font-bold leading-tight line-clamp-2">{{ ev.title }}</span>
              <span v-if="ev.place" class="text-[1.25rem] text-[#b9ad9c] line-clamp-1">{{ ev.place }}</span>
            </span>
          </button>
        </div>
      </section>

      <section v-if="items.length" class="flex flex-col gap-4">
        <h2 class="font-display text-[2.25rem] font-bold">Ses photos</h2>
        <TvMediaGrid :items="items" @open="(i) => openViewer(i, false)" />
      </section>
      <p v-else-if="!story.length && !loadingLife" class="text-[2rem] text-[#c9bfb1]">Pas encore de photo de {{ person.name }} dans ce cercle.</p>
    </div>

    <TvViewer v-if="viewer" :key="viewer.key" :items="items" :start="viewer.start" :autoplay="viewer.play" :title="person.name" @close="closeViewer" />
    <TvLifeStory v-if="storyStart !== null" :events="story" :start="storyStart" :person-name="firstName" @close="closeStory" />
  </TvLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, defineAsyncComponent } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import TvLayout from '@/Layouts/TvLayout.vue';
import TvViewer from '@/Components/Tv/TvViewer.vue';
import TvMediaGrid from '@/Components/Tv/TvMediaGrid.vue';
import { focusEl, focusInitial, onTvKey } from '@/Tv/remote';
import { buildStoryEvents } from '@/utils/lifeStory';

// Le récit embarque Leaflet : chargé seulement quand on le lance.
const TvLifeStory = defineAsyncComponent(() => import('@/Components/Tv/TvLifeStory.vue'));

const props = defineProps({
  tab: String, circle: Object, circles: Array, account: Object,
  person: Object,
  items: Array,
  showLife: Boolean,
  inTree: Boolean,
  back: { type: String, default: '/tv/ecran/proches' },
});

const firstName = computed(() => props.person.name.split(/\s+/)[0]);

// ---- Frise de vie (même source que la fiche du site) -----------------------
const timeline = ref([]);
const loadingLife = ref(props.showLife);
const story = computed(() => buildStoryEvents(timeline.value));
const lifeEvents = computed(() => story.value.map((ev) => ({
  ...ev,
  _key: `${ev.life_event_id ?? ev.kind}-${ev.date}-${ev.title}`,
  image: ev.burst[0]?.src || ev.media?.medium_url || ev.media?.url || ev.place_photo_url || ev.related?.avatar_url || null,
})));

let reloads = 0;
let reloadTimer = null;
const loadTimeline = async () => {
  try {
    const { data } = await axios.get(`/people/${props.person.id}/timeline`);
    timeline.value = data || [];
    // Lieux et photos de lieux résolus en arrière-plan : quelques rechargements.
    if (timeline.value.some((i) => i.place_pending) && reloads < 4) {
      reloads += 1;
      reloadTimer = setTimeout(loadTimeline, 3000 * reloads);
    }
  } catch (e) {
    timeline.value = [];
  } finally {
    const first = loadingLife.value;
    loadingLife.value = false;
    // Le bouton « Le récit de sa vie » apparaît : il prend le focus.
    if (first) nextTick(() => focusInitial());
  }
};

// ---- Récit de vie ------------------------------------------------------------
const storyStart = ref(null);
const openStory = (i) => { storyStart.value = i; };
const closeStory = async () => {
  const i = storyStart.value;
  storyStart.value = null;
  await nextTick();
  focusEl(document.querySelectorAll('.tv-life')[i] || null);
};

// ---- Visionneuse -------------------------------------------------------------
const viewer = ref(null);
let opened = 0;
const openViewer = (start, play) => { viewer.value = { key: ++opened, start, play }; };
const closeViewer = async (index) => {
  viewer.value = null;
  await nextTick();
  focusEl(document.querySelector(`[data-index="${index}"]`));
};

onTvKey((action) => {
  if (viewer.value || storyStart.value !== null || action !== 'playpause' || !props.items.length) return false;
  openViewer(Number(document.activeElement?.dataset?.index ?? 0), true);
  return true;
});

onMounted(() => { if (props.showLife) loadTimeline(); });
onBeforeUnmount(() => clearTimeout(reloadTimer));
</script>
