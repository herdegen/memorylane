<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      ref="rootEl"
      class="fixed inset-0 z-100 bg-black flex items-center justify-center select-none overflow-hidden"
      @mousemove="showControls"
      @click="showControls"
    >
      <!-- Média courant : chaque slide est un calque absolu, ce qui permet un
           vrai crossfade (l'entrant et le sortant se superposent). -->
      <Transition name="crossfade">
        <div
          v-if="currentItem"
          :key="currentItem.id"
          class="absolute inset-0 flex items-center justify-center"
        >
          <img
            v-if="currentItem.type === 'photo'"
            :src="photoUrl(currentItem)"
            :alt="currentItem.original_name"
            class="max-w-full max-h-full object-contain"
            :class="{ 'ken-burns': kenBurns }"
            :style="kenBurns ? kbStyle : null"
          />
          <video
            v-else-if="currentItem.type === 'video'"
            ref="videoEl"
            :src="videoUrl(currentItem)"
            autoplay
            playsinline
            class="max-w-full max-h-full object-contain"
            @ended="next"
            @error="next"
          />
          <div v-else class="text-surface-400 text-center">
            <p class="text-lg">{{ currentItem.original_name }}</p>
          </div>
        </div>
      </Transition>

      <!-- Contrôles (auto-masqués) -->
      <Transition name="fade">
        <div v-if="controlsVisible" class="absolute inset-0 pointer-events-none">
          <!-- Fermer -->
          <button
            @click.stop="close"
            class="absolute top-4 right-4 pointer-events-auto w-11 h-11 rounded-full bg-black/50 text-white flex items-center justify-center hover:bg-black/70 transition-colors"
            title="Quitter le diaporama"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <!-- Barre du bas -->
          <div class="absolute bottom-0 left-0 right-0 pointer-events-auto bg-linear-to-t from-black/70 to-transparent px-6 pb-6 pt-12">
            <div class="flex items-center justify-center gap-4">
              <button
                @click.stop="previous"
                class="w-11 h-11 rounded-full bg-white/10 text-white flex items-center justify-center hover:bg-white/20 transition-colors"
                title="Précédent"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
              </button>

              <button
                @click.stop="togglePause"
                class="w-14 h-14 rounded-full bg-white/15 text-white flex items-center justify-center hover:bg-white/25 transition-colors"
                :title="isPaused ? 'Reprendre' : 'Pause'"
              >
                <svg v-if="isPaused" class="w-7 h-7 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M8 5v14l11-7z" />
                </svg>
                <svg v-else class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M6 4h4v16H6zM14 4h4v16h-4z" />
                </svg>
              </button>

              <button
                @click.stop="next"
                class="w-11 h-11 rounded-full bg-white/10 text-white flex items-center justify-center hover:bg-white/20 transition-colors"
                title="Suivant"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
              </button>
            </div>

            <p class="text-center text-white/70 text-sm mt-3">
              {{ pointer + 1 }} / {{ order.length }}
              <span v-if="currentItem?.original_name" class="text-white/40">— {{ currentItem.original_name }}</span>
            </p>
          </div>
        </div>
      </Transition>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';

const props = defineProps({
  media: { type: Array, required: true },
  photoDuration: { type: Number, default: 5000 },
  // Ordre pseudo-aléatoire pondéré vers les photos à visages reconnus.
  shuffle: { type: Boolean, default: false },
  // Zoom/pan lent (Ken Burns) sur les photos.
  kenBurns: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const isOpen = ref(false);
const pointer = ref(0);
const isPaused = ref(false);
const controlsVisible = ref(true);
const rootEl = ref(null);
const videoEl = ref(null);

let photoTimer = null;
let controlsTimer = null;

// Les documents ne sont pas affichables : on ne garde que photos et vidéos.
const playableItems = computed(() =>
  props.media.filter((m) => m.type === 'photo' || m.type === 'video')
);

// Ordre de lecture effectif (figé à l'ouverture pour rester stable).
const order = ref([]);

const currentItem = computed(() => order.value[pointer.value] || null);

// Mélange pondéré (Efraimidis–Spirakis) : chaque élément reçoit une clé
// u^(1/poids) ; on trie par clé décroissante. Les photos avec au moins un
// visage reconnu pèsent davantage → elles remontent, sans jamais être exclues.
const weightedShuffle = (list) =>
  list
    .map((item) => {
      const weight = item.matched_faces_count > 0 ? 3 : 1;
      const u = Math.random() || 1e-9;
      return { item, key: Math.pow(u, 1 / weight) };
    })
    .sort((a, b) => b.key - a.key)
    .map((entry) => entry.item);

// Paramètres Ken Burns régénérés à chaque slide (direction de pan aléatoire).
const KB_DIRECTIONS = [
  [-1, -1], [1, -1], [-1, 1], [1, 1],
  [0, -1], [0, 1], [-1, 0], [1, 0],
];
const kbStyle = ref({});
const refreshKenBurns = () => {
  if (!props.kenBurns) return;
  const [dx, dy] = KB_DIRECTIONS[Math.floor(Math.random() * KB_DIRECTIONS.length)];
  const dist = 3; // % de translation, discret
  kbStyle.value = {
    '--kb-dur': `${props.photoDuration + 1200}ms`,
    '--kb-x': `${dx * dist}%`,
    '--kb-y': `${dy * dist}%`,
  };
};

const photoUrl = (media) => {
  const medium = media.conversions?.find((c) => c.conversion_name === 'medium');
  return medium?.url || media.url;
};

const videoUrl = (media) => {
  const web = media.conversions?.find((c) => c.conversion_name === 'web');
  return web?.url || media.url;
};

const clearPhotoTimer = () => {
  if (photoTimer) {
    clearTimeout(photoTimer);
    photoTimer = null;
  }
};

const scheduleAdvance = () => {
  clearPhotoTimer();
  if (isPaused.value || !currentItem.value) return;

  // Les photos avancent après un délai ; les vidéos avancent sur @ended.
  if (currentItem.value.type !== 'video') {
    photoTimer = setTimeout(next, props.photoDuration);
  }
};

const goTo = (index) => {
  if (order.value.length === 0) return;
  pointer.value = (index + order.value.length) % order.value.length;
  refreshKenBurns();
  scheduleAdvance();
};

const next = () => goTo(pointer.value + 1);
const previous = () => goTo(pointer.value - 1);

const togglePause = () => {
  isPaused.value = !isPaused.value;

  if (currentItem.value?.type === 'video' && videoEl.value) {
    isPaused.value ? videoEl.value.pause() : videoEl.value.play();
  }

  scheduleAdvance();
};

const showControls = () => {
  controlsVisible.value = true;
  if (controlsTimer) clearTimeout(controlsTimer);
  controlsTimer = setTimeout(() => {
    controlsVisible.value = false;
  }, 3000);
};

const handleKeydown = (e) => {
  if (!isOpen.value) return;
  if (e.key === 'Escape') close();
  else if (e.key === 'ArrowRight') next();
  else if (e.key === 'ArrowLeft') previous();
  else if (e.key === ' ') {
    e.preventDefault();
    togglePause();
  }
};

const handleFullscreenChange = () => {
  // Sortie du plein écran (Échap navigateur) = fermeture du diaporama
  if (isOpen.value && !document.fullscreenElement) {
    close();
  }
};

const open = (startIndex = 0) => {
  const base = playableItems.value;
  if (base.length === 0) return;

  // Fige l'ordre de lecture pour toute la séance.
  order.value = props.shuffle ? weightedShuffle(base) : [...base];
  // En mode aléatoire, startIndex n'a pas de sens : on démarre au début.
  pointer.value = props.shuffle ? 0 : Math.min(startIndex, order.value.length - 1);
  isPaused.value = false;
  isOpen.value = true;
  refreshKenBurns();
  showControls();
  document.addEventListener('keydown', handleKeydown);
  document.addEventListener('fullscreenchange', handleFullscreenChange);

  // Plein écran après le rendu du conteneur
  requestAnimationFrame(() => {
    rootEl.value?.requestFullscreen?.().catch(() => {});
    scheduleAdvance();
  });
};

const close = () => {
  isOpen.value = false;
  clearPhotoTimer();
  document.removeEventListener('keydown', handleKeydown);
  document.removeEventListener('fullscreenchange', handleFullscreenChange);
  if (document.fullscreenElement) {
    document.exitFullscreen?.().catch(() => {});
  }
  emit('close');
};

watch(pointer, () => showControls());

onBeforeUnmount(() => {
  clearPhotoTimer();
  if (controlsTimer) clearTimeout(controlsTimer);
  document.removeEventListener('keydown', handleKeydown);
  document.removeEventListener('fullscreenchange', handleFullscreenChange);
});

defineExpose({ open });
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 300ms ease-in-out;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Crossfade : entrant et sortant se superposent (pas de mode out-in). */
.crossfade-enter-active,
.crossfade-leave-active {
  transition: opacity 800ms ease-in-out;
}
.crossfade-enter-from,
.crossfade-leave-to {
  opacity: 0;
}

/* Ken Burns : zoom/pan lent, une fois par slide. La translation en % est
   relative à la taille de l'image → panoramique discret. */
.ken-burns {
  animation: ken-burns var(--kb-dur, 6s) ease-out forwards;
  will-change: transform;
}
@keyframes ken-burns {
  from {
    transform: scale(1.02) translate(0, 0);
  }
  to {
    transform: scale(1.12) translate(var(--kb-x, 0), var(--kb-y, 0));
  }
}
</style>
