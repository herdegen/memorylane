<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      ref="rootEl"
      class="fixed inset-0 z-100 bg-black flex items-center justify-center select-none overflow-hidden"
      @mousemove="showControls"
      @click="showControls"
    >
      <!-- Slide courant : chaque slide est un calque absolu, ce qui permet un
           vrai crossfade (l'entrant et le sortant se superposent). -->
      <Transition name="crossfade">
        <div
          v-if="currentItem"
          :key="currentItem.key"
          class="absolute inset-0 flex items-center justify-center"
        >
          <img
            v-if="currentItem.type === 'photo'"
            :src="currentItem.src"
            :alt="currentItem.label"
            class="w-full h-full object-contain"
            :class="{ 'ken-burns': kenBurns }"
            :style="kenBurns ? kbStyle : null"
          />
          <video
            v-else-if="currentItem.type === 'video'"
            ref="videoEl"
            :src="currentItem.src"
            autoplay
            playsinline
            class="w-full h-full object-contain"
            @ended="next"
            @error="next"
          />
          <!-- Slide « carte » : événement de vie, texte… (frise personne) -->
          <div
            v-else-if="currentItem.type === 'card'"
            class="w-full h-full flex items-center justify-center bg-linear-to-br from-[#78350f] to-[#1c1917] px-8"
          >
            <div class="text-center max-w-2xl">
              <div v-if="currentItem.card.icon" class="text-6xl mb-4">{{ currentItem.card.icon }}</div>
              <p v-if="currentItem.card.date" class="text-brand-300 text-lg mb-2">{{ currentItem.card.date }}</p>
              <h2 class="text-snow text-3xl font-semibold mb-2">{{ currentItem.card.title }}</h2>
              <p v-if="currentItem.card.place" class="text-snow/70 text-lg">{{ currentItem.card.place }}</p>
              <p v-if="currentItem.card.description" class="text-snow/60 mt-3 whitespace-pre-wrap">{{ currentItem.card.description }}</p>
              <img
                v-if="currentItem.card.avatarUrl"
                :src="currentItem.card.avatarUrl"
                class="w-24 h-24 rounded-full object-cover mx-auto mt-5 border-2 border-snow/30"
              />
            </div>
          </div>
          <div v-else class="text-surface-400 text-center">
            <p class="text-lg">{{ currentItem.label }}</p>
          </div>
        </div>
      </Transition>

      <!-- Diaporama qui voyage : carte entre deux étapes -->
      <TravelInterlude v-if="travel" ref="interlude" />

      <!-- ===== Téléphone : façon « stories » =====
           Zones tactiles (gauche = précédent, droite = suivant), maintien =
           pause, balayage vers le bas = fermer ; barres de progression en
           haut, légende sur dégradé en bas. -->
      <template v-if="isMobile">
        <div
          class="absolute inset-0 touch-none"
          @pointerdown.stop="onStoryDown"
          @pointermove="onStoryMove"
          @pointerup="onStoryUp"
          @pointercancel="onStoryCancel"
          @click.stop
        ></div>

        <!-- Barres de progression (une par diapositive, ou une seule au-delà de 24) -->
        <div class="absolute left-3 right-3 flex gap-1 pointer-events-none" :style="{ top: 'calc(12px + env(safe-area-inset-top))' }">
          <span
            v-for="k in barCount"
            :key="k"
            class="flex-1 h-[3px] rounded-full bg-snow/35 overflow-hidden"
          >
            <span
              :key="k - 1 === barIndex ? `p${pointer}` : `b${k}`"
              class="block h-full bg-snow"
              :class="{ 'story-fill': k - 1 === barIndex && currentItem?.type !== 'video' && !traveling }"
              :style="k - 1 < barIndex ? { width: '100%' } : (k - 1 === barIndex ? { width: currentItem?.type === 'video' || traveling ? '100%' : undefined, '--story-dur': `${photoDuration}ms`, animationPlayState: isPaused ? 'paused' : 'running' } : { width: '0%' })"
            ></span>
          </span>
        </div>

        <!-- En-tête : titre + moment, pause, fermer -->
        <div class="absolute left-4 right-2 flex items-center justify-between gap-2 pointer-events-none" :style="{ top: 'calc(24px + env(safe-area-inset-top))' }">
          <div class="min-w-0 text-snow [text-shadow:0_1px_6px_rgba(0,0,0,.6)]">
            <div class="text-sm font-bold truncate">{{ title || currentItem?.label || '' }}</div>
            <div v-if="currentItem?.when" class="text-xs text-snow/85 truncate">{{ currentItem.when }}</div>
          </div>
          <div class="flex shrink-0 pointer-events-auto">
            <button type="button" class="w-11 h-11 flex items-center justify-center text-snow" :aria-label="isPaused ? 'Reprendre' : 'Pause'" @click.stop="togglePause">
              <svg v-if="isPaused" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
              <svg v-else class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="5" width="4" height="14" /><rect x="14" y="5" width="4" height="14" /></svg>
            </button>
            <button type="button" class="w-11 h-11 flex items-center justify-center text-snow" aria-label="Fermer" @click.stop="close">
              <svg class="w-[22px] h-[22px]" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
        </div>

        <!-- Légende -->
        <div
          v-if="currentItem && currentItem.type !== 'card'"
          class="absolute inset-x-0 bottom-0 px-5 pt-20 pb-[calc(2rem+env(safe-area-inset-bottom))] bg-linear-to-t from-black/80 to-transparent flex flex-col gap-2.5 pointer-events-none text-snow"
        >
          <span v-if="currentItem.chapter" class="self-start px-2.5 py-1 rounded-full bg-snow/20 text-xs font-bold tracking-[.06em] uppercase">{{ currentItem.chapter }}</span>
          <div v-if="storyCaption" class="font-display text-[26px] leading-[1.15] [text-shadow:0_2px_10px_rgba(0,0,0,.5)] line-clamp-3">{{ storyCaption }}</div>
          <div v-if="currentItem.people?.length" class="flex flex-wrap gap-2">
            <span v-for="name in currentItem.people.slice(0, 4)" :key="name" class="inline-flex items-center gap-1.5 h-7 pl-1 pr-2.5 rounded-full bg-snow/20 text-[13px] font-semibold">
              <span class="w-[22px] h-[22px] rounded-full bg-brand-600 text-[10px] font-bold flex items-center justify-center">{{ initials(name) }}</span>{{ name.split(/\s+/)[0] }}
            </span>
          </div>
          <div class="flex items-center justify-between text-xs text-snow/75">
            <span>{{ pointer + 1 }} / {{ order.length }}</span>
            <span v-if="storyHintVisible">Touchez pour avancer · maintenez pour faire pause</span>
          </div>
        </div>
      </template>

      <!-- Contrôles desktop (auto-masqués) -->
      <Transition name="fade">
        <div v-if="controlsVisible && !isMobile" class="absolute inset-0 pointer-events-none">
          <!-- Fermer -->
          <button
            @click.stop="close"
            class="absolute top-4 right-4 pointer-events-auto w-11 h-11 rounded-full bg-black/50 text-snow flex items-center justify-center hover:bg-black/70 transition-colors"
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
                class="w-11 h-11 rounded-full bg-snow/10 text-snow flex items-center justify-center hover:bg-snow/20 transition-colors"
                title="Précédent"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
              </button>

              <button
                @click.stop="togglePause"
                class="w-14 h-14 rounded-full bg-snow/15 text-snow flex items-center justify-center hover:bg-snow/25 transition-colors"
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
                class="w-11 h-11 rounded-full bg-snow/10 text-snow flex items-center justify-center hover:bg-snow/20 transition-colors"
                title="Suivant"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
              </button>
            </div>

            <p class="text-center text-snow/70 text-sm mt-3">
              {{ pointer + 1 }} / {{ order.length }}
              <span v-if="currentItem?.label || currentItem?.chapter" class="text-snow/40">— {{ currentItem.label || currentItem.chapter }}</span>
            </p>
          </div>
        </div>
      </Transition>
    </div>
  </Teleport>
</template>

<script setup>
// Diaporama plein écran UNIQUE (albums, frise personne…). Chaque slide est
// normalisé par l'appelant :
//   { key, type: 'photo'|'video'|'card', src?, label?, weight?,
//     card?: { icon, date, title, place, description, avatarUrl } }
// Le type 'card' permet des slides texte/événements (naissance d'un enfant,
// moment de vie…) au milieu des photos.
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import TravelInterlude from '@/Components/TravelInterlude.vue';

const props = defineProps({
  slides: { type: Array, required: true },
  photoDuration: { type: Number, default: 5000 },
  // Ordre pseudo-aléatoire pondéré par slide.weight (ex. photos à visages).
  shuffle: { type: Boolean, default: false },
  // Zoom/pan lent (Ken Burns) sur les photos.
  kenBurns: { type: Boolean, default: false },
  // Album à plusieurs étapes : slide.place = { index, label, latitude,
  // longitude } ; une carte vole d'une étape à la suivante entre deux slides.
  travel: { type: Boolean, default: false },
  // Titre affiché en haut sur téléphone (façon stories) ; les slides peuvent
  // porter `when` (moment), `chapter` (chip) et `people` (prénoms).
  title: { type: String, default: '' },
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

// Ordre de lecture effectif (figé à l'ouverture pour rester stable).
const order = ref([]);

const currentItem = computed(() => order.value[pointer.value] || null);

// Mélange pondéré (Efraimidis–Spirakis) : chaque élément reçoit une clé
// u^(1/poids) ; on trie par clé décroissante. Les slides à poids fort
// remontent, sans jamais être exclus.
const weightedShuffle = (list) =>
  list
    .map((item) => {
      const weight = item.weight || 1;
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

const clearPhotoTimer = () => {
  if (photoTimer) {
    clearTimeout(photoTimer);
    photoTimer = null;
  }
};

// Temps restant de la diapositive en cours (la pause le conserve : sinon la
// photo durait plus longtemps à chaque pause et la barre finissait trop tôt).
let remaining = 0;
let startedAt = 0;
const scheduleAdvance = ({ resume = false } = {}) => {
  clearPhotoTimer();
  if (!resume) remaining = props.photoDuration;
  if (isPaused.value || !currentItem.value) return;

  // Photos et cartes avancent après un délai ; les vidéos sur @ended.
  if (currentItem.value.type !== 'video') {
    startedAt = Date.now();
    photoTimer = setTimeout(next, remaining);
  }
};

const goTo = (index) => {
  if (order.value.length === 0) return;
  pointer.value = (index + order.value.length) % order.value.length;
  lastPlace = currentItem.value?.place || lastPlace;
  refreshKenBurns();
  scheduleAdvance();
};

// Diaporama qui voyage : dernière étape montrée, et interlude en cours.
const interlude = ref(null);
const traveling = ref(false);
let lastPlace = null;
let travelRun = 0;

/** Carte jusqu'à l'étape du slide `index`, si elle change ; vrai si à jour. */
const travelTo = async (index) => {
  const place = order.value[index]?.place;
  if (!props.travel || !place || lastPlace?.index === place.index) return true;
  const run = ++travelRun;
  clearPhotoTimer();
  videoEl.value?.pause?.();
  traveling.value = true;
  const done = await interlude.value?.fly(lastPlace, place, `Étape ${place.index} · ${place.label}`);
  if (run !== travelRun || done === false) return false;
  traveling.value = false;
  interlude.value?.hide();
  return true;
};

const stopTravel = () => {
  travelRun++;
  traveling.value = false;
  interlude.value?.cancel();
};

const next = async () => {
  const target = (pointer.value + 1) % Math.max(order.value.length, 1);
  if (await travelTo(target)) goTo(target);
};
const previous = () => {
  stopTravel();
  goTo(pointer.value - 1);
};

const togglePause = () => {
  isPaused.value = !isPaused.value;

  if (currentItem.value?.type === 'video' && videoEl.value) {
    isPaused.value ? videoEl.value.pause() : videoEl.value.play();
  }

  if (isPaused.value) {
    if (photoTimer) remaining = Math.max(0, remaining - (Date.now() - startedAt));
    clearPhotoTimer();
  } else {
    scheduleAdvance({ resume: true });
  }
};

// ---- Téléphone : façon « stories » ------------------------------------------
const MOBILE_MAX = 639;
const isMobile = ref(false);
const barCount = computed(() => (order.value.length <= 24 ? order.value.length : 1));
const barIndex = computed(() => (order.value.length <= 24 ? pointer.value : 0));
const storyHintVisible = ref(true);
// Légende : un titre saisi, jamais un nom de fichier (IMG_1234.jpg).
const storyCaption = computed(() => {
  const label = currentItem.value?.label || '';
  return /\.[a-z0-9]{2,4}$/i.test(label) ? '' : label;
});
const initials = (name) => (name || '').split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();

// Un doigt posé : > 220 ms = pause tant qu'il reste ; relâché avant = tap
// (gauche = précédent, droite = suivant) ; glissé vers le bas > 100 px = fermer.
let story = null;
let holdTimer = null;
let heldPause = false;
const onStoryDown = (e) => {
  clearTimeout(holdTimer);
  story = { x: e.clientX, y: e.clientY, t: Date.now(), moved: false };
  heldPause = false;
  holdTimer = setTimeout(() => {
    if (!story || story.moved || isPaused.value) return;
    heldPause = true;
    togglePause();
  }, 220);
};
const onStoryMove = (e) => {
  if (!story) return;
  if (Math.abs(e.clientX - story.x) > 12 || Math.abs(e.clientY - story.y) > 12) story.moved = true;
};
const onStoryUp = (e) => {
  if (!story) return;
  clearTimeout(holdTimer);
  const dy = e.clientY - story.y;
  const dx = e.clientX - story.x;
  const current = story;
  story = null;
  storyHintVisible.value = false;
  if (heldPause) {
    heldPause = false;
    if (isPaused.value) togglePause();
    return;
  }
  if (dy > 100 && Math.abs(dx) < 80) { close(); return; }
  if (current.moved) {
    if (dx < -60) next();
    else if (dx > 60) previous();
    return;
  }
  if (Date.now() - current.t > 400) return;
  e.clientX < window.innerWidth / 3 ? previous() : next();
};
const onStoryCancel = () => {
  clearTimeout(holdTimer);
  story = null;
  if (heldPause) { heldPause = false; if (isPaused.value) togglePause(); }
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
  const base = props.slides;
  if (base.length === 0) return;

  // Fige l'ordre de lecture pour toute la séance.
  order.value = props.shuffle ? weightedShuffle(base) : [...base];
  // En mode aléatoire, startIndex n'a pas de sens : on démarre au début.
  pointer.value = props.shuffle ? 0 : Math.min(startIndex, order.value.length - 1);
  isPaused.value = false;
  isOpen.value = true;
  isMobile.value = window.innerWidth <= MOBILE_MAX;
  storyHintVisible.value = true;
  lastPlace = null;
  interlude.value?.reset();
  refreshKenBurns();
  showControls();
  document.addEventListener('keydown', handleKeydown);
  document.addEventListener('fullscreenchange', handleFullscreenChange);

  // Plein écran après le rendu du conteneur ; en voyage, la carte présente
  // d'abord la première étape.
  requestAnimationFrame(async () => {
    rootEl.value?.requestFullscreen?.().catch(() => {});
    await nextTick();
    if (props.travel && (await travelTo(pointer.value))) {
      lastPlace = currentItem.value?.place || lastPlace;
    }
    scheduleAdvance();
  });
};

const close = () => {
  stopTravel();
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
  clearTimeout(holdTimer);
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

/* Barre de progression de la diapositive courante (stories). */
.story-fill {
  animation: story-fill var(--story-dur, 5000ms) linear forwards;
}
@keyframes story-fill {
  from { width: 0; }
  to { width: 100%; }
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
