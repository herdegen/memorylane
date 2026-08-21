<template>
  <div
    class="relative group overflow-hidden bg-surface-100 cursor-pointer transition-transform duration-200"
    :class="[
      fill ? 'w-full h-full rounded-xs' : 'aspect-square rounded-lg hover:scale-105 hover:shadow-warm-lg',
      { 'ring-2 ring-brand-500 ring-offset-2': selectable && isSelected },
    ]"
    @click="$emit('click', media, $event)"
  >
    <!-- Image Thumbnail -->
    <img
      v-if="media.type === 'photo' && thumbnailUrl"
      :src="thumbnailUrl"
      :alt="media.original_name"
      class="w-full h-full object-cover"
      loading="lazy"
    />

    <!-- Video Thumbnail -->
    <div
      v-else-if="media.type === 'video'"
      class="relative w-full h-full"
    >
      <img
        v-if="thumbnailUrl"
        :src="thumbnailUrl"
        :alt="media.original_name"
        class="w-full h-full object-cover"
        loading="lazy"
      />
      <div
        v-else
        class="w-full h-full flex items-center justify-center bg-surface-200"
      >
        <svg
          class="h-12 w-12 text-surface-400"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"
          />
        </svg>
      </div>
      <!-- Play Icon Overlay -->
      <div
        class="absolute inset-0 flex items-center justify-center bg-black/20"
      >
        <svg
          class="h-12 w-12 text-white opacity-80"
          fill="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            d="M8 5v14l11-7z"
          />
        </svg>
      </div>
    </div>

    <!-- Document Thumbnail -->
    <div
      v-else
      class="w-full h-full flex flex-col items-center justify-center bg-surface-200"
    >
      <svg
        class="h-12 w-12 text-surface-400"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
        />
      </svg>
      <span class="mt-2 text-xs text-surface-500 truncate max-w-full px-2">
        {{ fileExtension }}
      </span>
    </div>

    <!-- Overlay de survol : uniquement les tags (nom de fichier et date
         retirés à la demande de l'user — bruit visuel) -->
    <div
      v-if="media.tags && media.tags.length > 0"
      class="absolute inset-0 bg-linear-to-t from-black via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-200"
    >
      <div class="absolute bottom-0 left-0 right-0 p-3 text-white">
        <div class="flex flex-wrap gap-1">
          <span
            v-for="tag in media.tags.slice(0, 3)"
            :key="tag.id"
            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
            :style="{ backgroundColor: tag.color || '#0D9488' }"
          >
            {{ tag.name }}
          </span>
          <span
            v-if="media.tags.length > 3"
            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-700 text-surface-200"
          >
            +{{ media.tags.length - 3 }}
          </span>
        </div>
      </div>
    </div>

    <!-- Selection Checkbox (if selectable) -->
    <div
      v-if="selectable"
      class="absolute top-2 right-2 z-10"
      @click.stop="$emit('toggle-selection', media, $event)"
    >
      <div
        :class="[
          'w-6 h-6 rounded-md border-2 flex items-center justify-center transition-all',
          isSelected
            ? 'bg-brand-600 border-brand-600'
            : 'bg-white/80 border-white hover:bg-white/100'
        ]"
      >
        <svg
          v-if="isSelected"
          class="w-4 h-4 text-white"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="3"
            d="M5 13l4 4L19 7"
          />
        </svg>
      </div>
    </div>

    <!-- Media Type Badge -->
    <div class="absolute top-2 left-2">
      <span
        v-if="media.type === 'video' && media.duration"
        class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-violet-100 dark:bg-violet-500/15 text-violet-700 dark:text-violet-300"
      >
        {{ formattedDuration }}
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { formatDuration } from '@/utils/format';
import { thumbnailUrl as mediaThumbnailUrl } from '@/utils/media';

const props = defineProps({
  media: {
    type: Object,
    required: true,
  },
  selectable: {
    type: Boolean,
    default: false,
  },
  isSelected: {
    type: Boolean,
    default: false,
  },
  // Mode mosaïque : la tuile remplit sa cellule de grille (spans variables)
  // au lieu d'être carrée ; coins quasi droits, pas de zoom au survol.
  fill: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['click', 'toggle-selection']);

// En mosaïque (fill), les cellules 2x2 dépassent la conversion `small` :
// on préfère `medium` pour rester net.
const thumbnailUrl = computed(() => {
  if (props.fill) {
    const medium = props.media.conversions?.find((c) => c.conversion_name === 'medium');
    if (medium?.url) return medium.url;
  }
  return mediaThumbnailUrl(props.media);
});

const fileExtension = computed(() => {
  if (!props.media.original_name) return '';
  const parts = props.media.original_name.split('.');
  return parts.length > 1 ? parts.pop().toUpperCase() : '';
});

const formattedDuration = computed(() => formatDuration(props.media.duration));
</script>
