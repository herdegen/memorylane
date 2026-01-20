<template>
  <div class="media-uploader">
    <div class="upload-area">
      <div
        ref="dropZone"
        class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-indigo-400 transition-colors duration-200"
        :class="{ 'border-indigo-500 bg-indigo-50': isDragging }"
        @dragover.prevent="handleDragOver"
        @dragleave="handleDragLeave"
        @drop.prevent="handleDrop"
      >
        <div v-if="!uploading && files.length === 0" class="space-y-4">
          <svg
            class="mx-auto h-12 w-12 text-gray-400"
            stroke="currentColor"
            fill="none"
            viewBox="0 0 48 48"
            aria-hidden="true"
          >
            <path
              d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <div>
            <label
              for="file-upload"
              class="cursor-pointer text-indigo-600 hover:text-indigo-500 font-medium"
            >
              Choisir des fichiers
            </label>
            <span class="text-gray-600"> ou glisser-déposer</span>
            <input
              id="file-upload"
              ref="fileInput"
              type="file"
              class="sr-only"
              multiple
              accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/x-msvideo,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
              @change="handleFileSelect"
            />
          </div>
          <p class="text-xs text-gray-500">
            JPG, PNG, GIF, WEBP, MP4, MOV, AVI, PDF, DOC, DOCX jusqu'à 2GB
          </p>
        </div>

        <div v-else class="space-y-4">
          <div v-if="uploading" class="space-y-2">
            <div class="flex items-center justify-center space-x-2">
              <svg
                class="animate-spin h-5 w-5 text-indigo-600"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  class="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  stroke-width="4"
                ></circle>
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
              </svg>
              <span class="text-gray-700 font-medium">Téléchargement en cours...</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2">
              <div
                class="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                :style="{ width: `${uploadProgress}%` }"
              ></div>
            </div>
            <p class="text-sm text-gray-600">
              {{ uploadedCount }} / {{ totalFiles }} fichier(s) téléchargé(s)
            </p>
          </div>

          <div v-else class="space-y-3">
            <div
              v-for="file in files"
              :key="file.id"
              class="flex items-center justify-between p-3 bg-white rounded-lg border border-gray-200"
            >
              <div class="flex items-center space-x-3 flex-1 min-w-0">
                <div class="flex-shrink-0">
                  <svg
                    v-if="isImage(file.type)"
                    class="h-6 w-6 text-blue-500"
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
                  <svg
                    v-else-if="isVideo(file.type)"
                    class="h-6 w-6 text-purple-500"
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
                  <svg
                    v-else
                    class="h-6 w-6 text-gray-500"
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
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-900 truncate">
                    {{ file.name }}
                  </p>
                  <p class="text-xs text-gray-500">{{ formatFileSize(file.size) }}</p>
                </div>
              </div>
              <button
                type="button"
                class="ml-3 text-red-600 hover:text-red-800"
                @click="removeFile(file.id)"
              >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </button>
            </div>

            <div class="flex space-x-3 pt-2">
              <button
                type="button"
                class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium"
                @click="startUpload"
              >
                Télécharger {{ files.length }} fichier(s)
              </button>
              <button
                type="button"
                class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors"
                @click="clearFiles"
              >
                Annuler
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="error" class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
        <div class="flex">
          <svg
            class="h-5 w-5 text-red-400"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
          </svg>
          <div class="ml-3">
            <p class="text-sm text-red-700">{{ error }}</p>
          </div>
        </div>
      </div>

      <div v-if="uploadedMedia.length > 0" class="mt-6">
        <h3 class="text-lg font-medium text-gray-900 mb-3">
          Fichiers téléchargés avec succès
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
          <div
            v-for="media in uploadedMedia"
            :key="media.id"
            class="relative group aspect-square rounded-lg overflow-hidden bg-gray-100"
          >
            <img
              v-if="media.type === 'photo'"
              :src="media.url"
              :alt="media.original_filename"
              class="w-full h-full object-cover"
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
                  d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                />
              </svg>
            </div>
            <div
              class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-opacity flex items-center justify-center"
            >
              <svg
                class="h-8 w-8 text-white opacity-0 group-hover:opacity-100 transition-opacity"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                />
              </svg>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const emit = defineEmits(['upload-complete']);

const fileInput = ref(null);
const dropZone = ref(null);
const isDragging = ref(false);
const files = ref([]);
const uploading = ref(false);
const uploadProgress = ref(0);
const uploadedCount = ref(0);
const totalFiles = ref(0);
const error = ref(null);
const uploadedMedia = ref([]);

let fileIdCounter = 0;

const isImage = (mimeType) => {
  return mimeType.startsWith('image/');
};

const isVideo = (mimeType) => {
  return mimeType.startsWith('video/');
};

const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const handleDragOver = (e) => {
  isDragging.value = true;
};

const handleDragLeave = (e) => {
  isDragging.value = false;
};

const handleDrop = (e) => {
  isDragging.value = false;
  const droppedFiles = Array.from(e.dataTransfer.files);
  addFiles(droppedFiles);
};

const handleFileSelect = (e) => {
  const selectedFiles = Array.from(e.target.files);
  addFiles(selectedFiles);
};

const addFiles = (newFiles) => {
  error.value = null;

  const validFiles = newFiles.filter(file => {
    // Check file size (2GB max)
    if (file.size > 2097152000) {
      error.value = `Le fichier "${file.name}" dépasse la taille maximale de 2GB`;
      return false;
    }

    // Check file type
    const allowedTypes = [
      'image/jpeg',
      'image/png',
      'image/gif',
      'image/webp',
      'video/mp4',
      'video/quicktime',
      'video/x-msvideo',
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    if (!allowedTypes.includes(file.type)) {
      error.value = `Le type de fichier "${file.name}" n'est pas supporté`;
      return false;
    }

    return true;
  });

  const filesWithIds = validFiles.map(file => ({
    id: ++fileIdCounter,
    file: file,
    name: file.name,
    size: file.size,
    type: file.type
  }));

  files.value.push(...filesWithIds);
};

const removeFile = (id) => {
  files.value = files.value.filter(f => f.id !== id);
};

const clearFiles = () => {
  files.value = [];
  error.value = null;
  if (fileInput.value) {
    fileInput.value.value = '';
  }
};

const startUpload = async () => {
  if (files.value.length === 0) return;

  uploading.value = true;
  uploadProgress.value = 0;
  uploadedCount.value = 0;
  totalFiles.value = files.value.length;
  error.value = null;
  uploadedMedia.value = [];

  try {
    for (let i = 0; i < files.value.length; i++) {
      const fileData = files.value[i];
      await uploadSingleFile(fileData.file);
      uploadedCount.value++;
      uploadProgress.value = Math.round((uploadedCount.value / totalFiles.value) * 100);
    }

    // Success - clear the form
    clearFiles();

    // Emit event to notify parent component
    emit('upload-complete', uploadedMedia.value);

  } catch (err) {
    error.value = err.message || 'Une erreur est survenue lors du téléchargement';
  } finally {
    uploading.value = false;
  }
};

const uploadSingleFile = async (file) => {
  const formData = new FormData();
  formData.append('file', file);

  try {
    const response = await axios.post('/media', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.data && response.data.media) {
      uploadedMedia.value.push(response.data.media);
    }
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.message;
    throw new Error(`Échec du téléchargement de "${file.name}": ${errorMessage}`);
  }
};
</script>
