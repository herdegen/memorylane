<template>
  <div class="relative inline-block w-full">
    <img
      :src="imageUrl"
      :alt="alt"
      class="w-full h-auto max-h-[70vh] object-contain mx-auto"
      @load="imageLoaded = true"
    />

    <!-- Face bounding boxes -->
    <template v-if="imageLoaded">
      <div
        v-for="face in visibleFaces"
        :key="face.id"
        class="absolute border-2 cursor-pointer transition-all duration-200 hover:border-4"
        :class="faceBoxClasses(face)"
        :style="faceBoxStyle(face)"
        @click="$emit('face-click', face)"
      >
        <!-- Person name if matched -->
        <span
          v-if="face.person"
          class="absolute -bottom-6 left-0 text-xs bg-green-600 text-white px-1.5 py-0.5 rounded whitespace-nowrap"
        >
          {{ face.person.name }}
        </span>
        <!-- Unknown indicator if unmatched -->
        <span
          v-else
          class="absolute -top-6 left-1/2 -translate-x-1/2 text-xs bg-amber-500 text-white px-1.5 py-0.5 rounded whitespace-nowrap"
        >
          ?
        </span>
      </div>
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
});

defineEmits(['face-click']);

const imageLoaded = ref(false);

const visibleFaces = computed(() => {
  return props.faces.filter(f => f.status !== 'dismissed');
});

const faceBoxClasses = (face) => {
  if (face.person) {
    return 'border-green-500 hover:border-green-400';
  }
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
</script>
