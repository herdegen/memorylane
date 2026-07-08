<template>
  <div
    ref="containerRef"
    class="relative inline-block w-full"
    :class="drawMode ? 'cursor-crosshair select-none' : ''"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="onPointerUp"
    @pointerleave="onPointerUp"
  >
    <img
      :src="imageUrl"
      :alt="alt"
      class="w-full h-auto max-h-[70vh] object-contain mx-auto"
      draggable="false"
      @load="imageLoaded = true"
    />

    <!-- Face bounding boxes -->
    <template v-if="imageLoaded">
      <div
        v-for="face in visibleFaces"
        :key="face.id"
        class="absolute border-2 transition-all duration-200"
        :class="[faceBoxClasses(face), drawMode ? 'pointer-events-none' : 'cursor-pointer hover:border-4']"
        :style="faceBoxStyle(face)"
        @click="!drawMode && $emit('face-click', face)"
      >
        <!-- Person name if matched -->
        <span
          v-if="face.person"
          class="absolute -bottom-6 left-0 text-xs bg-green-600 text-white px-1.5 py-0.5 rounded-sm whitespace-nowrap"
        >
          {{ face.person.name }}
        </span>
        <!-- Unknown indicator if unmatched -->
        <span
          v-else
          class="absolute -top-6 left-1/2 -translate-x-1/2 text-xs bg-amber-500 text-white px-1.5 py-0.5 rounded-sm whitespace-nowrap"
        >
          ?
        </span>
      </div>

      <!-- Live rectangle being drawn -->
      <div
        v-if="drawMode && draft"
        class="absolute border-2 border-dashed border-brand-500 bg-brand-500/10 pointer-events-none"
        :style="{ left: `${draft.x}%`, top: `${draft.y}%`, width: `${draft.width}%`, height: `${draft.height}%` }"
      />
    </template>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  imageUrl: {
    type: String,
    required: true,
  },
  alt: {
    type: String,
    default: '',
  },
  faces: {
    type: Array,
    default: () => [],
  },
  // Mode « dessiner une zone » pour ajouter un visage manuellement.
  drawMode: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['face-click', 'region-drawn']);

const imageLoaded = ref(false);
const containerRef = ref(null);
const drawing = ref(false);
const draft = ref(null);
let startPt = null;

const visibleFaces = computed(() => props.faces.filter(f => f.status !== 'dismissed'));

const faceBoxClasses = (face) => {
  if (face.person) return 'border-green-500 hover:border-green-400';
  return 'border-amber-400 hover:border-amber-300';
};

const faceBoxStyle = (face) => {
  const box = face.bounding_box;
  if (!box) return {};
  return {
    left: `${box.x}%`,
    top: `${box.y}%`,
    width: `${box.width}%`,
    height: `${box.height}%`,
  };
};

const clamp = (v) => Math.max(0, Math.min(100, v));

const pointToPercent = (e) => {
  const rect = containerRef.value.getBoundingClientRect();
  return {
    x: clamp(((e.clientX - rect.left) / rect.width) * 100),
    y: clamp(((e.clientY - rect.top) / rect.height) * 100),
  };
};

const rectFrom = (a, b) => ({
  x: Math.min(a.x, b.x),
  y: Math.min(a.y, b.y),
  width: Math.abs(a.x - b.x),
  height: Math.abs(a.y - b.y),
});

const onPointerDown = (e) => {
  if (!props.drawMode) return;
  e.preventDefault();
  startPt = pointToPercent(e);
  draft.value = { ...startPt, width: 0, height: 0 };
  drawing.value = true;
};

const onPointerMove = (e) => {
  if (!drawing.value) return;
  draft.value = rectFrom(startPt, pointToPercent(e));
};

const onPointerUp = () => {
  if (!drawing.value) return;
  drawing.value = false;
  const r = draft.value;
  draft.value = null;
  // Ignore les clics/zones trop petits.
  if (r && r.width > 2 && r.height > 2) {
    emit('region-drawn', r);
  }
};
</script>
