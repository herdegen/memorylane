<template>
  <Head :title="$t('Histoires — Télévision')" />
  <TvLayout
    :tab="tab" :circle="circle" :circles="circles" :account="account"
    back="/tv/ecran/accueil" fill nav-class="relative z-10"
    :hints="[['OK', $t('Lire l’histoire')], ['◀ ▶', $t('Autres histoires')], ['↩', $t('Accueil')]]"
  >
    <!-- Fond : le document de couverture de l'histoire en vue -->
    <div class="fixed inset-x-0 top-0 h-[38.75rem] pointer-events-none">
      <Transition name="tvbg">
        <img v-if="active?.cover" :key="active.cover" :src="active.cover" alt="" class="absolute inset-0 w-full h-full object-cover" />
      </Transition>
      <div class="absolute inset-0" style="background: linear-gradient(to right, rgba(18,16,12,.96), rgba(18,16,12,.7) 55%, rgba(18,16,12,.2))"></div>
      <div class="absolute inset-x-0 bottom-0 h-[12.5rem]" style="background: linear-gradient(to top, #12100c, rgba(18,16,12,0))"></div>
    </div>

    <div class="relative z-10 flex-1 min-h-0 flex flex-col">
      <div v-if="active" class="shrink-0 px-24 pt-14 w-[72rem] flex flex-col gap-4">
        <span class="self-start px-4.5 py-2 rounded-full bg-white/15 text-[1.375rem] font-extrabold tracking-[.08em] uppercase">
          {{ kindLabel(active.kind) }}<template v-if="active.period"> · {{ active.period }}</template><template v-if="active.draft"> · {{ $t('Brouillon') }}</template>
        </span>
        <h1 class="font-display text-[4.25rem] leading-[1.05] font-bold line-clamp-2">{{ active.title }}</h1>
        <p v-if="active.summary" class="text-[1.75rem] leading-snug text-[#e7e0d6] line-clamp-3">{{ active.summary }}</p>
        <p class="text-[1.5rem] text-[#b9ad9c]">{{ $tc(':count chapitre|:count chapitres', active.chapters) }}<template v-if="active.main"> · {{ active.main }}</template></p>
      </div>
      <p v-else class="px-24 pt-14 text-[2rem] text-[#c9bfb1]">{{ $t('Aucune histoire pour l’instant. Elles se racontent depuis le site, dans « Histoires ».') }}</p>

      <div class="flex-1 min-h-0 flex items-end pb-10">
        <div class="flex gap-7 overflow-x-auto px-24 py-6" data-tv-row>
          <Link
            v-for="(s, i) in stories"
            :key="s.id"
            :href="`/tv/ecran/histoires/${s.id}`"
            data-tv
            :data-tv-autofocus="i === 0 ? '' : null"
            class="tv-card shrink-0 w-[24rem] flex flex-col gap-3 rounded-[1.25rem]"
            @focus="active = s"
          >
            <div class="relative h-[15rem] rounded-[1.25rem] overflow-hidden bg-[#2c261e]">
              <img v-if="s.cover" :src="s.cover" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover" />
              <span v-else class="absolute inset-0 flex items-center justify-center text-[5rem]" aria-hidden="true">📖</span>
            </div>
            <div class="px-1 text-[1.625rem] font-bold truncate">{{ s.title }}</div>
            <div class="px-1 -mt-2 text-[1.375rem] text-[#b9ad9c] truncate">{{ s.period || kindLabel(s.kind) }}</div>
          </Link>
        </div>
      </div>
    </div>
  </TvLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import TvLayout from '@/Layouts/TvLayout.vue';
import { storyKindLabel as kindLabel } from '@/utils/stories';

const props = defineProps({
  tab: String,
  circle: Object,
  circles: Array,
  account: Object,
  stories: Array,
});

const active = ref(props.stories[0] || null);
</script>

<style scoped>
.tvbg-enter-active, .tvbg-leave-active { transition: opacity 1.2s ease; }
.tvbg-enter-from, .tvbg-leave-to { opacity: 0; }
</style>
