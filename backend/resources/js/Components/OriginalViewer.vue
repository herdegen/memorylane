<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-[60] flex flex-col bg-black/95 text-white select-none"
      role="dialog"
      aria-modal="true"
      :aria-label="$t('Original : :title', { title })"
    >
      <!-- Barre d'outils -->
      <div class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm bg-black/70">
        <p class="flex-1 min-w-0 truncate">
          {{ title }}
          <span class="text-white/60">· {{ naturalW }} × {{ naturalH }}</span>
          <span v-if="!loaded" class="text-white/60">· {{ $t('chargement de l’original…') }}</span>
        </p>
        <button type="button" class="tool" :aria-label="$t('Dézoomer')" @click="zoomBy(1 / 1.5)">−</button>
        <span class="w-14 text-center tabular-nums">{{ $t(':value %', { value: Math.round(scale * 100) }) }}</span>
        <button type="button" class="tool" :aria-label="$t('Zoomer')" @click="zoomBy(1.5)">+</button>
        <button type="button" class="tool" @click="fit">{{ $t('Ajuster') }}</button>
        <button
          v-if="faces.length"
          type="button"
          class="tool"
          :class="showFaces ? 'bg-white/25' : 'line-through opacity-70'"
          :aria-pressed="showFaces"
          :title="$t('Afficher ou masquer les cadres des visages')"
          @click="showFaces = !showFaces"
        >
          {{ $t('Cadres') }}
        </button>
        <button type="button" class="tool" :aria-label="$t('Fermer')" @click="emit('close')">✕</button>
      </div>

      <!-- Image : déplacement au glisser, zoom molette / pincement -->
      <div
        ref="viewport"
        class="relative flex-1 overflow-hidden touch-none"
        :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
        @wheel.prevent="onWheel"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointercancel="onUp"
        @dblclick="onDblClick"
      >
        <div class="absolute left-0 top-0 origin-top-left" :style="stageStyle">
          <img v-if="previewUrl" :src="previewUrl" alt="" class="absolute inset-0 w-full h-full" draggable="false" @load="onPreviewLoad" />
          <img
            :src="originalUrl"
            :alt="title"
            class="absolute inset-0 w-full h-full transition-opacity duration-300"
            :class="loaded ? 'opacity-100' : 'opacity-0'"
            draggable="false"
            @load="onOriginalLoad"
          />
          <template v-if="showFaces">
            <div
              v-for="face in visibleFaces"
              :key="face.id"
              class="absolute rounded-sm pointer-events-none"
              :class="face.person ? 'border-green-500' : (face.status === 'rejected' ? 'border-surface-400' : 'border-amber-400')"
              :style="boxStyle(face)"
            >
              <span
                v-if="labelVisible(face)"
                class="absolute left-0 top-full mt-0.5 whitespace-nowrap rounded-sm px-1.5 py-0.5 text-xs text-white origin-top-left"
                :class="face.person ? 'bg-green-600' : (face.status === 'rejected' ? 'bg-surface-500' : 'bg-amber-500')"
                :style="{ transform: `scale(${1 / scale})` }"
              >
                {{ face.person ? face.person.name : (face.status === 'rejected' ? $t('inconnu') : '?') }}
              </span>
            </div>
          </template>
        </div>
      </div>

      <p class="px-3 py-1.5 text-center text-xs text-white/60 bg-black/70">
        <span class="hidden sm:inline">{{ $t('Molette ou pincement pour zoomer · glisser pour se déplacer · double-clic : taille réelle') }}</span>
        <span class="sm:hidden">{{ $t('Pincer pour zoomer · glisser pour se déplacer') }}</span>
        <template v-if="canEdit && faces.length"> · {{ $t('cliquez sur un visage pour l’identifier') }}</template>
      </p>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
  originalUrl: { type: String, required: true },
  // Miniature déjà en cache, affichée le temps que l'original arrive.
  previewUrl: { type: String, default: null },
  // Dimensions enregistrées de l'original (pour la mise à l'échelle avant chargement).
  width: { type: Number, default: null },
  height: { type: Number, default: null },
  title: { type: String, default: '' },
  faces: { type: Array, default: () => [] },
  canEdit: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'face-click']);

const viewport = ref(null);
const naturalW = ref(0);
const naturalH = ref(0);
const loaded = ref(false);
const showFaces = ref(true);
const scale = ref(1);
const tx = ref(0);
const ty = ref(0);
const dragging = ref(false);
let fitScale = 1;

const visibleFaces = computed(() => props.faces.filter(f => f.status !== 'dismissed' && f.bounding_box));

// La scène fait la taille de l'original en pixels ; le zoom est une
// transformation : les cadres (en %) restent alignés à toutes les échelles.
const stageStyle = computed(() => ({
  width: `${naturalW.value}px`,
  height: `${naturalH.value}px`,
  transform: `translate(${tx.value}px, ${ty.value}px) scale(${scale.value})`,
}));

const boxStyle = (face) => {
  const b = face.bounding_box;
  return {
    left: `${b.x}%`,
    top: `${b.y}%`,
    width: `${b.width}%`,
    height: `${b.height}%`,
    borderWidth: `${2 / scale.value}px`,
  };
};

// Noms seulement quand le visage est assez grand à l'écran (en vue
// d'ensemble d'une photo de groupe, ils se chevaucheraient).
const labelVisible = (face) => face.bounding_box.width / 100 * naturalW.value * scale.value >= 28;

// Dimensions affichées de l'original (orientation EXIF appliquée) déduites
// de la miniature, ramenée au grand côté enregistré.
const onPreviewLoad = (e) => {
  if (loaded.value) return;
  const { naturalWidth: w, naturalHeight: h } = e.target;
  const long = Math.max(props.width || 0, props.height || 0);
  const k = long ? long / Math.max(w, h) : 1;
  setSize(Math.round(w * k), Math.round(h * k));
};

const onOriginalLoad = (e) => {
  const first = !naturalW.value;
  const ratio = naturalW.value ? e.target.naturalWidth / naturalW.value : 1;
  naturalW.value = e.target.naturalWidth;
  naturalH.value = e.target.naturalHeight;
  loaded.value = true;
  if (first) {
    fit();
  } else if (ratio !== 1) {
    // Taille réelle légèrement différente de l'estimation : même cadrage.
    scale.value /= ratio;
    fitScale /= ratio;
  }
};

const setSize = (w, h) => {
  naturalW.value = w;
  naturalH.value = h;
  fit();
};

const fit = () => {
  const el = viewport.value;
  if (!el || !naturalW.value) return;
  const { width: vw, height: vh } = el.getBoundingClientRect();
  fitScale = Math.min(vw / naturalW.value, vh / naturalH.value, 1);
  scale.value = fitScale;
  tx.value = (vw - naturalW.value * fitScale) / 2;
  ty.value = (vh - naturalH.value * fitScale) / 2;
};

const clampScale = (s) => Math.min(Math.max(s, fitScale * 0.5), Math.max(3, fitScale));

// Zoom autour d'un point du viewport (le point reste sous le curseur).
const zoomAt = (factor, cx, cy) => {
  const next = clampScale(scale.value * factor);
  tx.value = cx - (cx - tx.value) * (next / scale.value);
  ty.value = cy - (cy - ty.value) * (next / scale.value);
  scale.value = next;
};

const center = () => {
  const r = viewport.value.getBoundingClientRect();
  return [r.width / 2, r.height / 2];
};
const local = (e) => {
  const r = viewport.value.getBoundingClientRect();
  return [e.clientX - r.left, e.clientY - r.top];
};

const zoomBy = (factor) => zoomAt(factor, ...center());

const onWheel = (e) => zoomAt(Math.exp(-e.deltaY * 0.0015), ...local(e));

let clickTimer = null;

const onDblClick = (e) => {
  clearTimeout(clickTimer);
  if (scale.value < 0.99) zoomAt(1 / scale.value, ...local(e));
  else fit();
};

// Glisser (1 doigt / souris) et pincer (2 doigts) ; un appui sans
// déplacement est un clic (visage → identification).
const pointers = new Map();
let moved = 0;
let pinch = null;

const onDown = (e) => {
  e.currentTarget.setPointerCapture(e.pointerId);
  pointers.set(e.pointerId, local(e));
  if (pointers.size === 1) moved = 0;
  dragging.value = true;
  pinch = null;
};

const onMove = (e) => {
  if (!pointers.has(e.pointerId)) return;
  const [x, y] = local(e);
  const [px, py] = pointers.get(e.pointerId);
  pointers.set(e.pointerId, [x, y]);
  if (pointers.size === 1) {
    tx.value += x - px;
    ty.value += y - py;
    moved += Math.abs(x - px) + Math.abs(y - py);
  } else if (pointers.size === 2) {
    const [a, b] = [...pointers.values()];
    const dist = Math.hypot(a[0] - b[0], a[1] - b[1]);
    const mid = [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
    if (pinch) {
      tx.value += mid[0] - pinch.mid[0];
      ty.value += mid[1] - pinch.mid[1];
      zoomAt(dist / pinch.dist, ...mid);
    }
    pinch = { dist, mid };
    moved = Infinity;
  }
};

const onUp = (e) => {
  if (!pointers.has(e.pointerId)) return;
  const point = pointers.get(e.pointerId);
  pointers.delete(e.pointerId);
  pinch = null;
  if (pointers.size === 0) {
    dragging.value = false;
    if (moved < 6 && e.type === 'pointerup') {
      clearTimeout(clickTimer);
      clickTimer = setTimeout(() => clickAt(...point), 250);
    }
  }
};

const clickAt = (cx, cy) => {
  if (!props.canEdit || !showFaces.value || !naturalW.value) return;
  const x = ((cx - tx.value) / scale.value / naturalW.value) * 100;
  const y = ((cy - ty.value) / scale.value / naturalH.value) * 100;
  const hits = visibleFaces.value.filter(({ bounding_box: b }) =>
    x >= b.x && x <= b.x + b.width && y >= b.y && y <= b.y + b.height);
  if (!hits.length) return;
  hits.sort((p, q) => p.bounding_box.width * p.bounding_box.height - q.bounding_box.width * q.bounding_box.height);
  emit('face-click', hits[0]);
};

const onKey = (e) => {
  if (e.key === 'Escape') emit('close');
  else if (e.key === '+' || e.key === '=') zoomBy(1.5);
  else if (e.key === '-') zoomBy(1 / 1.5);
};

let previousOverflow = '';
onMounted(() => {
  previousOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  window.addEventListener('keydown', onKey);
  window.addEventListener('resize', fit);
  // Sans miniature : taille enregistrée en attendant l'original.
  if (!props.previewUrl && props.width && props.height) setSize(props.width, props.height);
});

onBeforeUnmount(() => {
  clearTimeout(clickTimer);
  document.body.style.overflow = previousOverflow;
  window.removeEventListener('keydown', onKey);
  window.removeEventListener('resize', fit);
});
</script>

<style scoped>
@reference "../../css/app.css";

.tool {
  @apply rounded-md px-2.5 py-1 font-medium text-white/90 bg-white/10 hover:bg-white/20 transition;
}
</style>
