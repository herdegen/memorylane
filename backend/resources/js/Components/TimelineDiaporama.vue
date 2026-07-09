<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      ref="rootEl"
      class="fixed inset-0 z-100 bg-black flex items-center justify-center select-none"
      @mousemove="showControls"
      @click="showControls"
    >
      <Transition name="fade" mode="out-in">
        <!-- Slide média -->
        <img
          v-if="current && current.media && current.media.type !== 'video'"
          :key="`p-${index}`"
          :src="current.media.medium_url || current.media.url"
          class="max-w-full max-h-full object-contain"
        />
        <video
          v-else-if="current && current.media && current.media.type === 'video'"
          :key="`v-${index}`"
          ref="videoEl"
          :src="current.media.url"
          autoplay
          playsinline
          class="max-w-full max-h-full object-contain"
          @ended="next"
          @error="next"
        />
        <!-- Slide carte d'événement -->
        <div
          v-else-if="current"
          :key="`e-${index}`"
          class="w-full h-full flex items-center justify-center bg-linear-to-br from-brand-900 to-surface-900 px-8"
        >
          <div class="text-center max-w-2xl">
            <div class="text-6xl mb-4">{{ kindIcon(current.kind) }}</div>
            <p class="text-brand-300 text-lg mb-2">{{ formatDate(current.date) }}</p>
            <h2 class="text-white text-3xl font-semibold mb-2">{{ current.title }}</h2>
            <p v-if="current.place" class="text-white/70 text-lg">{{ current.place }}</p>
            <p v-if="current.description" class="text-white/60 mt-3 whitespace-pre-wrap">{{ current.description }}</p>
            <img
              v-if="current.related && current.related.avatar_url"
              :src="current.related.avatar_url"
              class="w-24 h-24 rounded-full object-cover mx-auto mt-5 border-2 border-white/30"
            />
          </div>
        </div>
      </Transition>

      <Transition name="fade">
        <div v-if="controlsVisible" class="absolute inset-0 pointer-events-none">
          <button
            @click.stop="close"
            class="absolute top-4 right-4 pointer-events-auto w-11 h-11 rounded-full bg-black/50 text-white flex items-center justify-center hover:bg-black/70"
            title="Quitter"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>

          <div class="absolute bottom-0 left-0 right-0 pointer-events-auto bg-linear-to-t from-black/70 to-transparent px-6 pb-6 pt-12">
            <div class="flex items-center justify-center gap-4">
              <button @click.stop="previous" class="w-11 h-11 rounded-full bg-white/10 text-white flex items-center justify-center hover:bg-white/20" title="Précédent">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
              </button>
              <button @click.stop="togglePause" class="w-14 h-14 rounded-full bg-white/15 text-white flex items-center justify-center hover:bg-white/25" :title="isPaused ? 'Reprendre' : 'Pause'">
                <svg v-if="isPaused" class="w-7 h-7 ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
                <svg v-else class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24"><path d="M6 4h4v16H6zM14 4h4v16h-4z" /></svg>
              </button>
              <button @click.stop="next" class="w-11 h-11 rounded-full bg-white/10 text-white flex items-center justify-center hover:bg-white/20" title="Suivant">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
              </button>
            </div>
            <p class="text-center text-white/70 text-sm mt-3">
              {{ index + 1 }} / {{ items.length }}
              <span v-if="current?.title" class="text-white/40">— {{ current.title }}</span>
            </p>
          </div>
        </div>
      </Transition>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';

const props = defineProps({
  items: { type: Array, required: true },
  photoDuration: { type: Number, default: 6000 },
});

const isOpen = ref(false);
const index = ref(0);
const isPaused = ref(false);
const controlsVisible = ref(true);
const rootEl = ref(null);
const videoEl = ref(null);

let timer = null;
let controlsTimer = null;

const current = computed(() => props.items[index.value] || null);

const clearTimer = () => { if (timer) { clearTimeout(timer); timer = null; } };

const scheduleAdvance = () => {
  clearTimer();
  if (isPaused.value || !current.value) return;
  // Les vidéos avancent sur @ended ; photos et cartes après un délai.
  if (!(current.value.media && current.value.media.type === 'video')) {
    timer = setTimeout(next, props.photoDuration);
  }
};

const next = () => {
  if (props.items.length === 0) return;
  index.value = (index.value + 1) % props.items.length;
  scheduleAdvance();
};

const previous = () => {
  if (props.items.length === 0) return;
  index.value = (index.value - 1 + props.items.length) % props.items.length;
  scheduleAdvance();
};

const togglePause = () => {
  isPaused.value = !isPaused.value;
  if (current.value?.media?.type === 'video' && videoEl.value) {
    isPaused.value ? videoEl.value.pause() : videoEl.value.play();
  }
  scheduleAdvance();
};

const showControls = () => {
  controlsVisible.value = true;
  if (controlsTimer) clearTimeout(controlsTimer);
  controlsTimer = setTimeout(() => { controlsVisible.value = false; }, 3000);
};

const handleKeydown = (e) => {
  if (!isOpen.value) return;
  if (e.key === 'Escape') close();
  else if (e.key === 'ArrowRight') next();
  else if (e.key === 'ArrowLeft') previous();
  else if (e.key === ' ') { e.preventDefault(); togglePause(); }
};

const handleFullscreenChange = () => {
  if (isOpen.value && !document.fullscreenElement) close();
};

const open = (startIndex = 0) => {
  if (props.items.length === 0) return;
  index.value = Math.min(startIndex, props.items.length - 1);
  isPaused.value = false;
  isOpen.value = true;
  showControls();
  document.addEventListener('keydown', handleKeydown);
  document.addEventListener('fullscreenchange', handleFullscreenChange);
  requestAnimationFrame(() => {
    rootEl.value?.requestFullscreen?.().catch(() => {});
    scheduleAdvance();
  });
};

const close = () => {
  isOpen.value = false;
  clearTimer();
  document.removeEventListener('keydown', handleKeydown);
  document.removeEventListener('fullscreenchange', handleFullscreenChange);
  if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
};

const ICONS = { birth: '🎂', death: '🕯️', marriage: '💍', child: '👶', job: '💼', education: '🎓', residence: '🏠', photo: '📷', moment: '★', custom: '★' };
const kindIcon = (k) => ICONS[k] || '★';

const formatDate = (d) => {
  if (!d) return '';
  const date = new Date(d);
  if (isNaN(date)) return d;
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
};

onBeforeUnmount(() => {
  clearTimer();
  if (controlsTimer) clearTimeout(controlsTimer);
  document.removeEventListener('keydown', handleKeydown);
  document.removeEventListener('fullscreenchange', handleFullscreenChange);
});

defineExpose({ open });
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 400ms ease-in-out; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
