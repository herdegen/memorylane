<template>
  <div
    class="relative group rounded-card overflow-hidden bg-white border border-surface-200 shadow-warm-sm cursor-pointer transition-all duration-200 hover:shadow-warm-md hover:border-brand-200 hover:scale-[1.01]"
    @click="$emit('click', album)"
  >
    <!-- Cover Image -->
    <div class="aspect-video bg-surface-100">
      <img
        v-if="album.cover_url"
        :src="album.cover_url"
        :alt="album.name"
        class="w-full h-full object-cover"
        loading="lazy"
      />
      <div
        v-else
        class="w-full h-full flex items-center justify-center bg-linear-to-br from-brand-50 to-surface-100"
      >
        <svg
          class="h-16 w-16 text-brand-300"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
          />
        </svg>
      </div>
    </div>

    <!-- Album Info -->
    <div class="p-4">
      <div class="flex items-start justify-between">
        <div class="flex-1 min-w-0">
          <h3 class="font-display text-lg font-semibold text-surface-900 truncate">
            {{ album.name }}
          </h3>
          <p v-if="ownerName" class="mt-0.5 text-xs text-surface-500">Partagé par {{ ownerName }}</p>
          <p v-if="album.description" class="mt-1 text-sm text-surface-500 line-clamp-2">
            {{ album.description }}
          </p>
        </div>
      </div>

      <div class="mt-3 flex items-center justify-between">
        <span class="text-sm text-surface-500">
          {{ album.media_count || 0 }} {{ album.media_count === 1 ? 'média' : 'médias' }}
        </span>

        <span class="flex items-center gap-1.5">
        <!-- Smart Badge -->
        <span
          v-if="album.is_smart"
          class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-violet-100 dark:bg-violet-500/15 text-violet-700 dark:text-violet-300"
          title="Album intelligent : se remplit automatiquement"
        >
          <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
          </svg>
          Auto
        </span>

        <!-- Visibility Badge : Public / Partagé / Privé -->
        <span
          :class="['inline-flex items-center px-2 py-1 rounded-full text-xs font-medium', visibility.class]"
        >
          {{ visibility.label }}
        </span>
        </span>
      </div>
    </div>

    <!-- Share indicator -->
    <div
      v-if="album.share_token"
      class="absolute top-2 right-2"
    >
      <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-brand-100 text-brand-700">
        <svg
          class="w-3 h-3 mr-1"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"
          />
        </svg>
        Partagé
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  album: {
    type: Object,
    required: true,
  },
  // Affiché sur la page « Partagés avec moi ».
  ownerName: {
    type: String,
    default: null,
  },
});

defineEmits(['click']);

const visibility = computed(() => {
  if (props.album.is_public) {
    return { label: 'Public', class: 'bg-teal-100 dark:bg-teal-500/15 text-teal-700 dark:text-teal-300' };
  }
  if ((props.album.accesses_count || 0) > 0) {
    return { label: 'Partagé', class: 'bg-amber-100 dark:bg-amber-500/15 text-amber-700 dark:text-amber-300' };
  }
  return { label: 'Privé', class: 'bg-surface-100 text-surface-600' };
});
</script>
