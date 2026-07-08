<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-8">
          <h1 class="text-display text-4xl text-surface-900">Partagés avec moi</h1>
          <p class="mt-1 text-sm text-surface-500">Albums que d'autres membres ont partagés avec vous</p>
        </div>

        <div
          v-if="albums.length > 0"
          class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"
        >
          <AlbumCard
            v-for="album in albums"
            :key="album.id"
            :album="album"
            :owner-name="album.user?.name"
            @click="goToAlbum(album)"
          />
        </div>

        <div v-else class="text-center py-16 bg-white rounded-lg shadow-xs">
          <svg class="mx-auto h-16 w-16 text-surface-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684z" />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-surface-900">Rien pour l'instant</h3>
          <p class="mt-2 text-surface-500">Aucun album ne vous a encore été partagé.</p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AlbumCard from '@/Components/AlbumCard.vue';

defineProps({
  albums: {
    type: Array,
    default: () => [],
  },
});

const goToAlbum = (album) => {
  router.visit(`/albums/${album.id}`);
};
</script>
