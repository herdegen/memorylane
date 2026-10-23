<template>
  <!-- Cadre photo : la télévision laissée seule fait défiler les photos du
       cercle, avec l'heure. N'importe quelle touche rend la main. -->
  <div data-tv-scope class="fixed inset-0 z-[90] bg-black overflow-hidden cursor-none select-none">
    <Transition name="tvframe">
      <img v-if="current" :key="current.id" :src="current.src" alt="" class="tv-frame-img absolute inset-0 w-full h-full object-contain" />
    </Transition>
    <div class="absolute inset-x-0 bottom-0 px-24 pt-40 pb-14 flex items-end justify-between gap-12" style="background: linear-gradient(to top, rgba(0,0,0,.75), rgba(0,0,0,0))">
      <div class="flex flex-col">
        <span class="text-[6rem] font-light leading-none tabular-nums">{{ clock }}</span>
        <span class="mt-2 text-[2rem] text-[#e7e0d6] first-letter:uppercase">{{ today }}</span>
      </div>
      <div v-if="current" class="text-right flex flex-col gap-1 min-w-0">
        <span v-if="current.title" class="font-display text-[2.25rem] font-bold truncate">{{ current.title }}</span>
        <span v-if="current.when" class="text-[1.625rem] text-[#c9bfb1] first-letter:uppercase">{{ current.when }}</span>
      </div>
    </div>
    <p v-if="!current && !loading" class="absolute inset-0 flex items-center justify-center text-[2rem] text-[#c9bfb1]">{{ $t('Pas de photo à afficher.') }}</p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { onTvKey, tvActivity } from '@/Tv/remote';
import { intlLocale } from '@/i18n';

const emit = defineEmits(['close']);

/** Durée d'une photo (ms). */
const PHOTO_MS = 12000;

const items = ref([]);
const index = ref(0);
const loading = ref(true);
const now = ref(new Date());
const current = computed(() => items.value[index.value] || null);

const clock = computed(() => now.value.toLocaleTimeString(intlLocale(), { hour: '2-digit', minute: '2-digit' }));
const today = computed(() => now.value.toLocaleDateString(intlLocale(), { weekday: 'long', day: 'numeric', month: 'long' }));

const load = async () => {
  loading.value = true;
  try {
    const { data } = await axios.get('/tv/ecran/cadre');
    items.value = data.items;
    index.value = 0;
  } catch (e) {
    /* hors ligne : on garde les photos déjà là */
  } finally {
    loading.value = false;
  }
};

let slide = null;
let tick = null;
const next = () => {
  if (index.value + 1 >= items.value.length) {
    load();
    return;
  }
  index.value++;
  const after = items.value[index.value + 1];
  if (after) new Image().src = after.src;
};

// N'importe quelle touche : on sort du cadre (sans autre effet).
onTvKey(() => {
  emit('close');
  return true;
});

onMounted(() => {
  tvActivity.blockers++;
  load();
  slide = setInterval(next, PHOTO_MS);
  tick = setInterval(() => { now.value = new Date(); }, 15000);
});
onBeforeUnmount(() => {
  tvActivity.blockers--;
  tvActivity.last = Date.now();
  clearInterval(slide);
  clearInterval(tick);
});
</script>

<style scoped>
.tvframe-enter-active, .tvframe-leave-active { transition: opacity 2s ease; }
.tvframe-enter-from, .tvframe-leave-to { opacity: 0; }
.tv-frame-img { animation: tv-frame-kb 14s ease-out forwards; }
@keyframes tv-frame-kb { from { transform: scale(1); } to { transform: scale(1.06); } }
</style>
