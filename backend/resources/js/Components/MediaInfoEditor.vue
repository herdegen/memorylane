<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-sm p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-surface-900">Informations</h2>
      <button
        v-if="!isEditing"
        @click="startEditing"
        class="text-sm text-brand-600 hover:text-brand-800"
      >
        Modifier
      </button>
    </div>

    <!-- Edit Mode -->
    <form v-if="isEditing" @submit.prevent="save" class="space-y-4">
      <div>
        <label for="title" class="block text-sm font-medium text-surface-700 mb-1">
          Titre
        </label>
        <input
          id="title"
          v-model="form.title"
          type="text"
          class="w-full px-3 py-2 border border-surface-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
          placeholder="Titre du media"
        />
      </div>

      <div>
        <label for="description" class="block text-sm font-medium text-surface-700 mb-1">
          Description
        </label>
        <textarea
          id="description"
          v-model="form.description"
          rows="3"
          class="w-full px-3 py-2 border border-surface-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
          placeholder="Decrivez ce media..."
        ></textarea>
      </div>

      <div class="flex justify-end gap-2">
        <button
          type="button"
          @click="cancelEditing"
          class="px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50"
        >
          Annuler
        </button>
        <button
          type="submit"
          :disabled="saving"
          class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700 disabled:opacity-50"
        >
          {{ saving ? 'Enregistrement...' : 'Enregistrer' }}
        </button>
      </div>
    </form>

    <!-- View Mode -->
    <dl v-else class="space-y-3">
      <div v-if="media.title || media.description">
        <dt class="text-sm font-medium text-surface-500">Titre</dt>
        <dd class="mt-1 text-sm text-surface-900">{{ media.title || '—' }}</dd>
      </div>

      <div v-if="media.description">
        <dt class="text-sm font-medium text-surface-500">Description</dt>
        <dd class="mt-1 text-sm text-surface-900 whitespace-pre-wrap">{{ media.description }}</dd>
      </div>

      <div>
        <dt class="text-sm font-medium text-surface-500">Nom du fichier</dt>
        <dd class="mt-1 text-sm text-surface-900">{{ media.original_name }}</dd>
      </div>

      <div>
        <dt class="text-sm font-medium text-surface-500">Type</dt>
        <dd class="mt-1">
          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-brand-100 text-brand-800">
            {{ formatType(media.type) }}
          </span>
        </dd>
      </div>

      <div>
        <dt class="text-sm font-medium text-surface-500">Taille</dt>
        <dd class="mt-1 text-sm text-surface-900">{{ formatFileSize(media.size) }}</dd>
      </div>

      <div v-if="media.width && media.height">
        <dt class="text-sm font-medium text-surface-500">Dimensions</dt>
        <dd class="mt-1 text-sm text-surface-900">{{ media.width }} x {{ media.height }}px</dd>
      </div>

      <div v-if="media.duration">
        <dt class="text-sm font-medium text-surface-500">Duree</dt>
        <dd class="mt-1 text-sm text-surface-900">{{ formatDuration(media.duration) }}</dd>
      </div>

      <div>
        <dt class="text-sm font-medium text-surface-500">Telecharge le</dt>
        <dd class="mt-1 text-sm text-surface-900">{{ formatDate(media.uploaded_at) }}</dd>
      </div>

      <div v-if="media.taken_at">
        <dt class="text-sm font-medium text-surface-500">Pris le</dt>
        <dd class="mt-1 text-sm text-surface-900">{{ formatDate(media.taken_at) }}</dd>
      </div>

      <div v-if="media.user">
        <dt class="text-sm font-medium text-surface-500">Uploade par</dt>
        <dd class="mt-1 text-sm text-surface-900">{{ media.user.name }}</dd>
      </div>
    </dl>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import axios from 'axios';

const props = defineProps({
  media: {
    type: Object,
    required: true,
  },
});

const emit = defineEmits(['updated']);

const isEditing = ref(false);
const saving = ref(false);

const form = reactive({
  title: props.media.title || '',
  description: props.media.description || '',
});

const startEditing = () => {
  form.title = props.media.title || '';
  form.description = props.media.description || '';
  isEditing.value = true;
};

const cancelEditing = () => {
  isEditing.value = false;
};

const save = async () => {
  saving.value = true;
  try {
    const response = await axios.put(`/media/${props.media.id}`, {
      title: form.title || null,
      description: form.description || null,
    });
    isEditing.value = false;
    emit('updated', response.data.media);
  } catch (error) {
    console.error('Failed to update media:', error);
    alert('Erreur lors de la mise a jour');
  } finally {
    saving.value = false;
  }
};

const formatType = (type) => {
  const types = { photo: 'Photo', video: 'Video', document: 'Document' };
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
