<template>
  <div
    class="relative group aspect-square rounded-lg overflow-hidden bg-gray-100 cursor-pointer transition-transform duration-200 hover:scale-105 hover:shadow-lg"
    @click="$emit('click', media)"
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
        class="w-full h-full flex items-center justify-center bg-gray-200"
      >
        <svg
          class="h-12 w-12 text-gray-400"
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
        class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-20"
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
      class="w-full h-full flex flex-col items-center justify-center bg-gray-200"
    >
      <svg
        class="h-12 w-12 text-gray-400"
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
      <span class="mt-2 text-xs text-gray-500 truncate max-w-full px-2">
        {{ fileExtension }}
      </span>
    </div>

    <!-- Hover Overlay with Info -->
    <div
      class="absolute inset-0 bg-gradient-to-t from-black via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-200"
    >
      <div class="absolute bottom-0 left-0 right-0 p-3 text-white">
        <p class="text-xs font-medium truncate">
          {{ media.original_name }}
        </p>
        <p class="text-xs text-gray-300 mt-1">
          {{ formattedDate }}
        </p>
      </div>
    </div>

    <!-- Selection Checkbox (if selectable) -->
    <div
      v-if="selectable"
      class="absolute top-2 right-2 z-10"
      @click.stop="$emit('toggle-selection', media)"
    >
      <div
        :class="[
          'w-6 h-6 rounded-md border-2 flex items-center justify-center transition-all',
          isSelected
            ? 'bg-indigo-600 border-indigo-600'
            : 'bg-white bg-opacity-80 border-white hover:bg-opacity-100'
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
        class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-purple-100 text-purple-800"
      >
        {{ formattedDuration }}
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

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
});

const emit = defineEmits(['click', 'toggle-selection']);

const thumbnailUrl = computed(() => {
  // Try to get the small or thumbnail conversion
  if (props.media.conversions && props.media.conversions.length > 0) {
    const thumbnail = props.media.conversions.find(
      (conv) => conv.conversion_name === 'small' || conv.conversion_name === 'thumbnail'
    );
    if (thumbnail && thumbnail.url) {
      return thumbnail.url;
    }
  }
  // Fallback to original URL if available
  return props.media.url || null;
});

const fileExtension = computed(() => {
  if (!props.media.original_name) return '';
  const parts = props.media.original_name.split('.');
  return parts.length > 1 ? parts.pop().toUpperCase() : '';
});

const formattedDate = computed(() => {
  const dateString = props.media.taken_at || props.media.uploaded_at;
  if (!dateString) return '';

  const date = new Date(dateString);
  const now = new Date();
  const diffTime = Math.abs(now - date);
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

  if (diffDays === 0) {
    return "Aujourd'hui";
  } else if (diffDays === 1) {
    return 'Hier';
  } else if (diffDays < 7) {
    return `Il y a ${diffDays} jours`;
  } else {
    return date.toLocaleDateString('fr-FR', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  }
});

const formattedDuration = computed(() => {
  const seconds = props.media.duration;
  if (!seconds) return '';

  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);

  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  }
  return `${minutes}:${secs.toString().padStart(2, '0')}`;
});
</script>
