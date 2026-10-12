<template>
  <Head :title="personName ? `Les photos de ${personName}` : 'Les photos'" />
  <ImpersonationBanner />
  <div class="h-[100dvh] bg-[#1c1917] text-[#fff] flex flex-col gap-4 px-4 pt-4 pb-5 sm:px-8 sm:pt-6 sm:pb-7 select-none">

    <header class="flex items-center justify-between gap-4">
      <Link href="/simple" class="min-h-[72px] px-7 rounded-full bg-[#fff]/12 flex items-center gap-3 text-[24px] font-semibold">
        <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7" /><path d="M5 10v10h14V10" /></svg>
        Accueil
      </Link>
      <div class="text-right">
        <div v-if="personName" class="text-[24px] font-semibold">Les photos de {{ personName }}</div>
        <div v-if="photos.length" class="text-[20px] text-[#d6d3d1]">Photo {{ index + 1 }} sur {{ photos.length }}</div>
      </div>
    </header>

    <div v-if="!photos.length" class="flex-1 flex items-center justify-center text-center text-3xl text-[#d6d3d1] px-8">
      Pas encore de photos ici.
    </div>

    <template v-else>
      <div class="flex-1 min-h-0 grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 sm:gap-6">
        <button
          type="button"
          class="w-20 h-20 sm:w-28 sm:h-28 rounded-full bg-[#fff]/14 flex items-center justify-center active:scale-95 transition"
          aria-label="Photo précédente"
          @click="prev"
        >
          <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7" /></svg>
        </button>

        <div class="h-full min-h-0 flex items-center justify-center">
          <img
            :key="current.id"
            :src="current.url"
            alt=""
            class="max-h-full max-w-full object-contain rounded-2xl"
          />
        </div>

        <button
          type="button"
          class="w-20 h-20 sm:w-28 sm:h-28 rounded-full bg-brand-400 text-[#1c1917] flex items-center justify-center active:scale-95 transition"
          aria-label="Photo suivante"
          @click="next"
        >
          <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7" /></svg>
        </button>
      </div>

      <footer class="flex items-center justify-between gap-5 flex-wrap sm:px-32">
        <div class="flex flex-col gap-1 min-w-0">
          <div v-if="current.title" class="font-display text-3xl sm:text-4xl font-bold leading-tight">{{ current.title }}</div>
          <div v-if="captionLine" class="text-[22px] text-[#e7e5e4]">{{ captionLine }}</div>
          <div v-if="current.voice_notes_count" class="text-[20px] text-brand-200 flex items-center gap-2">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0 0 14 0M12 18v3" /></svg>
            {{ current.voice_notes_count === 1 ? 'Déjà un souvenir raconté' : `Déjà ${current.voice_notes_count} souvenirs racontés` }}
          </div>
        </div>
        <Link
          :href="`/simple/photos/${current.id}/raconter`"
          class="min-h-[84px] px-8 rounded-[22px] bg-[#fff] text-brand-900 flex items-center gap-3 text-[24px] font-bold shrink-0"
        >
          <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0 0 14 0M12 18v3" /></svg>
          Raconter cette photo
        </Link>
      </footer>
    </template>
  </div>
</template>

<script setup>
import ImpersonationBanner from '@/Components/ImpersonationBanner.vue';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
  photos: { type: Array, default: () => [] },
  startIndex: { type: Number, default: 0 },
  personName: { type: String, default: null },
});

const index = ref(Math.min(props.startIndex, Math.max(0, props.photos.length - 1)));
const current = computed(() => props.photos[index.value]);

const captionLine = computed(() => {
  const p = current.value;
  const parts = [];
  if (p.when) parts.push(p.when);
  if (p.who?.length) {
    const names = p.who.length < 2 ? p.who.join('') : `${p.who.slice(0, -1).join(', ')} et ${p.who.at(-1)}`;
    parts.push(`avec ${names}`);
  }
  return parts.join(' · ');
});

function next() {
  index.value = (index.value + 1) % props.photos.length;
}

function prev() {
  index.value = (index.value - 1 + props.photos.length) % props.photos.length;
}

// Précharge la photo suivante : pas d'écran vide en appuyant sur « suivant ».
watch(index, (i) => {
  const upcoming = props.photos[(i + 1) % props.photos.length];
  if (upcoming) new Image().src = upcoming.url;
}, { immediate: true });

function onKey(e) {
  if (e.key === 'ArrowRight') next();
  if (e.key === 'ArrowLeft') prev();
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>
