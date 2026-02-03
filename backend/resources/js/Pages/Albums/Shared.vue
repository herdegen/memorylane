<template>
  <div class="min-h-screen bg-gray-100">
    <!-- Simple Header -->
    <nav class="bg-white shadow-sm">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center">
            <span class="text-xl font-bold text-gray-900">MemoryLane</span>
          </div>
          <div class="flex items-center">
            <a
              href="/login"
              class="inline-flex items-center px-4 py-2 text-sm font-medium text-indigo-600 hover:text-indigo-800"
            >
              Connexion
            </a>
          </div>
        </div>
      </div>
    </nav>

    <main class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Album Header -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
          <h1 class="text-2xl font-bold text-gray-900">{{ album.name }}</h1>
          <p v-if="album.description" class="mt-2 text-gray-600">{{ album.description }}</p>
          <div class="mt-3 flex items-center gap-4 text-sm text-gray-500">
            <span>{{ album.media_count || 0 }} medias</span>
            <span v-if="album.user">Partage par {{ album.user.name }}</span>
          </div>
        </div>

        <!-- Media Grid -->
        <div v-if="album.media && album.media.length > 0">
          <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            <div
              v-for="media in album.media"
              :key="media.id"
              class="relative aspect-square rounded-lg overflow-hidden bg-gray-100 cursor-pointer group"
              @click="openLightbox(media)"
            >
              <!-- Photo/Video Thumbnail -->
              <img
                v-if="getThumbnailUrl(media)"
                :src="getThumbnailUrl(media)"
                :alt="media.original_name"
                class="w-full h-full object-cover transition-transform duration-200 group-hover:scale-105"
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
                    d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                  />
                </svg>
              </div>

              <!-- Video indicator -->
              <div
                v-if="media.type === 'video'"
                class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-20"
              >
                <svg
                  class="h-12 w-12 text-white opacity-80"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path d="M8 5v14l11-7z" />
                </svg>
              </div>

              <!-- Hover overlay -->
              <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-all duration-200 flex items-center justify-center">
                <svg
                  class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 transition-opacity"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"
                  />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div
          v-else
          class="text-center py-16 bg-white rounded-lg shadow-sm"
        >
          <svg
            class="mx-auto h-16 w-16 text-gray-300"
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
          <h3 class="mt-4 text-lg font-medium text-gray-900">Cet album est vide</h3>
        </div>
      </div>
    </main>

    <!-- Simple Lightbox -->
    <div
      v-if="lightboxMedia"
      class="fixed inset-0 z-50 bg-black bg-opacity-90 flex items-center justify-center"
      @click="closeLightbox"
    >
      <button
        class="absolute top-4 right-4 text-white hover:text-gray-300"
        @click="closeLightbox"
      >
        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>

      <img
        v-if="lightboxMedia.type === 'photo'"
        :src="lightboxMedia.url"
        :alt="lightboxMedia.original_name"
        class="max-w-full max-h-full object-contain"
        @click.stop
      />

      <video
        v-else-if="lightboxMedia.type === 'video'"
        :src="lightboxMedia.url"
        controls
        class="max-w-full max-h-full"
        @click.stop
      />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
  album: {
    type: Object,
    required: true,
  },
});

const lightboxMedia = ref(null);

const getThumbnailUrl = (media) => {
  if (media.conversions && media.conversions.length > 0) {
    const thumbnail = media.conversions.find(
      (conv) => conv.conversion_name === 'small' || conv.conversion_name === 'thumbnail'
    );
    if (thumbnail && thumbnail.url) {
      return thumbnail.url;
    }
  }
  return media.url || null;
};

const openLightbox = (media) => {
  lightboxMedia.value = media;
};

const closeLightbox = () => {
  lightboxMedia.value = null;
};
</script>
