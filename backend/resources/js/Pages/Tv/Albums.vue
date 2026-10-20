<template>
  <Head title="Albums — Télévision" />
  <TvLayout
    :tab="tab" :circle="circle" :circles="circles" :account="account"
    back="/tv/ecran/accueil" fill nav-class="relative z-10"
    :hints="[['OK', 'Ouvrir l\'album'], ['▲ ▼', 'Autres rangées'], ['↩', 'Accueil']]"
  >
    <!-- Fond : les photos de l'album en vue (celui à l'honneur, puis celui choisi) -->
    <div class="fixed inset-x-0 top-0 h-[38.75rem] pointer-events-none">
      <Transition name="tvbg">
        <img v-if="background" :key="background" :src="background" alt="" class="absolute inset-0 w-full h-full object-cover" />
      </Transition>
      <div class="absolute inset-0" style="background: linear-gradient(to right, rgba(18,16,12,.95), rgba(18,16,12,.6) 50%, rgba(18,16,12,0))"></div>
      <div class="absolute inset-x-0 bottom-0 h-[12.5rem]" style="background: linear-gradient(to top, #12100c, rgba(18,16,12,0))"></div>
    </div>

    <div class="relative z-10 flex-1 min-h-0 flex flex-col">
      <div v-if="active" class="shrink-0 px-24 pt-14 w-[66rem] flex flex-col gap-4">
        <span class="self-start px-4.5 py-2 rounded-full bg-white/15 text-[1.375rem] font-extrabold tracking-[.08em] uppercase">{{ active.reason || 'Album' }}</span>
        <h1 class="font-display text-[4.5rem] leading-[1.02] font-bold line-clamp-2">{{ active.name }}</h1>
        <p class="text-[1.875rem] text-[#e7e0d6]">{{ active.meta }}</p>
        <div class="flex gap-5 mt-2" data-tv-row>
          <Link :href="`/tv/ecran/albums/${active.id}?play=1`" data-tv data-tv-autofocus class="tv-btn tv-btn-primary">
            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>Diaporama
          </Link>
          <Link :href="`/tv/ecran/albums/${active.id}?play=1&shuffle=1`" data-tv class="tv-btn">Lecture aléatoire</Link>
          <Link :href="`/tv/ecran/albums/${active.id}`" data-tv class="tv-btn">Voir les photos</Link>
        </div>
      </div>
      <p v-else class="px-24 pt-14 text-[2rem] text-[#c9bfb1]">Aucun album avec des photos dans ce cercle pour l'instant.</p>

      <!-- Rangées : défilent sous l'en-tête -->
      <div class="flex-1 min-h-0 overflow-y-auto mt-10 flex flex-col gap-8 pb-8">
        <section v-for="row in rows" :key="row.key" class="flex flex-col gap-4">
          <h2 class="px-24 font-display text-[2.25rem] font-bold">{{ row.title }}</h2>
          <div class="flex gap-7 overflow-x-auto px-24 py-6 -my-3" data-tv-row>
            <TvAlbumCard v-for="a in row.albums" :key="row.key + a.id" :album="a" @focus="hover" />
          </div>
        </section>
      </div>
    </div>
  </TvLayout>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import TvLayout from '@/Layouts/TvLayout.vue';
import TvAlbumCard from '@/Components/Tv/TvAlbumCard.vue';

const props = defineProps({
  tab: String,
  circle: Object,
  circles: Array,
  account: Object,
  featured: Object,
  rows: Array,
});

const active = ref(props.featured || props.rows[0]?.albums[0] || null);

// L'album en vue suit la carte choisie (après une courte pause : pas de
// clignotement quand on parcourt une rangée rapidement).
let hoverTimer = null;
const hover = (album) => {
  clearTimeout(hoverTimer);
  hoverTimer = setTimeout(() => {
    if (active.value?.id !== album.id) active.value = album;
  }, 350);
};

// Fond : diaporama muet des photos de l'album en vue.
const slide = ref(0);
const background = computed(() => {
  const a = active.value;
  if (!a) return null;
  const list = a.backgrounds?.length ? a.backgrounds : [a.cover];
  return list[slide.value % list.length] || null;
});
let slideTimer = setInterval(() => { slide.value++; }, 5000);
watch(active, () => { slide.value = 0; });

onBeforeUnmount(() => {
  clearTimeout(hoverTimer);
  clearInterval(slideTimer);
});
</script>

<style scoped>
.tvbg-enter-active, .tvbg-leave-active { transition: opacity 1.2s ease; }
.tvbg-enter-from, .tvbg-leave-to { opacity: 0; }
</style>
