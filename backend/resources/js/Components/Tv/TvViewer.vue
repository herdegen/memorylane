<template>
  <!-- Visionneuse et diaporama plein écran (télécommande) :
       ◀ ▶ photo précédente / suivante · OK lecture / pause · ▼ écouter le
       souvenir raconté · ↩ fermer. Le bandeau s'efface pendant la lecture. -->
  <div data-tv-scope class="fixed inset-0 z-[60] bg-black overflow-hidden select-none" :class="{ 'cursor-none': !chrome }">
    <Transition name="tvx">
      <img
        v-if="item && item.type !== 'video'"
        :key="item.id"
        :src="item.src"
        alt=""
        class="absolute inset-0 w-full h-full object-contain"
        :class="{ 'tv-kb': playing }"
      />
      <video
        v-else-if="item"
        :key="item.id"
        ref="videoEl"
        :src="item.src"
        :poster="item.thumb"
        class="absolute inset-0 w-full h-full object-contain bg-black"
        playsinline
        preload="auto"
        @ended="onVideoEnded"
        @loadeddata="startVideo"
      ></video>
    </Transition>

    <Transition name="tvchrome">
      <div v-show="chrome" class="absolute inset-0 pointer-events-none">
        <!-- Haut : où l'on est -->
        <div class="absolute inset-x-0 top-0 px-24 pt-[3.375rem] pb-28 flex items-start justify-between" style="background: linear-gradient(to bottom, rgba(0,0,0,.75), rgba(0,0,0,0))">
          <div class="flex flex-col gap-2">
            <span class="text-[1.625rem] text-[#e7e0d6]">{{ title }}</span>
            <span class="text-[1.375rem] text-[#c9bfb1]">{{ pos + 1 }} / {{ order.length }}{{ shuffle ? ' · ordre aléatoire' : '' }}</span>
          </div>
          <span class="flex items-center gap-3 px-5.5 py-3 rounded-full bg-white/15 text-[1.5rem] font-bold">
            <svg v-if="playing" class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
            <svg v-else class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" /><rect x="14" y="5" width="4" height="14" /></svg>
            {{ playing ? 'Diaporama' : 'En pause' }}
          </span>
        </div>

        <!-- Bas : légende, personnes, souvenir raconté, progression -->
        <div v-if="item" class="absolute inset-x-0 bottom-0 px-24 pt-40 pb-[3.375rem] flex flex-col gap-5" style="background: linear-gradient(to top, rgba(0,0,0,.85), rgba(0,0,0,0))">
          <div v-if="item.title && item.title !== title" class="font-display text-[4rem] font-bold leading-[1.05]">{{ item.title }}</div>
          <div v-if="item.when" class="text-[1.875rem] text-[#e7e0d6] first-letter:uppercase">{{ item.when }}</div>
          <div v-if="item.people.length || note" class="flex gap-3.5 flex-wrap items-center">
            <span v-for="name in item.people.slice(0, 8)" :key="name" class="px-5.5 py-2.5 rounded-full bg-white/15 text-[1.625rem] font-semibold">{{ name }}</span>
            <span v-if="item.people.length > 8" class="text-[1.625rem] text-[#c9bfb1]">et {{ item.people.length - 8 }} autres</span>
            <span v-if="note" class="flex items-center gap-3 px-6.5 py-3.5 rounded-full text-[1.625rem] font-extrabold" :class="listening ? 'bg-[#f7f2ea] text-[#14110d]' : 'bg-[#f59e0b] text-[#14110d]'">
              <svg class="w-6.5 h-6.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0014 0M12 18v3" /></svg>
              {{ listening ? 'Écoute en cours… ▼ pour arrêter' : `▼ Écouter le souvenir de ${note.by}${note.seconds ? ' · ' + duration(note.seconds) : ''}` }}
            </span>
          </div>
          <div class="h-2 rounded-full bg-white/20 overflow-hidden mt-2">
            <div class="h-full bg-[#f7f2ea] transition-all duration-500" :style="{ width: ((pos + 1) / order.length) * 100 + '%' }"></div>
          </div>
          <div class="flex gap-10 text-[1.375rem] text-[#c9bfb1]">
            <span>◀ ▶ Précédente / suivante</span>
            <span>OK {{ playing ? 'Pause' : 'Lecture' }}</span>
            <span>↩ Fermer</span>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { onTvKey, tvActivity } from '@/Tv/remote';

const props = defineProps({
  items: { type: Array, required: true },
  start: { type: Number, default: 0 },
  autoplay: { type: Boolean, default: false },
  shuffle: { type: Boolean, default: false },
  title: { type: String, default: '' },
});
const emit = defineEmits(['close']);

/** Durée d'une photo pendant le diaporama (ms). */
const PHOTO_MS = 7000;

// Ordre de passage : la photo de départ, puis la suite (ou un mélange).
const order = (() => {
  const all = props.items.map((_, i) => i);
  if (!props.shuffle) return all;
  const rest = all.filter((i) => i !== props.start);
  for (let i = rest.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [rest[i], rest[j]] = [rest[j], rest[i]];
  }
  return [props.start, ...rest];
})();

const pos = ref(props.shuffle ? 0 : Math.min(props.start, order.length - 1));
const playing = ref(props.autoplay);
const chrome = ref(true);
const videoEl = ref(null);
const item = computed(() => props.items[order[pos.value]] || null);
const note = computed(() => item.value?.voice_notes?.[0] || null);

const duration = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

// ---- Souvenir raconté ------------------------------------------------------
const listening = ref(false);
let audio = null;
const stopAudio = () => {
  audio?.pause();
  audio = null;
  listening.value = false;
};
const toggleAudio = () => {
  if (listening.value) {
    stopAudio();
    schedule();
    return;
  }
  if (!note.value) return;
  audio = new Audio(note.value.url);
  audio.addEventListener('ended', () => { listening.value = false; audio = null; schedule(); });
  audio.play().then(() => { listening.value = true; clearTimeout(timer); }).catch(() => { audio = null; });
};

// ---- Lecture -----------------------------------------------------------------
let timer = null;
function schedule() {
  clearTimeout(timer);
  if (!playing.value || listening.value || item.value?.type === 'video') return;
  timer = setTimeout(() => go(1), PHOTO_MS);
}

const go = (step) => {
  stopAudio();
  pos.value = (pos.value + step + order.length) % order.length;
};

const startVideo = () => {
  const v = videoEl.value;
  if (!v || !playing.value) return;
  v.play().catch(() => { v.muted = true; v.play().catch(() => {}); });
};
const onVideoEnded = () => { if (playing.value) go(1); };

const togglePlay = () => {
  playing.value = !playing.value;
  const v = videoEl.value;
  if (v && item.value?.type === 'video') {
    if (playing.value) startVideo();
    else v.pause();
  }
};

// Le bandeau : visible à la moindre touche, s'efface après 4 s de lecture.
let chromeTimer = null;
const poke = () => {
  chrome.value = true;
  clearTimeout(chromeTimer);
  if (playing.value) chromeTimer = setTimeout(() => { chrome.value = false; }, 4000);
};

watch([pos, playing], () => { schedule(); poke(); });

// Photos suivantes préchargées : pas d'écran noir entre deux photos.
watch(pos, () => {
  for (const step of [1, 2]) {
    const next = props.items[order[(pos.value + step) % order.length]];
    if (next && next.type !== 'video') new Image().src = next.src;
  }
}, { immediate: true });

onTvKey((action) => {
  poke();
  switch (action) {
    case 'left': case 'prev': go(-1); break;
    case 'right': case 'next': go(1); break;
    case 'ok': case 'playpause': togglePlay(); break;
    case 'down': toggleAudio(); break;
    case 'back':
      if (listening.value) stopAudio();
      else emit('close', order[pos.value]);
      break;
    default: break;
  }
  return true;
});

onMounted(() => { tvActivity.blockers++; schedule(); poke(); });
onBeforeUnmount(() => {
  tvActivity.blockers--;
  tvActivity.last = Date.now();
  clearTimeout(timer);
  clearTimeout(chromeTimer);
  stopAudio();
});
</script>

<style scoped>
.tvx-enter-active, .tvx-leave-active { transition: opacity .8s ease; }
.tvx-enter-from, .tvx-leave-to { opacity: 0; }
.tvchrome-enter-active, .tvchrome-leave-active { transition: opacity .4s ease; }
.tvchrome-enter-from, .tvchrome-leave-to { opacity: 0; }
.tv-kb { animation: tv-kb 9s ease-out forwards; }
@keyframes tv-kb { from { transform: scale(1); } to { transform: scale(1.07); } }
</style>
