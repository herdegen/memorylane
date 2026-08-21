<template>
  <Head title="Albums" />
  <AppLayout>
    <div class="py-9 px-4 sm:px-8">
      <!-- En-tête -->
      <div class="flex flex-wrap items-end justify-between gap-4 mb-5">
        <div>
          <h1 class="font-display text-4xl font-bold text-surface-900">Albums</h1>
          <p class="mt-1.5 text-sm text-surface-500">
            {{ albums.length }} {{ albums.length === 1 ? 'album' : 'albums' }} — les souvenirs, rangés par histoire
          </p>
        </div>
        <button @click="showCreateModal = true" class="btn-primary">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Nouvel album
        </button>
      </div>

      <!-- Filtres (fusionne « Partagés avec moi » dans la page) -->
      <div class="flex flex-wrap gap-2 mb-6">
        <button
          v-for="f in filters"
          :key="f.value"
          type="button"
          class="px-4 py-1.5 rounded-full text-[13px] font-medium transition"
          :class="filter === f.value
            ? 'bg-brand-600 text-white font-semibold'
            : 'bg-white border border-surface-300 text-surface-600 hover:bg-surface-50'"
          @click="filter = f.value"
        >
          {{ f.label }}
          <span v-if="f.count > 0" class="opacity-70">· {{ f.count }}</span>
        </button>
      </div>

      <!-- Grille -->
      <div
        v-if="filteredAlbums.length > 0"
        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4"
      >
        <AlbumCard
          v-for="album in filteredAlbums"
          :key="album.id"
          :album="album"
          :owner-name="album.is_owner === false ? album.user?.name : null"
          @click="goToAlbum(album)"
        />
      </div>

      <!-- Aucun résultat pour ce filtre -->
      <div v-else-if="albums.length > 0" class="text-center py-16 bg-white rounded-lg shadow-xs">
        <p class="text-surface-500">Aucun album ne correspond à ce filtre.</p>
        <button @click="filter = 'all'" class="mt-3 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline">
          Voir tous les albums
        </button>
      </div>

      <!-- Empty State -->
      <div v-else class="text-center py-16 bg-white rounded-lg shadow-xs">
        <svg class="mx-auto h-16 w-16 text-surface-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
          />
        </svg>
        <h3 class="mt-4 text-lg font-medium text-surface-900">Aucun album</h3>
        <p class="mt-2 text-surface-500">Créez votre premier album pour organiser vos médias.</p>
        <button
          @click="showCreateModal = true"
          class="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-brand-600 bg-brand-50 dark:bg-brand-500/10 rounded-lg hover:bg-brand-100 dark:hover:bg-brand-500/20"
        >
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Créer un album
        </button>
      </div>

      <!-- Create Modal -->
      <AlbumFormModal
        v-if="showCreateModal"
        @close="showCreateModal = false"
        @saved="handleAlbumCreated"
      />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AlbumCard from '@/Components/AlbumCard.vue';
import AlbumFormModal from '@/Components/AlbumFormModal.vue';

const props = defineProps({
  albums: {
    type: Array,
    default: () => [],
  },
});

const showCreateModal = ref(false);

// Filtres par visibilité/appartenance — « Partagés avec moi » vit ici
// plutôt que sur une page à part.
const filter = ref('all');

const matchers = {
  all: () => true,
  mine: (a) => a.is_owner !== false,
  shared: (a) => a.is_owner === false,
  public: (a) => !!a.is_public,
  smart: (a) => !!a.is_smart,
};

const filters = computed(() => [
  { value: 'all', label: 'Tous', count: props.albums.length },
  { value: 'mine', label: 'Les miens', count: props.albums.filter(matchers.mine).length },
  { value: 'shared', label: 'Partagés avec moi', count: props.albums.filter(matchers.shared).length },
  { value: 'public', label: 'Publics', count: props.albums.filter(matchers.public).length },
  { value: 'smart', label: 'Intelligents', count: props.albums.filter(matchers.smart).length },
]);

const filteredAlbums = computed(() => props.albums.filter(matchers[filter.value] || matchers.all));

const goToAlbum = (album) => {
  router.visit(`/albums/${album.id}`);
};

const handleAlbumCreated = () => {
  router.reload();
};
</script>
