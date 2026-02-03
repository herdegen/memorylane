<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
          <div>
            <h1 class="text-2xl font-bold text-gray-900">Mes Albums</h1>
            <p class="mt-1 text-sm text-gray-500">Organisez vos photos et videos en albums</p>
          </div>
          <button
            @click="showCreateModal = true"
            class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Creer un album
          </button>
        </div>

        <!-- Albums Grid -->
        <div
          v-if="albums.length > 0"
          class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"
        >
          <AlbumCard
            v-for="album in albums"
            :key="album.id"
            :album="album"
            @click="goToAlbum(album)"
          />
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
              d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
            />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-gray-900">Aucun album</h3>
          <p class="mt-2 text-gray-500">Creez votre premier album pour organiser vos medias.</p>
          <button
            @click="showCreateModal = true"
            class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Creer un album
          </button>
        </div>

        <!-- Create Modal -->
        <AlbumFormModal
          v-if="showCreateModal"
          @close="showCreateModal = false"
          @saved="handleAlbumCreated"
        />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AlbumCard from '@/Components/AlbumCard.vue';
import AlbumFormModal from '@/Components/AlbumFormModal.vue';

defineProps({
  albums: {
    type: Array,
    default: () => [],
  },
});

const showCreateModal = ref(false);

const goToAlbum = (album) => {
  router.visit(`/albums/${album.id}`);
};

const handleAlbumCreated = () => {
  router.reload();
};
</script>
