<template>
  <div
    class="relative group rounded-lg overflow-hidden bg-white shadow-md cursor-pointer transition-all duration-200 hover:shadow-xl hover:scale-[1.02]"
    @click="$emit('click', album)"
  >
    <!-- Cover Image -->
    <div class="aspect-video bg-gray-100">
      <img
        v-if="album.cover_url"
        :src="album.cover_url"
        :alt="album.name"
        class="w-full h-full object-cover"
        loading="lazy"
      />
      <div
        v-else
        class="w-full h-full flex items-center justify-center bg-gradient-to-br from-indigo-100 to-purple-100"
      >
        <svg
          class="h-16 w-16 text-indigo-300"
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
          <h3 class="text-lg font-semibold text-gray-900 truncate">
            {{ album.name }}
          </h3>
          <p v-if="album.description" class="mt-1 text-sm text-gray-500 line-clamp-2">
            {{ album.description }}
          </p>
        </div>
      </div>

      <div class="mt-3 flex items-center justify-between">
        <span class="text-sm text-gray-500">
          {{ album.media_count || 0 }} {{ album.media_count === 1 ? 'media' : 'medias' }}
        </span>

        <!-- Visibility Badge -->
        <span
          :class="[
            'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
            album.is_public
              ? 'bg-green-100 text-green-800'
              : 'bg-gray-100 text-gray-600'
          ]"
        >
          <svg
            v-if="album.is_public"
            class="w-3 h-3 mr-1"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
          </svg>
          <svg
            v-else
            class="w-3 h-3 mr-1"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
            />
          </svg>
          {{ album.is_public ? 'Public' : 'Prive' }}
        </span>
      </div>
    </div>

    <!-- Share indicator -->
    <div
      v-if="album.share_token"
      class="absolute top-2 right-2"
    >
      <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
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
        Partage
      </span>
    </div>
  </div>
</template>

<script setup>
defineProps({
  album: {
    type: Object,
    required: true,
  },
});

defineEmits(['click']);
</script>
