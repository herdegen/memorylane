<template>
  <div
    class="fixed inset-0 z-50 overflow-y-auto"
    aria-labelledby="modal-title"
    role="dialog"
    aria-modal="true"
  >
    <!-- Backdrop -->
    <div
      class="fixed inset-0 bg-surface-900 bg-opacity-50 transition-opacity"
      @click="$emit('close')"
    ></div>

    <!-- Modal -->
    <div class="flex min-h-full items-center justify-center p-4">
      <div
        class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all w-full max-w-lg"
        @click.stop
      >
        <!-- Header -->
        <div class="bg-white px-6 py-4 border-b border-surface-200">
          <h3 class="text-lg font-semibold text-surface-900">
            {{ album ? 'Modifier l\'album' : 'Creer un album' }}
          </h3>
        </div>

        <!-- Form -->
        <form @submit.prevent="submit" class="px-6 py-4 space-y-4">
          <FormField
            v-model="form.name"
            id="album-name"
            label="Nom de l'album"
            placeholder="Ex: Vacances ete 2024"
            :error="form.errors.name"
            required
          />

          <div>
            <label class="block text-sm font-medium text-surface-700 mb-2">
              Description
            </label>
            <textarea
              v-model="form.description"
              rows="3"
              class="w-full px-4 py-2.5 border border-surface-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
              placeholder="Decrivez cet album..."
            ></textarea>
            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
              {{ form.errors.description }}
            </p>
          </div>

          <div class="flex items-center">
            <input
              id="is-public"
              v-model="form.is_public"
              type="checkbox"
              class="h-4 w-4 text-brand-600 focus:ring-brand-500 border-surface-300 rounded"
            />
            <label for="is-public" class="ml-2 block text-sm text-surface-700">
              Album public (visible par tous les utilisateurs)
            </label>
          </div>
        </form>

        <!-- Footer -->
        <div class="bg-surface-50 px-6 py-4 flex justify-end gap-3">
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium text-surface-700 bg-white border border-surface-300 rounded-lg hover:bg-surface-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-500"
            @click="$emit('close')"
          >
            Annuler
          </button>
          <FormButton
            type="submit"
            :text="album ? 'Enregistrer' : 'Creer'"
            :loading-text="album ? 'Enregistrement...' : 'Creation...'"
            :loading="form.processing"
            @click="submit"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import FormField from '@/Components/Forms/FormField.vue';
import FormButton from '@/Components/Forms/FormButton.vue';

const props = defineProps({
  album: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'saved']);

const form = useForm({
  name: props.album?.name || '',
  description: props.album?.description || '',
  is_public: props.album?.is_public || false,
  cover_media_id: props.album?.cover_media_id || null,
});

const submit = () => {
  if (props.album) {
    form.put(`/albums/${props.album.id}`, {
      onSuccess: () => {
        emit('saved');
        emit('close');
      },
    });
  } else {
    form.post('/albums', {
      onSuccess: () => {
        emit('saved');
        emit('close');
      },
    });
  }
};
</script>
