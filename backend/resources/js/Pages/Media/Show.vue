<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Back button -->
        <div class="mb-6">
          <Link
            href="/media"
            class="inline-flex items-center text-sm text-surface-600 hover:text-surface-900 transition"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour à la galerie
          </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Media preview (left side - 2 columns) -->
          <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
              <!-- Image with face detection overlay -->
              <div v-if="media.type === 'photo'" class="relative bg-black">
                <FaceDetectionOverlay
                  :image-url="media.url"
                  :alt="media.original_name"
                  :faces="media.detected_faces || []"
                  @face-click="handleFaceClick"
                />
              </div>

              <!-- Video -->
              <div v-else-if="media.type === 'video'" class="relative bg-black">
                <video
                  :src="media.url"
                  controls
                  class="w-full h-auto max-h-[70vh] mx-auto"
                >
                  Votre navigateur ne supporte pas la lecture de vidéos.
                </video>
              </div>

              <!-- Document -->
              <div v-else class="flex flex-col items-center justify-center p-12 bg-surface-50">
                <svg class="h-24 w-24 text-surface-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
                <h3 class="text-lg font-medium text-surface-900 mb-2">{{ media.original_name }}</h3>
                <p class="text-sm text-surface-500 mb-4">Document ({{ formatFileSize(media.size) }})</p>
                <a
                  :href="`/media/${media.id}/download`"
                  class="inline-flex items-center px-4 py-2 bg-brand-600 text-white rounded-lg hover:bg-brand-700 transition"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  Télécharger
                </a>
              </div>
            </div>
          </div>

          <!-- Media info (right side - 1 column) -->
          <div class="space-y-6">
            <!-- Basic info with editor -->
            <MediaInfoEditor :media="media" @updated="handleMediaUpdated" />

            <!-- Tags -->
            <div class="bg-white rounded-lg shadow-sm p-6">
              <h2 class="text-lg font-semibold text-surface-900 mb-4">Tags</h2>
              <TagInput :media-id="media.id" :initial-tags="media.tags || []" @tags-updated="handleTagsUpdated" />
            </div>

            <!-- Vision AI Status -->
            <VisionStatusBadge
              v-if="media.type === 'photo'"
              :media-id="media.id"
              :initial-status="media.metadata?.vision_status"
              :initial-faces-count="media.metadata?.vision_faces_count || 0"
              :initial-error="media.metadata?.vision_error"
              @analysis-complete="handleAnalysisComplete"
            />

            <!-- Face Match Panel -->
            <FaceMatchPanel
              v-if="selectedFace"
              :face="selectedFace"
              :media-id="media.id"
              @matched="handleFaceMatched"
              @dismissed="handleFaceDismissed"
              @close="selectedFace = null"
            />

            <!-- Vision AI Labels -->
            <VisionLabels
              v-if="media.type === 'photo' && media.metadata?.vision_labels"
              :labels="media.metadata.vision_labels"
            />

            <!-- People -->
            <PersonInput
              :media-id="media.id"
              :initial-people="media.people || []"
              @people-updated="handlePeopleUpdated"
            />

            <!-- Geolocation -->
            <GeolocationEditor
              :media-id="media.id"
              :initial-latitude="media.metadata?.latitude"
              :initial-longitude="media.metadata?.longitude"
              :initial-altitude="media.metadata?.altitude"
              @updated="handleGeolocationUpdated"
              @removed="handleGeolocationRemoved"
            />

            <!-- Actions -->
            <div class="bg-white rounded-lg shadow-sm p-6">
              <h2 class="text-lg font-semibold text-surface-900 mb-4">Actions</h2>
              <div class="space-y-2">
                <a
                  :href="`/media/${media.id}/download`"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-surface-300 rounded-lg text-sm font-medium text-surface-700 bg-white hover:bg-surface-50 transition"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  Télécharger
                </a>

                <button
                  @click="deleteMedia"
                  class="w-full inline-flex items-center justify-center px-4 py-2 border border-red-300 rounded-lg text-sm font-medium text-red-700 bg-white hover:bg-red-50 transition"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                  Supprimer
                </button>
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
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TagInput from '@/Components/TagInput.vue';
import PersonInput from '@/Components/PersonInput.vue';
import GeolocationEditor from '@/Components/GeolocationEditor.vue';
import MediaInfoEditor from '@/Components/MediaInfoEditor.vue';
import FaceDetectionOverlay from '@/Components/FaceDetectionOverlay.vue';
import FaceMatchPanel from '@/Components/FaceMatchPanel.vue';
import VisionStatusBadge from '@/Components/VisionStatusBadge.vue';
import VisionLabels from '@/Components/VisionLabels.vue';
import axios from 'axios';

const props = defineProps({
  media: {
    type: Object,
    required: true,
  },
});

const selectedFace = ref(null);

const handleMediaUpdated = (updatedMedia) => {
  router.reload();
};

const handleTagsUpdated = (tags) => {
  console.log('Tags updated:', tags);
};

const handleGeolocationUpdated = (metadata) => {
  console.log('Geolocation updated:', metadata);
};

const handleGeolocationRemoved = () => {
  console.log('Geolocation removed');
};

const handlePeopleUpdated = (people) => {
  console.log('People updated:', people);
};

const handleFaceClick = (face) => {
  if (face.status === 'unmatched') {
    selectedFace.value = face;
  }
};

const handleFaceMatched = () => {
  selectedFace.value = null;
  router.reload();
};

const handleFaceDismissed = () => {
  selectedFace.value = null;
  router.reload();
};

const handleAnalysisComplete = () => {
  router.reload();
};

const deleteMedia = async () => {
  if (!confirm('Êtes-vous sûr de vouloir supprimer ce média ? Cette action est irréversible.')) {
    return;
  }

  try {
    await axios.delete(`/media/${props.media.id}`);
    router.visit('/media');
  } catch (error) {
    alert('Erreur lors de la suppression: ' + (error.response?.data?.message || error.message));
  }
};

const formatType = (type) => {
  const types = {
    photo: 'Photo',
    video: 'Vidéo',
    document: 'Document',
  };
  return types[type] || type;
};

const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatDuration = (seconds) => {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);

  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  }
  return `${minutes}:${secs.toString().padStart(2, '0')}`;
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};
</script>
