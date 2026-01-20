<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
          <h1 class="text-3xl font-bold text-gray-900">Télécharger des médias</h1>
          <p class="mt-2 text-gray-600">
            Ajoutez vos photos, vidéos et documents à votre bibliothèque familiale.
          </p>
        </div>

        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <MediaUploader @upload-complete="handleUploadComplete" />
          </div>
        </div>

        <div v-if="recentUploads.length > 0" class="mt-6 bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-xl font-semibold text-gray-900">Médias téléchargés</h2>
              <Link
                href="/media"
                class="text-indigo-600 hover:text-indigo-700 text-sm font-medium"
              >
                Voir la galerie →
              </Link>
            </div>
            <div class="space-y-2">
              <div
                v-for="media in recentUploads"
                :key="media.id"
                class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
              >
                <div class="flex items-center space-x-3 flex-1 min-w-0">
                  <div class="flex-shrink-0">
                    <div v-if="media.type === 'photo'" class="w-12 h-12 rounded overflow-hidden bg-gray-200">
                      <img
                        :src="media.url"
                        :alt="media.original_filename"
                        class="w-full h-full object-cover"
                      />
                    </div>
                    <div v-else-if="media.type === 'video'" class="w-12 h-12 rounded bg-purple-100 flex items-center justify-center">
                      <svg class="h-6 w-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                      </svg>
                    </div>
                    <div v-else class="w-12 h-12 rounded bg-gray-100 flex items-center justify-center">
                      <svg class="h-6 w-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                      </svg>
                    </div>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">
                      {{ media.original_filename }}
                    </p>
                    <p class="text-xs text-gray-500">
                      {{ formatFileSize(media.size) }} · {{ formatMediaType(media.type) }}
                    </p>
                  </div>
                </div>
                <div class="flex items-center space-x-2">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    Téléchargé
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div class="ml-3">
              <h3 class="text-sm font-medium text-blue-800">À propos du traitement des médias</h3>
              <div class="mt-2 text-sm text-blue-700">
                <p>
                  Vos médias sont traités automatiquement en arrière-plan :
                </p>
                <ul class="list-disc list-inside mt-1 space-y-1">
                  <li>Extraction des données EXIF (date, lieu, appareil photo)</li>
                  <li>Génération de miniatures et versions optimisées</li>
                  <li>Extraction des informations de géolocalisation</li>
                </ul>
                <p class="mt-2">
                  Ce traitement peut prendre quelques minutes. Vous pouvez continuer à utiliser l'application.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import MediaUploader from '@/Components/MediaUploader.vue';

const recentUploads = ref([]);

const handleUploadComplete = (uploadedMedia) => {
  // Add newly uploaded media to recent uploads list
  recentUploads.value.unshift(...uploadedMedia);

  // Keep only the last 10 uploads for display
  if (recentUploads.value.length > 10) {
    recentUploads.value = recentUploads.value.slice(0, 10);
  }
};

const formatFileSize = (bytes) => {
  if (!bytes) return '0 Bytes';
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatMediaType = (type) => {
  const types = {
    photo: 'Photo',
    video: 'Vidéo',
    document: 'Document'
  };
  return types[type] || type;
};
</script>
