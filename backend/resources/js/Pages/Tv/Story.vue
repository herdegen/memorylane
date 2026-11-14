<template>
  <Head :title="story.title" />
  <TvLayout bare fill back="/tv/ecran/histoires" :hints="hints">
    <div class="relative flex-1 min-h-0 flex flex-col">
      <!-- En-tête discret : l'histoire et le chapitre en cours -->
      <header class="shrink-0 flex items-baseline gap-6 px-24 pt-12 text-[1.5rem] text-[#b9ad9c]">
        <span class="font-display font-bold text-[#f7f2ea] truncate">{{ story.title }}</span>
        <span v-if="page.chapter" class="truncate">{{ $t('Chapitre :n sur :total', { n: page.chapter.number, total: page.chapter.total }) }} · {{ page.chapter.title }}</span>
        <span class="ml-auto shrink-0 tabular-nums">{{ index + 1 }} / {{ pages.length }}</span>
      </header>

      <Transition name="tvpage" mode="out-in">
        <!-- Couverture -->
        <section v-if="page.type === 'cover'" :key="index" class="relative flex-1 min-h-0 flex items-center px-24">
          <img v-if="page.image" :src="page.image" alt="" class="absolute right-24 top-8 bottom-8 max-w-[44%] h-[calc(100%-4rem)] object-contain rounded-2xl opacity-90" />
          <div class="relative w-[58%] flex flex-col gap-6">
            <span class="self-start px-4.5 py-2 rounded-full bg-white/15 text-[1.375rem] font-extrabold tracking-[.08em] uppercase">
              {{ kindLabel(page.kind) }}<template v-if="page.period"> · {{ page.period }}</template>
            </span>
            <h1 class="font-display text-[4.75rem] leading-[1.04] font-bold">{{ page.title }}</h1>
            <p v-if="page.summary" class="text-[2rem] leading-snug text-[#e7e0d6]">{{ page.summary }}</p>
            <p v-if="page.people.length" class="text-[1.625rem] text-[#b9ad9c]">{{ page.people.join(' · ') }}</p>
            <p class="mt-4 text-[1.75rem] font-bold text-[#fcd34d]">▶ {{ $t('Appuyez sur ▶ pour commencer') }}</p>
          </div>
        </section>

        <!-- Ouverture d'un chapitre -->
        <section v-else-if="page.type === 'chapter'" :key="index" class="flex-1 min-h-0 flex flex-col justify-center px-24 gap-5">
          <span class="text-[1.75rem] font-extrabold tracking-[.12em] uppercase text-[#fcd34d]">{{ $t('Chapitre :n sur :total', { n: page.number, total: page.total }) }}</span>
          <h2 class="font-display text-[5.5rem] leading-[1.02] font-bold max-w-[80rem]">{{ page.title }}</h2>
          <p v-if="page.when" class="text-[2.25rem] text-[#c9bfb1]">{{ page.when }}</p>
        </section>

        <!-- Texte -->
        <section v-else-if="page.type === 'text'" :key="index" class="flex-1 min-h-0 flex items-center px-24">
          <p class="max-w-[78rem] font-display text-[2.6rem] leading-[1.45] text-[#f7f2ea]">{{ page.text }}</p>
        </section>

        <!-- Citation -->
        <section v-else-if="page.type === 'quote'" :key="index" class="flex-1 min-h-0 flex flex-col justify-center px-32 gap-8">
          <p class="max-w-[72rem] font-display italic text-[3.25rem] leading-[1.35] text-[#fef3c7]">« {{ page.text }} »</p>
          <p v-if="page.cite" class="text-[1.875rem] text-[#c9bfb1]">— {{ page.cite }}</p>
        </section>

        <!-- Document, en grand -->
        <section v-else-if="page.type === 'media'" :key="index" class="flex-1 min-h-0 flex flex-col items-center px-24 pt-6 gap-5">
          <video v-if="page.video" :src="page.src" controls autoplay class="flex-1 min-h-0 max-w-full rounded-xl bg-black"></video>
          <img v-else :src="page.src" alt="" class="flex-1 min-h-0 max-w-full object-contain rounded-xl shadow-2xl" />
          <p v-if="page.caption" class="shrink-0 max-w-[80rem] text-center text-[1.875rem] text-[#e7e0d6]">{{ page.caption }}</p>
        </section>

        <!-- Ce qu'on ignore, questions à la famille -->
        <section v-else-if="page.type === 'open'" :key="index" class="flex-1 min-h-0 flex flex-col justify-center px-24 gap-10 max-w-[90rem]">
          <div v-if="page.unknown" class="flex flex-col gap-4">
            <h2 class="font-display text-[3.5rem] font-bold">{{ $t('Ce qu’on ignore') }}</h2>
            <p class="text-[1.875rem] leading-snug text-[#e7e0d6]">{{ page.unknown }}</p>
          </div>
          <div v-if="page.questions.length" class="flex flex-col gap-4">
            <h2 class="font-display text-[3.5rem] font-bold">{{ $t('Questions à la famille') }}</h2>
            <ul class="flex flex-col gap-3 text-[1.875rem] leading-snug text-[#e7e0d6]">
              <li v-for="q in page.questions" :key="q" class="flex gap-4"><span class="text-[#fcd34d]">?</span>{{ q }}</li>
            </ul>
            <p class="text-[1.5rem] text-[#b9ad9c]">{{ $t('On peut y répondre sur le site, sous l’histoire.') }}</p>
          </div>
        </section>
      </Transition>

      <!-- Avancement -->
      <div class="shrink-0 mx-24 mb-6 h-1.5 rounded-full bg-white/10 overflow-hidden">
        <div class="h-full bg-[#fcd34d] transition-all duration-500" :style="{ width: ((index + 1) / pages.length * 100) + '%' }"></div>
      </div>
    </div>
  </TvLayout>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import TvLayout from '@/Layouts/TvLayout.vue';
import { onTvKey, tvActivity } from '@/Tv/remote';
import { storyKindLabel as kindLabel } from '@/utils/stories';
import { t } from '@/i18n';

const props = defineProps({
  tab: String,
  circle: Object,
  circles: Array,
  account: Object,
  story: { type: Object, required: true },
  pages: { type: Array, required: true },
});

const index = ref(0);
const page = computed(() => props.pages[index.value]);
const hints = [['◀ ▶', t('Page précédente / suivante')], ['OK', t('Suivant')], ['↩', t('Histoires')]];

const go = (step) => {
  const next = index.value + step;
  if (next >= 0 && next < props.pages.length) index.value = next;
  // Préchargement de l'image suivante.
  const ahead = props.pages[index.value + 1];
  if (ahead?.type === 'media' && !ahead.video) new Image().src = ahead.src;
};

// Lecture au salon : pas de cadre photo par-dessus.
tvActivity.blockers++;
onBeforeUnmount(() => { tvActivity.blockers--; tvActivity.last = Date.now(); });

onTvKey((action) => {
  tvActivity.last = Date.now();
  switch (action) {
    case 'right': case 'next': case 'ok': case 'down': go(1); break;
    case 'left': case 'prev': case 'up': go(-1); break;
    case 'back': router.visit('/tv/ecran/histoires'); break;
    default: break;
  }
  return true;
});
</script>

<style scoped>
.tvpage-enter-active, .tvpage-leave-active { transition: opacity .25s ease; }
.tvpage-enter-from, .tvpage-leave-to { opacity: 0; }
</style>
