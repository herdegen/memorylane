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

      <!-- Grille : albums du cercle courant -->
      <h2 v-if="hereAlbums.length && elsewhereAlbums.length && current" class="text-sm font-semibold uppercase tracking-wider text-surface-500 mb-3">
        Albums de {{ current.name }}
      </h2>
      <div
        v-if="hereAlbums.length > 0"
        data-tour="albums"
        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4"
      >
        <AlbumCard
          v-for="album in hereAlbums"
          :key="album.id"
          :album="album"
          :owner-name="album.is_owner === false ? album.user?.name : null"
          @click="goToAlbum(album)"
        />
      </div>

      <!-- Ailleurs : albums d'autres cercles, mis à part et grisés -->
      <template v-if="elsewhereAlbums.length > 0">
        <div class="mt-10 mb-3">
          <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-500">Ailleurs</h2>
          <p class="text-xs text-surface-500 mt-1">
            {{ current ? `Pas encore partagés avec ${current.name} : un clic les y ajoute.` : 'Albums partagés avec d\'autres cercles.' }}
          </p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          <div v-for="album in elsewhereAlbums" :key="album.id" class="flex flex-col gap-2">
            <div class="opacity-60 hover:opacity-100 transition-opacity">
              <AlbumCard
                :album="album"
                :owner-name="album.is_owner === false ? album.user?.name : null"
                @click="goToAlbum(album)"
              />
            </div>
            <button
              v-if="current && album.is_owner"
              type="button"
              class="self-start inline-flex items-center gap-1.5 h-8 px-3 rounded-full text-xs font-semibold border border-dashed border-surface-300 text-surface-700 hover:border-brand-400 hover:text-brand-700"
              :disabled="sharing === album.id"
              @click="shareWithCurrent(album)"
            >
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
              Partager avec {{ current.name }}
            </button>
          </div>
        </div>
      </template>

      <!-- Aucun résultat pour ce filtre -->
      <div v-if="filteredAlbums.length === 0 && albums.length > 0" class="text-center py-16 bg-white rounded-lg shadow-xs">
        <p class="text-surface-500">Aucun album ne correspond à ce filtre.</p>
        <button @click="filter = 'all'" class="mt-3 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline">
          Voir tous les albums
        </button>
      </div>

      <!-- Empty State -->
      <div v-if="albums.length === 0" class="text-center py-16 bg-white rounded-lg shadow-xs">
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
import axios from 'axios';
import { useCircle } from '@/Composables/useCircle';
import { useToast } from '@/Composables/useToast';

const toast = useToast();

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
  circles: (a) => (a.households_count || 0) > 0,
  smart: (a) => !!a.is_smart,
};

const filters = computed(() => [
  { value: 'all', label: 'Tous', count: props.albums.length },
  { value: 'mine', label: 'Les miens', count: props.albums.filter(matchers.mine).length },
  { value: 'shared', label: 'Partagés avec moi', count: props.albums.filter(matchers.shared).length },
  { value: 'circles', label: 'Partagés avec un cercle', count: props.albums.filter(matchers.circles).length },
  { value: 'smart', label: 'Intelligents', count: props.albums.filter(matchers.smart).length },
]);

const filteredAlbums = computed(() => props.albums.filter(matchers[filter.value] || matchers.all));

// Rangement par cercle courant : ses albums d'abord, les autres à part.
const { current } = useCircle();
const hereAlbums = computed(() => filteredAlbums.value.filter((a) => a.in_context !== false));
const elsewhereAlbums = computed(() => filteredAlbums.value.filter((a) => a.in_context === false));

const sharing = ref(null);
const shareWithCurrent = async (album) => {
  sharing.value = album.id;
  try {
    await axios.post(`/albums/${album.id}/circles`, { household_id: current.value.id });
    router.reload({ only: ['albums'], preserveScroll: true });
  } catch (e) {
    toast.error(e.response?.data?.message || 'Impossible de partager cet album.');
  } finally {
    sharing.value = null;
  }
};

const goToAlbum = (album) => {
  router.visit(`/albums/${album.id}`);
};

const handleAlbumCreated = () => {
  router.reload();
};
</script>
